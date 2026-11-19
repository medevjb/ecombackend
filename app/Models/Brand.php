<?php

namespace App\Models;

use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property string|null $description
 * @property string|null $logo_url
 */
#[Fillable(['name', 'slug', 'description', 'logo_url'])]
class Brand extends CatalogRecord
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;
}
