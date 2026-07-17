#!/usr/bin/env bash
set -euo pipefail

DRY_RUN=false
for arg in "$@"; do
    case $arg in
        --dry-run) DRY_RUN=true ; shift ;;
        --help|-h)
            echo "Usage: $0 [--dry-run]"
            exit 0
            ;;
        *) echo "Unknown option: $arg" >&2 ; exit 1 ;;
    esac
done

if [ ! -f composer.json ]; then
    echo "Run from the project root." >&2
    exit 1
fi

if [ ! -d vendor ]; then
    echo "vendor/ missing. Run composer install first." >&2
    exit 1
fi

# Invoke via php so this works when +x was lost (e.g. GitHub Actions artifacts).
if [ "$DRY_RUN" = true ]; then
    echo "Running Laravel Pint (verify)..."
    php vendor/bin/pint --config=qa/pint.json --test
else
    echo "Running Laravel Pint..."
    php vendor/bin/pint --config=qa/pint.json
fi
