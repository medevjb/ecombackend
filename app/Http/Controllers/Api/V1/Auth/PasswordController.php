<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ChangePasswordRequest;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\Auth\AccountPasswords;
use App\Services\Auth\CustomerEmailVerificationCodes;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PasswordController extends Controller
{
    use ApiResponse;

    public function forgot(ForgotPasswordRequest $request, AccountPasswords $accounts, CustomerEmailVerificationCodes $verificationCodes): JsonResponse
    {
        if ($request->routeIs('api.v1.admin.*')) {
            $accounts->sendResetLink($request->validated('email'), true);

            return $this->apiSuccess($request, null, 'If the account is eligible, a reset link will be sent.', 202);
        }

        $verificationToken = $verificationCodes->issueForEmail(
            $request->validated('email'),
            CustomerEmailVerificationCodes::PURPOSE_PASSWORD_RESET,
        );

        return $this->apiSuccess($request, [
            'email' => $request->validated('email'),
            'verification_token' => $verificationToken,
        ], 'If the account is eligible, a password reset code will be sent.', 202)->header('Cache-Control', 'no-store');
    }

    public function verifyCode(Request $request, CustomerEmailVerificationCodes $verificationCodes, AccountPasswords $accounts): JsonResponse
    {
        $validated = $request->validate([
            'verification_token' => ['required', 'string', 'size:64', 'regex:/\A[a-zA-Z0-9]+\z/'],
            'code' => ['required', 'digits:6'],
        ]);
        $verified = $verificationCodes->verify(
            $validated['verification_token'],
            $validated['code'],
            [CustomerEmailVerificationCodes::PURPOSE_PASSWORD_RESET],
        );
        $resetToken = $accounts->createResetToken($verified['user']);

        return $this->apiSuccess($request, [
            'email' => $verified['user']->email,
            'reset_token' => $resetToken,
        ], 'Code confirmed. Choose a new password.')->header('Cache-Control', 'no-store');
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
