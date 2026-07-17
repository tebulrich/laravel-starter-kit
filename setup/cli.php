#!/usr/bin/env php
<?php

declare(strict_types=1);

use StarterKit\Setup\EnvFileEditor;
use StarterKit\Setup\SetupAbortedException;
use StarterKit\Setup\SetupApplier;
use StarterKit\Setup\SetupCli;
use StarterKit\Setup\SetupWizard;
use StarterKit\Setup\SupervisorAutostartTuner;

use function Laravel\Prompts\outro;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$cli = new SetupCli(array_slice($argv, 1));

if ($cli->wantsHelp() === true) {
    fwrite(STDOUT, $cli->helpText() . "\n");
    exit(0);
}

try {
    $plan = $cli->isNonInteractive() === true
        ? $cli->planFromOptions()
        : (new SetupWizard($root))->ask();
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

$dryRun = $cli->isDryRun();

$applier = new SetupApplier(
    basePath: $root,
    env: new EnvFileEditor($root . '/.env'),
    supervisor: new SupervisorAutostartTuner($root . '/docker/configs/supervisor'),
);

try {
    $actions = $applier->apply($plan, dryRun: $dryRun);
} catch (SetupAbortedException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "\n" . ($dryRun === true ? "Dry run — planned actions:\n" : "Applied:\n"));
foreach ($actions as $action) {
    fwrite(STDOUT, ' - ' . $action . "\n");
}

if ($dryRun === false) {
    outro('Setup complete. Start the stack with ./start.sh when ready.');
}

exit(0);
