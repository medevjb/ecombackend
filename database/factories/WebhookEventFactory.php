<?php

namespace Database\Factories;

use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebhookEvent> */
class WebhookEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_event_id' => 'evt_'.fake()->unique()->numerify('#########'),
            'type' => 'payment_intent.succeeded',
            'object_id' => 'pi_'.fake()->unique()->numerify('#########'),
        ];
    }
}
