<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewsletterSubscriptionNotice extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Newsletter subscription')
            ->line('Your newsletter subscription is active.')
            ->action('Unsubscribe', rtrim(config()->string('auth.storefront_url'), '/').'/newsletter/unsubscribe#'.$this->token)
            ->line('You can also enter this unsubscribe code on the storefront.')
            ->line($this->token);
    }
}
