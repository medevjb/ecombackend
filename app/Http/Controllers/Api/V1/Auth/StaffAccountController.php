<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Http\Resources\Api\V1\StaffResource;
use App\Models\StaffUser;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffAccountController extends Controller
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();

        return $this->apiSuccess($request, new StaffResource($staff->account), 'Staff profile retrieved.');
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();
        $staff->account->update($request->safe()->only(['name']));

        return $this->apiSuccess($request, new StaffResource($staff->account), 'Profile updated.');
    }
}
