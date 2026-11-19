<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveTaxonomyRequest;
use App\Http\Resources\Api\V1\TaxonomyResource;
use App\Models\Brand;
use App\Services\Catalog\CatalogMaintenance;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class BrandController extends Controller
{
    use ApiResponse;

    public function index(Request $request, CatalogMaintenance $catalog): JsonResponse
    {
        $items = $catalog->paginate($this->query(), $request);

        return $this->apiSuccess($request, TaxonomyResource::collection($items), 'Brand records retrieved.', meta: $catalog->meta($items));
    }

    public function show(Request $request, string $brand): JsonResponse
    {
        $item = $this->query()->where('public_id', $brand)->firstOrFail();

        return $this->apiSuccess($request, new TaxonomyResource($item), 'Brand retrieved.');
    }

    public function store(SaveTaxonomyRequest $request, CatalogMaintenance $catalog): JsonResponse
    {
        $record = new Brand;
        $item = $catalog->save($record, $request->validated(), $catalog->actor($request), 'brand');

        return $this->apiSuccess($request, new TaxonomyResource($item), 'Brand created.', 201);
    }

    public function update(SaveTaxonomyRequest $request, string $brand, CatalogMaintenance $catalog): JsonResponse
    {
        $record = $this->query()->where('public_id', $brand)->firstOrFail();
        $item = $catalog->save($record, $request->validated(), $catalog->actor($request), 'brand');

        return $this->apiSuccess($request, new TaxonomyResource($item), 'Brand updated.');
    }

    public function status(Request $request, string $brand, CatalogMaintenance $catalog): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(CatalogStatus::class)]]);
        $item = $catalog->apply($this->query(), $brand, $data['status'] === 'active' ? 'activate' : 'deactivate', $catalog->actor($request), 'brand');

        return $this->apiSuccess($request, new TaxonomyResource($item), 'Brand status updated.');
    }

    public function destroy(Request $request, string $brand, CatalogMaintenance $catalog): Response
    {
        $catalog->apply($this->query(), $brand, 'delete', $catalog->actor($request), 'brand');

        return response()->noContent();
    }

    public function restore(Request $request, string $brand, CatalogMaintenance $catalog): JsonResponse
    {
        $item = $catalog->apply($this->query(), $brand, 'restore', $catalog->actor($request), 'brand');

        return $this->apiSuccess($request, new TaxonomyResource($item), 'Brand restored.');
    }

    public function reorder(Request $request, CatalogMaintenance $catalog): Response
    {
        $catalog->reorder($this->query(), $request, $catalog->actor($request), 'brand');

        return response()->noContent();
    }

    public function bulk(Request $request, CatalogMaintenance $catalog): JsonResponse
    {
        $result = $catalog->bulk($this->query(), $request, $catalog->actor($request), 'brands', 'brand');

        return $this->apiSuccess($request, $result, 'Bulk action completed.');
    }

    /** @return Builder<Brand> */
    private function query(): Builder
    {
        return Brand::query();
    }
}
