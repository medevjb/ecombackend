<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ChangePasswordRequest;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\Auth\AccountPasswords;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PasswordController extends Controller
{
    use ApiResponse;

    public function forgot(ForgotPasswordRequest $request, AccountPasswords $accounts): JsonResponse
    {
        $accounts->sendResetLink($request->validated('email'), $request->routeIs('api.v1.admin.*'));

        return $this->apiSuccess($request, null, 'If the account is eligible, a reset link will be sent.', 202);
    }

    public function reset(ResetPasswordRequest $request, AccountPasswords $accounts): JsonResponse|Response
    {
        $reset = $accounts->resetPassword(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password'),
            $request->routeIs('api.v1.admin.*'),
        );

        if (! $reset) {
            return $this->apiFailure($request, 'INVALID_RESET_TOKEN', 'The reset token is invalid or expired.', 422);
        }

        return response()->noContent();
    }

    public function update(ChangePasswordRequest $request, AccountPasswords $accounts): Response
    {
        /** @var User|StaffUser $identity */
        $identity = $request->user();
        $user = $identity instanceof StaffUser ? $identity->account : $identity;
        $accounts->changePassword($user, $request->validated('current_password'), $request->validated('password'));

        return response()->noContent();
    }
}
