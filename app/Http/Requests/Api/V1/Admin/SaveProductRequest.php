<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => Str::upper($this->input('sku'))]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? [] : ['sometimes'];
        $id = Product::query()->where('public_id', $this->route('product'))->value('id');

        return [
            'name' => [...$required, 'required', 'string', 'max:255'],
            'slug' => [...$required, 'required', 'string', 'max:255', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', Rule::unique('products', 'slug')->ignore($id)],
            'sku' => $this->isMethod('POST') ? ['required', 'string', 'max:100', 'regex:/\A[A-Z0-9][A-Z0-9._-]*\z/', 'unique:product_variants,sku'] : ['prohibited'],
            'regular_amount' => [...$required, 'required', 'integer', 'min:0', 'max:9007199254740991'],
            'sale_amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9007199254740991'],
            'category_ids' => ['sometimes', 'array', 'list', 'max:100'],
            'category_ids.*' => ['required', 'ulid', 'distinct:ignore_case'],
            'brand_id' => ['sometimes', 'nullable', 'ulid'],
            'featured' => ['sometimes', 'required', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:30000'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
