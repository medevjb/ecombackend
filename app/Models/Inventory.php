<?php

namespace App\Models;

use Database\Factories\InventoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $product_variant_id
 * @property int $on_hand
 * @property int $reserved
 * @property-read ProductVariant|null $variant
 */
class Inventory extends Model
{
    protected $attributes = ['on_hand' => 0, 'reserved' => 0];

    /** @use HasFactory<InventoryFactory> */
    use HasFactory;

    public function available(): int
    {
        return $this->on_hand - $this->reserved;
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
