#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CREATE_APP="${ROOT_DIR}/bin/create-app"

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

assert_file() {
    local path="$1"
    local label="$2"
    if [[ ! -f "${path}" ]]; then
        printf 'FAIL [%s]: missing file %q\n' "${label}" "${path}" >&2
        failures=$((failures + 1))
    fi
}

tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT

set +e
out="$("${CREATE_APP}" --help 2>&1)"
code=$?
set -e
assert_eq "${code}" "0" "--help exits 0"
assert_contains "${out}" "--github" "--help mentions --github"
assert_contains "${out}" "curl -fsSL" "--help mentions curl bootstrap"
assert_contains "${out}" "--source=." "--help mentions later publish"

set +e
out="$("${CREATE_APP}" 2>&1)"
code=$?
set -e
assert_eq "${code}" "1" "missing name exits 1"
assert_contains "${out}" "Usage:" "missing name prints usage"

set +e
out="$("${CREATE_APP}" foo/bar --github 2>&1)"
code=$?
set -e
assert_eq "${code}" "1" "path with --github exits 1"
assert_contains "${out}" "paths" "path with --github mentions paths"

set +e
out="$("${CREATE_APP}" my-app --github --local 2>&1)"
code=$?
set -e
assert_eq "${code}" "1" "--github with --local exits 1"

set +e
out="$("${CREATE_APP}" my-app --public 2>&1)"
code=$?
set -e
assert_eq "${code}" "1" "--public without --github exits 1"
assert_contains "${out}" "--github" "--public without --github mentions --github"

occupied="${tmp}/occupied"
mkdir -p "${occupied}"
echo stay > "${occupied}/file"
set +e
out="$("${CREATE_APP}" "${occupied}" 2>&1)"
code=$?
set -e
assert_eq "${code}" "1" "occupied path exits 1"
assert_eq "$(cat "${occupied}/file")" "stay" "occupied path is not overwritten"

dest="${tmp}/my-app"
"${CREATE_APP}" "${dest}" >/dev/null
assert_file "${dest}/bin/setup" "copied bin/setup"
assert_file "${dest}/bin/create-app" "copied bin/create-app"
assert_file "${dest}/composer.json" "copied composer.json"
if [[ ! -d "${dest}/.git" ]]; then
    printf 'FAIL [fresh git]: missing %q\n' "${dest}/.git" >&2
    failures=$((failures + 1))
fi
if [[ -d "${dest}/vendor" ]]; then
    printf 'FAIL [no vendor]: vendor/ should not be copied\n' >&2
    failures=$((failures + 1))
fi
if [[ -f "${dest}/.env" ]]; then
    printf 'FAIL [no .env]: .env should not be copied\n' >&2
    failures=$((failures + 1))
fi

remotes="$(git -C "${dest}" remote)"
assert_eq "${remotes}" "" "no kit remote"

if git -C "${dest}" rev-parse --verify HEAD >/dev/null 2>&1; then
    count="$(git -C "${dest}" rev-list --count HEAD)"
    assert_eq "${count}" "1" "fresh history is a single commit"
    subject="$(git -C "${dest}" log -1 --format=%s)"
    assert_eq "${subject}" "Initial commit" "first commit message"
else
    staged="$(git -C "${dest}" status --porcelain)"
    if [[ -z "${staged}" ]]; then
        printf 'FAIL [staged files]: expected a commit or staged files\n' >&2
        failures=$((failures + 1))
    fi
fi

alias_dest="${tmp}/alias-app"
"${CREATE_APP}" "${alias_dest}" --local >/dev/null
assert_file "${alias_dest}/bin/setup" "--local still copies bin/setup"

plain_kit="${tmp}/extracted-kit"
mkdir -p "${plain_kit}/bin"
cp "${ROOT_DIR}/composer.json" "${plain_kit}/composer.json"
cp "${ROOT_DIR}/bin/setup" "${plain_kit}/bin/setup"
cp "${CREATE_APP}" "${plain_kit}/bin/create-app"
printf 'marker\n' > "${plain_kit}/PLAIN_KIT_MARKER"
chmod +x "${plain_kit}/bin/create-app" "${plain_kit}/bin/setup"
plain_dest="${tmp}/from-plain"
"${plain_kit}/bin/create-app" "${plain_dest}" >/dev/null
assert_file "${plain_dest}/PLAIN_KIT_MARKER" "plain tree copy includes files"
assert_file "${plain_dest}/bin/setup" "plain tree copy includes bin/setup"
if [[ -d "${plain_dest}/.git" ]]; then
    remotes="$(git -C "${plain_dest}" remote)"
    assert_eq "${remotes}" "" "plain tree copy has no remote"
fi

piped_dest="${tmp}/piped-app"
STARTER_KIT_GIT_URL="${ROOT_DIR}" bash -s -- "${piped_dest}" < "${CREATE_APP}" >/dev/null
assert_file "${piped_dest}/bin/setup" "piped bootstrap copies bin/setup"
assert_file "${piped_dest}/composer.json" "piped bootstrap copies composer.json"
piped_remotes="$(git -C "${piped_dest}" remote)"
assert_eq "${piped_remotes}" "" "piped bootstrap has no kit remote"
if git -C "${piped_dest}" rev-parse --verify HEAD >/dev/null 2>&1; then
    piped_count="$(git -C "${piped_dest}" rev-list --count HEAD)"
    assert_eq "${piped_count}" "1" "piped bootstrap is a single commit"
    piped_subject="$(git -C "${piped_dest}" log -1 --format=%s)"
    assert_eq "${piped_subject}" "Initial commit" "piped bootstrap commit message"
fi

if [[ "${failures}" -gt 0 ]]; then
    printf '%s assertion(s) failed\n' "${failures}" >&2
    exit 1
fi

echo "create-app tests passed"
