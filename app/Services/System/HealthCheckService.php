<?php

declare(strict_types=1);

namespace App\Services\System;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Reports process, database, and Redis readiness for operators and probes.
 *
 * Used by the public /api/health endpoint. Does not authenticate callers.
 * Does not mutate state; failures are returned as structured check results.
 */
class HealthCheckService
{
    /**
     * Run readiness checks and return a payload plus overall HTTP-oriented ok flag.
     *
     * @return array{ok: bool, payload: array{status: string, checks: array<string, array{ok: bool, detail: string}>}}
     */
    public function check(): array
    {
        $checks = [
            'app'      => $this->okCheck('application process'),
            'database' => $this->databaseCheck(),
            'redis'    => $this->redisCheck(),
        ];

        $ok = true;
        foreach ($checks as $check) {
            if ($check['ok'] === false) {
                $ok = false;
                break;
            }
        }

        return [
            'ok'      => $ok,
            'payload' => [
                'status' => $ok === true ? 'ok' : 'degraded',
                'checks' => $checks,
            ],
        ];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function okCheck(string $detail): array
    {
        return [
            'ok'     => true,
            'detail' => $detail,
        ];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function databaseCheck(): array
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            return [
                'ok'     => true,
                'detail' => Config::string('database.default'),
            ];
        } catch (Throwable) {
            return [
                'ok'     => false,
                'detail' => 'unavailable',
            ];
        }
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function redisCheck(): array
    {
        try {
            $pong = Redis::connection()->ping();

            return [
                'ok'     => true,
                'detail' => is_string($pong) === true ? $pong : 'PONG',
            ];
        } catch (Throwable) {
            return [
                'ok'     => false,
                'detail' => 'unavailable',
            ];
        }
    }
}
