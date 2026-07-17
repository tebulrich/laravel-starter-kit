#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CODE_SECURITY_ROOT_DIR="${ROOT_DIR}"
# shellcheck source=scripts/lib/code-security-runtime.sh
source "${ROOT_DIR}/scripts/lib/code-security-runtime.sh"

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

test_strict_defaults() {
    unset CODE_SECURITY_STRICT
    assert_eq "$(code_security_strict)" "1" "strict default"

    CODE_SECURITY_STRICT=0
    assert_eq "$(code_security_strict)" "0" "strict off"
    unset CODE_SECURITY_STRICT

    CODE_SECURITY_STRICT=true
    assert_eq "$(code_security_strict)" "1" "strict true"
    unset CODE_SECURITY_STRICT
}

test_image_defaults() {
    unset CODE_SECURITY_SEMGREP_IMAGE CODE_SECURITY_ZAP_IMAGE CODE_SECURITY_SYFT_IMAGE CODE_SECURITY_TRIVY_IMAGE CODE_SECURITY_GITLEAKS_IMAGE CODE_SECURITY_NODE_IMAGE
    assert_contains "$(code_security_semgrep_image)" "semgrep/semgrep:" "semgrep image default"
    assert_contains "$(code_security_zap_image)" "zaproxy" "zap image default"
    assert_contains "$(code_security_syft_image)" "syft:" "syft image default"
    assert_contains "$(code_security_trivy_image)" "trivy:" "trivy image default"
    assert_contains "$(code_security_gitleaks_image)" "gitleaks:" "gitleaks image default"
    assert_contains "$(code_security_node_image)" "node:24-alpine" "node image default"

    CODE_SECURITY_SEMGREP_IMAGE=semgrep/semgrep:test
    assert_eq "$(code_security_semgrep_image)" "semgrep/semgrep:test" "semgrep image override"
    unset CODE_SECURITY_SEMGREP_IMAGE
}

test_target_discovery() {
    local composers
    local npms
    local tmp
    local yarn_targets

    composers="$(code_security_composer_targets "${ROOT_DIR}")"
    npms="$(code_security_npm_targets "${ROOT_DIR}")"

    assert_contains "${composers}" "${ROOT_DIR}" "composer includes project root"
    # npm/yarn targets are optional until a Vue/Vite lockfile exists
    if [[ -f "${ROOT_DIR}/package-lock.json" || -f "${ROOT_DIR}/yarn.lock" ]]; then
        assert_contains "${npms}" "${ROOT_DIR}" "js SCA includes root when a lockfile exists"
    fi

    tmp="$(mktemp -d)"
    mkdir -p "${tmp}/frontend"
    touch "${tmp}/frontend/yarn.lock"
    yarn_targets="$(code_security_js_targets "${tmp}")"
    assert_contains "${yarn_targets}" "${tmp}/frontend" "js SCA discovers frontend/yarn.lock"
    rm -rf "${tmp}"
}

test_script_help() {
    local help_out
    help_out="$("${ROOT_DIR}/scripts/code-security.sh" --help)"
    assert_contains "${help_out}" "SAST + secrets + SCA" "help mentions auto ZAP on check"
    assert_contains "${help_out}" "OWASP ZAP" "help mentions ZAP"
    assert_contains "${help_out}" "Composer audit" "help mentions SCA"
    assert_contains "${help_out}" "Gitleaks" "help mentions secrets"
    assert_contains "${help_out}" "CycloneDX" "help mentions SBOM"
    assert_contains "${help_out}" "Trivy" "help mentions Trivy"
    assert_contains "${help_out}" "yarn/npm audit" "help mentions yarn SCA"
}

test_stack_probe_is_boolean() {
    if code_security_stack_is_up; then
        assert_eq "0" "0" "stack_is_up returned success"
    else
        assert_eq "1" "1" "stack_is_up returned failure"
    fi
}

test_config_files_exist() {
    if [[ ! -f "${ROOT_DIR}/.semgrepignore" ]]; then
        printf 'FAIL [semgrepignore missing]\n' >&2
        failures=$((failures + 1))
    fi
    if [[ ! -f "${ROOT_DIR}/qa/semgrep/exclude-rules.txt" ]]; then
        printf 'FAIL [semgrep exclude-rules missing]\n' >&2
        failures=$((failures + 1))
    fi
    if [[ ! -f "${ROOT_DIR}/qa/zap/rules.tsv" ]]; then
        printf 'FAIL [zap rules.tsv missing]\n' >&2
        failures=$((failures + 1))
    else
        local zap_rules
        zap_rules="$(cat "${ROOT_DIR}/qa/zap/rules.tsv")"
        assert_contains "${zap_rules}" "10017" "zap rules ignore cross-domain JS on health HTML"
        assert_contains "${zap_rules}" "90003" "zap rules ignore SRI on health HTML"
    fi
    if [[ ! -f "${ROOT_DIR}/.gitleaks.toml" ]]; then
        printf 'FAIL [gitleaks config missing]\n' >&2
        failures=$((failures + 1))
    fi
}

