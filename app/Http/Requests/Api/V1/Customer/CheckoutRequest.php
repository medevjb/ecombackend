<?php

namespace App\Http\Requests\Api\V1\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->routeIs('api.v1.customer.checkout.store')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => $this->routeIs('api.v1.customer.checkout.store')
                ? ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/']
                : ['prohibited'],
            'address_id' => ['required', 'ulid'],
            'subtotal_amount' => ['prohibited'],
            'discount_amount' => ['prohibited'],
            'shipping_amount' => ['prohibited'],
            'tax_amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'order_status' => ['prohibited'],
            'payment_status' => ['prohibited'],
        ];
    }
}
