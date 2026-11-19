<?php

namespace App\Services\Catalog;

use App\Enums\CatalogStatus;
use App\Enums\ProductStatus;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StaffUser;
use HasinHayder\Tyro\Support\TyroAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductVariants
{
    /** @param array<string, mixed> $data */
    public function save(string $productId, ?string $variantId, array $data, StaffUser $actor): ProductVariant
    {
        try {
            return DB::transaction(function () use ($productId, $variantId, $data, $actor): ProductVariant {
                $product = $this->lockProduct($productId);
                $variant = $variantId === null ? $product->variants()->make() : $this->lookup($product, $variantId);
                $variant->fill(Arr::only($data, ['sku', 'price_override_amount']));
                if (isset($data['option_ids'])) {
                    if ($variant->is_default) {
                        throw ValidationException::withMessages(['option_ids' => ['The default SKU cannot have options.']]);
                    }

                    $options = $this->options($data['option_ids']);
                    $combination = hash('sha256', implode(':', array_keys($options)));
                    $other = $product->variants()->withTrashed()->where('combination_key', $combination);
                    if ($variant->exists) {
                        $other->whereKeyNot($variant->id);
                    }
                    if ($other->exists()) {
                        throw ValidationException::withMessages(['option_ids' => ['This option combination already exists.']]);
                    }
                    $variant->forceFill(['combination_key' => $combination]);
                }

                if (isset($data['sku'])) {
                    $otherSku = ProductVariant::query()->withTrashed()->where('sku', $data['sku']);
                    if ($variant->exists) {
                        $otherSku->whereKeyNot($variant->id);
                    }
                    if ($otherSku->exists()) {
                        throw ValidationException::withMessages(['sku' => ['The SKU has already been taken.']]);
                    }
                }

                if ($variantId === null) {
                    $variant->forceFill(['is_default' => false, 'status' => CatalogStatus::Active, 'created_by' => $actor->id]);
                }
                $fields = array_keys($variant->getDirty());
                $variant->forceFill(['updated_by' => $actor->id])->save();

                if ($variantId === null) {
                    $variant->inventory()->create();
                }

                if (isset($options)) {
                    $variant->values()->sync($options);
                    $fields[] = 'options';
                }

                $this->audit($variant, $variantId === null ? 'product-variant.created' : 'product-variant.updated', $fields);

                return $variant->load(['product', 'values']);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sku' => ['The SKU or option combination already exists.']]);
        }
    }

    public function setStatus(string $productId, string $variantId, CatalogStatus $status, StaffUser $actor): ProductVariant
    {
        return DB::transaction(function () use ($productId, $variantId, $status, $actor): ProductVariant {
            $product = $this->lockProduct($productId);
            $variant = $this->lookup($product, $variantId);
            if ($status === CatalogStatus::Inactive) {
                $this->requireOtherSellableVariant($product, $variant);
            }
            $variant->forceFill(['status' => $status, 'updated_by' => $actor->id])->save();
            $this->audit($variant, 'product-variant.status.updated', ['status']);

            return $variant->load(['product', 'values']);
        }, 3);
    }

    public function archive(string $productId, string $variantId, StaffUser $actor): void
    {
        DB::transaction(function () use ($productId, $variantId, $actor): void {
            $product = $this->lockProduct($productId);
            $variant = $this->lookup($product, $variantId);
            abort_if($variant->is_default, 409, 'The default SKU cannot be deleted.');
            $this->requireOtherSellableVariant($product, $variant);
            $variant->forceFill(['updated_by' => $actor->id])->save();
            $variant->delete();
            $this->audit($variant, 'product-variant.deleted', ['deleted_at']);
        }, 3);
    }

    private function lockProduct(string $id): Product
    {
        return Product::query()->where('public_id', $id)->lockForUpdate()->firstOrFail();
    }

    private function lookup(Product $product, string $id): ProductVariant
    {
        return $product->variants()->where('public_id', $id)->firstOrFail();
    }

    /** @param list<string> $ids
     * @return array<int, array{attribute_id: int}>
     */
    private function options(array $ids): array
    {
        $options = AttributeValue::query()->whereIn('public_id', $ids)
            ->where('status', CatalogStatus::Active)
            ->whereHas('attribute', fn (Builder $attribute) => $attribute->where('status', CatalogStatus::Active))
            ->orderBy('id')->get();
        if ($options->count() !== count($ids) || $options->pluck('attribute_id')->unique()->count() !== count($ids)) {
            throw ValidationException::withMessages(['option_ids' => ['Select one active option per attribute.']]);
        }

        $result = [];
        foreach ($options as $option) {
            $result[$option->id] = ['attribute_id' => $option->attribute_id];
        }

        return $result;
    }

    private function requireOtherSellableVariant(Product $product, ProductVariant $variant): void
    {
        if ($product->status === ProductStatus::Active && ! $product->variants()
            ->whereKeyNot($variant->id)->where('status', CatalogStatus::Active)->exists()) {
            abort(409, 'An active product needs at least one active variant.');
        }
    }

    /** @param list<string> $fields */
    private function audit(ProductVariant $variant, string $event, array $fields): void
    {
        TyroAudit::log($event, $variant, null, ['changed_fields' => $fields], ['request_id' => request()->attributes->get('api_request_id')]);
    }
}
