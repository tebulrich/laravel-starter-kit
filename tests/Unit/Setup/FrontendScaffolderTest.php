<?php

declare(strict_types=1);

namespace Tests\Unit\Setup;

use PHPUnit\Framework\TestCase;
use StarterKit\Setup\AppLayout;
use StarterKit\Setup\AuthStack;
use StarterKit\Setup\DatabaseEngine;
use StarterKit\Setup\FrontendScaffolder;
use StarterKit\Setup\SetupPlan;

final class FrontendScaffolderTest extends TestCase
{
    private string $workspace;

    private string $templatesRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace     = sys_get_temp_dir() . '/starter-fe-' . uniqid('', true);
        $this->templatesRoot = dirname(__DIR__, 3) . '/setup/templates';
        mkdir($this->workspace . '/routes', 0775, true);
        mkdir($this->workspace . '/config', 0775, true);
        file_put_contents($this->workspace . '/.env', "APP_NAME=Test\n");
        file_put_contents($this->workspace . '/.env.example', "APP_NAME=Test\n");
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

    public function test_vue_separate_creates_sibling_with_ci(): void
    {
        $plan = new SetupPlan(
            appName: 'Orders',
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

        $scaffolder = new FrontendScaffolder($this->workspace, $this->templatesRoot);
        $target     = $scaffolder->targetPath($plan);
        $this->assertSame($this->workspace . '-frontend', $target);

        $scaffolder->scaffold($plan);

        $this->assertFileExists($target . '/package.json');
        $this->assertFileExists($target . '/src/main.ts');
        $this->assertFileExists($target . '/.github/workflows/ci.yml');
        $this->assertFileExists($target . '/.gitlab-ci.yml');
        $this->assertFileExists($target . '/README.md');
        $this->assertFileExists($target . '/compose.yaml');
        $this->assertFileExists($target . '/start.sh');
        $this->assertFileExists($target . '/stop.sh');
        $start = (string) file_get_contents($target . '/start.sh');
        $this->assertStringContainsString('docker compose up -d', $start);
        $this->assertStringContainsString('./stop.sh', $start);
        $compose = (string) file_get_contents($target . '/compose.yaml');
        $this->assertStringContainsString('node:24-alpine', $compose);
        $this->assertStringContainsString('host.docker.internal', $compose);
        $this->assertStringContainsString('yarn dev', $compose);
        $this->assertStringContainsString('Open: $${SCHEME}://localhost', $compose);
        $this->assertStringContainsString('https://php:443', $compose);
        $this->assertStringContainsString('_app', $compose);
        $this->assertStringNotContainsString('corepack enable', $compose);
        $this->assertFileExists($target . '/scripts/create-certificate.sh');
        $vite = (string) file_get_contents($target . '/vite.config.ts');
        $this->assertStringContainsString('httpsConfig', $vite);
        $this->assertStringContainsString('localhost.pem', $vite);
        $this->assertStringContainsString('https://localhost', $vite);
        $this->assertStringContainsString('secure: false', $vite);
    }

    public function test_vue_monolith_uses_frontend_subdir(): void
    {
        $plan = new SetupPlan(
            appName: 'Orders',
            appUrl: 'https://localhost',
            httpPort: 80,
            httpsPort: 443,
            database: DatabaseEngine::Mysql,
            auth: AuthStack::Passport,
            layout: AppLayout::Monolith,
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

        $scaffolder = new FrontendScaffolder($this->workspace, $this->templatesRoot);
        $scaffolder->scaffold($plan);

        $this->assertFileExists($this->workspace . '/frontend/package.json');
        $this->assertFileExists($this->workspace . '/frontend/src/App.vue');
        $this->assertFileExists($this->workspace . '/frontend/compose.yaml');
        $this->assertFileDoesNotExist($this->workspace . '/frontend/.github/workflows/ci.yml');
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
