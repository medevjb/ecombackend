<?php

namespace App\Models;

use Database\Factories\StorefrontContentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $public_id
 * @property string $type
 * @property string $key
 * @property array<string, mixed> $data
 * @property int $sort_order
 * @property bool $published
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
#[Fillable(['type', 'key', 'data', 'sort_order', 'published', 'starts_at', 'ends_at'])]
class StorefrontContent extends Model
{
    /** @use HasFactory<StorefrontContentFactory> */
    use HasFactory, HasUlids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @param Builder<self> $query */
    public function scopePublished(Builder $query): void
    {
        $time = now();
        $query->where('published', true)
            ->where(fn (Builder $window) => $window->whereNull('starts_at')->orWhere('starts_at', '<=', $time))
            ->where(fn (Builder $window) => $window->whereNull('ends_at')->orWhere('ends_at', '>', $time));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['data' => 'array', 'sort_order' => 'integer', 'published' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
