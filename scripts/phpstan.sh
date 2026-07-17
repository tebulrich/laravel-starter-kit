#!/usr/bin/env bash
set -euo pipefail

for arg in "$@"; do
    case $arg in
        --dry-run|--help|-h) ;;
        *) echo "Unknown option: $arg" >&2 ; exit 1 ;;
    esac
done

if [ ! -f composer.json ]; then
    echo "Run from the project root." >&2
    exit 1
fi

if [ ! -f qa/phpstan.neon ]; then
    echo "Missing qa/phpstan.neon" >&2
    exit 1
fi

# Invoke via php so this works when +x was lost (e.g. GitHub Actions artifacts).
echo "Running PHPStan..."
php vendor/bin/phpstan analyse -c qa/phpstan.neon --memory-limit=1G
