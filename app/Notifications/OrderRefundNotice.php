<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderRefundNotice extends Notification
{
    public function __construct(public readonly string $number, public readonly string $status) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Refund update for '.$this->number)
            ->line('The refund for order '.$this->number.' is '.$this->status.'.');
    }
}
