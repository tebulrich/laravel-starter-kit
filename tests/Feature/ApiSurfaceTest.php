<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\System\HealthCheckService;
use Laravel\Passport\Passport;
use Tests\TestCase;

final class ApiSurfaceTest extends TestCase
{
    public function test_root_redirects_to_framework_health_endpoint(): void
    {
        $this->get('/')->assertRedirect('/up');
    }

    public function test_framework_health_page_sends_baseline_security_headers(): void
    {
        $this->get('/up')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeaderMissing('X-Powered-By');
    }

    public function test_system_status_requires_passport_token(): void
    {
        $this->getJson('/api/system/status')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_system_status_returns_ok_for_authenticated_user(): void
    {
        Passport::actingAs(User::factory()->make([
            'id'    => 1,
            'email' => 'api@example.com',
        ]));

        $this->getJson('/api/system/status')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_system_status_can_include_queue_driver(): void
    {
        Passport::actingAs(User::factory()->make([
            'id'    => 1,
            'email' => 'api@example.com',
        ]));

        $this->getJson('/api/system/status?include=queue')
            ->assertOk()
            ->assertJsonPath('queue', 'sync');
    }

    public function test_health_endpoint_reports_checks(): void
    {
        $this->mock(HealthCheckService::class, function ($mock): void {
            $mock->shouldReceive('check')->once()->andReturn([
                'ok'      => true,
                'payload' => [
                    'status' => 'ok',
                    'checks' => [
                        'app'      => ['ok' => true, 'detail' => 'application process'],
                        'database' => ['ok' => true, 'detail' => 'sqlite'],
                        'redis'    => ['ok' => true, 'detail' => 'PONG'],
                    ],
                ],
            ]);
        });

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.app.ok', true)
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.redis.ok', true);
    }

    public function test_health_endpoint_returns_service_unavailable_when_degraded(): void
    {
        $this->mock(HealthCheckService::class, function ($mock): void {
            $mock->shouldReceive('check')->once()->andReturn([
                'ok'      => false,
                'payload' => [
                    'status' => 'degraded',
                    'checks' => [
                        'app'      => ['ok' => true, 'detail' => 'application process'],
                        'database' => ['ok' => false, 'detail' => 'connection refused'],
                        'redis'    => ['ok' => true, 'detail' => 'PONG'],
                    ],
                ],
            ]);
        });

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded');
    }
}
