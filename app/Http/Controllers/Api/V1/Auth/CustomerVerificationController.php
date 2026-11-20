<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Auth\CustomerEmailVerificationCodes;
use App\Traits\ApiResponse;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerVerificationController extends Controller
{
    use ApiResponse;

    public function send(Request $request, CustomerEmailVerificationCodes $verificationCodes): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $verificationCodes->send($user);
        }

        return $this->apiSuccess($request, null, 'Email verification code request accepted.', 202);
    }

    public function resend(Request $request, CustomerEmailVerificationCodes $verificationCodes): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);
        $email = Str::lower($validated['email']);
        $user = User::query()->customers()->whereNull('suspended_at')->where('email', $email)->first();

        if ($user !== null && ! $user->hasVerifiedEmail()) {
            $verificationCodes->send($user);
        }

        return $this->apiSuccess($request, null, 'If an unverified customer account exists for that email, a verification code will be sent.', 202);
    }

    public function verifyCode(Request $request, CustomerEmailVerificationCodes $verificationCodes): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'digits:6'],
        ]);
        $email = Str::lower($validated['email']);
        $user = User::query()->customers()->whereNull('suspended_at')->where('email', $email)->first();

        if ($user === null) {
            throw ValidationException::withMessages(['code' => ['That verification code is incorrect or expired.']]);
        }

        $verificationCodes->verify($user, $validated['code']);

        return $this->apiSuccess($request, new UserResource($user->refresh()), 'Email address verified.');
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
