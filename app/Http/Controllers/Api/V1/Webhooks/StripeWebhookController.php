<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessStripeWebhook;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function store(Request $request): Response
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if (! str_starts_with($secret, 'whsec_')) {
            throw new ApiProblem('STRIPE_WEBHOOK_NOT_CONFIGURED', 'Stripe webhooks are not configured.', 503);
        }

        $body = $request->getContent();
        if (strlen($body) > 1_048_576) {
            throw new ApiProblem('WEBHOOK_TOO_LARGE', 'The webhook payload is too large.', 413);
        }

        try {
            $event = Webhook::constructEvent($body, (string) $request->header('Stripe-Signature'), $secret);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            throw new ApiProblem('STRIPE_SIGNATURE_INVALID', 'The Stripe signature is invalid.', 400);
        }

        if ($event->livemode !== false) {
            throw new ApiProblem('STRIPE_LIVE_EVENT_REJECTED', 'Only Stripe Test Mode events are accepted.', 400);
        }

        $allowed = ['payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.canceled',
            'refund.created', 'refund.updated', 'refund.failed'];
        if (! in_array($event->type, $allowed, true)) {
            return response()->noContent();
        }
        $objectId = $event->data->object->id ?? null;
        $eventId = $event->toArray()['id'] ?? null;
        if (! is_string($objectId) || $objectId === '' || ! is_string($eventId) || $eventId === '' || strlen($objectId) > 255 || strlen($eventId) > 255) {
            throw new ApiProblem('WEBHOOK_INVALID', 'The webhook does not contain a valid object reference.', 400);
        }

        $receipt = WebhookEvent::query()->firstOrCreate(
            ['provider_event_id' => $eventId],
            ['type' => $event->type, 'object_id' => $objectId],
        );
        if ($receipt->processed_at === null) {
            ProcessStripeWebhook::dispatch($receipt->id)->afterCommit();
        }

        return response()->noContent();
    }
}
