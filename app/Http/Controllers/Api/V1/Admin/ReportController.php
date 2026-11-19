<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\User;
use App\Services\Catalog\CatalogMaintenance;
use App\Services\Orders\SalesReporting;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    use ApiResponse;

    public function dashboard(Request $request, SalesReporting $reporting): JsonResponse
    {
        $today = Carbon::today('UTC');
        $summary = Cache::remember('admin.dashboard.sales.'.$today->toDateString(), 60, fn () => $reporting->summarize(
            $today->copy()->subDays(29), $today->copy()->addDay(),
        ));

        return $this->apiSuccess($request, [
            'timezone' => 'UTC', 'currency' => 'USD', 'today' => $summary['daily'][29],
            'customer_count' => User::query()->customers()->count(),
            'low_stock_count' => Inventory::query()->whereHas('variant')
                ->whereRaw('on_hand - reserved <= ?', [config()->integer('inventory.low_stock_threshold')])->count(),
            'sales_series' => $summary['daily'],
            'recent_orders' => OrderResource::collection(Order::query()->with('payment')->latest('created_at')->latest('id')->limit(10)->get()),
        ], 'Dashboard retrieved.');
    }

    public function sales(Request $request, SalesReporting $reporting, CatalogMaintenance $pagination): JsonResponse
    {
        [$from, $until] = $this->dateRange($request);
        $data = $request->validate($this->pageRules() + ['group_by' => ['sometimes', Rule::in(['day', 'product', 'category'])]]);
        $group = $data['group_by'] ?? 'day';
        if ($group === 'day') {
            return $this->apiSuccess($request, $reporting->summarize($from, $until), 'Sales retrieved.');
        }

        $query = DB::table('orders')->join('order_items AS lines', 'lines.order_id', '=', 'orders.id')
            ->where('orders.paid_at', '>=', $from)->where('orders.paid_at', '<', $until);
        if ($group === 'product') {
            $query->join('products AS subject', 'subject.id', '=', 'lines.product_id');
        } else {
            $query->join('category_product AS membership', 'membership.product_id', '=', 'lines.product_id')
                ->join('categories AS subject', 'subject.id', '=', 'membership.category_id');
        }
        $totals = $query->select(['subject.public_id AS id', 'subject.name'])
            ->selectRaw('SUM(lines.quantity) AS units, SUM(lines.line_amount) AS gross_merchandise_amount')
            ->groupBy('subject.public_id', 'subject.name');
        $items = DB::query()->fromSub($totals, 'sales')->orderByDesc('gross_merchandise_amount')->orderBy('id')
            ->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));
        $items->through(fn ($row): array => [
            'id' => $row->id, 'name' => $row->name, 'units' => (int) $row->units,
            'gross_merchandise_amount' => (int) $row->gross_merchandise_amount,
        ]);

        return $this->apiSuccess($request, [
            'group_by' => $group, 'timezone' => 'UTC', 'currency' => 'USD', 'items' => $items->items(),
        ], 'Merchandise sales retrieved.', meta: $pagination->meta($items));
    }

    public function inventory(Request $request, InventoryController $inventory): JsonResponse
    {
        $request->merge(['low_stock' => true]);

        return $inventory->index($request);
    }

    public function coupons(Request $request, CatalogMaintenance $pagination): JsonResponse
    {
        [$from, $until] = $this->dateRange($request);
        $request->validate($this->pageRules());
        $totals = DB::table('coupon_redemptions AS uses')->join('coupons', 'coupons.id', '=', 'uses.coupon_id')
            ->where('uses.redeemed_at', '>=', $from)->where('uses.redeemed_at', '<', $until)
            ->select(['coupons.public_id AS id', 'coupons.code'])
            ->selectRaw('COUNT(*) AS redemptions, SUM(uses.discount_amount) AS discount_amount')
            ->groupBy('coupons.public_id', 'coupons.code');
        $items = DB::query()->fromSub($totals, 'usage')->orderByDesc('redemptions')->orderBy('id')
            ->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));
        $items->through(fn ($row): array => [
            'id' => $row->id, 'code' => $row->code, 'redemptions' => (int) $row->redemptions,
            'discount_amount' => (int) $row->discount_amount,
        ]);

        return $this->apiSuccess($request, $items->items(), 'Coupon usage retrieved.', meta: $pagination->meta($items));
    }

    /** @return array{Carbon, Carbon} */
    private function dateRange(Request $request): array
    {
        $data = $request->validate([
            'from' => ['required_with:to', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $today = Carbon::today('UTC');
        $from = isset($data['from']) ? Carbon::parse($data['from'], 'UTC') : $today->copy()->subDays(29);
        $to = isset($data['to']) ? Carbon::parse($data['to'], 'UTC') : $today;
        if ($from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['to' => 'The report range may contain at most 366 days.']);
        }

        return [$from, $to->addDay()];
    }

    /** @return array<string, list<string>> */
    private function pageRules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
