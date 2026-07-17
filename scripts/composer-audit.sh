#!/usr/bin/env bash
set -euo pipefail

# Thin wrapper kept for older docs/scripts. Prefer: composer code-security:sca

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

for arg in "$@"; do
    case $arg in
        --help|-h)
            echo "Usage: $0 [--dry-run]"
            echo "Runs composer audit via scripts/code-security.sh sca (analysis-only)."
            exit 0
            ;;
        --dry-run) ;;
        *)
            echo "Unknown option: $arg" >&2
            exit 1
            ;;
    esac
done

exec ./scripts/code-security.sh sca
