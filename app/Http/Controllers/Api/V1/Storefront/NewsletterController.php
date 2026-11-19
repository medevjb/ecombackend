<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use App\Notifications\NewsletterSubscriptionNotice;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class NewsletterController extends Controller
{
    use ApiResponse;

    public function subscribe(Request $request): JsonResponse
    {
        $values = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'consent' => ['required', 'accepted'],
            'resubscribe_token' => ['sometimes', 'required', 'string', 'regex:/\A[A-Za-z0-9]{64}\z/'],
            'source' => ['sometimes', 'required', 'string', 'max:64', 'alpha_dash:ascii'],
        ]);
        $email = Str::lower($values['email']);
        $token = Str::random(64);
        $hash = hash('sha256', $token);
        DB::transaction(function () use ($email, $token, $hash, $values): void {
            $now = now();
            $attributes = ['source' => $values['source'] ?? 'storefront', 'unsubscribe_hash' => $hash, 'consented_at' => $now];
            $new = DB::table('newsletter_subscribers')->insertOrIgnore($attributes + [
                'email' => $email, 'public_id' => (string) Str::ulid(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($new === 0) {
                $subscriber = NewsletterSubscriber::query()->where('email', $email)->lockForUpdate()->firstOrFail();
                if ($subscriber->unsubscribed_at === null || ! hash_equals(
                    $subscriber->getAttribute('unsubscribe_hash'), hash('sha256', $values['resubscribe_token'] ?? ''),
                )) {
                    return;
                }
                $subscriber->forceFill($attributes + ['unsubscribed_at' => null])->save();
            }
            Notification::route('mail', $email)->notify(new NewsletterSubscriptionNotice($token));
        }, 5);

        return $this->apiSuccess($request, null, 'Subscription request received.', 202);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $values = $request->validate(['token' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{64}\z/']]);
        NewsletterSubscriber::query()->where('unsubscribe_hash', hash('sha256', $values['token']))
            ->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now()]);

        return $this->apiSuccess($request, null, 'Unsubscribe request received.');
    }
}
