<?php

namespace App\Services\Catalog;

use App\Enums\CatalogStatus;
use App\Models\Category;
use App\Models\StaffUser;
use HasinHayder\Tyro\Support\TyroAudit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CategoryTree
{
    /** @return Collection<int, Category> */
    public function visible(): Collection
    {
        $categories = Category::query()->where('status', CatalogStatus::Active)->orderBy('sort_order')->orderBy('id')->get();
        $byParent = $categories->groupBy('parent_id');

        foreach ($categories as $category) {
            $category->setRelation('children', $byParent->get($category->id, new Collection));
        }

        return $byParent->get('', new Collection);
    }

    /** @param Collection<int, Category> $nodes */
    public function findVisible(Collection $nodes, string $slug): ?Category
    {
        foreach ($nodes as $category) {
            if ($category->slug === $slug) {
                return $category;
            }

            $found = $this->findVisible($category->getRelation('children'), $slug);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    public function save(?string $id, array $data, StaffUser $actor): Category
    {
        return DB::transaction(function () use ($id, $data, $actor): Category {
            $locked = $this->lockTree();
            $category = $id === null ? new Category : $this->find($locked, $id);

            if (array_key_exists('parent_id', $data)) {
                $parent = $data['parent_id'] === null ? null : $locked->firstWhere('public_id', $data['parent_id']);
                if ($data['parent_id'] !== null && ($parent === null || $parent->trashed())) {
                    throw ValidationException::withMessages(['parent_id' => ['The parent category is unavailable.']]);
                }

                $this->validateParent($category, $parent, $locked);
                $category->parent_id = $parent?->id;
            }

            $category->fill($data);
            $fields = array_keys($category->getDirty());
            $category->forceFill(['updated_by' => $actor->id]);

            if ($id === null) {
                $category->forceFill(['created_by' => $actor->id, 'status' => CatalogStatus::Inactive]);
            }

            $category->save();
            $this->audit($category, $id === null ? 'category.created' : 'category.updated', ['changed_fields' => $fields]);

            return $category->load('parent');
        }, 3);
    }

    /** @param 'activate'|'deactivate'|'delete'|'restore' $action */
    public function apply(string $id, string $action, StaffUser $actor): Category
    {
        return DB::transaction(function () use ($id, $action, $actor): Category {
            $query = Category::query()->where('public_id', $id)->lockForUpdate();
            $category = ($action === 'restore' ? $query->onlyTrashed() : $query)->firstOrFail();
            $category->forceFill(['updated_by' => $actor->id]);

            match ($action) {
                'activate' => $category->forceFill(['status' => CatalogStatus::Active])->save(),
                'deactivate' => $category->forceFill(['status' => CatalogStatus::Inactive])->save(),
                'delete' => $category->save() && $category->delete(),
                'restore' => $category->restore(),
            };

            $this->audit($category, 'category.'.$action, ['status' => $category->status->value]);

            return $category->load('parent');
        }, 3);
    }

    /** @param list<string> $ids */
    public function reorder(?string $parentId, array $ids, StaffUser $actor): void
    {
        DB::transaction(function () use ($parentId, $ids, $actor): void {
            $locked = $this->lockTree();
            $parent = $parentId === null ? null : $this->find($locked, $parentId);
            $siblings = $locked->filter(fn (Category $category): bool => ! $category->trashed() && $category->parent_id === $parent?->id)->keyBy('public_id');

            if (count($ids) !== $siblings->count() || array_diff($ids, $siblings->keys()->all()) !== []) {
                throw ValidationException::withMessages(['ids' => ['Supply every category in the selected parent group exactly once.']]);
            }

            foreach ($ids as $position => $id) {
                $siblings[$id]->forceFill(['sort_order' => $position, 'updated_by' => $actor->id])->save();
            }

            TyroAudit::log('category.reordered', $parent, null, ['category_ids' => $ids], $this->auditContext());
        }, 3);
    }

    /** @return Collection<int, Category> */
    private function lockTree(): Collection
    {
        return Category::query()->withTrashed()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /** @param Collection<int, Category> $categories */
    private function find(Collection $categories, string $id, bool $trashed = false): Category
    {
        $category = $categories->firstWhere('public_id', $id);

        abort_if($category === null || $category->trashed() !== $trashed, 404);

        return $category;
    }

    /** @param Collection<int, Category> $categories */
    private function validateParent(Category $category, ?Category $parent, Collection $categories): void
    {
        while ($parent !== null) {
            if ($parent->id === $category->id) {
                throw ValidationException::withMessages(['parent_id' => ['A category cannot be its own ancestor.']]);
            }

            $parent = $parent->parent_id === null ? null : $categories->get($parent->parent_id);
        }
    }

    /** @param array<string, mixed> $changes */
    private function audit(Category $category, string $action, array $changes): void
    {
        TyroAudit::log($action, $category, null, $changes, $this->auditContext());
    }

    /** @return array{request_id: mixed} */
    private function auditContext(): array
    {
        return ['request_id' => request()->attributes->get('api_request_id')];
    }
}
