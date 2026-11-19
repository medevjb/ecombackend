<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\StaffUser;
use HasinHayder\Tyro\Support\TyroAudit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductImages
{
    /** @param array{alt_text?: string|null, primary?: bool} $data */
    public function upload(string $productId, ?string $imageId, UploadedFile $image, array $data, StaffUser $actor): ProductImage
    {
        $product = $this->product($productId);
        $disk = (string) config('filesystems.product_media_disk');
        $path = $image->store('products/'.$product->public_id, $disk);
        abort_if($path === false, 503, 'The image could not be stored.');

        try {
            return DB::transaction(function () use ($productId, $imageId, $data, $actor, $disk, $path): ProductImage {
                $product = $this->product($productId, lock: true);
                $record = $imageId === null ? $product->images()->make() : $this->image($product, $imageId);
                $new = ! $record->exists;
                $attributes = ['disk' => $disk, 'path' => $path, 'updated_by' => $actor->id];

                if ($new) {
                    $position = $product->images()->max('sort_order');
                    $attributes += [
                        'created_by' => $actor->id,
                        'sort_order' => $position === null ? 0 : $position + 1,
                        'is_primary' => $position === null,
                    ];
                }

                if (array_key_exists('alt_text', $data)) {
                    $attributes['alt_text'] = $data['alt_text'];
                }

                $record->forceFill($attributes)->save();
                if ($data['primary'] ?? false) {
                    $this->assignPrimary($product, $record, $actor);
                }

                $this->audit($record, $new ? 'product-image.created' : 'product-image.replaced', $actor);

                return $record;
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    /** @param array{alt_text?: string|null, primary?: bool} $data */
    public function update(string $productId, string $imageId, array $data, StaffUser $actor): ProductImage
    {
        return DB::transaction(function () use ($productId, $imageId, $data, $actor): ProductImage {
            $product = $this->product($productId, lock: true);
            $record = $this->image($product, $imageId);
            if (array_key_exists('alt_text', $data)) {
                $record->forceFill(['alt_text' => $data['alt_text'], 'updated_by' => $actor->id])->save();
            }
            if ($data['primary'] ?? false) {
                $this->assignPrimary($product, $record, $actor);
            }
            $this->audit($record, 'product-image.updated', $actor);

            return $record;
        }, 3);
    }

    public function delete(string $productId, string $imageId, StaffUser $actor): void
    {
        DB::transaction(function () use ($productId, $imageId, $actor): void {
            $product = $this->product($productId, lock: true);
            $record = $this->image($product, $imageId);
            $primary = $record->is_primary;
            $record->forceFill(['updated_by' => $actor->id, 'is_primary' => false])->save();
            $record->delete();

            if ($primary) {
                $next = $product->images()->orderBy('sort_order')->orderBy('id')->first();
                if ($next !== null) {
                    $this->assignPrimary($product, $next, $actor);
                }
            }

            $this->audit($record, 'product-image.deleted', $actor);
        }, 3);
    }

    /** @param list<string> $ids */
    public function reorder(string $productId, array $ids, StaffUser $actor): void
    {
        DB::transaction(function () use ($productId, $ids, $actor): void {
            $product = $this->product($productId, lock: true);
            $records = $product->images()->get()->keyBy('public_id');
            if (count($ids) !== $records->count() || array_diff($ids, $records->keys()->all()) !== []) {
                throw ValidationException::withMessages(['ids' => ['Supply every image for this product exactly once.']]);
            }
            foreach ($ids as $position => $id) {
                $records[$id]->forceFill(['sort_order' => $position, 'updated_by' => $actor->id])->save();
            }
            TyroAudit::log('product-images.reordered', $product, null, ['ids' => $ids], ['request_id' => request()->attributes->get('api_request_id')]);
        }, 3);
    }

    private function product(string $id, bool $lock = false): Product
    {
        $query = Product::query()->where('public_id', $id);

        return $lock ? $query->lockForUpdate()->firstOrFail() : $query->firstOrFail();
    }

    private function image(Product $product, string $id): ProductImage
    {
        return $product->images()->where('public_id', $id)->firstOrFail();
    }

    private function assignPrimary(Product $product, ProductImage $record, StaffUser $actor): void
    {
        $product->images()->where('is_primary', true)->whereKeyNot($record->id)->update(['is_primary' => false, 'updated_by' => $actor->id]);
        $record->forceFill(['is_primary' => true, 'updated_by' => $actor->id])->save();
    }

    private function audit(ProductImage $record, string $event, StaffUser $actor): void
    {
        TyroAudit::log($event, $record, null, ['actor_id' => $actor->id], ['request_id' => request()->attributes->get('api_request_id')]);
    }
}
