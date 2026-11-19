<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveTaxonomyRequest;
use App\Http\Resources\Api\V1\TaxonomyResource;
use App\Models\ProductAttribute;
use App\Services\Catalog\CatalogMaintenance;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class AttributeController extends Controller
{
    use ApiResponse;

    public function index(Request $request, CatalogMaintenance $catalog): JsonResponse
    {
        $items = $catalog->paginate($this->query(), $request);

        return $this->apiSuccess($request, TaxonomyResource::collection($items), 'Attribute records retrieved.', meta: $catalog->meta($items));
    }

    public function show(Request $request, string $attribute): JsonResponse
    {
        $item = $this->query()->where('public_id', $attribute)->firstOrFail();

        return $this->apiSuccess($request, new TaxonomyResource($item), 'Attribute retrieved.');
    }

    public function store(SaveTaxonomyRequest $request, CatalogMaintenance $catalog): JsonResponse
    {
        $record = new ProductAttribute;
        $item = $catalog->save($record, $request->validated(), $catalog->actor($request), 'attribute');

        return $this->apiSuccess($request, new TaxonomyResource($item), 'Attribute created.', 201);
    }

    public function update(SaveTaxonomyRequest $request, string $attribute, CatalogMaintenance $catalog): JsonResponse
    {
        $record = $this->query()->where('public_id', $attribute)->firstOrFail();
        $item = $catalog->save($record, $request->validated(), $catalog->actor($request), 'attribute');

        return $this->apiSuccess($request, new TaxonomyResource($item), 'Attribute updated.');
    }

    public function status(Request $request, string $attribute, CatalogMaintenance $catalog): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(CatalogStatus::class)]]);
        $item = $catalog->apply($this->query(), $attribute, $data['status'] === 'active' ? 'activate' : 'deactivate', $catalog->actor($request), 'attribute');

        return $this->apiSuccess($request, new TaxonomyResource($item), 'Attribute status updated.');
    }

    public function destroy(Request $request, string $attribute, CatalogMaintenance $catalog): Response
    {
        $catalog->apply($this->query(), $attribute, 'delete', $catalog->actor($request), 'attribute');

        return response()->noContent();
    }

    public function restore(Request $request, string $attribute, CatalogMaintenance $catalog): JsonResponse
    {
        $item = $catalog->apply($this->query(), $attribute, 'restore', $catalog->actor($request), 'attribute');

        return $this->apiSuccess($request, new TaxonomyResource($item), 'Attribute restored.');
    }

    public function reorder(Request $request, CatalogMaintenance $catalog): Response
    {
        $catalog->reorder($this->query(), $request, $catalog->actor($request), 'attribute');

        return response()->noContent();
    }

    public function bulk(Request $request, CatalogMaintenance $catalog): JsonResponse
    {
        $result = $catalog->bulk($this->query(), $request, $catalog->actor($request), 'attributes', 'attribute');

        return $this->apiSuccess($request, $result, 'Bulk action completed.');
    }

    /** @return Builder<ProductAttribute> */
    private function query(): Builder
    {
        return ProductAttribute::query();
    }
}