test_composer_audit_uses_lockfile() {
    local fake_composer
    local output
    local temp_dir

    temp_dir="$(mktemp -d)"
    fake_composer="${temp_dir}/composer.php"
    cat > "${fake_composer}" <<'PHP'
<?php

echo implode(' ', array_slice($argv, 1));
PHP

    output="$(code_security_run_composer_audit "${fake_composer}")"
    rm -rf "${temp_dir}"

    assert_contains "${output}" "audit --locked --no-interaction --abandoned=fail" "composer audit uses lockfile and fails on abandoned"
}

test_composer_scripts_registered() {
    local scripts_json
    scripts_json="$(php -r 'echo json_encode(json_decode((string) file_get_contents($argv[1]), true)["scripts"] ?? []);' "${ROOT_DIR}/composer.json")"
    assert_contains "${scripts_json}" "code-security" "root composer has code-security"
    assert_contains "${scripts_json}" "code-security:dast" "root composer has code-security:dast"
    assert_contains "${scripts_json}" "code-security:sbom" "root composer has code-security:sbom"
    assert_contains "${scripts_json}" "code-security:image-scan" "root composer has code-security:image-scan"
    assert_contains "${scripts_json}" "code-security:secrets" "root composer has code-security:secrets"
}

test_severity_defaults() {
    unset CODE_SECURITY_TRIVY_FS_SEVERITY CODE_SECURITY_TRIVY_IMAGE_SEVERITY
    assert_eq "$(code_security_trivy_fs_severity)" "CRITICAL,HIGH" "trivy fs severity default"
    assert_eq "$(code_security_trivy_image_severity)" "CRITICAL" "trivy image severity default"
}

test_trivy_skip_and_ignore_defaults() {
    unset CODE_SECURITY_TRIVY_FS_SKIP_DIRS CODE_SECURITY_TRIVY_IGNORE_UNFIXED
    assert_contains "$(code_security_trivy_fs_skip_dirs)" "**/vendor" "fs skip includes nested vendor"
    assert_contains "$(code_security_trivy_fs_skip_dirs)" "**/node_modules" "fs skip includes nested node_modules"
    assert_eq "$(code_security_trivy_ignore_unfixed)" "1" "ignore-unfixed default on"
}

test_host_php_usable_requires_working_binary() {
    if PATH="/nonexistent" code_security_host_php_usable; then
        printf 'FAIL [host php usable]: expected failure when php is not on PATH\n' >&2
        failures=$((failures + 1))
    fi
}

test_skip_docker_step_inside_container() {
    local output

    CODE_SECURITY_INSIDE_CONTAINER=1
    output="$(code_security_skip_docker_step 'SAST (Semgrep)')"
    assert_eq "$?" "0" "in-container docker skip is not a failure"
    assert_contains "${output}" "Skipping SAST (Semgrep)" "skip names the step"
    assert_contains "${output}" "./scripts/code-security.sh" "skip points at host script"
    unset CODE_SECURITY_INSIDE_CONTAINER
}

test_network_default_uses_app_suffix() {
    unset CODE_SECURITY_COMPOSE_NETWORK CODE_SECURITY_PHP_SERVICE
    CODE_SECURITY_ROOT_DIR="${ROOT_DIR}"
    assert_contains "$(code_security_compose_network)" "_app" "compose network default ends with _app"
}

test_strict_defaults
test_image_defaults
test_target_discovery
test_script_help
test_stack_probe_is_boolean
test_config_files_exist
test_composer_audit_uses_lockfile
test_composer_scripts_registered
test_severity_defaults
test_trivy_skip_and_ignore_defaults
test_network_default_uses_app_suffix
test_skip_docker_step_inside_container
test_host_php_usable_requires_working_binary

if [[ "${failures}" -gt 0 ]]; then
    printf '%d code-security runtime test(s) failed.\n' "${failures}" >&2
    exit 1
fi

printf 'OK: code-security runtime tests passed.\n'
