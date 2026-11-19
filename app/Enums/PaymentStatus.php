<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Exception = 'payment_exception';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
}
