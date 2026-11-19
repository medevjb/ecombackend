<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class SaveProductVariantRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => Str::upper($this->input('sku'))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $prefix = $this->isMethod('POST') ? [] : ['sometimes'];

        return [
            'sku' => [...$prefix, 'required', 'string', 'max:100', 'regex:/\A[A-Z0-9][A-Z0-9._-]*\z/'],
            'price_override_amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9007199254740991'],
            'option_ids' => [...$prefix, 'required', 'array', 'list', 'min:1', 'max:20'],
            'option_ids.*' => ['required', 'ulid', 'distinct:ignore_case'],
        ];
    }
}
