<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDOException;

class StatusController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request): JsonResponse
    {
        try {
            DB::connection()->getPdo();
        } catch (PDOException|SQLiteDatabaseDoesNotExistException) {
            return $this->apiFailure($request, 'DATABASE_UNAVAILABLE', 'The API could not connect to the database.', 503)
                ->header('Cache-Control', 'no-store');
        }

        return $this->apiSuccess($request, [
            'status' => 'operational',
            'version' => 'v1',
            'checks' => [
                'api' => 'operational',
                'database' => 'operational',
            ],
            'checked_at' => now()->toISOString(),
        ], 'The API reached the database.')->header('Cache-Control', 'no-store');
    }
}
