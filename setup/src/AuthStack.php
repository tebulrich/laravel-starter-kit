<?php

declare(strict_types=1);

namespace StarterKit\Setup;

enum AuthStack: string
{
    case Passport          = 'passport';
    case PassportAuthentik = 'passport_authentik';
    case Session           = 'session';

    public function label(): string
    {
        return match ($this) {
            self::Passport          => 'Laravel Passport (API tokens)',
            self::PassportAuthentik => 'Passport + Authentik (OIDC/JWT placeholders)',
            self::Session           => 'Session guard only (no API token routes)',
        };
    }

    public function authProvider(): string
    {
        return match ($this) {
            self::PassportAuthentik       => 'authentik',
            self::Passport, self::Session => 'native',
        };
    }

    public function usesPassport(): bool
    {
        return $this !== self::Session;
    }
}
