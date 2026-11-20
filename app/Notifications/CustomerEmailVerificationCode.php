<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerEmailVerificationCode extends Notification
{
    public function __construct(public readonly string $code, public readonly string $purpose) {}

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
        $reset = $this->purpose === 'password_reset';

        return (new MailMessage)
            ->subject($reset ? 'Your SHOP.CO password reset code' : 'Your SHOP.CO verification code')
            ->greeting($reset ? 'Reset your password' : 'Verify your email address')
            ->line($reset ? 'Enter this one-time code to continue resetting your SHOP.CO password:' : 'Enter this one-time code to verify your SHOP.CO account:')
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
