<?php

namespace App\Enums;

enum CouponUsageStatus: string
{
    case Reserved = 'reserved';
    case Redeemed = 'redeemed';
    case Released = 'released';
}
