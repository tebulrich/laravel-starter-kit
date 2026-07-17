<?php

declare(strict_types=1);

namespace Tests\Unit\Setup;

use PHPUnit\Framework\TestCase;
use StarterKit\Setup\AppLayout;
use StarterKit\Setup\AuthStack;
use StarterKit\Setup\DatabaseEngine;
use StarterKit\Setup\ExistingFrontendAction;
use StarterKit\Setup\FrontendScaffolder;
use StarterKit\Setup\SetupAbortedException;
use StarterKit\Setup\SetupPlan;

final class ExistingFrontendActionTest extends TestCase
{
    private string $workspace;

    private string $templatesRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace     = sys_get_temp_dir() . '/starter-exist-fe-' . uniqid('', true);
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
        foreach (glob($this->workspace . '-frontend-backup-*') ?: [] as $backup) {
            $this->deleteTree($backup);
        }
        parent::tearDown();
    }

    public function test_keep_skips_scaffold_and_preserves_marker(): void
    {
        $target = $this->workspace . '-frontend';
        mkdir($target, 0775, true);
        file_put_contents($target . '/package.json', "{\"name\":\"existing\"}\n");
        file_put_contents($target . '/KEEP_ME', "yes\n");

        $scaffolder = new FrontendScaffolder($this->workspace, $this->templatesRoot);
        $actions    = $scaffolder->scaffold($this->vuePlan(ExistingFrontendAction::Keep));

        $this->assertFileExists($target . '/KEEP_ME');
        $this->assertSame("{\"name\":\"existing\"}\n", (string) file_get_contents($target . '/package.json'));
        $this->assertTrue($this->actionsContain($actions, 'Keep existing frontend'));
    }

    public function test_backup_renames_then_scaffolds(): void
    {
        $target = $this->workspace . '-frontend';
        mkdir($target, 0775, true);
        file_put_contents($target . '/package.json', "{\"name\":\"old\"}\n");
        file_put_contents($target . '/OLD_MARKER', "1\n");

        $scaffolder = new FrontendScaffolder($this->workspace, $this->templatesRoot);
        $scaffolder->scaffold($this->vuePlan(ExistingFrontendAction::Backup));

        $this->assertFileExists($target . '/package.json');
        $this->assertFileExists($target . '/src/main.ts');
        $this->assertFileDoesNotExist($target . '/OLD_MARKER');

        $backups = glob($this->workspace . '-frontend-backup-*') ?: [];
        $this->assertCount(1, $backups);
        $this->assertFileExists($backups[0] . '/OLD_MARKER');
    }

    public function test_delete_removes_then_scaffolds(): void
    {
        $target = $this->workspace . '-frontend';
        mkdir($target, 0775, true);
        file_put_contents($target . '/package.json', "{\"name\":\"old\"}\n");
        file_put_contents($target . '/OLD_MARKER', "1\n");

        $scaffolder = new FrontendScaffolder($this->workspace, $this->templatesRoot);
        $scaffolder->scaffold($this->vuePlan(ExistingFrontendAction::Delete));

        $this->assertFileExists($target . '/src/main.ts');
        $this->assertFileDoesNotExist($target . '/OLD_MARKER');
        $this->assertSame([], glob($this->workspace . '-frontend-backup-*') ?: []);
    }

    public function test_abort_throws_and_leaves_tree(): void
    {
        $target = $this->workspace . '-frontend';
        mkdir($target, 0775, true);
        file_put_contents($target . '/package.json', "{\"name\":\"old\"}\n");
        file_put_contents($target . '/OLD_MARKER', "1\n");

        $scaffolder = new FrontendScaffolder($this->workspace, $this->templatesRoot);

        try {
            $scaffolder->scaffold($this->vuePlan(ExistingFrontendAction::Abort));
            $this->fail('Expected SetupAbortedException');
        } catch (SetupAbortedException $exception) {
            $this->assertStringContainsString($target, $exception->getMessage());
        }

        $this->assertFileExists($target . '/OLD_MARKER');
    }

    private function vuePlan(ExistingFrontendAction $action): SetupPlan
    {
        return new SetupPlan(
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
            existingFrontendAction: $action,
        );
    }

    /**
     * @param  list<string>  $actions
     */
    private function actionsContain(array $actions, string $needle): bool
    {
        foreach ($actions as $action) {
            if (str_contains($action, $needle) === true) {
                return true;
            }
        }

        return false;
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
