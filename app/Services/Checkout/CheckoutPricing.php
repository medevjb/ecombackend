<?php

namespace App\Services\Checkout;

use App\Exceptions\ApiProblem;
use App\Models\Cart;
use App\Models\CustomerAddress;
use App\Services\Cart\CartPricing;

class CheckoutPricing
{
    private const int SAFE_MINOR_UNITS = 9_007_199_254_740_991;

    public function __construct(private readonly CartPricing $pricing, private readonly ShippingSettings $shipping) {}

    /** @return array<string, mixed> */
    public function quote(Cart $cart, CustomerAddress $address): array
    {
        $cartQuote = $this->pricing->quote($cart);
        $shipping = $this->shipping->amount();
        $basisPoints = config()->integer('checkout.tax_basis_points');
        if ($basisPoints < 0 || $basisPoints > 10_000) {
            throw new ApiProblem('CHECKOUT_CONFIGURATION_INVALID', 'Checkout tax is not configured correctly.', 503);
        }

        $conflicts = $cartQuote['conflicts'];
        if ($cartQuote['items'] === []) {
            $conflicts[] = ['code' => 'EMPTY_CART'];
        }
        if (($cartQuote['coupon']['status'] ?? null) === 'invalid') {
            $conflicts[] = ['code' => $cartQuote['coupon']['error_code']];
        }
        $taxable = $cartQuote['total_amount'];
        $tax = intdiv($taxable, 10_000) * $basisPoints + intdiv(($taxable % 10_000) * $basisPoints, 10_000);
        $total = $taxable + $shipping + $tax;
        if ($total > self::SAFE_MINOR_UNITS) {
            $total = null;
            $conflicts[] = ['code' => 'CHECKOUT_TOTAL_LIMIT'];
        }

        return [
            'currency' => 'USD',
            'subtotal_amount' => $cartQuote['subtotal_amount'],
            'discount_amount' => $cartQuote['discount_amount'],
            'shipping_amount' => $shipping,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'coupon' => $cartQuote['coupon'],
            'items' => $cartQuote['items'],
            'address' => ['id' => $address->getKey() === null ? null : $address->public_id] + $address->only([
                'recipient', 'line1', 'line2', 'city', 'region', 'postal_code', 'country', 'phone',
            ]),
            'conflicts' => $conflicts,
            'can_checkout' => $conflicts === [],
        ];
    }
}
