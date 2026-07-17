<?php

declare(strict_types=1);

namespace Tests\Unit\Setup;

use PHPUnit\Framework\TestCase;
use StarterKit\Setup\AppKeyGenerator;
use StarterKit\Setup\AppLayout;
use StarterKit\Setup\AuthStack;
use StarterKit\Setup\DatabaseEngine;
use StarterKit\Setup\EnvFileEditor;
use StarterKit\Setup\SetupApplier;
use StarterKit\Setup\SetupPlan;
use StarterKit\Setup\SupervisorAutostartTuner;

final class SetupApplierTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir() . '/starter-setup-' . uniqid('', true);
        mkdir($this->workspace . '/docker/configs/supervisor', 0775, true);
        mkdir($this->workspace . '/storage/app', 0775, true);
        mkdir($this->workspace . '/config', 0775, true);
        mkdir($this->workspace . '/routes', 0775, true);

        file_put_contents(
            $this->workspace . '/.env.example',
            "APP_NAME=Example\nAPP_URL=https://localhost\nAPP_PORT_HTTP=80\nAPP_PORT_HTTPS=443\nAPP_KEY=\nDB_CONNECTION=mysql\nDB_HOST=mysql\nDB_PORT=3306\nFORWARD_DB_PORT=3306\nCOMPOSE_PROFILES=mysql\nAUTH_PROVIDER=native\nTELESCOPE_ENABLED=false\nDEBUGBAR_ENABLED=false\nSENTRY_LARAVEL_DSN=\nSENTRY_TRACES_SAMPLE_RATE=0.0\nSTARTER_SAMPLE_API=true\nSTARTER_FEATURE_PASSPORT=true\nSTARTER_FEATURE_SENTRY=true\nSTARTER_FEATURE_AUTHENTIK=false\n"
        );

        foreach ([
            'worker-laravel-queue.conf',
            'worker-laravel-cron.conf',
            'task-artisan-migrate.conf',
        ] as $file) {
            file_put_contents(
                $this->workspace . '/docker/configs/supervisor/' . $file,
                "[program:test]\nautostart=true\n"
            );
        }
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->workspace);
        $sibling = $this->workspace . '-frontend';
        if (is_dir($sibling) === true) {
            $this->deleteTree($sibling);
        }
        parent::tearDown();
    }

    public function test_applier_writes_env_supervisor_compose_profile_and_manifest(): void
    {
        $plan = new SetupPlan(
            appName: 'Demo API',
            appUrl: 'https://localhost:9443',
            httpPort: 8080,
            httpsPort: 9443,
            database: DatabaseEngine::Pgsql,
            auth: AuthStack::PassportAuthentik,
            layout: AppLayout::Monolith,
            enableSentry: false,
            enableTelescope: true,
            enableDebugbar: false,
            enableQueueWorker: false,
            enableScheduler: true,
            enableAutoMigrate: false,
            keepSampleApi: false,
            generateAppKey: true,
            runYarnInstall: false,
        );

        $applier = new SetupApplier(
            basePath: $this->workspace,
            env: new EnvFileEditor($this->workspace . '/.env'),
            supervisor: new SupervisorAutostartTuner($this->workspace . '/docker/configs/supervisor'),
            appKeyGenerator: new AppKeyGenerator,
        );

        $actions = $applier->apply($plan);

        $this->assertNotEmpty($actions);
        $this->assertFileExists($this->workspace . '/.env');
        $env = (string) file_get_contents($this->workspace . '/.env');
        $this->assertStringContainsString('APP_NAME="Demo API"', $env);
        $this->assertStringContainsString('APP_PORT_HTTP=8080', $env);
        $this->assertStringContainsString('APP_PORT_HTTPS=9443', $env);
        $this->assertStringContainsString('DB_CONNECTION=pgsql', $env);
        $this->assertStringContainsString('COMPOSE_PROFILES=pgsql', $env);
        $this->assertStringContainsString('STARTER_FRONTEND_LAYOUT=monolith', $env);
        $this->assertStringContainsString('5173', $env);
        $this->assertFileExists($this->workspace . '/frontend/package.json');
        $this->assertFileExists($this->workspace . '/frontend/src/App.vue');

        $manifest = json_decode((string) file_get_contents($this->workspace . '/storage/app/setup-manifest.json'), true);
        $this->assertIsArray($manifest);
        $this->assertSame('monolith', $manifest['plan']['layout']);
        $this->assertArrayNotHasKey('frontend', $manifest['plan']);
        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/=]+$/', (new EnvFileEditor($this->workspace . '/.env'))->get('APP_KEY') ?? '');
    }

    public function test_reapply_keeps_existing_app_key(): void
    {
        copy($this->workspace . '/.env.example', $this->workspace . '/.env');
        $env = new EnvFileEditor($this->workspace . '/.env');
        $env->set('APP_KEY', 'base64:existing-key-must-not-rotate');

        $plan = new SetupPlan(
            appName: 'Keep Key',
            appUrl: 'https://localhost',
            httpPort: 80,
            httpsPort: 443,
            database: DatabaseEngine::Mysql,
            auth: AuthStack::Passport,
            layout: AppLayout::Monolith,
            enableSentry: false,
            enableTelescope: false,
            enableDebugbar: false,
            enableQueueWorker: false,
            enableScheduler: false,
            enableAutoMigrate: false,
            keepSampleApi: false,
            generateAppKey: true,
            runYarnInstall: false,
        );

        $applier = new SetupApplier(
            basePath: $this->workspace,
            env: new EnvFileEditor($this->workspace . '/.env'),
            supervisor: new SupervisorAutostartTuner($this->workspace . '/docker/configs/supervisor'),
            appKeyGenerator: new AppKeyGenerator,
        );

        $actions = $applier->apply($plan);

        $this->assertContains('Keep existing APP_KEY', $actions);
        $this->assertSame('base64:existing-key-must-not-rotate', (new EnvFileEditor($this->workspace . '/.env'))->get('APP_KEY'));
    }

    public function test_dry_run_does_not_write_files(): void
    {
        $plan = new SetupPlan(
            appName: 'Dry',
            appUrl: 'https://localhost',
            httpPort: 80,
            httpsPort: 443,
            database: DatabaseEngine::Mysql,
            auth: AuthStack::Passport,
            layout: AppLayout::Separate,
            enableSentry: true,
            enableTelescope: false,
            enableDebugbar: false,
            enableQueueWorker: true,
            enableScheduler: true,
            enableAutoMigrate: true,
            keepSampleApi: true,
            generateAppKey: false,
            runYarnInstall: false,
        );

        $applier = new SetupApplier(
            basePath: $this->workspace,
            env: new EnvFileEditor($this->workspace . '/.env'),
            supervisor: new SupervisorAutostartTuner($this->workspace . '/docker/configs/supervisor'),
        );

        $actions = $applier->apply($plan, dryRun: true);

        $this->assertFileDoesNotExist($this->workspace . '/.env');
        $this->assertTrue(
            count(array_filter($actions, static fn (string $a): bool => str_contains($a, 'sibling'))) > 0
        );
    }

    private function deleteTree(string $path): void
    {
        if (is_dir($path) === false) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.') {
                continue;
            }
            if ($item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full) === true) {
                $this->deleteTree($full);
            } else {
                unlink($full);
            }
        }

        rmdir($path);
    }
}
