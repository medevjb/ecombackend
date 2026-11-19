<?php

namespace Tests\Support;

use App\Exceptions\ApiProblem;
use App\Models\StaffUser;
use App\Services\Inventory\InventoryService;
use Closure;
use Illuminate\Support\Str;
use RuntimeException;

class InventoryRaceWorker
{
    public static function make(string $operation, string $worker, string $path, int $variantId, string $publicId, int $actorId): Closure
    {
        return static function () use ($operation, $worker, $path, $variantId, $publicId, $actorId): string {
            touch($path.'/'.$operation.'-'.$worker);
            $deadline = microtime(true) + 5;

            do {
                clearstatcache();
                if (is_file($path.'/'.$operation.'-one') && is_file($path.'/'.$operation.'-two')) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);

            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The other test process did not reach the race barrier.');
            }

            $service = app(InventoryService::class);

            if ($operation === 'adjust') {
                $actor = StaffUser::query()->whereKey($actorId)->firstOrFail();
                $service->adjust($publicId, 1, 'Concurrent receipt', $actor, 'race-'.$worker);

                return 'adjusted';
            }

            try {
                $service->reserve((string) Str::ulid(), [$variantId => 1], now()->addMinutes(10));

                return 'reserved';
            } catch (ApiProblem $problem) {
                return $problem->errorCode;
            }
        };
    }
}
