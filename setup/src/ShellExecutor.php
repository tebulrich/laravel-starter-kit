<?php

declare(strict_types=1);

namespace StarterKit\Setup;

/**
 * Thin wrapper around shell execution so Docker yarn can be unit-tested.
 *
 * @phpstan-type ShellResult array{exit: int, output: list<string>}
 */
interface ShellExecutor
{
    /**
     * @return array{exit: int, output: list<string>}
     */
    public function run(string $command): array;
}
