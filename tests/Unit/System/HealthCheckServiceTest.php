<?php

declare(strict_types=1);

namespace Tests\Unit\System;

use App\Services\System\HealthCheckService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class HealthCheckServiceTest extends TestCase
{
    public function test_check_returns_ok_when_database_and_redis_respond(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getPdo')->once()->andReturn(Mockery::mock());
        DB::shouldReceive('connection')->once()->andReturn($connection);
        DB::shouldReceive('select')->once()->with('select 1')->andReturn([[1]]);

        $redis = Mockery::mock();
        $redis->shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $result = (new HealthCheckService)->check();

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['payload']['status']);
        $this->assertTrue($result['payload']['checks']['database']['ok']);
        $this->assertTrue($result['payload']['checks']['redis']['ok']);
    }

    public function test_check_marks_degraded_when_database_fails(): void
    {
        DB::shouldReceive('connection')->once()->andThrow(new RuntimeException('db down'));

        $redis = Mockery::mock();
        $redis->shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $result = (new HealthCheckService)->check();

        $this->assertFalse($result['ok']);
        $this->assertSame('degraded', $result['payload']['status']);
        $this->assertFalse($result['payload']['checks']['database']['ok']);
        $this->assertSame('unavailable', $result['payload']['checks']['database']['detail']);
        $this->assertTrue($result['payload']['checks']['redis']['ok']);
    }
}
