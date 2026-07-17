<?php

declare(strict_types=1);

namespace StarterKit\Setup;

use RuntimeException;

/**
 * Sets autostart=true|false on Supervisor program files without rewriting the whole unit.
 */
final class SupervisorAutostartTuner
{
    public function __construct(
        private readonly string $supervisorDirectory,
    ) {}

    public function setAutostart(string $relativeConfName, bool $enabled): void
    {
        $path = rtrim($this->supervisorDirectory, '/') . '/' . $relativeConfName;

        if (is_file($path) === false) {
            throw new RuntimeException("Supervisor config not found: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read {$path}");
        }

        $value = $enabled === true ? 'true' : 'false';

        if (preg_match('/^autostart\s*=\s*.*$/m', $contents) === 1) {
            $contents = preg_replace('/^autostart\s*=\s*.*$/m', 'autostart=' . $value, $contents, 1);
        } else {
            $contents = rtrim($contents) . "\nautostart={$value}\n";
        }

        if ($contents === null || file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Unable to update {$path}");
        }
    }
}
