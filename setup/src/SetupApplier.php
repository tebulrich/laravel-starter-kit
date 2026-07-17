<?php

declare(strict_types=1);

namespace StarterKit\Setup;

use RuntimeException;

/**
 * Applies a SetupPlan on the host: .env (including COMPOSE_PROFILES), supervisor, starter config, manifest.
 *
 * Does not require a running container or Artisan bootstrap.
 */
final class SetupApplier
{
    public function __construct(
        private readonly string $basePath,
        private readonly EnvFileEditor $env,
        private readonly SupervisorAutostartTuner $supervisor,
        private readonly AppKeyGenerator $appKeyGenerator = new AppKeyGenerator,
        private readonly ?FrontendScaffolder $frontend = null,
    ) {}

    /**
     * @return list<string>
     */
    public function apply(SetupPlan $plan, bool $dryRun = false): array
    {
        $actions = [];

        $envValues = $this->envValues($plan);
        $actions[] = 'Update .env (' . count($envValues) . ' keys), including COMPOSE_PROFILES=' . $plan->database->composeProfile();

        if ($dryRun === false) {
            $this->env->ensureExistsFrom($this->basePath . '/.env.example');
            $this->env->setMany($envValues);
        }

        $actions[] = 'Write config/starter.php feature flags';
        if ($dryRun === false) {
            $this->writeStarterConfig($plan);
        }

        $actions[] = 'Write storage/app/setup-manifest.json';
        if ($dryRun === false) {
            $this->writeManifest($plan);
        }

        $actions[] = 'Tune Supervisor autostart (queue / scheduler / migrate)';
        if ($dryRun === false) {
            $this->supervisor->setAutostart('worker-laravel-queue.conf', $plan->enableQueueWorker);
            $this->supervisor->setAutostart('worker-laravel-cron.conf', $plan->enableScheduler);
            $this->supervisor->setAutostart('task-artisan-migrate.conf', $plan->enableAutoMigrate);
        }

        if ($plan->generateAppKey === true) {
            $existingKey = trim((string) $this->env->get('APP_KEY'));

            if ($existingKey !== '') {
                $actions[] = 'Keep existing APP_KEY';
            } else {
                $actions[] = 'Generate APP_KEY';
                if ($dryRun === false) {
                    $this->env->set('APP_KEY', $this->appKeyGenerator->generate());
                }
            }
        }

        if ($plan->auth->usesPassport() === true) {
            $actions[] = 'Passport enabled: oauth migrations are in database/migrations; keys are created on first migrate-on-boot when missing';
        }

        if ($plan->auth === AuthStack::PassportAuthentik) {
            $actions[] = 'Authentik placeholders written (install Authentik JWT middleware separately)';
        }

        $actions[] = 'Compose will start only the "' . $plan->database->composeProfile() . '" database profile (./start.sh)';

        $scaffolder = $this->frontend ?? new FrontendScaffolder(
            basePath: $this->basePath,
            templatesRoot: dirname(__DIR__) . '/templates',
        );
        foreach ($scaffolder->scaffold($plan, dryRun: $dryRun) as $action) {
            $actions[] = $action;
        }

        return $actions;
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    private function envValues(SetupPlan $plan): array
    {
        $values = [
            'APP_NAME'                 => $plan->appName,
            'APP_URL'                  => $plan->appUrl,
            'APP_PORT_HTTP'            => $plan->httpPort,
            'APP_PORT_HTTPS'           => $plan->httpsPort,
            'DB_CONNECTION'            => $plan->database->value,
            'DB_HOST'                  => $plan->database->defaultHost(),
            'DB_PORT'                  => $plan->database->defaultPort(),
            'FORWARD_DB_PORT'          => $plan->database->defaultPort(),
            'COMPOSE_PROFILES'         => $plan->database->composeProfile(),
            'AUTH_PROVIDER'            => $plan->auth->authProvider(),
            'STARTER_FEATURE_PASSPORT' => $plan->auth->usesPassport() === true ? 'true' : 'false',
            'TELESCOPE_ENABLED'        => $plan->enableTelescope,
            'DEBUGBAR_ENABLED'         => $plan->enableDebugbar,
            'STARTER_SAMPLE_API'       => $plan->keepSampleApi === true ? 'true' : 'false',
            'STARTER_FRONTEND_LAYOUT'  => $plan->layout->value,
            'CORS_ALLOWED_ORIGINS'     => implode(',', [
                $plan->publicHttpsOrigin(),
                'http://127.0.0.1:5173',
                'http://localhost:5173',
                'https://127.0.0.1:5173',
                'https://localhost:5173',
            ]),
        ];

        if ($plan->enableSentry === false) {
            $values['SENTRY_LARAVEL_DSN']        = '';
            $values['SENTRY_TRACES_SAMPLE_RATE'] = '0.0';
            $values['STARTER_FEATURE_SENTRY']    = 'false';
        } else {
            $values['STARTER_FEATURE_SENTRY'] = 'true';
        }

        if ($plan->auth === AuthStack::PassportAuthentik) {
            $values['STARTER_FEATURE_AUTHENTIK'] = 'true';
            $values['AUTHENTIK_URL']             = 'https://authentik.example.com';
            $values['AUTHENTIK_CLIENT_ID']       = '';
            $values['AUTHENTIK_CLIENT_SECRET']   = '';
            $values['AUTHENTIK_EMAIL_CLAIM']     = 'email';
        } else {
            $values['STARTER_FEATURE_AUTHENTIK'] = 'false';
        }

        return $values;
    }

    private function writeStarterConfig(SetupPlan $plan): void
    {
        $path      = $this->basePath . '/config/starter.php';
        $sample    = $plan->keepSampleApi          === true ? 'true' : 'false';
        $sentry    = $plan->enableSentry           === true ? 'true' : 'false';
        $passport  = $plan->auth->usesPassport()   === true ? 'true' : 'false';
        $authentik = $plan->auth                   === AuthStack::PassportAuthentik ? 'true' : 'false';
        $layout    = var_export($plan->layout->value, true);

        $contents = <<<PHP
<?php

declare(strict_types=1);

/**
 * Feature flags written by `bin/setup` (host-side wizard).
 * Prefer changing them through the setup wizard rather than hand-editing.
 */
return [
    'features' => [
        'sample_api' => (bool) env('STARTER_SAMPLE_API', {$sample}),
        'sentry' => (bool) env('STARTER_FEATURE_SENTRY', {$sentry}),
        'passport' => (bool) env('STARTER_FEATURE_PASSPORT', {$passport}),
        'authentik' => (bool) env('STARTER_FEATURE_AUTHENTIK', {$authentik}),
        'frontend_layout' => (string) env('STARTER_FRONTEND_LAYOUT', {$layout}),
    ],
];

PHP;

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Unable to write {$path}");
        }
    }

    private function writeManifest(SetupPlan $plan): void
    {
        $directory = $this->basePath . '/storage/app';

        if (is_dir($directory) === false && mkdir($directory, 0775, true) === false) {
            throw new RuntimeException("Unable to create {$directory}");
        }

        $path    = $directory . '/setup-manifest.json';
        $payload = [
            'generated_at' => gmdate('c'),
            'plan'         => $plan->toManifest(),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false || file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException("Unable to write {$path}");
        }
    }
}
