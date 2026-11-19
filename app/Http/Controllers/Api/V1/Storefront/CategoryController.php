<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Services\Catalog\CategoryTree;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    use ApiResponse;

    public function index(Request $request, CategoryTree $tree): JsonResponse
    {
        return $this->apiSuccess($request, CategoryResource::collection($tree->visible()), 'Categories retrieved.');
    }

    public function show(Request $request, string $slug, CategoryTree $tree): JsonResponse
    {
        $category = $tree->findVisible($tree->visible(), $slug);
        abort_if($category === null, 404);

        return $this->apiSuccess($request, new CategoryResource($category), 'Category retrieved.');
    }
}
