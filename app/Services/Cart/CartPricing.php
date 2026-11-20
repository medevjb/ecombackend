<?php

namespace App\Services\Cart;

use App\Enums\ProductStatus;
use App\Exceptions\ApiProblem;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Services\Catalog\ProductDiscovery;
use App\Services\Promotions\CouponService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

class CartPricing
{
    private const int MAX_SAFE_AMOUNT = 9_007_199_254_740_991;

    public function __construct(private readonly ProductDiscovery $discovery, private readonly CouponService $coupons) {}

    /** @return Builder<ProductVariant> */
    public function eligibleVariants(): Builder
    {
        $visibleCategories = $this->discovery->visibleCategoryIds();

        return ProductVariant::query()->eligible()->with(['product.images' => fn ($images) => $images->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id'), 'inventory'])
            ->whereHas('product', fn (Builder $product) => $product->where('status', ProductStatus::Active)
                ->whereHas('categories', fn (Builder $category) => $category->whereIntegerInRaw('categories.id', $visibleCategories)));
    }

    /** @param list<int> $variantIds
     * @return Collection<int, ProductVariant>
     */
    public function offers(array $variantIds): Collection
    {
        return $this->eligibleVariants()->whereKey($variantIds)->get()->keyBy('id');
    }

    public function available(?ProductVariant $variant): int
    {
        return $variant?->inventory?->available() ?? 0;
    }

    /** @return array<string, mixed> */
    public function quote(Cart $cart, bool $includeCoupon = true): array
    {
        $items = $cart->items()->with('variant')->orderBy('id')->get();
        $offers = $this->offers(array_values($items->map(fn (CartItem $item): int => $item->product_variant_id)->all()));
        $subtotal = 0;
        $lines = [];
        $conflicts = [];

        foreach ($items as $item) {
            $offer = $offers->get($item->product_variant_id);
            $product = $offer?->product;
            $regular = $offer->price_override_amount ?? $product->regular_amount ?? null;
            $unit = $regular === null ? null : min($regular, $product->sale_amount ?? $regular);
            $available = $this->available($offer);
            $conflict = match (true) {
                $offer === null || $unit === null => 'VARIANT_UNAVAILABLE',
                $available < $item->quantity => 'INSUFFICIENT_STOCK',
                $unit > intdiv(self::MAX_SAFE_AMOUNT - $subtotal, $item->quantity) => 'CART_TOTAL_LIMIT',
                default => null,
            };
            $lineAmount = $conflict === null ? $unit * $item->quantity : null;
            $subtotal += $lineAmount ?? 0;
            if ($conflict !== null) {
                $conflicts[] = ['item_id' => $item->public_id, 'code' => $conflict];
            }
            $photo = $product?->images->first();
            $lines[] = [
                'id' => $item->public_id,
                'variant_id' => $item->variant->public_id,
                'sku' => $item->variant->sku,
                'product_id' => $product?->public_id,
                'name' => $product?->name,
                'slug' => $product?->slug,
                'image_url' => $photo === null ? null : Storage::disk($photo->disk)->url($photo->path),
                'quantity' => $item->quantity,
                'unit_amount' => $unit,
                'line_amount' => $lineAmount,
            ];
        }

        $quote = [
            'id' => $cart->public_id,
            'items' => $lines,
            'subtotal_amount' => $subtotal,
            'currency' => 'USD',
            'conflicts' => $conflicts,
        ];

        $coupon = $includeCoupon ? $cart->coupon : null;
        $quote['coupon'] = null;
        $quote['discount_amount'] = 0;

        if ($coupon !== null) {
            try {
                $amount = $this->coupons->estimate($coupon, $cart, $quote);
                $quote['coupon'] = ['code' => $coupon->code, 'status' => 'applied'];
                $quote['discount_amount'] = $amount;
            } catch (ApiProblem $problem) {
                $quote['coupon'] = ['code' => $coupon->code, 'status' => 'invalid', 'error_code' => $problem->errorCode];
            }
        }

        $quote['total_amount'] = $subtotal - $quote['discount_amount'];

        return $quote;
    }
}
