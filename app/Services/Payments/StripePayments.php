<?php

namespace App\Services\Payments;

use App\Exceptions\ApiProblem;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;

class StripePayments
{
    public function ensureConfigured(): void
    {
        $secret = (string) config('services.stripe.secret');

        if (! str_starts_with($secret, 'sk_test_') && ! str_starts_with($secret, 'rk_test_')) {
            throw new ApiProblem('STRIPE_TEST_MODE_NOT_CONFIGURED', 'Stripe Test Mode is not configured.', 503);
        }
    }

    public function createIntent(string $checkoutId, int $amount, string $currency): PaymentIntent
    {
        try {
            return $this->client()->paymentIntents->create([
                'amount' => $amount,
                'currency' => strtolower($currency),
                'payment_method_types' => ['card'],
                'metadata' => ['checkout_id' => $checkoutId],
            ], ['idempotency_key' => 'checkout:'.$checkoutId]);
        } catch (ApiErrorException) {
            throw $this->unavailable();
        }
    }

    public function getIntent(string $identity): PaymentIntent
    {
        try {
            return $this->client()->paymentIntents->retrieve($identity);
        } catch (ApiErrorException) {
            throw $this->unavailable();
        }
    }

    public function cancelIntent(string $intentId): PaymentIntent
    {
        try {
            return $this->client()->paymentIntents->cancel($intentId, [], ['idempotency_key' => 'cancel:'.$intentId]);
        } catch (ApiErrorException) {
            throw $this->unavailable();
        }
    }

    public function refund(string $intentId, string $refundId, int $amount): Refund
    {
        try {
            return $this->client()->refunds->create([
                'payment_intent' => $intentId,
                'amount' => $amount,
                'metadata' => ['refund_id' => $refundId],
            ], ['idempotency_key' => 'refund:'.$refundId]);
        } catch (ApiErrorException) {
            throw $this->unavailable();
        }
    }

    public function getRefund(string $refundId): Refund
    {
        try {
            return $this->client()->refunds->retrieve($refundId);
        } catch (ApiErrorException) {
            throw $this->unavailable();
        }
    }

    private function client(): StripeClient
    {
        $this->ensureConfigured();

        return new StripeClient(['api_key' => (string) config('services.stripe.secret'), 'max_network_retries' => 2]);
    }

    private function unavailable(): ApiProblem
    {
        return new ApiProblem('STRIPE_TEMPORARILY_UNAVAILABLE', 'The payment provider is temporarily unavailable.', 503);
    }
}
