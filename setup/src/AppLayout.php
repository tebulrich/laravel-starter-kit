<?php

declare(strict_types=1);

namespace StarterKit\Setup;

enum AppLayout: string
{
    case Monolith = 'monolith';
    case Separate = 'separate';

    public function label(): string
    {
        return match ($this) {
            self::Monolith => 'Monolith (Vue SPA under frontend/ in this repository)',
            self::Separate => 'Separate app (sibling Vue SPA at {project}-frontend)',
        };
    }
}
