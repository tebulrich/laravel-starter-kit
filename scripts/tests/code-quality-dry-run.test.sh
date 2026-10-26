#!/usr/bin/env bash
# code-quality.sh passes --dry-run to every script it runs. Each of them must accept it,
# or `composer code-quality:check` stops at the first one that does not.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
failures=0

for script in $(grep -o 'run_script [a-z]*\.sh' "${ROOT_DIR}/scripts/code-quality.sh" | awk '{print $2}'); do
    if ! grep -Eq -- '--dry-run[|)]' "${ROOT_DIR}/scripts/${script}"; then
        printf 'FAIL: %s does not accept --dry-run\n' "${script}" >&2
        failures=$((failures + 1))
    fi
done

if [[ ${failures} -gt 0 ]]; then
    exit 1
fi
echo "code-quality dry-run ok"
