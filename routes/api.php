<?php

declare(strict_types=1);

use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Controllers\Api\SystemStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthCheckController::class)
    ->name('api.health');

if (config('starter.features.sample_api') === true) {
    $status = Route::get('/system/status', SystemStatusController::class)
        ->name('api.system.status');

    if (config('starter.features.passport') === true) {
        $status->middleware('auth:api');
    }
}
