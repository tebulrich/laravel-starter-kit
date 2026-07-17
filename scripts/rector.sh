#!/usr/bin/env bash
set -euo pipefail

DRY_RUN=false
for arg in "$@"; do
    case $arg in
        --dry-run) DRY_RUN=true ; shift ;;
        *) ;;
    esac
done

# Invoke via php so this works when +x was lost (e.g. GitHub Actions artifacts).
if [ "$DRY_RUN" = true ]; then
    echo "Running Rector (dry-run)..."
    OUTPUT="$(php vendor/bin/rector --config=qa/rector.php --dry-run)" || EXIT_CODE=$?
    EXIT_CODE=${EXIT_CODE:-0}
    echo "$OUTPUT"
    if echo "$OUTPUT" | grep -q 'would make changes'; then
        echo "Rector found issues that need fixing."
        exit 1
    fi
    if [ "$EXIT_CODE" -ne 0 ]; then
        exit "$EXIT_CODE"
    fi
    echo "Rector found no issues."
    exit 0
fi

echo "Running Rector..."
php vendor/bin/rector --config=qa/rector.php
