<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CatalogStatus;
use App\Enums\CouponType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveCouponRequest;
use App\Http\Resources\Api\V1\CouponResource;
use App\Models\Coupon;
use App\Services\Catalog\CatalogMaintenance;
use App\Services\Promotions\CouponService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'required', 'string', 'max:40'],
            'status' => ['sometimes', Rule::enum(CatalogStatus::class)],
            'type' => ['sometimes', Rule::enum(CouponType::class)],
            'sort_by' => ['sometimes', Rule::in(['code', 'created_at', 'ends_at'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'trashed' => ['sometimes', 'boolean'],
        ]);
        $query = Coupon::query()->with(['products', 'categories']);
        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }
        foreach (['status', 'type'] as $filter) {
            if (isset($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }
        if (isset($filters['search'])) {
            $prefix = strtr(Str::upper($filters['search']), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->whereRaw("code LIKE ? ESCAPE '!'", [$prefix]);
        }
        $direction = $filters['sort_direction'] ?? 'desc';
        $items = $query->orderBy($filters['sort_by'] ?? 'created_at', $direction)->orderBy('id', $direction)
            ->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, CouponResource::collection($items), 'Coupons retrieved.', meta: [
            'pagination' => [
                'current_page' => $items->currentPage(), 'per_page' => $items->perPage(),
                'total' => $items->total(), 'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $coupon): JsonResponse
    {
        $record = Coupon::query()->with(['products', 'categories'])->where('public_id', $coupon)->firstOrFail();

        return $this->apiSuccess($request, new CouponResource($record), 'Coupon retrieved.');
    }

    public function store(SaveCouponRequest $request, CouponService $coupons, CatalogMaintenance $catalog): JsonResponse
    {
        $record = $coupons->save(null, $request->validated(), $catalog->actor($request));

        return $this->apiSuccess($request, new CouponResource($record), 'Coupon created.', 201);
    }

    public function update(SaveCouponRequest $request, string $coupon, CouponService $coupons, CatalogMaintenance $catalog): JsonResponse
    {
        $record = $coupons->save($coupon, $request->validated(), $catalog->actor($request));

        return $this->apiSuccess($request, new CouponResource($record), 'Coupon updated.');
    }

    public function status(Request $request, string $coupon, CouponService $coupons, CatalogMaintenance $catalog): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(CatalogStatus::class)]]);
        $record = $coupons->mutate($coupon, $data['status'] === 'active' ? 'activate' : 'deactivate', $catalog->actor($request));

        return $this->apiSuccess($request, new CouponResource($record), 'Coupon status updated.');
    }

    public function destroy(Request $request, string $coupon, CouponService $coupons, CatalogMaintenance $catalog): Response
    {
        $coupons->mutate($coupon, 'delete', $catalog->actor($request));

        return response()->noContent();
    }

    public function restore(Request $request, string $coupon, CouponService $coupons, CatalogMaintenance $catalog): JsonResponse
    {
        $record = $coupons->mutate($coupon, 'restore', $catalog->actor($request));

        return $this->apiSuccess($request, new CouponResource($record), 'Coupon restored as inactive.');
    }

    public function bulk(Request $request, CouponService $coupons, CatalogMaintenance $catalog): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate'])],
            'ids' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'ids.*' => ['required', 'ulid', 'distinct:ignore_case'],
        ]);
        $actor = $catalog->actor($request);
        $succeeded = [];
        $failed = [];

        foreach ($data['ids'] as $id) {
            try {
                $coupons->mutate($id, $data['action'] === 'activate' ? 'activate' : 'deactivate', $actor);
                $succeeded[] = $id;
            } catch (ModelNotFoundException) {
                $failed[] = ['id' => $id, 'code' => 'NOT_FOUND'];
            }
        }

        return $this->apiSuccess($request, [
            'succeeded_ids' => $succeeded, 'failed' => $failed,
            'counts' => ['succeeded' => count($succeeded), 'failed' => count($failed)],
        ], 'Coupon bulk action completed.');
    }
}
