<?php

namespace App\Services\Catalog;

use App\Enums\CatalogStatus;
use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\StaffUser;
use HasinHayder\Tyro\Support\TyroAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductCatalog
{
    /** @param array<string, mixed> $data */
    public function save(?string $id, array $data, StaffUser $actor): Product
    {
        try {
            return DB::transaction(function () use ($id, $data, $actor): Product {
                $product = $id === null ? new Product : Product::query()->where('public_id', $id)->lockForUpdate()->firstOrFail();
                $product->fill(Arr::except($data, ['brand_id', 'category_ids', 'sku']));

                if ($product->sale_amount !== null && $product->sale_amount >= $product->regular_amount) {
                    throw ValidationException::withMessages(['sale_amount' => ['The sale amount must be lower than the regular amount.']]);
                }

                if (array_key_exists('brand_id', $data)) {
                    $brand = $data['brand_id'] === null ? null : Brand::query()->where('public_id', $data['brand_id'])->first();
                    if ($data['brand_id'] !== null && $brand === null) {
                        throw ValidationException::withMessages(['brand_id' => ['The selected brand is unavailable.']]);
                    }
                    $product->brand()->associate($brand);
                }

                $categoryKeys = null;
                if (array_key_exists('category_ids', $data)) {
                    $categoryKeys = Category::query()->whereIn('public_id', $data['category_ids'])->pluck('id');
                    if ($categoryKeys->count() !== count($data['category_ids'])) {
                        throw ValidationException::withMessages(['category_ids' => ['At least one selected category is unavailable.']]);
                    }
                    if ($product->exists && $product->status === ProductStatus::Active && $categoryKeys->isEmpty()) {
                        throw ValidationException::withMessages(['category_ids' => ['An active product requires a category.']]);
                    }
                }

                $changes = array_keys($product->getDirty());
                $product->forceFill(['updated_by' => $actor->id]);
                if ($id === null) {
                    $product->forceFill(['status' => ProductStatus::Draft, 'currency' => 'USD', 'created_by' => $actor->id]);
                }

                $product->save();
                if ($categoryKeys !== null) {
                    $product->categories()->sync($categoryKeys);
                    $changes[] = 'category_ids';
                }
                if ($id === null) {
                    $product->variants()->make()->forceFill([
                        'sku' => $data['sku'],
                        'is_default' => true,
                        'combination_key' => hash('sha256', ''),
                        'status' => CatalogStatus::Active,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ])->save();
                }

                $this->audit($product, $id === null ? 'product.created' : 'product.updated', ['changed_fields' => $changes]);

                return $product;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if ($id === null && Product::query()->withTrashed()->where('slug', $data['slug'])->exists()) {
                throw ValidationException::withMessages(['slug' => ['The slug has already been taken.']]);
            }
            if ($id === null && DB::table('product_variants')->where('sku', $data['sku'])->exists()) {
                throw ValidationException::withMessages(['sku' => ['The SKU has already been taken.']]);
            }

            throw $exception;
        }
    }

    public function setStatus(string $id, ProductStatus $status, StaffUser $actor): Product
    {
        return DB::transaction(function () use ($id, $status, $actor): Product {
            $product = Product::query()->where('public_id', $id)->lockForUpdate()->firstOrFail();
            if ($status === ProductStatus::Active) {
                if (! $product->categories()->exists()) {
                    throw ValidationException::withMessages(['status' => ['A product needs a category before publication.']]);
                }
                if (! $product->variants()->eligible()->exists()) {
                    throw ValidationException::withMessages(['status' => ['A product needs an active variant before publication.']]);
                }
                $product->forceFill(['published_at' => $product->published_at ?? now()]);
            }

            $previous = $product->status;
            $product->forceFill(['status' => $status, 'updated_by' => $actor->id])->save();
            $this->audit($product, 'product.status.updated', ['status' => $status->value], ['status' => $previous->value]);

            return $product;
        }, 3);
    }

    public function archive(string $id, StaffUser $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $product = Product::query()->where('public_id', $id)->lockForUpdate()->firstOrFail();
            $product->forceFill(['updated_by' => $actor->id])->save();
            $product->delete();
            $this->audit($product, 'product.deleted', []);
        }, 3);
    }

    public function restore(string $id, StaffUser $actor): Product
    {
        return DB::transaction(function () use ($id, $actor): Product {
            $product = Product::query()->onlyTrashed()->where('public_id', $id)->lockForUpdate()->firstOrFail();
            $product->forceFill(['status' => ProductStatus::Draft, 'updated_by' => $actor->id])->restore();
            $this->audit($product, 'product.restored', ['status' => ProductStatus::Draft->value]);

            return $product;
        }, 3);
    }

    /** @param array<string, mixed> $after
     * @param  array<string, mixed>|null  $before
     */
    private function audit(Product $product, string $event, array $after, ?array $before = null): void
    {
        TyroAudit::log($event, $product, $before, $after, ['request_id' => request()->attributes->get('api_request_id')]);
    }
}
