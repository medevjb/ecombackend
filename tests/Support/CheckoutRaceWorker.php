<?php

namespace Tests\Support;

use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Services\Checkout\CheckoutAttempts;
use App\Services\Payments\StripePayments;
use Closure;
use RuntimeException;
use Stripe\PaymentIntent;

class CheckoutRaceWorker
{
    public static function make(string $path, string $slot, int $customerId, string $addressId): Closure
    {
        return static function () use ($path, $slot, $customerId, $addressId): string {
            config(['checkout.flat_shipping_amount' => 300]);
            app()->instance(StripePayments::class, new class extends StripePayments
            {
                public function ensureConfigured(): void {}

                public function createIntent(string $checkoutId, int $amount, string $currency): PaymentIntent
                {
                    return PaymentIntent::constructFrom([
                        'id' => 'pi_fixture_'.$checkoutId,
                        'amount' => $amount,
                        'currency' => strtolower($currency),
                        'metadata' => ['checkout_id' => $checkoutId],
                        'livemode' => false,
                        'client_secret' => 'fixture_only',
                    ]);
                }
            });
            $buyer = User::query()->whereKey($customerId)->firstOrFail();
            touch($path.'/'.$slot);
            $deadline = microtime(true) + 5;
            while (! is_file($path.'/one') || ! is_file($path.'/two')) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('The other checkout process did not reach the race barrier.');
                }
                usleep(10_000);
                clearstatcache();
            }

            try {
                app(CheckoutAttempts::class)->start($buyer, $addressId, 'last-unit');

                return 'pending_order';
            } catch (ApiProblem $exception) {
                return $exception->errorCode;
            }
        };
    }
}
