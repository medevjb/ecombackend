<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\ProductAttribute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTaxonomyRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $routeIsValue = $this->routeIs('api.v1.admin.attribute-values.*');
        $brand = $this->routeIs('api.v1.admin.brands.*');
        $query = match (true) {
            $routeIsValue => AttributeValue::query(),
            $brand => Brand::query(),
            default => ProductAttribute::query(),
        };
        $parameter = $routeIsValue ? 'value' : ($brand ? 'brand' : 'attribute');
        $slug = Rule::unique($query->getModel()->getTable(), 'slug');
        if ($routeIsValue) {
            $parent = ProductAttribute::query()->where('public_id', $this->route('attribute'))->firstOrFail();
            $query->where('attribute_id', $parent->id);
            $slug->where('attribute_id', $parent->id);
        }
        if ($this->isMethod('PATCH')) {
            $slug->ignore($query->where('public_id', $this->route($parameter))->value('id'));
        }
        $prefix = $this->isMethod('POST') ? [] : ['sometimes'];
        $base = [
            'name' => [...$prefix, 'required', 'string', 'max:255'],
            'slug' => [...$prefix, 'required', 'string', 'max:255', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug],
        ];

        return $brand ? $base + [
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'logo_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
        ] : $base;
    }
}
