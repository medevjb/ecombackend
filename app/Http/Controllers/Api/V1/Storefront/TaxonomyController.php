<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TaxonomyResource;
use App\Models\Brand;
use App\Models\ProductAttribute;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxonomyController extends Controller
{
    use ApiResponse;

    public function brands(Request $request): JsonResponse
    {
        $brands = Brand::query()->where('status', CatalogStatus::Active)->orderBy('sort_order')->orderBy('id')->get();

        return $this->apiSuccess($request, TaxonomyResource::collection($brands), 'Brands retrieved.');
    }

    public function attributes(Request $request): JsonResponse
    {
        $attributes = ProductAttribute::query()->where('status', CatalogStatus::Active)
            ->with(['values' => fn ($query) => $query->where('status', CatalogStatus::Active)->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')->orderBy('id')->get();

        return $this->apiSuccess($request, TaxonomyResource::collection($attributes), 'Attributes retrieved.');
    }
}
