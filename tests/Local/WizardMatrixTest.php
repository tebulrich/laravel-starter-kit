<?php

declare(strict_types=1);

namespace Tests\Local;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use StarterKit\Setup\AppKeyGenerator;
use StarterKit\Setup\AppLayout;
use StarterKit\Setup\AuthStack;
use StarterKit\Setup\DatabaseEngine;
use StarterKit\Setup\EnvFileEditor;
use StarterKit\Setup\FrontendScaffolder;
use StarterKit\Setup\SetupApplier;
use StarterKit\Setup\SetupCli;
use StarterKit\Setup\SetupPlan;
use StarterKit\Setup\SupervisorAutostartTuner;

/**
 * Full wizard combination matrix.
 *
 * Skipped in the default CI suite (see qa/phpunit.xml group exclude).
 * Run locally — see README “Wizard matrix tests”.
 *
 * @group wizard-matrix
 */
#[Group('wizard-matrix')]
final class WizardMatrixTest extends TestCase
{
    private string $root;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root      = sys_get_temp_dir() . '/wizard-matrix-' . uniqid('', true);
        $this->workspace = $this->root . '/laravel-starter-kit';
        $this->prepareWorkspace($this->workspace);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: AppLayout, 1: DatabaseEngine, 2: AuthStack}>
     */
    public static function structuralCombinations(): array
    {
        $cases = [];

        foreach (AppLayout::cases() as $layout) {
            foreach (DatabaseEngine::cases() as $database) {
                foreach (AuthStack::cases() as $auth) {
                    $key = implode('-', [
                        $layout->value,
                        $database->value,
                        $auth->value,
                    ]);
                    $cases[$key] = [$layout, $database, $auth];
                }
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function booleanFlagCombinations(): array
    {
        $flags = [
            'enableSentry',
            'enableTelescope',
            'enableDebugbar',
            'enableQueueWorker',
            'enableScheduler',
            'enableAutoMigrate',
            'keepSampleApi',
            'generateAppKey',
        ];

        $cases = [];
        foreach ($flags as $flag) {
            $cases[$flag . '-true']  = [$flag, true];
            $cases[$flag . '-false'] = [$flag, false];
        }

        return $cases;
    }

    #[DataProvider('structuralCombinations')]
    public function test_structural_combination_applies_cleanly(
        AppLayout $layout,
        DatabaseEngine $database,
        AuthStack $auth,
    ): void {
        $runYarn = $this->shouldRunYarnInstall();

        $plan = new SetupPlan(
            appName: 'Matrix ' . $layout->value,
            appUrl: 'https://localhost',
            httpPort: 80,
            httpsPort: 443,
            database: $database,
            auth: $auth,
            layout: $layout,
            enableSentry: true,
            enableTelescope: false,
            enableDebugbar: false,
            enableQueueWorker: true,
            enableScheduler: true,
            enableAutoMigrate: true,
            keepSampleApi: true,
            generateAppKey: true,
            runYarnInstall: $runYarn,
        );

        $actions = $this->applier()->apply($plan);

        $this->assertNotEmpty($actions);
        $this->assertFileExists($this->workspace . '/.env');
        $this->assertFileExists($this->workspace . '/config/starter.php');
        $this->assertFileExists($this->workspace . '/storage/app/setup-manifest.json');

        $env = (string) file_get_contents($this->workspace . '/.env');
        $this->assertStringContainsString('DB_CONNECTION=' . $database->value, $env);
        $this->assertStringContainsString('COMPOSE_PROFILES=' . $database->composeProfile(), $env);
        $this->assertStringContainsString('AUTH_PROVIDER=' . $auth->authProvider(), $env);
        $this->assertStringContainsString('STARTER_FRONTEND_LAYOUT=' . $layout->value, $env);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:.+/m', $env);

        $manifest = json_decode((string) file_get_contents($this->workspace . '/storage/app/setup-manifest.json'), true);
        $this->assertIsArray($manifest);
        $this->assertArrayNotHasKey('frontend', $manifest['plan']);
        $this->assertSame($layout->value, $manifest['plan']['layout']);
        $this->assertSame($database->value, $manifest['plan']['database']);
        $this->assertSame($auth->value, $manifest['plan']['auth']);

        if ($layout === AppLayout::Monolith) {
            $this->assertFileExists($this->workspace . '/frontend/package.json');
            $this->assertFileExists($this->workspace . '/frontend/src/main.ts');
            $this->assertFileExists($this->workspace . '/frontend/src/App.vue');
            $this->assertFileDoesNotExist($this->workspace . '/frontend/.github/workflows/ci.yml');
            $this->assertStringContainsString('5173', $env);
            if ($runYarn === true) {
                $this->assertDirectoryExists($this->workspace . '/frontend/node_modules');
            }
        }

        if ($layout === AppLayout::Separate) {
            $sibling = $this->workspace . '-frontend';
            $this->assertDirectoryExists($sibling);
            $this->assertFileExists($sibling . '/package.json');
            $this->assertFileExists($sibling . '/src/main.ts');
            $this->assertFileExists($sibling . '/.github/workflows/ci.yml');
            $this->assertFileExists($sibling . '/.gitlab-ci.yml');
            $this->assertFileExists($sibling . '/.env');
            $this->assertFileExists($sibling . '/README.md');
            $package = (string) file_get_contents($sibling . '/package.json');
            $this->assertStringContainsString('"vue"', $package);
            if ($runYarn === true) {
                $this->assertDirectoryExists($sibling . '/node_modules');
            }
        }

        $cli = new SetupCli([
            '--no-interaction',
            '--layout=' . $layout->value,
            '--database=' . $database->value,
            '--auth=' . $auth->value,
            '--no-yarn-install',
            '--no-generate-key',
        ]);
        $fromCli = $cli->planFromOptions();
        $this->assertSame($layout, $fromCli->layout);
        $this->assertSame($database, $fromCli->database);
        $this->assertSame($auth, $fromCli->auth);
    }

    #[DataProvider('booleanFlagCombinations')]
    public function test_each_boolean_flag_is_honoured(string $flag, bool $value): void
    {
        $defaults = [
            'enableSentry'      => true,
            'enableTelescope'   => false,
            'enableDebugbar'    => false,
            'enableQueueWorker' => true,
            'enableScheduler'   => true,
            'enableAutoMigrate' => true,
            'keepSampleApi'     => true,
            'generateAppKey'    => true,
        ];
        $defaults[$flag] = $value;

        $plan = new SetupPlan(
            appName: 'Flag Matrix',
            appUrl: 'https://localhost',
            httpPort: 80,
            httpsPort: 443,
            database: DatabaseEngine::Mysql,
            auth: AuthStack::Passport,
            layout: AppLayout::Monolith,
            enableSentry: $defaults['enableSentry'],
            enableTelescope: $defaults['enableTelescope'],
            enableDebugbar: $defaults['enableDebugbar'],
            enableQueueWorker: $defaults['enableQueueWorker'],
            enableScheduler: $defaults['enableScheduler'],
            enableAutoMigrate: $defaults['enableAutoMigrate'],
            keepSampleApi: $defaults['keepSampleApi'],
            generateAppKey: $defaults['generateAppKey'],
            runYarnInstall: false,
        );

        $this->applier()->apply($plan);

        $env      = (string) file_get_contents($this->workspace . '/.env');
        $manifest = json_decode((string) file_get_contents($this->workspace . '/storage/app/setup-manifest.json'), true);
        $this->assertIsArray($manifest);

        $queue   = (string) file_get_contents($this->workspace . '/docker/configs/supervisor/worker-laravel-queue.conf');
        $cron    = (string) file_get_contents($this->workspace . '/docker/configs/supervisor/worker-laravel-cron.conf');
        $migrate = (string) file_get_contents($this->workspace . '/docker/configs/supervisor/task-artisan-migrate.conf');

        match ($flag) {
            'enableSentry' => $value === true
                ? $this->assertStringContainsString('STARTER_FEATURE_SENTRY=true', $env)
                : $this->assertStringContainsString('STARTER_FEATURE_SENTRY=false', $env),
            'enableTelescope' => $this->assertStringContainsString(
                'TELESCOPE_ENABLED=' . ($value === true ? 'true' : 'false'),
                $env,
            ),
            'enableDebugbar' => $this->assertStringContainsString(
                'DEBUGBAR_ENABLED=' . ($value === true ? 'true' : 'false'),
                $env,
            ),
            'enableQueueWorker' => $this->assertStringContainsString(
                'autostart=' . ($value === true ? 'true' : 'false'),
                $queue,
            ),
            'enableScheduler' => $this->assertStringContainsString(
                'autostart=' . ($value === true ? 'true' : 'false'),
                $cron,
            ),
            'enableAutoMigrate' => $this->assertStringContainsString(
                'autostart=' . ($value === true ? 'true' : 'false'),
                $migrate,
            ),
            'keepSampleApi'  => $this->assertSame($value, $manifest['plan']['sample_api']),
            'generateAppKey' => $value === true
                ? $this->assertMatchesRegularExpression('/^APP_KEY=base64:.+/m', $env)
                : $this->assertDoesNotMatchRegularExpression('/^APP_KEY=base64:.+/m', $env),
            default => $this->fail('Unhandled flag: ' . $flag),
        };
    }

    public function test_cli_help_documents_layout(): void
    {
        $help = (new SetupCli(['--help']))->helpText();
        $this->assertStringContainsString('--layout=monolith|separate', $help);
        $this->assertStringContainsString('{project}-frontend', $help);
        $this->assertStringContainsString('Vue SPA', $help);
        $this->assertStringNotContainsString('blade', $help);
    }

    private function applier(): SetupApplier
    {
        return new SetupApplier(
            basePath: $this->workspace,
            env: new EnvFileEditor($this->workspace . '/.env'),
            supervisor: new SupervisorAutostartTuner($this->workspace . '/docker/configs/supervisor'),
            appKeyGenerator: new AppKeyGenerator,
            frontend: new FrontendScaffolder(
                basePath: $this->workspace,
                templatesRoot: dirname(__DIR__, 2) . '/setup/templates',
            ),
        );
    }

    private function shouldRunYarnInstall(): bool
    {
        $value = getenv('RUN_WIZARD_MATRIX_YARN');

        return $value === '1' || $value === 'true';
    }

    private function prepareWorkspace(string $workspace): void
    {
        mkdir($workspace . '/docker/configs/supervisor', 0775, true);
        mkdir($workspace . '/storage/app', 0775, true);
        mkdir($workspace . '/config', 0775, true);
        mkdir($workspace . '/routes', 0775, true);

        file_put_contents(
            $workspace . '/.env.example',
            "APP_NAME=Example\nAPP_URL=https://localhost\nAPP_PORT_HTTP=80\nAPP_PORT_HTTPS=443\nAPP_KEY=\nDB_CONNECTION=mysql\nDB_HOST=mysql\nDB_PORT=3306\nFORWARD_DB_PORT=3306\nCOMPOSE_PROFILES=mysql\nAUTH_PROVIDER=native\nTELESCOPE_ENABLED=false\nDEBUGBAR_ENABLED=false\nSENTRY_LARAVEL_DSN=\nSENTRY_TRACES_SAMPLE_RATE=0.0\nSTARTER_SAMPLE_API=true\nSTARTER_FEATURE_PASSPORT=true\nSTARTER_FEATURE_SENTRY=true\nSTARTER_FEATURE_AUTHENTIK=false\nSTARTER_FRONTEND_LAYOUT=monolith\nCORS_ALLOWED_ORIGINS=https://localhost,http://127.0.0.1:5173,http://localhost:5173\n"
        );

        foreach ([
            'worker-laravel-queue.conf',
            'worker-laravel-cron.conf',
            'task-artisan-migrate.conf',
        ] as $file) {
            file_put_contents(
                $workspace . '/docker/configs/supervisor/' . $file,
                "[program:test]\nautostart=true\n"
            );
        }
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
