<?php

namespace App\Http\Requests\Api\V1\Customer;

use Illuminate\Foundation\Http\FormRequest;

class SaveAddressRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper($this->input('country'))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return self::shippingRules(partial: ! $this->isMethod('POST')) + ['is_default' => ['sometimes', 'required', 'boolean']];
    }

    /** @return array<string, list<string>> */
    public static function shippingRules(bool $partial = false): array
    {
        $prefix = $partial ? ['sometimes'] : [];

        return [
            'recipient' => [...$prefix, 'required', 'string', 'max:150'],
            'line1' => [...$prefix, 'required', 'string', 'max:200'],
            'line2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'city' => [...$prefix, 'required', 'string', 'max:100'],
            'region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => [...$prefix, 'required', 'string', 'max:32'],
            'country' => [...$prefix, 'required', 'string', 'regex:/\A[A-Z]{2}\z/'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:35', 'regex:/\A\+?[0-9][0-9 ().-]+\z/'],
        ];
    }
}
