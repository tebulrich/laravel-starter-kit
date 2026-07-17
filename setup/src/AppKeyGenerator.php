<?php

declare(strict_types=1);

namespace StarterKit\Setup;

/**
 * Generates a Laravel APP_KEY without bootstrapping Artisan.
 */
final class AppKeyGenerator
{
    public function generate(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }
}
