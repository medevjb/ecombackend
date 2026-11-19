<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveCategoryRequest;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Models\Category;
use App\Models\StaffUser;
use App\Services\Catalog\CatalogMaintenance;
use App\Services\Catalog\CategoryTree;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    use ApiResponse;

    public function index(Request $request, CatalogMaintenance $catalog): JsonResponse
    {
        $items = $catalog->paginate(Category::query()->with('parent'), $request);

        return $this->apiSuccess($request, CategoryResource::collection($items), 'Categories retrieved.', meta: $catalog->meta($items));
    }

    public function show(Request $request, string $category): JsonResponse
    {
        $model = Category::query()->with('parent')->where('public_id', $category)->firstOrFail();

        return $this->apiSuccess($request, new CategoryResource($model), 'Category retrieved.');
    }

    public function store(SaveCategoryRequest $request, CategoryTree $tree): JsonResponse
    {
        $model = $tree->save(null, $request->validated(), $this->actor($request));

        return $this->apiSuccess($request, new CategoryResource($model), 'Category created.', 201);
    }

    public function update(SaveCategoryRequest $request, string $category, CategoryTree $tree): JsonResponse
    {
        $model = $tree->save($category, $request->validated(), $this->actor($request));

        return $this->apiSuccess($request, new CategoryResource($model), 'Category updated.');
    }

    public function status(Request $request, string $category, CategoryTree $tree): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(CatalogStatus::class)]]);
        $model = $tree->apply($category, $data['status'] === 'active' ? 'activate' : 'deactivate', $this->actor($request));

        return $this->apiSuccess($request, new CategoryResource($model), 'Category status updated.');
    }

    public function destroy(Request $request, string $category, CategoryTree $tree): Response
    {
        $tree->apply($category, 'delete', $this->actor($request));

        return response()->noContent();
    }

    public function restore(Request $request, string $category, CategoryTree $tree): JsonResponse
    {
        $model = $tree->apply($category, 'restore', $this->actor($request));

        return $this->apiSuccess($request, new CategoryResource($model), 'Category restored.');
    }

    public function reorder(Request $request, CategoryTree $tree): Response
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'ulid'],
            'ids' => ['present', 'array', 'list', 'max:1000'],
            'ids.*' => ['required', 'ulid', 'distinct:ignore_case'],
        ]);
        $tree->reorder($data['parent_id'] ?? null, $data['ids'], $this->actor($request));

        return response()->noContent();
    }

    public function bulk(Request $request, CatalogMaintenance $catalog): JsonResponse
    {
        $result = $catalog->bulk(Category::query(), $request, $this->actor($request), 'categories', 'category');

        return $this->apiSuccess($request, $result, 'Category bulk action completed.');
    }

    private function actor(Request $request): StaffUser
    {
        $actor = $request->user();
        abort_unless($actor instanceof StaffUser, 401);

        return $actor;
    }
}
