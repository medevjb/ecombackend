<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Checkout\ShippingSettings;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingSettingsController extends Controller
{
    use ApiResponse;

    public function show(Request $request, ShippingSettings $shipping): JsonResponse
    {
        return $this->apiSuccess($request, ['flat_shipping_amount' => $shipping->amount(), 'currency' => 'USD'], 'Shipping settings retrieved.');
    }

    public function update(Request $request, ShippingSettings $shipping): JsonResponse
    {
        $data = $request->validate([
            'flat_shipping_amount' => ['required', 'integer', 'min:0', 'max:'.ShippingSettings::MAX_AMOUNT],
        ]);

        $amount = (int) $data['flat_shipping_amount'];
        $shipping->update($amount);

        return $this->apiSuccess($request, ['flat_shipping_amount' => $amount, 'currency' => 'USD'], 'Shipping settings updated.');
    }
}
