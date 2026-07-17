<?php

declare(strict_types=1);

namespace App\Services\System;

use Illuminate\Support\Facades\Config;

/**
 * Builds a small operational status payload for health-style API checks.
 *
 * Controllers stay thin: they call this service and return the array as JSON.
 */
final class SystemStatusService
{
    /**
     * @return array{
     *     status: string,
     *     app: string,
     *     version?: string,
     *     queue?: string
     * }
     */
    public function status(?string $include = null): array
    {
        $payload = [
            'status' => 'ok',
            'app'    => Config::string('app.name'),
        ];

        if ($include === 'version') {
            $payload['version'] = $this->readVersion();
        }

        if ($include === 'queue') {
            $payload['queue'] = Config::string('queue.default');
        }

        return $payload;
    }

    private function readVersion(): string
    {
        $path = public_path('version.json');

        if (is_file($path) === false) {
            return 'unknown';
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return 'unknown';
        }

        /** @var array{version?: string}|null $decoded */
        $decoded = json_decode($raw, true);

        if (is_array($decoded) === false) {
            return 'unknown';
        }

        $version = $decoded['version'] ?? null;

        return is_string($version) === true && $version !== '' ? $version : 'unknown';
    }
}
