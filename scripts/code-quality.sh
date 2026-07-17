#!/usr/bin/env bash
set -euo pipefail

DRY_RUN=false
for arg in "$@"; do
    case $arg in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --help|-h)
            echo "Usage: $0 [--dry-run]"
            echo "  --dry-run    Verify only (matches CI / composer code-quality:check)"
            exit 0
            ;;
        *)
            echo "Unknown option: $arg" >&2
            exit 1
            ;;
    esac
done

if [ ! -f composer.json ]; then
    echo "Run this script from the project root." >&2
    exit 1
fi

if [ ! -d vendor ]; then
    echo "vendor/ missing; running composer install..."
    composer install --no-progress --no-interaction --prefer-dist --optimize-autoloader
fi

run_script() {
    local script_name="$1"
    chmod +x "scripts/$script_name"
    if [ "$DRY_RUN" = true ]; then
        "./scripts/$script_name" --dry-run
    else
        "./scripts/$script_name"
    fi
}

echo "Code quality ($( [ "$DRY_RUN" = true ] && echo verify-only || echo fix-mode ))"
echo "=================================================="
run_script pint.sh
run_script rector.sh
run_script phpstan.sh
run_script phpunit.sh
echo "=================================================="
echo "All code-quality checks passed."
echo "Security (SAST/SCA): ./scripts/code-security.sh"
