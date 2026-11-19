<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use App\Enums\CouponType;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property CouponType $type
 * @property int $value
 * @property string|null $currency
 * @property int $minimum_amount
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property CatalogStatus $status
 * @property int|null $global_limit
 * @property int|null $per_customer_limit
 * @property Carbon|null $deleted_at
 */
#[Fillable(['code', 'minimum_amount', 'global_limit', 'per_customer_limit'])]
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $attributes = ['minimum_amount' => 0, 'status' => 'inactive'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CouponType::class, 'status' => CatalogStatus::class,
            'value' => 'integer', 'minimum_amount' => 'integer',
            'starts_at' => 'datetime', 'ends_at' => 'datetime',
            'global_limit' => 'integer', 'per_customer_limit' => 'integer',
        ];
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)->withTrashed();
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withTrashed();
    }
}
