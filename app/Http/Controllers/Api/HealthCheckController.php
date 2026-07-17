<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\System\HealthCheckService;
use Illuminate\Http\JsonResponse;

/**
 * Public readiness endpoint for load balancers and operators.
 *
 * Returns 200 when app, database, and Redis checks pass; 503 when any fail.
 */
final class HealthCheckController extends Controller
{
    public function __construct(
        private readonly HealthCheckService $healthCheckService,
    ) {}

    public function __invoke(): JsonResponse
    {
        $result = $this->healthCheckService->check();

        return response()->json(
            $result['payload'],
            $result['ok'] === true ? 200 : 503,
        );
    }
}
