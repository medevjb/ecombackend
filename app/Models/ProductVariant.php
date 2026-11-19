<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property string $sku
 * @property bool $is_default
 * @property CatalogStatus $status
 * @property int|null $price_override_amount
 */
#[Fillable(['sku', 'price_override_amount'])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => CatalogStatus::class, 'is_default' => 'boolean', 'price_override_amount' => 'integer'];
    }

    /** @param Builder<ProductVariant> $query */
    public function scopeEligible(Builder $query): void
    {
        $query->where('product_variants.status', CatalogStatus::Active)->whereNotExists(function ($options): void {
            $options->selectRaw('1')
                ->from('variant_attribute_values')
                ->join('attribute_values', 'attribute_values.id', '=', 'variant_attribute_values.attribute_value_id')
                ->join('attributes', 'attributes.id', '=', 'variant_attribute_values.attribute_id')
                ->whereColumn('variant_attribute_values.product_variant_id', 'product_variants.id')
                ->where(function ($invalid): void {
                    $invalid->whereNotNull('attribute_values.deleted_at')->orWhereNotNull('attributes.deleted_at')
                        ->orWhere('attribute_values.status', '!=', CatalogStatus::Active->value)
                        ->orWhere('attributes.status', '!=', CatalogStatus::Active->value);
                });
        });
    }

    /** @return HasOne<Inventory, $this> */
    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsToMany<AttributeValue, $this> */
    public function values(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'variant_attribute_values')->withPivot('attribute_id');
    }
}
