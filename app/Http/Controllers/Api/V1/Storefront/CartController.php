<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\User;
use App\Services\Cart\CartPricing;
use App\Services\Cart\CartService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CartService $carts, private readonly CartPricing $pricing) {}

    public function show(Request $request): JsonResponse
    {
        $cart = $this->carts->resolveRequest($request);

        return $this->snapshot($request, $cart['cart'], $cart['guest_token']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'ulid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CartService::MAX_ITEM_QUANTITY],
        ]);
        $cart = $this->carts->resolveRequest($request);
        $this->carts->add($cart['cart'], $data['variant_id'], (int) $data['quantity']);

        return $this->snapshot($request, $cart['cart'], $cart['guest_token']);
    }

    public function update(Request $request, string $item): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:'.CartService::MAX_ITEM_QUANTITY]]);
        $cart = $this->carts->resolveRequest($request);
        $this->carts->update($cart['cart'], $item, (int) $data['quantity']);

        return $this->snapshot($request, $cart['cart'], $cart['guest_token']);
    }

    public function destroy(Request $request, string $item): Response
    {
        return $this->remove($request, $item);
    }

    public function clear(Request $request): Response
    {
        return $this->remove($request, null);
    }

    public function merge(Request $request): JsonResponse
    {
        $token = $request->header('X-Cart-Token');
        if ($token === null) {
            throw ValidationException::withMessages(['guest_token' => ['An X-Cart-Token header is required.']]);
        }

        $customer = $request->user();
        abort_unless($customer instanceof User, 401);
        $result = $this->carts->merge($customer, $token);

        return $this->apiSuccess($request, [
            'cart' => $this->pricing->quote($result['cart']),
            'merge_conflicts' => $result['conflicts'],
        ], 'Cart merged.')->header('Cache-Control', 'no-store');
    }

    private function snapshot(Request $request, Cart $cart, ?string $guestToken): JsonResponse
    {
        $response = $this->apiSuccess($request, $this->pricing->quote($cart), 'Cart retrieved.')->header('Cache-Control', 'no-store');

        if ($guestToken !== null) {
            $response->header('X-Cart-Token', $guestToken);
        }

        return $response;
    }

    private function remove(Request $request, ?string $item): Response
    {
        $cart = $this->carts->resolveRequest($request);
        $this->carts->remove($cart['cart'], $item);

        $response = response()->noContent()->header('Cache-Control', 'no-store');
        if ($cart['guest_token'] !== null) {
            $response->header('X-Cart-Token', $cart['guest_token']);
        }

        return $response;
    }
}
