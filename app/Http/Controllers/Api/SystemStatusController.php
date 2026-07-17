<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SystemStatusRequest;
use App\Services\System\SystemStatusService;
use Illuminate\Http\JsonResponse;

/**
 * Sample API controller: validates via Form Request, delegates to a service.
 */
final class SystemStatusController extends Controller
{
    public function __construct(
        private readonly SystemStatusService $systemStatusService,
    ) {}

    public function __invoke(SystemStatusRequest $request): JsonResponse
    {
        return response()->json(
            $this->systemStatusService->status($request->include())
        );
    }
}
