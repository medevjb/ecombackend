<?php

namespace App\Http\Requests\Api\V1\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $address = $this->input('address');
        if (is_array($address) && is_string($address['country'] ?? null)) {
            $address['country'] = strtoupper($address['country']);
            $this->merge(['address' => $address]);
        }

        if ($this->routeIs('api.v1.customer.checkout.store')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $shippingRules = SaveAddressRequest::shippingRules();
        $rules = [
            'idempotency_key' => $this->routeIs('api.v1.customer.checkout.store')
                ? ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/']
                : ['prohibited'],
            'address_id' => ['required_without:address', 'prohibits:address', 'ulid'],
            'address' => ['required_without:address_id', 'prohibits:address_id', 'array:'.implode(',', array_keys($shippingRules))],
            'subtotal_amount' => ['prohibited'],
            'discount_amount' => ['prohibited'],
            'shipping_amount' => ['prohibited'],
            'tax_amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'order_status' => ['prohibited'],
            'payment_status' => ['prohibited'],
        ];

        if ($this->has('address')) {
            foreach ($shippingRules as $field => $rule) {
                $rules['address.'.$field] = $rule;
            }
        }

        return $rules;
    }

    /** @return string|array<string, mixed> */
    public function shippingAddress(): string|array
    {
        return $this->validated('address') ?? $this->validated('address_id');
    }
}
