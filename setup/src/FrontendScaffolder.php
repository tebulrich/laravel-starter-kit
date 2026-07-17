<?php

declare(strict_types=1);

namespace StarterKit\Setup;

use RuntimeException;

/**
 * Scaffolds the Vue SPA (monolith `frontend/` or sibling `*-frontend`).
 *
 * Frontend dependency install uses Docker yarn (host Node/yarn is not required).
 * Existing Vue trees are handled via ExistingFrontendAction (keep / backup / delete / abort).
 */
final class FrontendScaffolder
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $templatesRoot,
        private readonly TemplatePublisher $publisher = new TemplatePublisher,
        private readonly ?DockerYarnRunner $yarn = null,
    ) {}

    /**
     * Absolute path where the Vue SPA should live for this plan.
     */
    public function targetPath(SetupPlan $plan): string
    {
        if ($plan->layout === AppLayout::Separate) {
            return dirname($this->basePath) . '/' . basename($this->basePath) . '-frontend';
        }

        return $this->basePath . '/frontend';
    }

    public function frontendExists(string $target): bool
    {
        return is_dir($target) === true && is_file($target . '/package.json') === true;
    }

    /**
     * @return list<string>
     *
     * @throws SetupAbortedException
     */
    public function scaffold(SetupPlan $plan, bool $dryRun = false): array
    {
        $actions = [];
        $target  = $this->targetPath($plan);
        $layout  = $plan->layout;
        $exists  = $this->frontendExists($target);

        if ($exists === true) {
            $actions = [
                ...$actions,
                ...$this->resolveExistingFrontend($plan, $target, $dryRun),
            ];

            if ($plan->existingFrontendAction === ExistingFrontendAction::Keep) {
                if ($dryRun === false) {
                    $this->updateBackendForVue($plan, $layout);
                }

                if ($plan->runYarnInstall === true) {
                    $actions[] = 'Run yarn install in ' . $target . ' via Docker';
                    if ($dryRun === false) {
                        $this->yarnRunner()->install($target);
                    }
                }

                return $actions;
            }
        }

        if ($layout === AppLayout::Separate) {
            $actions[] = 'Scaffold Vue SPA in sibling directory: ' . $target;
            $actions[] = 'Write GitHub + GitLab CI for the separate frontend repository';
        } else {
            $actions[] = 'Scaffold Vue SPA under frontend/ (monolith)';
        }

        if ($dryRun === false) {
            $this->publishVue($plan, $target);
            $this->updateBackendForVue($plan, $layout);
        }

        if ($plan->runYarnInstall === true) {
            $actions[] = 'Run yarn install in ' . $target . ' via Docker';
            if ($dryRun === false) {
                $this->yarnRunner()->install($target);
            }
        }

        return $actions;
    }

    /**
     * @return list<string>
     *
     * @throws SetupAbortedException
     */
    private function resolveExistingFrontend(SetupPlan $plan, string $target, bool $dryRun): array
    {
        $action = $plan->existingFrontendAction;

        return match ($action) {
            ExistingFrontendAction::Keep => [
                'Keep existing frontend at ' . $target . ' (skip scaffold)',
            ],
            ExistingFrontendAction::Backup => $this->backupExisting($target, $dryRun),
            ExistingFrontendAction::Delete => $this->deleteExisting($target, $dryRun),
            ExistingFrontendAction::Abort  => throw new SetupAbortedException(
                'Setup aborted: frontend already exists at ' . $target
                . '. Re-run with --on-existing-frontend=keep|backup|delete or choose an option interactively.'
            ),
        };
    }

    /**
     * @return list<string>
     */
    private function backupExisting(string $target, bool $dryRun): array
    {
        $backup = rtrim($target, '/') . '-backup-' . gmdate('Ymd\THis\Z');

        if ($dryRun === false) {
            if (rename($target, $backup) === false) {
                throw new RuntimeException("Unable to rename {$target} to {$backup}");
            }
        }

        return [
            'Rename existing frontend ' . $target . ' → ' . $backup,
        ];
    }

    /**
     * @return list<string>
     */
    private function deleteExisting(string $target, bool $dryRun): array
    {
        if ($dryRun === false) {
            $this->deleteTree($target);
        }

        return [
            'Delete existing frontend at ' . $target,
        ];
    }

    private function yarnRunner(): DockerYarnRunner
    {
        return $this->yarn ?? new DockerYarnRunner($this->basePath);
    }

    private function publishVue(SetupPlan $plan, string $target): void
    {
        $this->publisher->publish(
            $this->templatesRoot . '/vue-spa',
            $target,
            $this->replacements($plan, $target),
        );

        if ($plan->layout === AppLayout::Separate) {
            $this->publisher->publish(
                $this->templatesRoot . '/vue-spa-ci',
                $target,
                $this->replacements($plan, $target),
            );
        }

        $envExample = $target . '/.env.example';
        $envFile    = $target . '/.env';
        if (is_file($envExample) === true && is_file($envFile) === false && copy($envExample, $envFile) === false) {
            throw new RuntimeException("Unable to create {$envFile}");
        }
    }

    private function updateBackendForVue(SetupPlan $plan, AppLayout $layout): void
    {
        $origins = [
            $plan->publicHttpsOrigin(),
            'http://127.0.0.1:5173',
            'http://localhost:5173',
            'https://127.0.0.1:5173',
            'https://localhost:5173',
        ];

        $env = new EnvFileEditor($this->basePath . '/.env');
        if (is_file($this->basePath . '/.env') === true) {
            $env->set('CORS_ALLOWED_ORIGINS', implode(',', $origins));
            $env->set('STARTER_FRONTEND_LAYOUT', $layout->value);
            if ($layout === AppLayout::Separate) {
                $env->set('STARTER_FRONTEND_PATH', $this->targetPath($plan));
            } else {
                $env->set('STARTER_FRONTEND_PATH', 'frontend');
            }
        }

        $example = new EnvFileEditor($this->basePath . '/.env.example');
        if (is_file($this->basePath . '/.env.example') === true) {
            $example->set('CORS_ALLOWED_ORIGINS', implode(',', $origins));
            $example->set('STARTER_FRONTEND_LAYOUT', $layout->value);
        }
    }

    /**
     * @return array<string, string>
     */
    private function replacements(SetupPlan $plan, string $target): array
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', basename($target)));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'frontend';
        }

        return [
            '{{APP_NAME}}'        => $plan->appName,
            '{{PACKAGE_NAME}}'    => $slug,
            '{{API_BASE_URL}}'    => rtrim($plan->appUrl, '/'),
            '{{API_PROXY}}'       => $plan->publicHttpsOrigin(),
            '{{HTTP_PORT}}'       => (string) $plan->httpPort,
            '{{HTTPS_PORT}}'      => (string) $plan->httpsPort,
            '{{VITE_PORT}}'       => '5173',
            '{{COMPOSE_NETWORK}}' => basename($this->basePath) . '_app',
        ];
    }

    private function deleteTree(string $path): void
    {
        if (is_dir($path) === false) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            throw new RuntimeException("Unable to list {$path}");
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . '/' . $item;
            if (is_dir($full) === true) {
                $this->deleteTree($full);
            } elseif (unlink($full) === false) {
                throw new RuntimeException("Unable to delete {$full}");
            }
        }

        if (rmdir($path) === false) {
            throw new RuntimeException("Unable to remove directory {$path}");
        }
    }
}
