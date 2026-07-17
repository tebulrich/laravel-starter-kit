#!/usr/bin/env bash
set -euo pipefail

for arg in "$@"; do
    case $arg in
        --help|-h)
            echo "Usage: ./scripts/phpunit.sh"
            echo "Runs Pest (qa/phpunit.xml). There is no --dry-run; tests always execute."
            exit 0
            ;;
        *) echo "Unknown option: $arg" >&2 ; exit 1 ;;
    esac
done

if [ ! -f composer.json ]; then
    echo "Run from the project root." >&2
    exit 1
fi

if [ ! -f qa/phpunit.xml ]; then
    echo "Missing qa/phpunit.xml" >&2
    exit 1
fi

if [ ! -f vendor/bin/pest ]; then
    echo "Pest is not installed. Run composer install." >&2
    exit 1
fi

# phpdotenv still calls file_get_contents(.env); Pest reports that as a warning
# when the file is missing (typical in CI after a bare checkout).
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Invoke via php so this works when +x was lost (e.g. GitHub Actions artifacts).
echo "Running Pest..."
php vendor/bin/pest -c qa/phpunit.xml
