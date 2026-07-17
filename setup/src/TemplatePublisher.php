<?php

declare(strict_types=1);

namespace StarterKit\Setup;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Copies a template directory into a destination, replacing {{PLACEHOLDER}} tokens.
 */
final class TemplatePublisher
{
    /**
     * @param  array<string, string>  $replacements
     */
    public function publish(string $templateDir, string $destinationDir, array $replacements): void
    {
        if (is_dir($templateDir) === false) {
            throw new RuntimeException("Template directory missing: {$templateDir}");
        }

        if (is_dir($destinationDir) === false && mkdir($destinationDir, 0775, true) === false) {
            throw new RuntimeException("Unable to create {$destinationDir}");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($templateDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen(rtrim($templateDir, '/')) + 1);
            $target   = rtrim($destinationDir, '/') . '/' . $relative;

            if ($item->isDir() === true) {
                if (is_dir($target) === false && mkdir($target, 0775, true) === false) {
                    throw new RuntimeException("Unable to create {$target}");
                }

                continue;
            }

            $parent = dirname($target);
            if (is_dir($parent) === false && mkdir($parent, 0775, true) === false) {
                throw new RuntimeException("Unable to create {$parent}");
            }

            $contents = file_get_contents($item->getPathname());
            if ($contents === false) {
                throw new RuntimeException('Unable to read ' . $item->getPathname());
            }

            $contents = strtr($contents, $replacements);

            if (file_put_contents($target, $contents) === false) {
                throw new RuntimeException("Unable to write {$target}");
            }

            if ($item->isExecutable() === true) {
                chmod($target, 0755);
            }
        }
    }
}
