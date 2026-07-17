<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Rotate Laravel logs using logrotate')]
#[Signature('logs:rotate')]
class LogsRotateCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Rotating logs...');

        $output = [];
        $code   = 0;
        exec(
            '/usr/sbin/logrotate /etc/logrotate.d/laravel-log-rotate --state /var/lib/logrotate/status',
            $output,
            $code,
        );

        if ($code !== 0) {
            $this->error('logrotate failed (exit ' . $code . ').');

            return self::FAILURE;
        }

        $this->info('Logs have been rotated.');

        return self::SUCCESS;
    }
}
