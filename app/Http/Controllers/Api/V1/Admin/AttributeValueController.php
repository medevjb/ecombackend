<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveTaxonomyRequest;
use App\Http\Resources\Api\V1\TaxonomyResource;
use App\Models\AttributeValue;
use App\Models\ProductAttribute;
use App\Services\Catalog\CatalogMaintenance;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class AttributeValueController extends Controller
{
    use ApiResponse;

    public function index(Request $request, string $attribute, CatalogMaintenance $catalog): JsonResponse
    {
        $items = $catalog->paginate($this->query($attribute), $request);

        return $this->apiSuccess($request, TaxonomyResource::collection($items), 'Attribute value records retrieved.', meta: $catalog->meta($items));
    }

    public function show(Request $request, string $attribute, string $value): JsonResponse
    {
        $item = $this->query($attribute)->where('public_id', $value)->firstOrFail();

        return $this->apiSuccess($request, new TaxonomyResource($item), 'Attribute value retrieved.');
    }

    public function store(SaveTaxonomyRequest $request, string $attribute, CatalogMaintenance $catalog): JsonResponse
    {
        $parent = ProductAttribute::query()->where('public_id', $attribute)->firstOrFail();
        $record = $parent->values()->make();
        $item = $catalog->save($record, $request->validated(), $catalog->actor($request), 'attribute-value');

        return $this->apiSuccess($request, new TaxonomyResource($item), 'Attribute value created.', 201);
    }

    public function update(SaveTaxonomyRequest $request, string $attribute, string $value, CatalogMaintenance $catalog): JsonResponse
    {
        $record = $this->query($attribute)->where('public_id', $value)->firstOrFail();
        $item = $catalog->save($record, $request->validated(), $catalog->actor($request), 'attribute-value');

        return $this->apiSuccess($request, new TaxonomyResource($item), 'Attribute value updated.');
    }

    public function status(Request $request, string $attribute, string $value, CatalogMaintenance $catalog): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(CatalogStatus::class)]]);
        $item = $catalog->apply($this->query($attribute), $value, $data['status'] === 'active' ? 'activate' : 'deactivate', $catalog->actor($request), 'attribute-value');

        return $this->apiSuccess($request, new TaxonomyResource($item), 'Attribute value status updated.');
    }

    public function destroy(Request $request, string $attribute, string $value, CatalogMaintenance $catalog): Response
    {
        $catalog->apply($this->query($attribute), $value, 'delete', $catalog->actor($request), 'attribute-value');

        return response()->noContent();
    }

    public function restore(Request $request, string $attribute, string $value, CatalogMaintenance $catalog): JsonResponse
    {
        $item = $catalog->apply($this->query($attribute), $value, 'restore', $catalog->actor($request), 'attribute-value');

        return $this->apiSuccess($request, new TaxonomyResource($item), 'Attribute value restored.');
    }

    public function reorder(Request $request, string $attribute, CatalogMaintenance $catalog): Response
    {
        $catalog->reorder($this->query($attribute), $request, $catalog->actor($request), 'attribute-value');

        return response()->noContent();
    }

    public function bulk(Request $request, string $attribute, CatalogMaintenance $catalog): JsonResponse
    {
        $result = $catalog->bulk($this->query($attribute), $request, $catalog->actor($request), 'attributes', 'attribute-value');

        return $this->apiSuccess($request, $result, 'Bulk action completed.');
    }

    /** @return Builder<AttributeValue> */
    private function query(string $attribute): Builder
    {
        $parent = ProductAttribute::query()->where('public_id', $attribute)->firstOrFail();

        return AttributeValue::query()->where('attribute_id', $parent->id);
    }
}
