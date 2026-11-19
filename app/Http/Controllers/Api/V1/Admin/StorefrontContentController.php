<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\StorefrontContentResource;
use App\Models\NewsletterSubscriber;
use App\Models\StorefrontContent;
use App\Services\Catalog\CatalogMaintenance;
use App\Services\Catalog\StorefrontContentRules;
use App\Traits\ApiResponse;
use HasinHayder\Tyro\Support\TyroAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StorefrontContentController extends Controller
{
    use ApiResponse;

    public function index(Request $request, CatalogMaintenance $pagination): JsonResponse
    {
        $request->validate($this->pageRules());
        $items = StorefrontContent::query()->where('type', $request->route('contentType'))->orderBy('sort_order')->orderBy('id')
            ->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, StorefrontContentResource::collection($items), 'Content retrieved.', meta: $pagination->meta($items));
    }

    public function show(Request $request, string $content): JsonResponse
    {
        $record = $this->find($request, $content);

        return $this->apiSuccess($request, new StorefrontContentResource($record), 'Content retrieved.');
    }

    public function store(Request $request, StorefrontContentRules $rules): JsonResponse
    {
        return $this->save($request, $rules, null);
    }

    public function update(Request $request, StorefrontContentRules $rules, string $content): JsonResponse
    {
        return $this->save($request, $rules, $content);
    }

    public function destroy(Request $request, string $content): JsonResponse
    {
        DB::transaction(function () use ($request, $content): void {
            $record = $this->find($request, $content, true);
            TyroAudit::log('storefront.content.deleted', $record, ['key' => $record->key, 'type' => $record->type]);
            $record->delete();
        }, 5);

        return response()->json(null, 204);
    }

    public function settings(Request $request): JsonResponse
    {
        return $this->show($request, 'site');
    }

    public function updateSettings(Request $request, StorefrontContentRules $rules): JsonResponse
    {
        return $this->save($request, $rules, 'site');
    }

    public function subscribers(Request $request, CatalogMaintenance $pagination): JsonResponse
    {
        $request->validate($this->pageRules() + ['active' => ['sometimes', 'boolean']]);
        $query = NewsletterSubscriber::query();
        if ($request->has('active')) {
            $request->boolean('active') ? $query->whereNull('unsubscribed_at') : $query->whereNotNull('unsubscribed_at');
        }
        $items = $query->latest('id')->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));
        $items->through(fn (NewsletterSubscriber $subscriber): array => [
            'id' => $subscriber->public_id, 'email' => $subscriber->email, 'source' => $subscriber->source,
            'consented_at' => $subscriber->consented_at->toISOString(),
            'unsubscribed_at' => $subscriber->unsubscribed_at?->toISOString(),
        ]);

        return $this->apiSuccess($request, $items->items(), 'Newsletter subscribers retrieved.', meta: $pagination->meta($items))
            ->header('Cache-Control', 'no-store');
    }

    private function save(Request $request, StorefrontContentRules $rules, ?string $id): JsonResponse
    {
        $type = $request->route('contentType');
        $validation = $rules->rules($type);
        if ($type !== 'settings') {
            $validation += [
                'key' => [$id === null ? 'required' : 'sometimes', 'string', 'max:80', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
                'published' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer', 'between:0,2147483647'],
                'starts_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i:sP'],
                'ends_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i:sP'],
            ];
            if ($id !== null && ! $request->has('data')) {
                $validation['data'] = ['sometimes', ...$validation['data']];
                foreach (array_keys($validation) as $key) {
                    if (str_starts_with($key, 'data.')) {
                        unset($validation[$key]);
                    }
                }
            }
        }
        $values = $request->validate($validation);
        try {
            $record = DB::transaction(function () use ($request, $type, $id, $values): StorefrontContent {
                $record = $id === null ? new StorefrontContent(['type' => $type]) : $this->find($request, $id, true);
                foreach (['starts_at', 'ends_at'] as $field) {
                    if (isset($values[$field])) {
                        $values[$field] = Carbon::parse($values[$field])->utc();
                    }
                }
                $record->fill($values);
                if ($record->starts_at !== null && $record->ends_at !== null && $record->ends_at->lte($record->starts_at)) {
                    throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
                }
                $changed = array_keys($record->getDirty());
                $created = ! $record->exists;
                $record->save();
                if ($changed !== []) {
                    TyroAudit::log('storefront.content.'.($created ? 'created' : 'updated'), $record, null, ['changed_fields' => $changed]);
                }

                return $record;
            }, 5);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['key' => 'This content key is already in use.']);
        }

        return $this->apiSuccess($request, new StorefrontContentResource($record->refresh()), 'Content saved.', $id === null ? 201 : 200);
    }

    private function find(Request $request, string $id, bool $lock = false): StorefrontContent
    {
        $type = $request->route('contentType');
        $query = StorefrontContent::query()->where('type', $type)->where($type === 'settings' ? 'key' : 'public_id', $id);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    /** @return array<string, list<string>> */
    private function pageRules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
