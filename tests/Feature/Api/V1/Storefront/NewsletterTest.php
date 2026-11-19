<?php

use App\Models\NewsletterSubscriber;
use App\Notifications\NewsletterSubscriptionNotice;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

it('deduplicates consent and preserves opt outs until the subscriber proves ownership', function () {
    Notification::fake();
    $body = ['email' => 'Demo@Example.com', 'consent' => true, 'source' => 'footer'];
    $this->postJson('/api/v1/newsletter/subscribe', $body)->assertAccepted()->assertJsonPath('data', null);
    $this->postJson('/api/v1/newsletter/subscribe', ['email' => 'demo@example.com'] + $body)->assertAccepted();
    $subscriber = NewsletterSubscriber::query()->sole();
    expect($subscriber->email)->toBe('demo@example.com');
    Notification::assertSentOnDemandTimes(NewsletterSubscriptionNotice::class, 1);
    $notice = Notification::sent(new AnonymousNotifiable, NewsletterSubscriptionNotice::class)->sole();
    expect(hash('sha256', $notice->token))->toBe($subscriber->getAttribute('unsubscribe_hash'));

    $this->postJson('/api/v1/newsletter/unsubscribe', ['token' => $notice->token])->assertOk();
    $this->postJson('/api/v1/newsletter/subscribe', $body)->assertAccepted();
    expect($subscriber->refresh()->unsubscribed_at)->not->toBeNull();
    $this->postJson('/api/v1/newsletter/subscribe', $body + ['resubscribe_token' => $notice->token], ['REMOTE_ADDR' => '127.0.0.2'])->assertAccepted();
    expect($subscriber->refresh()->unsubscribed_at)->toBeNull();
});
