<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AuditLogIndexRequest;
use App\Http\Resources\Api\V1\AuditLogResource;
use App\Traits\ApiResponse;
use Carbon\CarbonImmutable;
use HasinHayder\Tyro\Models\AuditLog;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    use ApiResponse;

    public function index(AuditLogIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $direction = $filters['sort_direction'] ?? 'desc';

        $query = AuditLog::query()->select(['id', 'user_id', 'event', 'auditable_type', 'auditable_id', 'created_at']);

        if (isset($filters['event'])) {
            $query->where('event', 'like', $filters['event'].'%');
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['to'])->addDay());
        }

        $query->orderBy($sortBy, $direction);

        if ($sortBy !== 'id') {
            $query->orderBy('id', $direction);
        }

        $logs = $query->paginate($request->integer('per_page', 20), page: $request->integer('page', 1));

        return $this->apiSuccess($request, AuditLogResource::collection($logs), 'Audit logs retrieved.', meta: [
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }
}
