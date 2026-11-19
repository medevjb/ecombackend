<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\StaffUser;
use App\Services\Auth\StaffTwoFactor;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StaffTwoFactorController extends Controller
{
    use ApiResponse;

    public function setup(Request $request, StaffTwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'regex:/\A[\s\S]{1,72}\z/'],
        ]);

        /** @var StaffUser $staff */
        $staff = $request->user();

        return $this->apiSuccess($request, $twoFactor->setup($staff, $data['current_password']), 'Two-factor enrollment started.')
            ->header('Cache-Control', 'no-store');
    }

    public function confirm(Request $request, StaffTwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']]);
        /** @var StaffUser $staff */
        $staff = $request->user();

        return $this->apiSuccess($request, [
            'recovery_codes' => $twoFactor->confirm($staff, $data['code']),
        ], 'Two-factor authentication enabled. Sign in again.')->header('Cache-Control', 'no-store');
    }

    public function challenge(Request $request, StaffTwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'code' => ['required', 'string', 'regex:/\A(?:[0-9]{6}|[a-f0-9]{32})\z/'],
        ]);

        return $this->apiSuccess($request, $twoFactor->challenge($data['challenge_token'], $data['code'], $request), 'Signed in.')
            ->header('Cache-Control', 'no-store');
    }

    public function disable(Request $request, StaffTwoFactor $twoFactor): Response
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'regex:/\A[\s\S]{1,72}\z/'],
            'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
        ]);
        /** @var StaffUser $staff */
        $staff = $request->user();

        $twoFactor->disable($staff, $data['current_password'], $data['code']);

        return response()->noContent();
    }

    public function recovery(Request $request, StaffTwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'regex:/\A[\s\S]{1,72}\z/'],
            'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
        ]);
        /** @var StaffUser $staff */
        $staff = $request->user();

        return $this->apiSuccess($request, [
            'recovery_codes' => $twoFactor->regenerateRecoveryCodes($staff, $data['current_password'], $data['code']),
        ], 'Recovery codes replaced.')->header('Cache-Control', 'no-store');
    }
}
