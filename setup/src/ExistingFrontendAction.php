<?php

declare(strict_types=1);

namespace StarterKit\Setup;

/**
 * What to do when a Vue frontend tree already exists on disk.
 */
enum ExistingFrontendAction: string
{
    case Keep   = 'keep';
    case Backup = 'backup';
    case Delete = 'delete';
    case Abort  = 'abort';

    public function label(): string
    {
        return match ($this) {
            self::Keep   => 'Keep existing tree (reuse; skip scaffold)',
            self::Backup => 'Rename existing tree to *-backup-<timestamp>, then scaffold fresh',
            self::Delete => 'Delete existing tree, then scaffold fresh',
            self::Abort  => 'Abort setup (leave disk unchanged)',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function promptOptions(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
