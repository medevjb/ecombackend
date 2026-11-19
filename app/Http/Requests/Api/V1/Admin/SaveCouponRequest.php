<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaveCouponRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Str::upper($this->input('code'))]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $prefix = $this->isMethod('POST') ? [] : ['sometimes'];
        $id = Coupon::query()->where('public_id', $this->route('coupon'))->value('id');

        return [
            'code' => [...$prefix, 'required', 'string', 'max:40', 'regex:/\A[A-Z0-9][A-Z0-9_-]*\z/', Rule::unique('coupons', 'code')->ignore($id)],
            'type' => [...$prefix, 'required', Rule::enum(CouponType::class)],
            'value' => [...$prefix, 'required', 'numeric', 'min:0.01', 'max:9007199254740991'],
            'currency' => ['sometimes', 'nullable', Rule::in(['USD'])],
            'minimum_amount' => ['sometimes', 'required', 'integer', 'min:0', 'max:9007199254740991'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'global_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647'],
            'per_customer_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647'],
            'product_ids' => ['sometimes', 'array', 'list', 'max:100'],
            'product_ids.*' => ['required', 'ulid', 'distinct:ignore_case'],
            'category_ids' => ['sometimes', 'array', 'list', 'max:100'],
            'category_ids.*' => ['required', 'ulid', 'distinct:ignore_case'],
        ];
    }
}
