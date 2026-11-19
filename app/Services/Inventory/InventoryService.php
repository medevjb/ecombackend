<?php

namespace App\Services\Inventory;

use App\Enums\ProductStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\ApiProblem;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\StaffUser;
use App\Models\StockReservation;
use Carbon\CarbonInterface;
use HasinHayder\Tyro\Support\TyroAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryService
{
    private const int MAX_STOCK = 4_294_967_295;

    public function adjust(string $variantId, int $delta, string $reason, StaffUser $actor, string $idempotencyKey): InventoryMovement
    {
        $event = hash('sha256', 'adjustment:'.$actor->id.':'.$idempotencyKey);

        try {
            return DB::transaction(function () use ($variantId, $delta, $reason, $actor, $event): InventoryMovement {
                $inventory = Inventory::query()->whereHas('variant', fn (Builder $variant) => $variant->where('public_id', $variantId))
                    ->lockForUpdate()->firstOrFail();
                $previous = InventoryMovement::query()->where('event_key', $event)->lockForUpdate()->first();
                if ($previous !== null) {
                    return $this->validateRetry($previous, $inventory->id, $delta, $reason);
                }

                $movement = $this->move($inventory, $delta, 0, 'adjustment', $reason, $event, $actor->id);
                TyroAudit::log('inventory.adjusted', $inventory, null, ['delta' => $delta, 'movement_id' => $movement->public_id], [
                    'request_id' => request()->attributes->get('api_request_id'),
                ]);

                return $movement;
            }, 5);
        } catch (UniqueConstraintViolationException $exception) {
            $movement = InventoryMovement::query()->where('event_key', $event)->first();
            $inventory = Inventory::query()->whereHas('variant', fn (Builder $variant) => $variant->where('public_id', $variantId))->first();

            if ($movement === null || $inventory === null) {
                throw $exception;
            }

            return $this->validateRetry($movement, $inventory->id, $delta, $reason);
        }
    }

    /** @param array<int, int> $variantQuantities */
    public function reserve(string $id, array $variantQuantities, CarbonInterface $expiresAt): StockReservation
    {
        if (! Str::isUlid($id) || $variantQuantities === []
            || collect($variantQuantities)->contains(fn (int $quantity, int $variant): bool => $variant <= 0 || $quantity < 1 || $quantity > self::MAX_STOCK)) {
            throw new ApiProblem('INVALID_RESERVATION', 'A reservation requires a valid ID and positive stock quantities.', 422);
        }
        $id = strtolower($id);
        ksort($variantQuantities);
        $fingerprint = hash('sha256', json_encode([$variantQuantities, $expiresAt->getTimestamp()], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($id, $variantQuantities, $expiresAt, $fingerprint): StockReservation {
                $previous = StockReservation::query()->where('public_id', $id)->lockForUpdate()->first();
                if ($previous !== null) {
                    return $this->matchingReservation($previous, $fingerprint);
                }
                if ($expiresAt->lte(now())) {
                    throw new ApiProblem('RESERVATION_EXPIRED', 'The reservation expiry must be in the future.', 422);
                }

                $reservation = new StockReservation;
                $reservation->forceFill([
                    'public_id' => $id, 'fingerprint' => $fingerprint, 'expires_at' => $expiresAt, 'status' => ReservationStatus::Active,
                ])->save();
                $inventories = Inventory::query()->whereIn('product_variant_id', array_keys($variantQuantities))
                    ->whereHas('variant', fn (Builder $variant) => $variant->eligible()->whereHas('product', fn (Builder $product) => $product->where('status', ProductStatus::Active)))
                    ->orderBy('product_variant_id')->lockForUpdate()->get();

                if ($inventories->count() !== count($variantQuantities)) {
                    throw new ApiProblem('VARIANT_UNAVAILABLE', 'At least one variant is unavailable.');
                }

                foreach ($inventories as $inventory) {
                    $quantity = $variantQuantities[$inventory->product_variant_id];
                    if ($inventory->available() < $quantity) {
                        throw new ApiProblem('INSUFFICIENT_STOCK', 'At least one variant has insufficient stock.');
                    }
                    $inventory->forceFill(['reserved' => $inventory->reserved + $quantity])->save();
                    DB::table('stock_reservation_items')->insert([
                        'stock_reservation_id' => $reservation->id, 'inventory_id' => $inventory->id, 'quantity' => $quantity,
                    ]);
                }

                return $reservation;
            }, 5);
        } catch (UniqueConstraintViolationException $exception) {
            $previous = StockReservation::query()->where('public_id', $id)->first();

            return $previous === null ? throw $exception : $this->matchingReservation($previous, $fingerprint);
        }
    }

    public function commit(string $id, string $verifiedPaymentReference): StockReservation
    {
        return DB::transaction(function () use ($id, $verifiedPaymentReference): StockReservation {
            $reservation = $this->lockReservation($id);
            $key = hash('sha256', $verifiedPaymentReference);
            if ($reservation->status === ReservationStatus::Committed) {
                if ($reservation->payment_key !== $key) {
                    throw new ApiProblem('PAYMENT_CONFLICT', 'The reservation was already committed for another payment.');
                }

                return $reservation;
            }
            if ($reservation->status === ReservationStatus::Released) {
                throw new ApiProblem('RESERVATION_RELEASED', 'The released reservation cannot be committed.');
            }

            $held = $reservation->status === ReservationStatus::Active;
            $quantities = $this->quantities($reservation);
            foreach ($this->lockInventories($quantities) as $inventory) {
                $quantity = $quantities[$inventory->id];
                if (! $held && $inventory->available() < $quantity) {
                    throw new ApiProblem('INSUFFICIENT_STOCK', 'Stock is no longer available to settle this payment.');
                }

                $this->move(
                    $inventory, -$quantity, $held ? -$quantity : 0, 'payment',
                    'Verified payment settlement', hash('sha256', 'payment:'.$reservation->id.':'.$inventory->id),
                );
            }

            $reservation->forceFill(['status' => ReservationStatus::Committed, 'payment_key' => $key])->save();

            return $reservation;
        }, 5);
    }

    public function release(string $id): StockReservation
    {
        return DB::transaction(function () use ($id): StockReservation {
            $reservation = $this->lockReservation($id);
            if ($reservation->status === ReservationStatus::Committed) {
                throw new ApiProblem('RESERVATION_COMMITTED', 'Paid stock requires an authorized restoration.');
            }

            return $this->releaseHeld($reservation, ReservationStatus::Released);
        }, 5);
    }

    public function expireDue(): int
    {
        $count = 0;
        StockReservation::query()->where('status', ReservationStatus::Active)->where('expires_at', '<=', now())
            ->select(['id', 'public_id'])->chunkById(100, function (Collection $rows) use (&$count): void {
                foreach ($rows as $row) {
                    $expired = DB::transaction(function () use ($row): bool {
                        $reservation = $this->lockReservation($row->public_id);
                        if ($reservation->status !== ReservationStatus::Active || $reservation->expires_at->gt(now())) {
                            return false;
                        }
                        $this->releaseHeld($reservation, ReservationStatus::Expired);

                        return true;
                    }, 5);
                    $count += (int) $expired;
                }
            });

        return $count;
    }

    public function restoreCommitted(string $id, string $verifiedRefundReference): StockReservation
    {
        return DB::transaction(function () use ($id, $verifiedRefundReference): StockReservation {
            $reservation = $this->lockReservation($id);
            if ($reservation->status !== ReservationStatus::Committed) {
                throw new ApiProblem('RESERVATION_NOT_COMMITTED', 'Only committed stock can be restored.');
            }

            $key = hash('sha256', $verifiedRefundReference);
            if ($reservation->restored_at !== null) {
                if ($reservation->restoration_key !== $key) {
                    throw new ApiProblem('RESTORATION_CONFLICT', 'This stock has already been restored.');
                }

                return $reservation;
            }
            $quantities = $this->quantities($reservation);
            foreach ($this->lockInventories($quantities) as $inventory) {
                $this->move(
                    $inventory, $quantities[$inventory->id], 0, 'restoration', 'Verified refund stock restoration',
                    hash('sha256', 'restore:'.$reservation->id.':'.$inventory->id),
                );
            }

            $reservation->forceFill(['restored_at' => now(), 'restoration_key' => $key])->save();

            return $reservation;
        }, 5);
    }

    private function releaseHeld(StockReservation $reservation, ReservationStatus $status): StockReservation
    {
        if ($reservation->status !== ReservationStatus::Active) {
            return $reservation;
        }

        $quantities = $this->quantities($reservation);
        foreach ($this->lockInventories($quantities) as $inventory) {
            $remaining = $inventory->reserved - $quantities[$inventory->id];
            if ($remaining < 0) {
                throw new ApiProblem('STOCK_STATE_CONFLICT', 'Reserved stock is inconsistent.');
            }
            $inventory->forceFill(['reserved' => $remaining])->save();
        }
        $reservation->forceFill(['status' => $status])->save();

        return $reservation;
    }

    private function validateRetry(InventoryMovement $movement, int $inventoryId, int $delta, string $reason): InventoryMovement
    {
        if ($movement->inventory_id !== $inventoryId || $movement->delta !== $delta || $movement->reason !== $reason) {
            throw new ApiProblem('IDEMPOTENCY_CONFLICT', 'The idempotency key was already used for a different adjustment.');
        }

        return $movement;
    }

    private function matchingReservation(StockReservation $reservation, string $fingerprint): StockReservation
    {
        if ($reservation->fingerprint !== $fingerprint) {
            throw new ApiProblem('IDEMPOTENCY_CONFLICT', 'The reservation ID was already used for a different request.');
        }

        return $reservation;
    }

    private function lockReservation(string $id): StockReservation
    {
        return StockReservation::query()->where('public_id', strtolower($id))->lockForUpdate()->firstOrFail();
    }

    /** @return array<int, int> */
    private function quantities(StockReservation $reservation): array
    {
        return DB::table('stock_reservation_items')->where('stock_reservation_id', $reservation->id)
            ->pluck('quantity', 'inventory_id')->map(fn (mixed $quantity): int => (int) $quantity)->all();
    }

    /**
     * @param  array<int, int>  $quantities
     * @return Collection<int, Inventory>
     */
    private function lockInventories(array $quantities): Collection
    {
        return Inventory::query()->whereIntegerInRaw('id', array_keys($quantities))
            ->orderBy('product_variant_id')->lockForUpdate()->get();
    }

    private function move(
        Inventory $inventory, int $delta, int $reservedDelta, string $type, string $reason, string $event, ?int $actorId = null,
    ): InventoryMovement {
        $onHand = $inventory->on_hand + $delta;
        $reserved = $inventory->reserved + $reservedDelta;
        if ($reserved < 0 || $onHand < $reserved || $onHand > self::MAX_STOCK) {
            throw new ApiProblem('STOCK_LIMIT', 'The change would exceed the inventory limits or reduce stock below reservations.');
        }

        $inventory->forceFill(['on_hand' => $onHand, 'reserved' => $reserved])->save();
        $movement = $inventory->movements()->make();
        $movement->forceFill([
            'delta' => $delta, 'on_hand_after' => $onHand, 'type' => $type, 'reason' => $reason, 'event_key' => $event, 'actor_id' => $actorId,
        ])->save();

        return $movement;
    }
}
