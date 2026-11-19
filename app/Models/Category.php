<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $parent_id
 * @property string|null $description
 * @property string|null $image_url
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property-read self|null $parent
 * @property-read Collection<int, Category> $children
 */
#[Fillable(['name', 'slug', 'description', 'image_url', 'seo_title', 'seo_description'])]
class Category extends CatalogRecord
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class)->withTrashed();
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
