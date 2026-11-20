<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customer\CheckoutRequest;
use App\Models\User;
use App\Services\Auth\AddressBook;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutAttempts;
use App\Services\Checkout\CheckoutPricing;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    use ApiResponse;

    public function preview(CheckoutRequest $request, CartService $carts, AddressBook $addresses, CheckoutPricing $pricing): JsonResponse
    {
        $customer = $request->user();
        abort_unless($customer instanceof User, 401);
        $address = $addresses->forCustomer($customer)->where('public_id', $request->validated('address_id'))->firstOrFail();
        $quote = $pricing->quote($carts->forCustomer($customer), $address);

        return $this->apiSuccess($request, $quote, 'Checkout preview calculated.')->header('Cache-Control', 'no-store');
    }

    public function store(CheckoutRequest $request, CheckoutAttempts $attempts): JsonResponse
    {
        $customer = $request->user();
        abort_unless($customer instanceof User, 401);

        $key = $request->validated('idempotency_key');
        $checkout = $attempts->start($customer, $request->validated('address_id'), $key);

        return $this->apiSuccess($request, $checkout, 'Checkout attempt prepared.')->header('Cache-Control', 'no-store');
    }

    public function resume(Request $request, string $order, CheckoutAttempts $attempts): JsonResponse
    {
        $customer = $request->user();
        abort_unless($customer instanceof User, 401);
        $checkout = $attempts->resume($customer, $order);

        return $this->apiSuccess($request, $checkout, 'Checkout attempt resumed.')->header('Cache-Control', 'no-store');
    }
}
