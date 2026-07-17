<?php

declare(strict_types=1);

namespace StarterKit\Setup;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Interactive prompts that produce a SetupPlan.
 *
 * Runs on the host (outside Compose) so it can set COMPOSE_PROFILES and scaffold the Vue SPA.
 */
final class SetupWizard
{
    public function __construct(
        private readonly string $basePath = '',
    ) {}

    public function ask(): SetupPlan
    {
        $basePath = $this->basePath !== '' ? $this->basePath : dirname(__DIR__, 2);

        intro('Laravel Starter Kit setup');
        note('Runs on the host. Configures .env, Compose DB profile, Vue SPA layout, Supervisor, and feature flags.');

        $appName = text(
            label: 'Application name',
            default: 'Laravel Starter Kit',
            required: true,
        );

        $httpPort = (int) text(
            label: 'Local HTTP port (host, redirects to HTTPS)',
            default: '80',
            required: true,
            validate: fn (string $value): ?string => ctype_digit($value) && (int) $value > 0
                ? null
                : 'Enter a positive integer port.',
        );

        $httpsPort = (int) text(
            label: 'Local HTTPS port (host)',
            default: '443',
            required: true,
            validate: fn (string $value): ?string => ctype_digit($value) && (int) $value > 0
                ? null
                : 'Enter a positive integer port.',
        );

        $defaultUrl = $httpsPort === 443
            ? 'https://localhost'
            : "https://localhost:{$httpsPort}";

        $appUrl = text(
            label: 'Application URL (use localhost so mkcert matches)',
            default: $defaultUrl,
            required: true,
        );

        $database = DatabaseEngine::from(select(
            label: 'Database engine (starts the matching Compose profile only)',
            options: [
                DatabaseEngine::Mysql->value => DatabaseEngine::Mysql->label(),
                DatabaseEngine::Pgsql->value => DatabaseEngine::Pgsql->label(),
            ],
            default: DatabaseEngine::Mysql->value,
        ));

        $auth = AuthStack::from(select(
            label: 'Authentication stack',
            options: [
                AuthStack::Passport->value          => AuthStack::Passport->label(),
                AuthStack::PassportAuthentik->value => AuthStack::PassportAuthentik->label(),
                AuthStack::Session->value           => AuthStack::Session->label(),
            ],
            default: AuthStack::Passport->value,
        ));

        $layout = AppLayout::from(select(
            label: 'Vue SPA layout',
            options: [
                AppLayout::Monolith->value => AppLayout::Monolith->label(),
                AppLayout::Separate->value => AppLayout::Separate->label(),
            ],
            default: AppLayout::Monolith->value,
        ));
        note(
            $layout === AppLayout::Separate
                ? 'A sibling folder named {project}-frontend will be created next to this repository.'
                : 'Vue will be installed under frontend/ in this repository.',
        );

        $existingFrontendAction = ExistingFrontendAction::Keep;
        $target                 = $this->vueTargetPath($basePath, $layout);
        if (is_dir($target) === true && is_file($target . '/package.json') === true) {
            note('Existing Vue frontend found at: ' . $target);
            $existingFrontendAction = ExistingFrontendAction::from(select(
                label: 'Existing frontend tree — what should setup do?',
                options: ExistingFrontendAction::promptOptions(),
                default: ExistingFrontendAction::Keep->value,
            ));
        }

        $enableSentry      = confirm('Enable Sentry configuration placeholders?', default: true);
        $enableTelescope   = confirm('Enable Laravel Telescope by default?', default: false);
        $enableDebugbar    = confirm('Enable Debugbar by default?', default: false);
        $enableQueueWorker = confirm('Autostart Redis queue worker in Supervisor?', default: true);
        $enableScheduler   = confirm('Autostart Laravel scheduler in Supervisor?', default: true);
        $enableAutoMigrate = confirm('Autostart migrate-on-boot Supervisor task?', default: true);
        $keepSampleApi     = confirm('Keep sample /api/system/status endpoint?', default: true);

        $existingKey = '';
        if (is_file($basePath . '/.env') === true) {
            $existingKey = trim((string) (new EnvFileEditor($basePath . '/.env'))->get('APP_KEY'));
        }
        if ($existingKey !== '') {
            note('Keeping existing APP_KEY.');
            $generateAppKey = false;
        } else {
            $generateAppKey = confirm('Generate APP_KEY now?', default: true);
        }

        $runYarnInstall = confirm('Run yarn install for the Vue SPA now (via Docker)?', default: true);

        return new SetupPlan(
            appName: $appName,
            appUrl: $appUrl,
            httpPort: $httpPort,
            httpsPort: $httpsPort,
            database: $database,
            auth: $auth,
            layout: $layout,
            enableSentry: $enableSentry,
            enableTelescope: $enableTelescope,
            enableDebugbar: $enableDebugbar,
            enableQueueWorker: $enableQueueWorker,
            enableScheduler: $enableScheduler,
            enableAutoMigrate: $enableAutoMigrate,
            keepSampleApi: $keepSampleApi,
            generateAppKey: $generateAppKey,
            runYarnInstall: $runYarnInstall,
            existingFrontendAction: $existingFrontendAction,
        );
    }

    private function vueTargetPath(string $basePath, AppLayout $layout): string
    {
        if ($layout === AppLayout::Separate) {
            return dirname($basePath) . '/' . basename($basePath) . '-frontend';
        }

        return $basePath . '/frontend';
    }
}
