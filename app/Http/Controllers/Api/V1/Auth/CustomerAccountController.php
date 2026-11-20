<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterCustomerRequest;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\CustomerAccounts;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class CustomerAccountController extends Controller
{
    use ApiResponse;

    public function store(RegisterCustomerRequest $request, CustomerAccounts $accounts): JsonResponse
    {
        $user = $accounts->register(
            $request->validated('name'),
            $request->validated('email'),
            $request->validated('password'),
        );
        $user->sendEmailVerificationNotification();

        return $this->apiSuccess($request, new UserResource($user), 'Customer account created.', 201);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->safe()->only(['name']));

        return $this->apiSuccess($request, new UserResource($user), 'Profile updated.');
    }
}
