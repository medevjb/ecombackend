<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\Auth\StaffTwoFactor;
use App\Services\Cart\CartService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class TokenController extends Controller
{
    use ApiResponse;

    public function store(LoginRequest $request, StaffTwoFactor $twoFactor, CartService $carts): JsonResponse
    {
        $guard = Auth::guard('web');

        if (! $guard->once($request->safe()->only(['email', 'password']))) {
            return $this->apiFailure($request, 'INVALID_CREDENTIALS', 'Invalid credentials.', 401);
        }

        /** @var User $user */
        $user = $guard->user();

        $result = $twoFactor->login($user, $request->validated('device_name', 'api-client'), $request);

        if (isset($result['two_factor_required'])) {
            return $this->apiSuccess($request, $result, 'Two-factor verification required.', 202)->header('Cache-Control', 'no-store');
        }

        $guestToken = $request->header('X-Cart-Token');

        if ($result['identity_type'] === 'customer' && $guestToken !== null) {
            try {
                $merged = $carts->merge($user, $guestToken);
                $result['cart_merge'] = ['status' => 'merged', 'conflicts' => $merged['conflicts']];
            } catch (ApiProblem $problem) {
                if ($problem->errorCode !== 'CART_NOT_FOUND') {
                    throw $problem;
                }
                $result['cart_merge'] = ['status' => 'unavailable', 'conflicts' => []];
            }
        }

        return $this->apiSuccess($request, $result, 'Signed in.')->header('Cache-Control', 'no-store');
    }

    public function show(Request $request): JsonResponse
    {
        $identity = $request->user();
        $account = $identity instanceof StaffUser ? $identity->account : $identity;

        return $this->apiSuccess($request, new UserResource($account), 'Profile retrieved.');
    }

    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
