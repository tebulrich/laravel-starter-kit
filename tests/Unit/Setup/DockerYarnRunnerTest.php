<?php

declare(strict_types=1);

namespace Tests\Unit\Setup;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StarterKit\Setup\DockerYarnRunner;
use StarterKit\Setup\ShellExecutor;

final class DockerYarnRunnerTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir() . '/starter-yarn-' . uniqid('', true);
        mkdir($this->workspace, 0775, true);
        file_put_contents($this->workspace . '/package.json', "{\"private\":true}\n");
        file_put_contents($this->workspace . '/compose.yaml', "services:\n  php:\n    image: laravel-starter-kit:8.5\n");
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->workspace);
        parent::tearDown();
    }

    public function test_install_uses_docker_run_with_project_image_when_php_is_not_running(): void
    {
        $commands = [];
        $executor = new class($commands) implements ShellExecutor
        {
            /**
             * @param  list<string>  $commands
             */
            public function __construct(private array &$commands) {}

            public function run(string $command): array
            {
                $this->commands[] = $command;

                if (str_contains($command, 'command -v docker') === true) {
                    return ['exit' => 0, 'output' => ['/usr/bin/docker']];
                }

                if (str_contains($command, 'docker compose') === true && str_contains($command, 'ps ') === true) {
                    return ['exit' => 0, 'output' => []];
                }

                if (str_contains($command, 'docker image inspect') === true) {
                    return ['exit' => 0, 'output' => ['ok']];
                }

                if (str_contains($command, 'command -v yarn') === true) {
                    return ['exit' => 0, 'output' => ['/usr/bin/yarn']];
                }

                if (str_contains($command, 'docker run') === true) {
                    return ['exit' => 0, 'output' => ['Done']];
                }

                return ['exit' => 1, 'output' => ['unexpected: ' . $command]];
            }
        };

        $runner = new DockerYarnRunner($this->workspace, $executor);
        $runner->install($this->workspace);

        $joined = implode("\n", $commands);
        $this->assertStringContainsString('docker run --rm', $joined);
        $this->assertStringContainsString('laravel-starter-kit:8.5', $joined);
        $this->assertStringContainsString('yarn install', $joined);
        $this->assertStringContainsString('-w /work', $joined);
        $this->assertStringNotContainsString('&& yarn install', $joined);
    }

    public function test_install_uses_compose_exec_when_php_is_running_and_path_is_in_project(): void
    {
        $commands = [];
        $executor = new class($commands) implements ShellExecutor
        {
            /**
             * @param  list<string>  $commands
             */
            public function __construct(private array &$commands) {}

            public function run(string $command): array
            {
                $this->commands[] = $command;

                if (str_contains($command, 'command -v docker') === true) {
                    return ['exit' => 0, 'output' => ['/usr/bin/docker']];
                }

                if (str_contains($command, 'docker compose') === true && str_contains($command, 'ps ') === true) {
                    return ['exit' => 0, 'output' => ['laravel-starter-kit-php-1']];
                }

                if (str_contains($command, 'command -v yarn') === true) {
                    return ['exit' => 0, 'output' => ['/usr/bin/yarn']];
                }

                if (str_contains($command, 'docker compose') === true && str_contains($command, 'exec ') === true) {
                    return ['exit' => 0, 'output' => ['Done']];
                }

                return ['exit' => 1, 'output' => ['unexpected: ' . $command]];
            }
        };

        $runner = new DockerYarnRunner($this->workspace, $executor);
        $runner->install($this->workspace);

        $joined = implode("\n", $commands);
        $this->assertStringContainsString('docker compose', $joined);
        $this->assertStringContainsString('exec -T', $joined);
        $this->assertStringContainsString('/var/www/html', $joined);
        $this->assertStringContainsString('yarn install', $joined);
        $this->assertStringNotContainsString('docker run', $joined);
    }

    public function test_sibling_directory_always_uses_docker_run(): void
    {
        $sibling = $this->workspace . '-frontend';
        mkdir($sibling, 0775, true);
        file_put_contents($sibling . '/package.json', "{\"private\":true}\n");

        $commands = [];
        $executor = new class($commands) implements ShellExecutor
        {
            /**
             * @param  list<string>  $commands
             */
            public function __construct(private array &$commands) {}

            public function run(string $command): array
            {
                $this->commands[] = $command;

                if (str_contains($command, 'command -v docker') === true) {
                    return ['exit' => 0, 'output' => ['/usr/bin/docker']];
                }

                if (str_contains($command, 'docker compose') === true && str_contains($command, 'ps ') === true) {
                    return ['exit' => 0, 'output' => ['laravel-starter-kit-php-1']];
                }

                if (str_contains($command, 'docker image inspect') === true) {
                    return ['exit' => 0, 'output' => ['ok']];
                }

                if (str_contains($command, 'command -v yarn') === true) {
                    return ['exit' => 0, 'output' => ['/usr/bin/yarn']];
                }

                if (str_contains($command, 'docker run') === true) {
                    return ['exit' => 0, 'output' => ['Done']];
                }

                return ['exit' => 1, 'output' => ['unexpected: ' . $command]];
            }
        };

        $runner = new DockerYarnRunner($this->workspace, $executor);
        $runner->install($sibling);

        $joined = implode("\n", $commands);
        $this->assertStringContainsString('docker run --rm', $joined);
        $this->assertStringContainsString(escapeshellarg($sibling), $joined);
        $this->assertStringNotContainsString('docker compose exec', $joined);

        $this->deleteTree($sibling);
    }

    public function test_missing_package_json_fails_before_docker(): void
    {
        $executor = new class implements ShellExecutor
        {
            public function run(string $command): array
            {
                self::fail('Docker must not be invoked without package.json');
            }
        };

        $runner = new DockerYarnRunner($this->workspace, $executor);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('package.json missing');
        $runner->install($this->workspace . '/missing');
    }

    /**
     * @param  non-empty-string  $path
     */
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
