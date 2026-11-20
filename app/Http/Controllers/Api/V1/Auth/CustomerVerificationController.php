<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\CustomerEmailVerificationCodes;
use App\Services\Auth\IssueAccessToken;
use App\Traits\ApiResponse;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CustomerVerificationController extends Controller
{
    use ApiResponse;

    public function send(Request $request, CustomerEmailVerificationCodes $verificationCodes): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $verificationCodes->issue($user, CustomerEmailVerificationCodes::PURPOSE_REGISTRATION);
        }

        return $this->apiSuccess($request, null, 'Email verification code request accepted.', 202);
    }

    public function resend(Request $request, CustomerEmailVerificationCodes $verificationCodes): JsonResponse
    {
        $validated = $request->validate([
            'verification_token' => ['required', 'string', 'size:64', 'regex:/\A[a-zA-Z0-9]+\z/'],
        ]);
        $verificationCodes->resend($validated['verification_token']);

        return $this->apiSuccess($request, null, 'If this verification request is still active, a new code will be sent.', 202);
    }

    public function verifyCode(Request $request, CustomerEmailVerificationCodes $verificationCodes, IssueAccessToken $tokens): JsonResponse
    {
        $validated = $request->validate([
            'verification_token' => ['required', 'string', 'size:64', 'regex:/\A[a-zA-Z0-9]+\z/'],
            'code' => ['required', 'digits:6'],
        ]);
        $verified = $verificationCodes->verify(
            $validated['verification_token'],
            $validated['code'],
            [CustomerEmailVerificationCodes::PURPOSE_REGISTRATION, CustomerEmailVerificationCodes::PURPOSE_LOGIN],
        );

        return $this->apiSuccess($request, $tokens->issue($verified['user'], 'storefront', $request), 'Email verified. You are now signed in.')
            ->header('Cache-Control', 'no-store');
    }

    public function verify(string $id, string $hash): Response
    {
        $user = User::query()->customers()->whereNull('suspended_at')->where('public_id', $id)->firstOrFail();

        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->noContent();
    }
}
