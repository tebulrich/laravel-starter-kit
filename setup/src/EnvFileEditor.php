<?php

declare(strict_types=1);

namespace StarterKit\Setup;

use RuntimeException;

/**
 * Minimal .env editor: set or insert KEY=value lines without a third-party package.
 */
final class EnvFileEditor
{
    public function __construct(
        private readonly string $path,
    ) {}

    public function ensureExistsFrom(string $examplePath): void
    {
        if (is_file($this->path) === true) {
            return;
        }

        if (is_file($examplePath) === false) {
            throw new RuntimeException("Missing env template: {$examplePath}");
        }

        if (copy($examplePath, $this->path) === false) {
            throw new RuntimeException("Unable to create {$this->path}");
        }
    }

    /**
     * @param  array<string, string|int|bool|null>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $this->stringify($value));
        }
    }

    public function set(string $key, string $value): void
    {
        if (is_file($this->path) === false) {
            throw new RuntimeException("Missing env file: {$this->path}");
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read {$this->path}");
        }

        $line    = $this->formatLine($key, $value);
        $pattern = '/^' . preg_quote($key, '/') . '=.*/m';

        if (preg_match($pattern, $contents) === 1) {
            $contents = preg_replace($pattern, $line, $contents, 1);

            if ($contents === null) {
                throw new RuntimeException("Unable to update {$key} in {$this->path}");
            }
        } else {
            $contents = rtrim($contents) . "\n{$line}\n";
        }

        if (file_put_contents($this->path, $contents) === false) {
            throw new RuntimeException("Unable to write {$this->path}");
        }
    }

    public function get(string $key): ?string
    {
        if (is_file($this->path) === false) {
            return null;
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            return null;
        }

        if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $contents, $matches) !== 1) {
            return null;
        }

        $raw = trim($matches[1]);

        if ($raw === '') {
            return '';
        }

        if (
            (str_starts_with($raw, '"') && str_ends_with($raw, '"'))
            || (str_starts_with($raw, "'") && str_ends_with($raw, "'"))
        ) {
            return substr($raw, 1, -1);
        }

        return $raw;
    }

    private function formatLine(string $key, string $value): string
    {
        if ($value === '') {
            return "{$key}=";
        }

        if (preg_match('/\s|#|"|\'/', $value) === 1) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

            return "{$key}=\"{$escaped}\"";
        }

        return "{$key}={$value}";
    }

    private function stringify(string|int|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value) === true) {
            return $value === true ? 'true' : 'false';
        }

        return (string) $value;
    }
}
