#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="${ROOT_DIR}/scripts/require-setup.sh"

failures=0

assert_eq() {
    local actual="$1"
    local expected="$2"
    local label="$3"

    if [[ "${actual}" != "${expected}" ]]; then
        printf 'FAIL [%s]: expected %q, got %q\n' "${label}" "${expected}" "${actual}" >&2
        failures=$((failures + 1))
    fi
}

assert_contains() {
    local haystack="$1"
    local needle="$2"
    local label="$3"

    if [[ "${haystack}" != *"${needle}"* ]]; then
        printf 'FAIL [%s]: expected to contain %q\n' "${label}" "${needle}" >&2
        failures=$((failures + 1))
    fi
}

tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT

mkdir -p "${tmp}/scripts"
cp "${SCRIPT}" "${tmp}/scripts/require-setup.sh"

set +e
out="$(sh "${tmp}/scripts/require-setup.sh" 2>&1)"
code=$?
set -e
assert_eq "${code}" "1" "missing setup files exit 1"
assert_contains "${out}" "bin/setup" "missing setup files mention bin/setup"

mkdir -p "${tmp}/storage/app"
touch "${tmp}/.env"
set +e
out="$(sh "${tmp}/scripts/require-setup.sh" 2>&1)"
code=$?
set -e
assert_eq "${code}" "1" "empty env without manifest exit 1"

rm -f "${tmp}/.env"
touch "${tmp}/storage/app/setup-manifest.json"
set +e
out="$(sh "${tmp}/scripts/require-setup.sh" 2>&1)"
code=$?
set -e
assert_eq "${code}" "1" "empty manifest without env exit 1"

printf 'COMPOSE_PROFILES=mysql\n' > "${tmp}/.env"
printf '{}\n' > "${tmp}/storage/app/setup-manifest.json"
set +e
out="$(sh "${tmp}/scripts/require-setup.sh" 2>&1)"
code=$?
set -e
assert_eq "${code}" "0" "mysql profile + non-empty files exit 0"

printf 'COMPOSE_PROFILES=redis\n' > "${tmp}/.env"
set +e
out="$(sh "${tmp}/scripts/require-setup.sh" 2>&1)"
code=$?
set -e
assert_eq "${code}" "1" "invalid COMPOSE_PROFILES exit 1"
assert_contains "${out}" "COMPOSE_PROFILES" "invalid profile mentions COMPOSE_PROFILES"

if [[ "${failures}" -gt 0 ]]; then
    printf '%s assertion(s) failed\n' "${failures}" >&2
    exit 1
fi

echo "require-setup tests passed"
