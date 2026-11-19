<?php

namespace App\Services\Catalog;

use App\Enums\CatalogStatus;
use App\Models\CatalogRecord;
use App\Models\StaffUser;
use HasinHayder\Tyro\Support\TyroAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogMaintenance
{
    /**
     * @template T of CatalogRecord
     *
     * @param  Builder<T>  $query
     * @return LengthAwarePaginator<int, T>
     */
    public function paginate(Builder $query, Request $request): LengthAwarePaginator
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'required', 'string', 'max:100'],
            'status' => ['sometimes', Rule::enum(CatalogStatus::class)],
            'sort_by' => ['sometimes', Rule::in(['name', 'slug', 'sort_order', 'created_at'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'trashed' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['search'])) {
            $prefix = strtr($filters['search'], ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->whereRaw("name LIKE ? ESCAPE '!'", [$prefix]);
        }

        $direction = $filters['sort_direction'] ?? 'asc';

        return $query->orderBy($filters['sort_by'] ?? 'sort_order', $direction)->orderBy('id', $direction)
            ->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));
    }

    /**
     * @template T of CatalogRecord
     *
     * @param LengthAwarePaginator<int, T> $items
     * @return array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}} */
    public function meta(LengthAwarePaginator $items): array
    {
        return ['pagination' => [
            'current_page' => $items->currentPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total(),
            'last_page' => $items->lastPage(),
        ]];
    }

    /** @param array<string, mixed> $data */
    public function save(CatalogRecord $record, array $data, StaffUser $actor, string $event): CatalogRecord
    {
        try {
            return DB::transaction(function () use ($record, $data, $actor, $event): CatalogRecord {
                $create = ! $record->exists;
                $record->fill($data);
                $fields = array_keys($record->getDirty());
                $record->forceFill(['updated_by' => $actor->id]);

                if ($create) {
                    $record->forceFill(['created_by' => $actor->id, 'status' => CatalogStatus::Inactive]);
                }

                $record->save();
                $this->audit($record, $event.($create ? '.created' : '.updated'), ['changed_fields' => $fields]);

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => ['The slug has already been taken.']]);
        }
    }

    /**
     * @template T of CatalogRecord
     *
     * @param  Builder<T>  $query
     * @param  'activate'|'deactivate'|'delete'|'restore'  $action
     */
    public function apply(Builder $query, string $id, string $action, StaffUser $actor, string $event): CatalogRecord
    {
        return DB::transaction(function () use ($query, $id, $action, $actor, $event): CatalogRecord {
            $lookup = (clone $query)->where('public_id', $id)->lockForUpdate();
            $record = ($action === 'restore' ? $lookup->onlyTrashed() : $lookup)->firstOrFail();
            $record->forceFill(['updated_by' => $actor->id]);

            match ($action) {
                'activate' => $record->forceFill(['status' => CatalogStatus::Active])->save(),
                'deactivate' => $record->forceFill(['status' => CatalogStatus::Inactive])->save(),
                'delete' => $record->save() && $record->delete(),
                'restore' => $record->restore(),
            };

            $this->audit($record, $event.'.'.$action, ['status' => $record->status->value]);

            return $record;
        }, 3);
    }

    /**
     * @template T of CatalogRecord
     *
     * @param  Builder<T>  $query
     */
    public function reorder(Builder $query, Request $request, StaffUser $actor, string $event): void
    {
        $data = $request->validate([
            'ids' => ['present', 'array', 'list', 'max:1000'],
            'ids.*' => ['required', 'ulid', 'distinct:ignore_case'],
        ]);

        DB::transaction(function () use ($query, $data, $actor, $event): void {
            $records = $query->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');

            if (count($data['ids']) !== $records->count() || array_diff($data['ids'], $records->keys()->all()) !== []) {
                throw ValidationException::withMessages(['ids' => ['Supply every record in this group exactly once.']]);
            }

            foreach ($data['ids'] as $position => $id) {
                $records[$id]->forceFill(['sort_order' => $position, 'updated_by' => $actor->id])->save();
            }

            TyroAudit::log($event.'.reordered', null, null, ['ids' => $data['ids']], $this->context());
        }, 3);
    }

    /**
     * @template T of CatalogRecord
     *
     * @param  Builder<T>  $query
     * @return array{ succeeded_ids: list<string>, failed: list<array{id: string, code: string}>, counts: array{succeeded: int, failed: int} }
     */
    public function bulk(Builder $query, Request $request, StaffUser $actor, string $permissionPrefix, string $event): array
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate', 'delete', 'restore'])],
            'ids' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'ids.*' => ['required', 'ulid', 'distinct:ignore_case'],
        ]);

        $permission = $permissionPrefix.(in_array($data['action'], ['delete', 'restore'], true) ? '.delete' : '.update');
        $succeeded = [];
        $failed = [];

        foreach ($data['ids'] as $id) {
            if (Gate::forUser($actor)->denies($permission)) {
                $failed[] = ['id' => $id, 'code' => 'FORBIDDEN'];

                continue;
            }

            try {
                $this->apply($query, $id, $data['action'], $actor, $event);
                $succeeded[] = $id;
            } catch (ModelNotFoundException) {
                $failed[] = ['id' => $id, 'code' => 'NOT_FOUND'];
            }
        }

        return ['succeeded_ids' => $succeeded, 'failed' => $failed, 'counts' => ['succeeded' => count($succeeded), 'failed' => count($failed)]];
    }

    public function actor(Request $request): StaffUser
    {
        $actor = $request->user();
        abort_unless($actor instanceof StaffUser, 401);

        return $actor;
    }

    /** @param array<string, mixed> $changes */
    private function audit(CatalogRecord $record, string $event, array $changes): void
    {
        TyroAudit::log($event, $record, null, $changes, $this->context());
    }

    /** @return array{request_id: mixed} */
    private function context(): array
    {
        return ['request_id' => request()->attributes->get('api_request_id')];
    }
}
