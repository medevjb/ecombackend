<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerEmailVerificationCode extends Notification
{
    public function __construct(public readonly string $code) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your SHOP.CO verification code')
            ->greeting('Verify your email address')
            ->line('Enter this one-time code to verify your SHOP.CO account:')
            ->line('**'.$this->code.'**')
            ->line('This code expires in 10 minutes and can only be used once.')
            ->line('If you did not create a SHOP.CO account, you can ignore this email.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
