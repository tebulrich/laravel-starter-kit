<?php

declare(strict_types=1);

namespace StarterKit\Setup;

enum DatabaseEngine: string
{
    case Mysql = 'mysql';
    case Pgsql = 'pgsql';

    public function label(): string
    {
        return match ($this) {
            self::Mysql => 'MySQL 8.4',
            self::Pgsql => 'PostgreSQL 18',
        };
    }

    public function defaultPort(): string
    {
        return match ($this) {
            self::Mysql => '3306',
            self::Pgsql => '5432',
        };
    }

    public function defaultHost(): string
    {
        return match ($this) {
            self::Mysql => 'mysql',
            self::Pgsql => 'pgsql',
        };
    }

    /**
     * Docker Compose profile name that starts this database service.
     */
    public function composeProfile(): string
    {
        return $this->value;
    }
}
