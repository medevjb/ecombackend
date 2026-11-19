<?php

namespace App\Services\Auth;

use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AddressBook
{
    /** @return Builder<CustomerAddress> */
    public function forCustomer(User $owner): Builder
    {
        return CustomerAddress::query()->where('user_id', $owner->id);
    }

    /** @param array<string, mixed> $data */
    public function save(User $owner, ?string $addressId, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($owner, $addressId, $data): CustomerAddress {
            User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $addresses = $this->forCustomer($owner);
            $address = $addressId === null
                ? (new CustomerAddress)->forceFill(['user_id' => $owner->id, 'is_default' => false])
                : (clone $addresses)->where('public_id', $addressId)->firstOrFail();

            if ($data['is_default'] ?? false) {
                if ($address->exists) {
                    $addresses->whereKeyNot($address->getKey());
                }
                $addresses->where('is_default', true)->update(['is_default' => false]);
            }

            $address->fill($data)->save();

            return $address;
        }, 3);
    }

    public function delete(User $owner, string $id): void
    {
        DB::transaction(function () use ($owner, $id): void {
            User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $this->forCustomer($owner)->where('public_id', $id)->firstOrFail()->delete();
        }, 3);
    }
}
