<?php

declare(strict_types=1);

namespace StarterKit\Setup;

use RuntimeException;

/**
 * Runs yarn inside Docker so the host does not need Node/yarn.
 *
 * Preference order:
 * 1. `docker compose exec` into the running php service when the target is under the project mount
 * 2. `docker run` with the project PHP image (includes yarn) mounting the target directory
 * 3. `docker run` with node:24-alpine + corepack when the project image is not built yet.
 */
final class DockerYarnRunner
{
    private const CONTAINER_APP_ROOT = '/var/www/html';

    private const PROJECT_IMAGE = 'laravel-starter-kit:8.5';

    private const FALLBACK_IMAGE = 'node:24-alpine';

    public function __construct(
        private readonly string $projectRoot,
        private readonly ShellExecutor $shell = new ProcShellExecutor,
    ) {}

    public function install(string $directory): void
    {
        $this->yarn($directory, 'install');
    }

    public function script(string $directory, string $script): void
    {
        $this->yarn($directory, 'run ' . $script);
    }

    public function yarn(string $directory, string $args): void
    {
        $absolute = $this->absoluteDirectory($directory);

        if (is_file($absolute . '/package.json') === false) {
            throw new RuntimeException("package.json missing in {$absolute}");
        }

        if ($this->dockerAvailable() === false) {
            throw new RuntimeException(
                'Docker is required to run yarn for frontend dependencies. Start Docker, then re-run setup.'
            );
        }

        if (
            $this->phpServiceIsRunning()            === true
            && $this->isUnderProjectRoot($absolute) === true
            && $this->phpContainerHasYarn()         === true
        ) {
            $this->composeExec($absolute, $args);

            return;
        }

        $this->dockerRun($absolute, $args);
    }

    private function phpContainerHasYarn(): bool
    {
        $result = $this->shell->run(
            'cd ' . escapeshellarg($this->projectRoot)
            . " && docker compose exec -T php sh -lc 'command -v yarn'"
        );

        return $result['exit'] === 0;
    }

    private function composeExec(string $absoluteDirectory, string $args): void
    {
        $workdir = $this->containerWorkdir($absoluteDirectory);
        $command = sprintf(
            'cd %s && docker compose exec -T -w %s php yarn %s',
            escapeshellarg($this->projectRoot),
            escapeshellarg($workdir),
            $args
        );

        $result = $this->shell->run($command);
        if ($result['exit'] !== 0) {
            throw new RuntimeException(
                "yarn {$args} failed in php container ({$workdir}) (exit {$result['exit']}):\n"
                . implode("\n", $result['output'])
            );
        }
    }

    private function dockerRun(string $absoluteDirectory, string $args): void
    {
        $uid   = function_exists('posix_geteuid') === true ? posix_geteuid() : 1000;
        $gid   = function_exists('posix_getegid') === true ? posix_getegid() : 1000;
        $image = $this->resolveImage();

        if ($image === self::FALLBACK_IMAGE) {
            $inner   = 'corepack enable && yarn ' . $args;
            $command = sprintf(
                'docker run --rm --user %d:%d -v %s:/work -w /work --entrypoint /bin/sh %s -lc %s',
                $uid,
                $gid,
                escapeshellarg($absoluteDirectory),
                escapeshellarg($image),
                escapeshellarg($inner)
            );
        } else {
            $command = sprintf(
                'docker run --rm --user %d:%d -v %s:/work -w /work %s yarn %s',
                $uid,
                $gid,
                escapeshellarg($absoluteDirectory),
                escapeshellarg($image),
                $args
            );
        }

        $result = $this->shell->run($command);
        if ($result['exit'] !== 0) {
            throw new RuntimeException(
                "yarn {$args} failed via docker ({$absoluteDirectory}) (exit {$result['exit']}):\n"
                . implode("\n", $result['output'])
            );
        }
    }

    private function resolveImage(): string
    {
        $inspect = $this->shell->run('docker image inspect ' . escapeshellarg(self::PROJECT_IMAGE) . ' >/dev/null');

        if ($inspect['exit'] !== 0) {
            return self::FALLBACK_IMAGE;
        }

        $hasYarn = $this->shell->run(
            'docker run --rm --entrypoint /bin/sh '
            . escapeshellarg(self::PROJECT_IMAGE)
            . " -lc 'command -v yarn'"
        );

        if ($hasYarn['exit'] === 0) {
            return self::PROJECT_IMAGE;
        }

        return self::FALLBACK_IMAGE;
    }

    private function phpServiceIsRunning(): bool
    {
        $result = $this->shell->run(
            'cd ' . escapeshellarg($this->projectRoot)
            . " && docker compose ps --status running --format '{{.Name}}' php"
        );

        if ($result['exit'] !== 0) {
            return false;
        }

        foreach ($result['output'] as $line) {
            if (trim($line) !== '') {
                return true;
            }
        }

        return false;
    }

    private function dockerAvailable(): bool
    {
        $result = $this->shell->run('command -v docker');

        return $result['exit'] === 0 && $result['output'] !== [];
    }

    private function isUnderProjectRoot(string $absoluteDirectory): bool
    {
        $root = rtrim($this->projectRoot, '/');
        $dir  = rtrim($absoluteDirectory, '/');

        return $dir === $root || str_starts_with($dir, $root . '/') === true;
    }

    private function containerWorkdir(string $absoluteDirectory): string
    {
        $root = rtrim($this->projectRoot, '/');
        $dir  = rtrim($absoluteDirectory, '/');

        if ($dir === $root) {
            return self::CONTAINER_APP_ROOT;
        }

        $relative = substr($dir, strlen($root) + 1);

        return self::CONTAINER_APP_ROOT . '/' . $relative;
    }

    private function absoluteDirectory(string $directory): string
    {
        $resolved = realpath($directory);
        if ($resolved === false) {
            return rtrim($directory, '/');
        }

        return $resolved;
    }
}
