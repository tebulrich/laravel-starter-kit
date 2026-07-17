<?php

declare(strict_types=1);

namespace StarterKit\Setup;

/**
 * Runs commands via PHP exec() and captures stdout/stderr lines.
 */
final class ProcShellExecutor implements ShellExecutor
{
    /**
     * @return array{exit: int, output: list<string>}
     */
    public function run(string $command): array
    {
        $output = [];
        $exit   = 0;
        exec($command . ' 2>&1', $output, $exit);

        /** @var list<string> $output */
        return [
            'exit'   => $exit,
            'output' => $output,
        ];
    }
}
