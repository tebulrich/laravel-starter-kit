<?php

declare(strict_types=1);

namespace StarterKit\Setup;

use InvalidArgumentException;

/**
 * Parses argv for the host-side setup CLI (no Artisan).
 */
final class SetupCli
{
    /**
     * @param  list<string>  $argv
     */
    public function __construct(
        private readonly array $argv,
    ) {}

    public function wantsHelp(): bool
    {
        return in_array('--help', $this->argv, true) || in_array('-h', $this->argv, true);
    }

    public function isDryRun(): bool
    {
        return in_array('--dry-run', $this->argv, true);
    }

    public function isNonInteractive(): bool
    {
        return in_array('--no-interaction', $this->argv, true) || in_array('-n', $this->argv, true);
    }

    public function helpText(): string
    {
        return <<<'TXT'
Usage: bin/setup [options]

Host-side configurator (runs outside Docker). Sets .env, COMPOSE_PROFILES,
Vue SPA scaffold (monolith or separate), Supervisor autostart flags, and config/starter.php.

Options:
  --name=NAME              Application name
  --url=URL                Application URL (default https://localhost)
  --port=80                Host HTTP port (redirects to HTTPS)
  --https-port=443         Host HTTPS port
  --database=mysql|pgsql   Database engine / Compose profile
  --auth=passport|passport_authentik|session
  --layout=monolith|separate  Vue SPA layout (default: monolith)
  --sentry / --no-sentry
  --telescope / --no-telescope
  --debugbar / --no-debugbar
  --queue-worker / --no-queue-worker
  --scheduler / --no-scheduler
  --auto-migrate / --no-auto-migrate
  --sample-api / --no-sample-api
  --generate-key / --no-generate-key
                           Fill APP_KEY only when it is empty (never rotates an existing key)
  --yarn-install / --no-yarn-install
                           Install frontend deps via Docker yarn (default: on; needs Docker)
  --on-existing-frontend=keep|backup|delete|abort
                           When a Vue frontend tree already exists (default: keep)
  --force-frontend         Alias for --on-existing-frontend=delete
  --no-interaction, -n     Use flags/defaults (no prompts)
  --dry-run                Print actions without writing files
  --help, -h               Show this help

Notes:
  - Frontend is always a Vue SPA (Vite + TypeScript + Tailwind).
  - Monolith uses frontend/ in this repository; separate creates ../{project}-frontend with its own CI.
  - Re-running setup with an existing Vue tree: keep / backup / delete / abort.
TXT;
    }

    public function planFromOptions(): SetupPlan
    {
        $database = DatabaseEngine::tryFrom($this->optionValue('database', 'mysql'));
        $auth     = AuthStack::tryFrom($this->optionValue('auth', 'passport'));
        $layout   = AppLayout::tryFrom($this->optionValue('layout', 'monolith'));

        if ($database === null) {
            throw new InvalidArgumentException('Invalid --database. Use mysql or pgsql.');
        }

        if ($auth === null) {
            throw new InvalidArgumentException('Invalid --auth. Use passport, passport_authentik, or session.');
        }

        if ($layout === null) {
            throw new InvalidArgumentException('Invalid --layout. Use monolith or separate.');
        }

        $httpPort   = (int) $this->optionValue('port', '80');
        $httpsPort  = (int) $this->optionValue('https-port', '443');
        $name       = $this->optionValue('name', 'Laravel Starter Kit');
        $defaultUrl = $httpsPort === 443
            ? 'https://localhost'
            : "https://localhost:{$httpsPort}";
        $url = $this->optionValue('url', $defaultUrl);

        return new SetupPlan(
            appName: $name,
            appUrl: $url,
            httpPort: $httpPort   > 0 ? $httpPort : 80,
            httpsPort: $httpsPort > 0 ? $httpsPort : 443,
            database: $database,
            auth: $auth,
            layout: $layout,
            enableSentry: $this->flagEnabled('sentry', default: true),
            enableTelescope: $this->flagEnabled('telescope', default: false),
            enableDebugbar: $this->flagEnabled('debugbar', default: false),
            enableQueueWorker: $this->flagEnabled('queue-worker', default: true),
            enableScheduler: $this->flagEnabled('scheduler', default: true),
            enableAutoMigrate: $this->flagEnabled('auto-migrate', default: true),
            keepSampleApi: $this->flagEnabled('sample-api', default: true),
            generateAppKey: $this->flagEnabled('generate-key', default: true),
            runYarnInstall: $this->flagEnabled('yarn-install', default: true),
            existingFrontendAction: $this->existingFrontendActionFromOptions(),
        );
    }

    public function existingFrontendActionFromOptions(): ExistingFrontendAction
    {
        if (in_array('--force-frontend', $this->argv, true) === true) {
            return ExistingFrontendAction::Delete;
        }

        $raw    = $this->optionValue('on-existing-frontend', ExistingFrontendAction::Keep->value);
        $action = ExistingFrontendAction::tryFrom($raw);

        if ($action === null) {
            throw new InvalidArgumentException(
                'Invalid --on-existing-frontend. Use keep, backup, delete, or abort.'
            );
        }

        return $action;
    }

    private function flagEnabled(string $name, bool $default): bool
    {
        if (in_array('--no-' . $name, $this->argv, true) === true) {
            return false;
        }

        if (in_array('--' . $name, $this->argv, true) === true) {
            return true;
        }

        return $default;
    }

    private function optionValue(string $name, string $default): string
    {
        $prefix = '--' . $name . '=';

        foreach ($this->argv as $arg) {
            if (str_starts_with($arg, $prefix) === true) {
                return substr($arg, strlen($prefix));
            }
        }

        return $default;
    }
}
