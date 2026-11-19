<?php

namespace App\Jobs;

use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendPaymentExceptionNotice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $paymentId) {}

    public function uniqueId(): string
    {
        return (string) $this->paymentId;
    }

    public function handle(): void
    {
        $email = config('checkout.operations_email');
        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $payment = Payment::query()->with('order')->whereKey($this->paymentId)->firstOrFail();
        if ($payment->getAttribute('exception_notified_at') !== null) {
            return;
        }

        $number = $payment->order->number;
        $code = $payment->getAttribute('exception_code');
        Mail::raw('Order '.$number.' requires payment review ('.$code.').', function ($message) use ($email, $number): void {
            $message->to($email)->subject('Order payment review: '.$number);
        });
        $payment->forceFill(['exception_notified_at' => now()])->save();
    }
}
