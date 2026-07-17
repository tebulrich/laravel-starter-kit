<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\SimplifyBoolIdenticalTrueRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\Set\ValueObject\SetList;
use Rector\ValueObject\PhpVersion;
use RectorLaravel\Set\LaravelSetList;

return static function (RectorConfig $rectorConfig): void {
    // Define which paths should be analyzed
    $rectorConfig->paths([
        dirname(__DIR__) . '/app',
        dirname(__DIR__) . '/config',
        dirname(__DIR__) . '/routes',
        dirname(__DIR__) . '/database',
        dirname(__DIR__) . '/setup/src',
        dirname(__DIR__) . '/tests',
    ]);

    // Exclude docker folder or any other folders with restricted permissions
    $rectorConfig->skip([
        dirname(__DIR__) . '/docker',
        dirname(__DIR__) . '/vendor',
        dirname(__DIR__) . '/storage',
        dirname(__DIR__) . '/bootstrap/cache',
        RemoveUselessReturnTagRector::class,
        // House rule: keep explicit === true / === false for boolean flags.
        SimplifyBoolIdenticalTrueRector::class,
    ]);

    // Set target PHP version
    $rectorConfig->phpVersion(PhpVersion::PHP_85);

    // Register Laravel-specific rules
    $rectorConfig->import(LaravelSetList::LARAVEL_130);
    $rectorConfig->import(LaravelSetList::LARAVEL_CODE_QUALITY);

    // Register general PHP upgrade and clean code rules
    $rectorConfig->import(SetList::PHP_85);
    $rectorConfig->import(SetList::CODE_QUALITY);
    $rectorConfig->import(SetList::DEAD_CODE);
    $rectorConfig->import(SetList::TYPE_DECLARATION);
    $rectorConfig->import(SetList::PRIVATIZATION);
    $rectorConfig->import(SetList::EARLY_RETURN);
};
