<?php

namespace App\Services\Auth;

use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class IssueAccessToken
{
    /** @return array<string, mixed> */
    public function issue(User $account, string $deviceName, Request $request): array
    {
        $staff = $account->staffIdentity;
        abort_if($staff === null && ! User::query()->customers()->whereKey($account->getKey())->exists(), 403);

        $profile = new UserResource($account)->toArray($request);
        $expiresAt = now()->addDay();
        $token = ($staff ?? $account)->createToken($deviceName, $profile['roles'], $expiresAt);
        $audience = $staff === null ? 'customer' : 'staff';

        return [
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toISOString(),
            'identity_type' => $audience,
            'dashboard' => $audience,
            'user' => $profile,
        ];
    }
}
