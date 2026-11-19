<?php

namespace App\Services\Catalog;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReviewStatus;
use App\Exceptions\ApiProblem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use HasinHayder\Tyro\Support\TyroAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ProductReviews
{
    /** @param array<string, mixed> $content */
    public function submit(Product $product, User $user, array $content): ProductReview
    {
        try {
            return DB::transaction(function () use ($product, $user, $content): ProductReview {
                $verified = $this->verifiedPurchase($product->id, $user->id);
                $review = ProductReview::withTrashed()->where('product_id', $product->id)->where('user_id', $user->id)
                    ->lockForUpdate()->first();
                if ($review !== null && ! $review->trashed()) {
                    throw new ApiProblem('REVIEW_EXISTS', 'You already have a review for this product.');
                }
                $review ??= new ProductReview(['product_id' => $product->id, 'user_id' => $user->id]);
                $review->fill($content + ['title' => null, 'body' => null]);
                $review->forceFill(['status' => ReviewStatus::Pending, 'verified_purchase' => $verified, 'deleted_at' => null])->save();

                return $review;
            }, 5);
        } catch (UniqueConstraintViolationException) {
            throw new ApiProblem('REVIEW_EXISTS', 'You already have a review for this product.');
        }
    }

    /** @param array<string, mixed> $content */
    public function edit(string $id, User $user, array $content): ProductReview
    {
        return DB::transaction(function () use ($id, $user, $content): ProductReview {
            $review = $this->owned($id, $user);
            $review->fill($content);
            if ($review->isDirty()) {
                $rating = $review->getRawOriginal('rating');
                $this->removeRating($review, (int) $rating);
                $review->forceFill([
                    'status' => ReviewStatus::Pending, 'verified_purchase' => $this->verifiedPurchase($review->product_id, $user->id),
                ])->save();
            }

            return $review;
        }, 5);
    }

    public function delete(string $id, User $user): void
    {
        DB::transaction(function () use ($id, $user): void {
            $review = $this->owned($id, $user);
            $this->removeRating($review, $review->rating);
            $review->delete();
        }, 5);
    }

    /** @param list<string> $ids */
    public function moderate(array $ids, ReviewStatus $status, string $reason): int
    {
        return DB::transaction(function () use ($ids, $status, $reason): int {
            $reviews = ProductReview::query()->whereIn('public_id', $ids)
                ->orderBy('product_id')->orderBy('id')->lockForUpdate()->get();
            if ($reviews->count() !== count($ids)) {
                throw new ApiProblem('REVIEW_NOT_FOUND', 'One or more reviews could not be found.', 404);
            }
            $changed = 0;
            foreach ($reviews as $review) {
                $before = $review->status;
                if ($before === $status) {
                    continue;
                }
                $this->removeRating($review, $review->rating);
                if ($status === ReviewStatus::Approved) {
                    $this->adjustMetrics($review->product_id, 1, $review->rating);
                }
                $review->forceFill(['status' => $status])->save();
                TyroAudit::log('review.moderated', $review, ['status' => $before->value], ['status' => $status->value], ['reason' => $reason]);
                $changed++;
            }

            return $changed;
        }, 5);
    }

    private function owned(string $id, User $user): ProductReview
    {
        return ProductReview::query()->where('public_id', $id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
    }

    private function verifiedPurchase(int $productId, int $userId): bool
    {
        $verified = DB::table('orders')->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('payments', 'payments.order_id', '=', 'orders.id')->where('orders.user_id', $userId)
            ->where('order_items.product_id', $productId)->whereNotNull('orders.paid_at')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereIn('payments.status', [PaymentStatus::Succeeded->value, PaymentStatus::PartiallyRefunded->value])->exists();

        if (! $verified && config()->boolean('reviews.require_verified_purchase')) {
            throw new ApiProblem('REVIEW_PURCHASE_REQUIRED', 'A verified purchase is required to review this product.', 403);
        }

        return $verified;
    }

    private function removeRating(ProductReview $review, int $rating): void
    {
        if ($review->status === ReviewStatus::Approved) {
            $this->adjustMetrics($review->product_id, -1, -$rating);
        }
    }

    private function adjustMetrics(int $productId, int $count, int $total): void
    {
        DB::table('product_metrics')->insertOrIgnore(['product_id' => $productId]);
        DB::table('product_metrics')->where('product_id', $productId)->incrementEach([
            'approved_rating_count' => $count, 'approved_rating_total' => $total,
        ]);
    }
}
