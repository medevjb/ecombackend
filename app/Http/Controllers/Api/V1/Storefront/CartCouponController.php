<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Services\Cart\CartPricing;
use App\Services\Cart\CartService;
use App\Services\Promotions\CouponService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CartCouponController extends Controller
{
    use ApiResponse;

    public function store(Request $request, CartService $carts, CartPricing $pricing, CouponService $coupons): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/']]);
        $coupon = Coupon::query()->where('code', Str::upper($data['code']))->first();
        if ($coupon === null) {
            throw new ApiProblem('COUPON_UNAVAILABLE', 'The coupon is unavailable.', 422);
        }

        $cart = $carts->resolveRequest($request);
        $locked = $carts->setCoupon($cart['cart'], $coupon, $coupons);

        return $this->response($request, $pricing->quote($locked), $cart['guest_token'], 'Coupon applied provisionally.');
    }

    public function destroy(Request $request, CartService $carts, CartPricing $pricing, CouponService $coupons): JsonResponse
    {
        $cart = $carts->resolveRequest($request);
        $locked = $carts->setCoupon($cart['cart'], null, $coupons);

        return $this->response($request, $pricing->quote($locked), $cart['guest_token'], 'Coupon removed.');
    }

    /** @param array<string, mixed> $data */
    private function response(Request $request, array $data, ?string $token, string $message): JsonResponse
    {
        $response = $this->apiSuccess($request, $data, $message)->header('Cache-Control', 'no-store');
        if ($token !== null) {
            $response->header('X-Cart-Token', $token);
        }

        return $response;
    }
}
