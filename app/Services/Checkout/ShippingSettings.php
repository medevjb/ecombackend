<?php

namespace App\Services\Checkout;

use HasinHayder\Tyro\Support\TyroAudit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ShippingSettings
{
    public const int DEFAULT_AMOUNT = 500;

    public const int MAX_AMOUNT = 99_999_999;

    public function amount(): int
    {
        return $this->effectiveAmount($this->setting()->value('amount'));
    }

    public function update(int $amount): void
    {
        DB::transaction(function () use ($amount): void {
            $previous = $this->effectiveAmount($this->setting()->lockForUpdate()->value('amount'));
            $this->setting()->update(['amount' => $amount]);

            if ($previous !== $amount) {
                TyroAudit::log('checkout.shipping.updated', null, ['flat_shipping_amount' => $previous], [
                    'flat_shipping_amount' => $amount,
                ], ['request_id' => request()->attributes->get('api_request_id')]);
            }
        }, 5);
    }

    private function effectiveAmount(mixed $override): int
    {
        $amount = filter_var($override ?? config('checkout.flat_shipping_amount'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => self::MAX_AMOUNT],
        ]);

        return $amount === false ? self::DEFAULT_AMOUNT : $amount;
    }

    private function setting(): Builder
    {
        return DB::table('checkout_settings')->where('key', 'flat_shipping_amount');
    }
}
