<?php

namespace App\Models;

use Database\Factories\UserFactory;
use HasinHayder\Tyro\Concerns\HasTyroRoles;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $public_id
 * @property-read StaffUser|null $staffIdentity
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasUlids;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use HasTyroRoles {
        suspend as private suspendTyroAccount;
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return HasOne<StaffUser, $this> */
    public function staffIdentity(): HasOne
    {
        return $this->hasOne(StaffUser::class, 'id');
    }

    /** @param Builder<self> $query */
    public function scopeCustomers(Builder $query): void
    {
        $query->whereDoesntHave('staffIdentity')
            ->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereNotIn('slug', ['customer', 'user']));
    }

    public function suspend(?string $reason = null): int
    {
        return DB::transaction(function () use ($reason): int {
            $revoked = $this->suspendTyroAccount($reason);
            DB::table('staff_login_challenges')->where('staff_user_id', $this->id)->delete();

            return $revoked + ($this->staffIdentity?->tokens()->delete() ?? 0);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
