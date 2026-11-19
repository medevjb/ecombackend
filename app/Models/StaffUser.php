<?php

namespace App\Models;

use Database\Factories\StaffUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property-read User $account
 */
class StaffUser extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<StaffUserFactory> */
    use HasFactory;

    public $incrementing = false;

    /** @return BelongsTo<User, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id');
    }
}
