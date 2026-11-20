<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterCustomerRequest;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\CustomerAccounts;
use App\Services\Auth\CustomerEmailVerificationCodes;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class CustomerAccountController extends Controller
{
    use ApiResponse;

    public function store(RegisterCustomerRequest $request, CustomerAccounts $accounts, CustomerEmailVerificationCodes $verificationCodes): JsonResponse
    {
        $user = $accounts->register(
            $request->validated('name'),
            $request->validated('email'),
            $request->validated('password'),
        );
        $verificationToken = $verificationCodes->issue($user, CustomerEmailVerificationCodes::PURPOSE_REGISTRATION);

        return $this->apiSuccess($request, [
            'email_verification_required' => true,
            'email' => $user->email,
            'verification_token' => $verificationToken,
            'user' => new UserResource($user),
        ], 'Customer account created. Check your email for a verification code.', 201)->header('Cache-Control', 'no-store');
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->safe()->only(['name']));

        return $this->apiSuccess($request, new UserResource($user), 'Profile updated.');
    }
}
