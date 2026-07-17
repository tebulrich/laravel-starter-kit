<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use StarterKit\Setup\ExistingFrontendAction;
use StarterKit\Setup\SetupCli;

final class SetupCliTest extends TestCase
{
    public function test_non_interactive_dry_run_plan_succeeds(): void
    {
        $cli = new SetupCli([
            '--no-interaction',
            '--dry-run',
            '--name=CI App',
            '--database=mysql',
            '--auth=passport',
            '--no-generate-key',
        ]);

        $this->assertTrue($cli->isDryRun());
        $this->assertTrue($cli->isNonInteractive());

        $plan = $cli->planFromOptions();
        $this->assertSame('CI App', $plan->appName);
        $this->assertSame('https://localhost', $plan->appUrl);
        $this->assertSame(80, $plan->httpPort);
        $this->assertSame(443, $plan->httpsPort);
        $this->assertSame('mysql', $plan->database->value);
        $this->assertSame('monolith', $plan->layout->value);
        $this->assertFalse($plan->generateAppKey);
    }

    public function test_vue_separate_flags_are_parsed(): void
    {
        $cli = new SetupCli([
            '--no-interaction',
            '--layout=separate',
            '--no-yarn-install',
        ]);

        $plan = $cli->planFromOptions();
        $this->assertSame('separate', $plan->layout->value);
        $this->assertFalse($plan->runYarnInstall);
    }

    public function test_force_frontend_flag_maps_to_delete_action(): void
    {
        $cli = new SetupCli([
            '--no-interaction',
            '--force-frontend',
            '--no-yarn-install',
        ]);

        $plan = $cli->planFromOptions();
        $this->assertSame(ExistingFrontendAction::Delete, $plan->existingFrontendAction);
    }

    public function test_on_existing_frontend_flag_is_parsed(): void
    {
        $cli = new SetupCli([
            '--no-interaction',
            '--on-existing-frontend=backup',
            '--no-yarn-install',
        ]);

        $plan = $cli->planFromOptions();
        $this->assertSame(ExistingFrontendAction::Backup, $plan->existingFrontendAction);
    }
}
