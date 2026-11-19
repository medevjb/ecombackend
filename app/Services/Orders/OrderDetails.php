<?php

namespace App\Services\Orders;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderDetails
{
    public function hydrate(Order $order): Order
    {
        $order->loadMissing('payment');
        $address = DB::table('order_addresses')->where('order_id', $order->id)->first([
            'recipient', 'line1', 'line2', 'city', 'region', 'postal_code', 'country', 'phone',
        ]);
        $items = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')
            ->get(['product_public_id AS product_id', 'variant_public_id AS variant_id', 'product_name', 'sku', 'options',
                'quantity', 'unit_amount', 'line_amount'])
            ->map(function ($line): object {
                $line->options = json_decode($line->options, true, flags: JSON_THROW_ON_ERROR);

                return $line;
            });
        $history = DB::table('order_status_histories')->where('order_id', $order->id)->orderBy('id')
            ->get(['from_status', 'to_status', 'created_at']);

        return $order->setRelation('snapshotAddress', $address)
            ->setRelation('snapshotItems', $items)->setRelation('snapshotHistory', $history);
    }
}
