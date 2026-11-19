<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Database\Factories\StockReservationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $fingerprint
 * @property ReservationStatus $status
 * @property Carbon $expires_at
 * @property string|null $payment_key
 * @property Carbon|null $restored_at
 * @property string|null $restoration_key
 */
class StockReservation extends Model
{
    /** @use HasFactory<StockReservationFactory> */
    use HasFactory, HasUlids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => ReservationStatus::class, 'expires_at' => 'datetime', 'restored_at' => 'datetime'];
    }
}
