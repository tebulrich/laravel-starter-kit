<?php

declare(strict_types=1);

namespace StarterKit\Setup;

/**
 * Immutable result of the host-side setup wizard (interactive or CLI flags).
 *
 * Frontend is always a Vue SPA; layout chooses monolith (`frontend/`) or separate (`*-frontend`).
 */
final readonly class SetupPlan
{
    public function __construct(
        public string $appName,
        public string $appUrl,
        public int $httpPort,
        public int $httpsPort,
        public DatabaseEngine $database,
        public AuthStack $auth,
        public AppLayout $layout,
        public bool $enableSentry,
        public bool $enableTelescope,
        public bool $enableDebugbar,
        public bool $enableQueueWorker,
        public bool $enableScheduler,
        public bool $enableAutoMigrate,
        public bool $keepSampleApi,
        public bool $generateAppKey,
        public bool $runYarnInstall = true,
        public ExistingFrontendAction $existingFrontendAction = ExistingFrontendAction::Keep,
    ) {}

    /**
     * Public HTTPS origin for browsers (mkcert covers localhost, not 127.0.0.1).
     */
    public function publicHttpsOrigin(): string
    {
        if ($this->httpsPort === 443) {
            return 'https://localhost';
        }

        return 'https://localhost:' . $this->httpsPort;
    }

    /**
     * @return array{
     *     app_name: string,
     *     app_url: string,
     *     http_port: int,
     *     https_port: int,
     *     database: string,
     *     compose_profile: string,
     *     auth: string,
     *     layout: string,
     *     sentry: bool,
     *     telescope: bool,
     *     debugbar: bool,
     *     queue_worker: bool,
     *     scheduler: bool,
     *     auto_migrate: bool,
     *     sample_api: bool,
     *     generate_app_key: bool,
     *     yarn_install: bool,
     *     existing_frontend_action: string
     * }
     */
    public function toManifest(): array
    {
        return [
            'app_name'                 => $this->appName,
            'app_url'                  => $this->appUrl,
            'http_port'                => $this->httpPort,
            'https_port'               => $this->httpsPort,
            'database'                 => $this->database->value,
            'compose_profile'          => $this->database->composeProfile(),
            'auth'                     => $this->auth->value,
            'layout'                   => $this->layout->value,
            'sentry'                   => $this->enableSentry,
            'telescope'                => $this->enableTelescope,
            'debugbar'                 => $this->enableDebugbar,
            'queue_worker'             => $this->enableQueueWorker,
            'scheduler'                => $this->enableScheduler,
            'auto_migrate'             => $this->enableAutoMigrate,
            'sample_api'               => $this->keepSampleApi,
            'generate_app_key'         => $this->generateAppKey,
            'yarn_install'             => $this->runYarnInstall,
            'existing_frontend_action' => $this->existingFrontendAction->value,
        ];
    }
}
