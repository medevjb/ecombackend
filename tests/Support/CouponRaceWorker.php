<?php

namespace Tests\Support;

use App\Exceptions\ApiProblem;
use App\Models\Cart;
use App\Models\Coupon;
use App\Services\Cart\CartPricing;
use App\Services\Promotions\CouponService;
use Closure;
use Illuminate\Support\Str;
use RuntimeException;

class CouponRaceWorker
{
    public static function make(string $path, string $slot, int $couponId, int $cartId): Closure
    {
        return static function () use ($path, $slot, $couponId, $cartId): string {
            $coupon = Coupon::query()->whereKey($couponId)->firstOrFail();
            $cart = Cart::query()->whereKey($cartId)->firstOrFail();
            $quote = app(CartPricing::class)->quote($cart);
            touch($path.'/'.$slot);
            $deadline = microtime(true) + 5;
            while (! is_file($path.'/one') || ! is_file($path.'/two')) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('The other coupon worker did not reach the barrier.');
                }
                usleep(10_000);
                clearstatcache();
            }

            try {
                app(CouponService::class)->reserve($coupon, $cart, $quote, (string) Str::ulid(), now()->addMinutes(10));

                return 'held';
            } catch (ApiProblem $problem) {
                return $problem->errorCode;
            }
        };
    }
}
