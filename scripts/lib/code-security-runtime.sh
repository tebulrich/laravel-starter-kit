#!/usr/bin/env bash
# Shared helpers for scripts/code-security.sh (SAST / SCA / DAST).

code_security_strict() {
    local value="${CODE_SECURITY_STRICT:-1}"
    case "${value}" in
        1 | true | TRUE | yes | YES) printf '1' ;;
        *) printf '0' ;;
    esac
}

code_security_semgrep_image() {
    printf '%s' "${CODE_SECURITY_SEMGREP_IMAGE:-semgrep/semgrep:1.173.0}"
}

code_security_zap_image() {
    printf '%s' "${CODE_SECURITY_ZAP_IMAGE:-ghcr.io/zaproxy/zaproxy:stable}"
}

code_security_syft_image() {
    printf '%s' "${CODE_SECURITY_SYFT_IMAGE:-anchore/syft:v1.51.0}"
}

code_security_trivy_image() {
    printf '%s' "${CODE_SECURITY_TRIVY_IMAGE:-aquasec/trivy:0.74.0}"
}

code_security_gitleaks_image() {
    printf '%s' "${CODE_SECURITY_GITLEAKS_IMAGE:-ghcr.io/gitleaks/gitleaks:v8.30.1}"
}

code_security_node_image() {
    printf '%s' "${CODE_SECURITY_NODE_IMAGE:-node:24-alpine}"
}

code_security_trivy_fs_severity() {
    printf '%s' "${CODE_SECURITY_TRIVY_FS_SEVERITY:-CRITICAL,HIGH}"
}

code_security_trivy_image_severity() {
    printf '%s' "${CODE_SECURITY_TRIVY_IMAGE_SEVERITY:-CRITICAL}"
}

code_security_trivy_fs_skip_dirs() {
    # Nested Composer/npm trees (app/vendor, **/node_modules) are not first-party
    # runtime; SCA covers lockfiles via composer/npm audit. Bare "vendor" only
    # skips the repo-root directory and left mockery docs Python pins in scope.
    printf '%s' "${CODE_SECURITY_TRIVY_FS_SKIP_DIRS:-**/vendor,**/node_modules,.git,qa/semgrep/reports,qa/zap/reports,qa/sbom/reports,qa/trivy/reports,qa/gitleaks/reports}"
}

code_security_trivy_ignore_unfixed() {
    # Image OS CVEs often have no Debian/Alpine fix yet. Default: gate only on
    # fixable findings so STRICT mode can block releases without a permanent red CI.
    local value="${CODE_SECURITY_TRIVY_IGNORE_UNFIXED:-1}"
    case "${value}" in
        1 | true | TRUE | yes | YES) printf '1' ;;
        *) printf '0' ;;
    esac
}

code_security_compose_network() {
    if [[ -n "${CODE_SECURITY_COMPOSE_NETWORK:-}" ]]; then
        printf '%s' "${CODE_SECURITY_COMPOSE_NETWORK}"
        return 0
    fi

    local container
    container="$(code_security_php_container_name "${CODE_SECURITY_ROOT_DIR:-.}")"
    if [[ -n "${container}" ]]; then
        local network
        network="$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{printf "%s\n" $k}}{{end}}' "${container}" 2>/dev/null | head -n 1 || true)"
        if [[ -n "${network}" ]]; then
            printf '%s' "${network}"
            return 0
        fi
    fi

    # Compose default: {project}_app where project is the directory name.
    printf '%s_app' "$(basename "${CODE_SECURITY_ROOT_DIR:-.}" | tr '[:upper:]' '[:lower:]' | tr ' .' '-')"
}

code_security_php_container_name() {
    local root_dir="${1:-${CODE_SECURITY_ROOT_DIR:-.}}"

    if [[ -n "${CODE_SECURITY_PHP_SERVICE:-}" ]]; then
        printf '%s' "${CODE_SECURITY_PHP_SERVICE}"
        return 0
    fi

    if ! command -v docker >/dev/null 2>&1; then
        return 0
    fi

    (
        cd "${root_dir}"
        docker compose ps --status running --format '{{.Name}}' php 2>/dev/null | head -n 1
    ) || true
}

code_security_zap_target() {
    printf '%s' "${CODE_SECURITY_ZAP_TARGET:-http://php:80/up}"
}

code_security_inside_container() {
    if [[ "${CODE_SECURITY_INSIDE_CONTAINER:-}" == "1" ]]; then
        return 0
    fi
    [[ -f /.dockerenv ]]
}

code_security_docker_ready() {
    command -v docker >/dev/null 2>&1
}

# 0 = skipped (inside PHP container) or should not be called when Docker exists.
# 1 = hard fail (host is missing Docker).
code_security_skip_docker_step() {
    local step="$1"

    if code_security_inside_container; then
        printf 'Skipping %s: Docker is not available inside the PHP container.\n' "${step}"
        printf 'Run Docker-backed scanners on the host: ./scripts/code-security.sh\n'
        return 0
    fi

    printf 'Docker is required for %s. Install Docker and retry.\n' "${step}" >&2
    return 1
}

code_security_ensure_report_dir() {
    local dir="$1"
    mkdir -p "${dir}"
}

code_security_run_semgrep() {
    local root_dir="$1"
    local report_dir="${root_dir}/qa/semgrep/reports"
    local exclude_file="${root_dir}/qa/semgrep/exclude-rules.txt"
    local image
    local strict
    local rule_id
    local -a scan_cmd

    if ! code_security_docker_ready; then
        code_security_skip_docker_step 'SAST (Semgrep)'
        return $?
    fi
    image="$(code_security_semgrep_image)"
    strict="$(code_security_strict)"
    code_security_ensure_report_dir "${report_dir}"

    scan_cmd=(
        docker run --rm
        --user "$(id -u):$(id -g)"
        -e HOME=/tmp
        -e SEMGREP_SEND_METRICS=off
        -v "${root_dir}:/src:ro"
        -v "${report_dir}:/reports:rw"
        -w /src
        "${image}"
        semgrep scan
        --config p/php
        --config r/php.laravel.security
        --config p/javascript
        --config p/typescript
        --metrics=off
        --oss-only
        --sarif-output=/reports/semgrep.sarif
        --json-output=/reports/semgrep.json
    )

    if [[ -f "${exclude_file}" ]]; then
        while IFS= read -r rule_id || [[ -n "${rule_id}" ]]; do
            rule_id="${rule_id%%#*}"
            rule_id="$(printf '%s' "${rule_id}" | tr -d '[:space:]')"
            if [[ -n "${rule_id}" ]]; then
                scan_cmd+=(--exclude-rule "${rule_id}")
            fi
        done < "${exclude_file}"
    fi

    # Fail the gate only on ERROR-severity findings when strict mode is on.
    if [[ "${strict}" == "1" ]]; then
        scan_cmd+=(--error --severity=ERROR)
    else
        scan_cmd+=(--severity=ERROR)
    fi

    printf 'Semgrep image: %s (strict=%s)\n' "${image}" "${strict}"
    "${scan_cmd[@]}"
}

code_security_run_gitleaks() {
    local root_dir="$1"
    local report_dir="${root_dir}/qa/gitleaks/reports"
    local config_file="${root_dir}/.gitleaks.toml"
    local image
    local strict
    local -a scan_cmd
    local gitleaks_status=0

    if ! code_security_docker_ready; then
        code_security_skip_docker_step 'Secrets (Gitleaks)'
        return $?
    fi
    image="$(code_security_gitleaks_image)"
    strict="$(code_security_strict)"
    code_security_ensure_report_dir "${report_dir}"

    scan_cmd=(
        docker run --rm
        --user "$(id -u):$(id -g)"
        -v "${root_dir}:/src:ro"
        -v "${report_dir}:/reports:rw"
        "${image}"
        dir /src
        --no-banner
        --redact
        --report-path /reports/gitleaks.json
        --report-format json
    )

    if [[ -f "${config_file}" ]]; then
        scan_cmd+=(--config /src/.gitleaks.toml)
    fi

    if [[ "${strict}" == "1" ]]; then
        scan_cmd+=(--exit-code 1)
    else
        scan_cmd+=(--exit-code 0)
    fi

    printf 'Gitleaks image: %s (strict=%s)\n' "${image}" "${strict}"
    set +e
    "${scan_cmd[@]}"
    gitleaks_status=$?
    set -e

    if [[ "${gitleaks_status}" -ne 0 && "${strict}" != "1" ]]; then
        printf 'Gitleaks exit %s with CODE_SECURITY_STRICT=0 — continuing.\n' "${gitleaks_status}" >&2
        return 0
    fi

    return "${gitleaks_status}"
}

code_security_composer_targets() {
    local root_dir="$1"
    local path

    # Root app lockfile (this starter). Nested locks are discovered when present.
    if [[ -f "${root_dir}/composer.lock" ]]; then
        printf '%s\n' "${root_dir}"
    fi

    for path in \
        "${root_dir}"/packages/*/composer.lock \
        "${root_dir}"/packages/*/*/composer.lock
    do
        if [[ -f "${path}" ]]; then
            printf '%s\n' "$(dirname "${path}")"
        fi
    done
}

code_security_js_targets() {
    local root_dir="$1"
    local path

    # Vue/Vite trees may live at repo root or under common frontend dirs.
    # Prefer lockfiles (yarn.lock or package-lock.json); package.json alone is not auditable.
    {
        for path in \
            "${root_dir}/package-lock.json" \
            "${root_dir}/yarn.lock" \
            "${root_dir}/frontend/package-lock.json" \
            "${root_dir}/frontend/yarn.lock" \
            "${root_dir}/resources/package-lock.json" \
            "${root_dir}/resources/yarn.lock" \
            "${root_dir}/packages/"*/package-lock.json \
            "${root_dir}/packages/"*/yarn.lock
        do
            if [[ -f "${path}" ]]; then
                dirname "${path}"
            fi
        done
    } | sort -u
}

code_security_npm_targets() {
    code_security_js_targets "$@"
}

code_security_host_php_usable() {
    command -v php >/dev/null 2>&1 || return 1
    php -r 'exit(0);' >/dev/null 2>&1
}

code_security_composer_bin() {
    if ! code_security_host_php_usable; then
        return 1
    fi
    if command -v composer >/dev/null 2>&1; then
        command -v composer
        return 0
    fi

    return 1
}

code_security_composer_abandoned_flag() {
    if [[ "$(code_security_strict)" == "1" ]]; then
        printf '%s' '--abandoned=fail'
    else
        printf '%s' '--abandoned=report'
    fi
}

code_security_run_composer_audit() {
    # Composer’s bundled PHAR still emits PHP 8.5 E_DEPRECATED noise
    # (curl_close, $http_response_header in json-schema). That is upstream
    # Composer — not a vulnerability in the audited package.
    local composer_bin="$1"
    shift

    php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' \
        "${composer_bin}" audit --locked --no-interaction \
        "$(code_security_composer_abandoned_flag)" "$@"
}

code_security_run_composer_audit_host() {
    local target_dir="$1"
    local label
    local composer_bin

    label="${target_dir#"${CODE_SECURITY_ROOT_DIR}/"}"
    if [[ "${label}" == "${target_dir}" ]]; then
        label="."
    fi

    composer_bin="$(code_security_composer_bin)" || {
        printf 'composer is not available on the host.\n' >&2
        return 1
    }

    printf '\n==> composer audit: %s\n' "${label}"
    (
        cd "${target_dir}"
        code_security_run_composer_audit "${composer_bin}"
    )
}

code_security_run_composer_audit_docker() {
    local target_dir="$1"
    local php_service
    local rel
    local container_path

    php_service="$(code_security_php_container_name "${CODE_SECURITY_ROOT_DIR}")"
    rel="${target_dir#"${CODE_SECURITY_ROOT_DIR}/"}"
    if [[ "${rel}" == "${target_dir}" ]]; then
        rel="."
        container_path="/var/www/html"
    else
        container_path="/var/www/html/${rel}"
    fi

    if [[ -z "${php_service}" ]]; then
        printf 'PHP container is not running. Start the stack with ./start.sh or install Composer on the host.\n' >&2
        return 1
    fi

    printf '\n==> composer audit (docker/%s): %s\n' "${php_service}" "${rel}"
    docker exec -w "${container_path}" "${php_service}" bash -lc \
        "php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' \"\$(command -v composer)\" audit --locked --no-interaction $(code_security_composer_abandoned_flag)"
}

code_security_run_yarn_audit_in_dir() {
    local level="${CODE_SECURITY_NPM_AUDIT_LEVEL:-high}"

    if yarn npm audit --help >/dev/null 2>&1; then
        yarn npm audit --recursive
        return $?
    fi

    yarn audit --level "${level}"
}

code_security_run_js_audit_host() {
    local target_dir="$1"
    local label
    local level="${CODE_SECURITY_NPM_AUDIT_LEVEL:-high}"

    label="${target_dir#"${CODE_SECURITY_ROOT_DIR}/"}"

    if [[ -f "${target_dir}/yarn.lock" ]]; then
        printf '\n==> yarn audit: %s\n' "${label}"
        (
            cd "${target_dir}"
            code_security_run_yarn_audit_in_dir
        )
        return $?
    fi

    printf '\n==> npm audit (--audit-level=%s): %s\n' "${level}" "${label}"
    (
        cd "${target_dir}"
        npm audit --audit-level="${level}"
    )
}

code_security_run_js_audit_docker() {
    local target_dir="$1"
    local label
    local level="${CODE_SECURITY_NPM_AUDIT_LEVEL:-high}"
    local image
    local -a docker_cmd

    if ! code_security_docker_ready; then
        code_security_skip_docker_step 'JS SCA (yarn/npm audit)'
        return $?
    fi
    image="$(code_security_node_image)"
    label="${target_dir#"${CODE_SECURITY_ROOT_DIR}/"}"

    docker_cmd=(
        docker run --rm
        --user "$(id -u):$(id -g)"
        -e HOME=/tmp
        -v "${target_dir}:/src:ro"
        -w /src
        "${image}"
        sh -lc
    )

    if [[ -f "${target_dir}/yarn.lock" ]]; then
        printf '\n==> yarn audit (docker/%s): %s\n' "${image}" "${label}"
        "${docker_cmd[@]}" \
            "corepack enable >/dev/null 2>&1 || true; if yarn npm audit --help >/dev/null 2>&1; then yarn npm audit --recursive; else yarn audit --level ${level}; fi"
        return $?
    fi

    printf '\n==> npm audit (docker/%s, --audit-level=%s): %s\n' "${image}" "${level}" "${label}"
    "${docker_cmd[@]}" "npm audit --audit-level=${level}"
}

code_security_run_js_audit() {
    local target_dir="$1"

    if [[ -f "${target_dir}/yarn.lock" ]]; then
        if command -v yarn >/dev/null 2>&1; then
            code_security_run_js_audit_host "${target_dir}"
            return $?
        fi
        code_security_run_js_audit_docker "${target_dir}"
        return $?
    fi

    if command -v npm >/dev/null 2>&1; then
        code_security_run_js_audit_host "${target_dir}"
        return $?
    fi

    code_security_run_js_audit_docker "${target_dir}"
}

code_security_run_sca() {
    local root_dir="$1"
    local strict
    local failures=0
    local target
    local use_host_composer=0

    strict="$(code_security_strict)"
    CODE_SECURITY_ROOT_DIR="${root_dir}"

    if code_security_composer_bin >/dev/null; then
        use_host_composer=1
    fi

    while IFS= read -r target; do
        if [[ "${use_host_composer}" -eq 1 ]]; then
            if ! code_security_run_composer_audit_host "${target}"; then
                failures=$((failures + 1))
            fi
        else
            if ! code_security_run_composer_audit_docker "${target}"; then
                failures=$((failures + 1))
            fi
        fi
    done < <(code_security_composer_targets "${root_dir}")

    while IFS= read -r target; do
        [[ -z "${target}" ]] && continue
        if ! code_security_run_js_audit "${target}"; then
            failures=$((failures + 1))
        fi
    done < <(code_security_js_targets "${root_dir}")

    if [[ "${failures}" -gt 0 ]]; then
        printf '\nSCA reported %d failing target(s).\n' "${failures}" >&2
        if [[ "${strict}" == "1" ]]; then
            return 1
        fi
        printf 'CODE_SECURITY_STRICT=0 — continuing despite SCA findings.\n' >&2
    fi
}

code_security_stack_is_up() {
    local network
    local php_service

    network="$(code_security_compose_network)"
    php_service="$(code_security_php_container_name "${CODE_SECURITY_ROOT_DIR:-.}")"

    if ! command -v docker >/dev/null 2>&1; then
        return 1
    fi
    if ! docker network inspect "${network}" >/dev/null 2>&1; then
        return 1
    fi
    if [[ -z "${php_service}" ]]; then
        return 1
    fi
    if ! docker ps --format '{{.Names}}' | grep -qx "${php_service}"; then
        return 1
    fi

    return 0
}

code_security_gateway_ready() {
    local network
    local php_service

    network="$(code_security_compose_network)"
    php_service="$(code_security_php_container_name "${CODE_SECURITY_ROOT_DIR:-.}")"

    if code_security_stack_is_up; then
        return 0
    fi

    if ! command -v docker >/dev/null 2>&1; then
        printf 'Docker is required for ZAP DAST.\n' >&2
        return 1
    fi
    if ! docker network inspect "${network}" >/dev/null 2>&1; then
        printf 'Docker network %s not found. Start the stack with ./start.sh first.\n' "${network}" >&2
        return 1
    fi

    printf 'PHP container is not running (looked for compose service php). Start the stack with ./start.sh first.\n' >&2
    if [[ -n "${php_service}" ]]; then
        printf 'Last detected name: %s\n' "${php_service}" >&2
    fi
    return 1
}

code_security_run_zap_if_stack_up() {
    local root_dir="$1"

    CODE_SECURITY_ROOT_DIR="${root_dir}"

    if ! code_security_docker_ready; then
        code_security_skip_docker_step 'DAST (OWASP ZAP)'
        return $?
    fi

    if code_security_stack_is_up; then
        code_security_run_zap "${root_dir}"
        return $?
    fi

    printf 'Compose stack is not running (need php service on %s) — skipping ZAP DAST.\n' \
        "$(code_security_compose_network)"
    printf 'Start with ./start.sh, then re-run, or use: ./scripts/code-security.sh dast\n'
}

code_security_run_zap() {
    local root_dir="$1"
    local report_dir="${root_dir}/qa/zap/reports"
    local rules_file="${root_dir}/qa/zap/rules.tsv"
    local image
    local network
    local target
    local strict
    local -a zap_cmd
    local zap_status=0

    if ! code_security_docker_ready; then
        code_security_skip_docker_step 'DAST (OWASP ZAP)'
        return $?
    fi
    code_security_gateway_ready || return 1

    image="$(code_security_zap_image)"
    network="$(code_security_compose_network)"
    target="$(code_security_zap_target)"
    strict="$(code_security_strict)"
    code_security_ensure_report_dir "${report_dir}"

    # Baseline is passive-only (safe for local/staging). Reports land under qa/zap/reports/.
    if [[ -s "${rules_file}" ]]; then
        zap_cmd=(
            docker run --rm
            --user "$(id -u):$(id -g)"
            --network "${network}"
            -v "${report_dir}:/zap/wrk:rw"
            -v "${rules_file}:/zap/rules.tsv:ro"
            "${image}"
            zap-baseline.py
            -t "${target}"
            -c /zap/rules.tsv
            -J zap-report.json
            -r zap-report.html
            -w zap-report.md
            -x zap-report.xml
        )
    else
        zap_cmd=(
            docker run --rm
            --user "$(id -u):$(id -g)"
            --network "${network}"
            -v "${report_dir}:/zap/wrk:rw"
            "${image}"
            zap-baseline.py
            -t "${target}"
            -J zap-report.json
            -r zap-report.html
            -w zap-report.md
            -x zap-report.xml
        )
    fi

    # -I: WARN-NEW does not fail the scan. FAIL-NEW still fails (matches
    # CODE_SECURITY_STRICT: fail on ZAP FAIL, not warnings).
    zap_cmd+=(-I)

    printf 'ZAP image: %s\n' "${image}"
    printf 'ZAP target: %s (network=%s, strict=%s)\n' "${target}" "${network}" "${strict}"
    set +e
    "${zap_cmd[@]}"
    zap_status=$?
    set -e

    # ZAP baseline: 0 ok, 1 FAIL alerts, 2 WARN alerts, 3 other errors.
    if [[ "${zap_status}" -eq 0 ]]; then
        return 0
    fi
    if [[ "${zap_status}" -eq 2 ]]; then
        printf 'ZAP reported warnings only (no FAIL-NEW).\n'
        return 0
    fi
    if [[ "${strict}" != "1" && "${zap_status}" -eq 1 ]]; then
        printf 'ZAP FAIL with CODE_SECURITY_STRICT=0 — continuing.\n' >&2
        return 0
    fi

    return "${zap_status}"
}

code_security_run_sbom() {
    local root_dir="$1"
    local report_dir="${root_dir}/qa/sbom/reports"
    local image
    local -a syft_cmd

    if ! code_security_docker_ready; then
        code_security_skip_docker_step 'SBOM (Syft)'
        return $?
    fi
    image="$(code_security_syft_image)"
    code_security_ensure_report_dir "${report_dir}"

    # CycloneDX JSON for diligence / supply-chain review. Generation always succeeds
    # as an artifact step; SCA remains the advisory fail gate.
    mkdir -p "${report_dir}/.cache"
    syft_cmd=(
        docker run --rm
        --user "$(id -u):$(id -g)"
        -e HOME=/cache
        -e XDG_CACHE_HOME=/cache
        -v "${root_dir}:/src:ro"
        -v "${report_dir}:/out:rw"
        -v "${report_dir}/.cache:/cache:rw"
        -w /src
        "${image}"
        scan dir:/src
        --exclude './**/vendor/**'
        --exclude './**/node_modules/**'
        --exclude './**/.git/**'
        --exclude './qa/**/reports/**'
        -o "cyclonedx-json=/out/sbom.cdx.json"
        -o "spdx-json=/out/sbom.spdx.json"
    )

    printf 'Syft image: %s\n' "${image}"
    printf 'SBOM outputs: %s/sbom.cdx.json , %s/sbom.spdx.json\n' "${report_dir}" "${report_dir}"
    "${syft_cmd[@]}"
}

code_security_run_trivy_fs() {
    local root_dir="$1"
    local report_dir="${root_dir}/qa/trivy/reports"
    local image
    local severity
    local skip_dirs
    local strict
    local -a trivy_cmd
    local trivy_status=0

    if ! code_security_docker_ready; then
        code_security_skip_docker_step 'Trivy filesystem scan'
        return $?
    fi
    image="$(code_security_trivy_image)"
    severity="$(code_security_trivy_fs_severity)"
    skip_dirs="$(code_security_trivy_fs_skip_dirs)"
    strict="$(code_security_strict)"
    code_security_ensure_report_dir "${report_dir}"

    trivy_cmd=(
        docker run --rm
        --user "$(id -u):$(id -g)"
        -e HOME=/tmp
        -e TRIVY_CACHE_DIR=/tmp/trivy-cache
        -v "${root_dir}:/src:ro"
        -v "${report_dir}:/reports:rw"
        "${image}"
        fs
        --scanners vuln
        --severity "${severity}"
        --skip-dirs "${skip_dirs}"
        --format json
        --output /reports/trivy-fs.json
        /src
    )

    if [[ "${strict}" == "1" ]]; then
        trivy_cmd+=(--exit-code 1)
    else
        trivy_cmd+=(--exit-code 0)
    fi

    printf 'Trivy image: %s (fs severity=%s, strict=%s)\n' "${image}" "${severity}" "${strict}"
    set +e
    "${trivy_cmd[@]}"
    trivy_status=$?
    set -e

    if [[ "${trivy_status}" -ne 0 && "${strict}" != "1" ]]; then
        printf 'Trivy filesystem scan exit %s with CODE_SECURITY_STRICT=0 — continuing.\n' "${trivy_status}" >&2
        return 0
    fi

    return "${trivy_status}"
}

code_security_run_trivy_images() {
    local root_dir="$1"
    local report_dir="${root_dir}/qa/trivy/reports"
    local image
    local severity
    local strict
    local ignore_unfixed
    local images_csv="${CODE_SECURITY_TRIVY_IMAGES:-}"
    local target
    local failures=0
    local -a image_list=()
    local -a trivy_cmd
    local trivy_status=0
    local safe_name

    if [[ -z "${images_csv}" ]]; then
        printf 'CODE_SECURITY_TRIVY_IMAGES is empty — skipping container image scan.\n'
        printf 'Example: CODE_SECURITY_TRIVY_IMAGES=laravel-starter-kit-php:8.5 ./scripts/code-security.sh image-scan\n'
        return 0
    fi

    if ! code_security_docker_ready; then
        code_security_skip_docker_step 'Trivy image scan'
        return $?
    fi
    image="$(code_security_trivy_image)"
    severity="$(code_security_trivy_image_severity)"
    strict="$(code_security_strict)"
    ignore_unfixed="$(code_security_trivy_ignore_unfixed)"
    code_security_ensure_report_dir "${report_dir}"

    IFS=',' read -r -a image_list <<<"${images_csv}"

    for target in "${image_list[@]}"; do
        target="$(printf '%s' "${target}" | tr -d '[:space:]')"
        if [[ -z "${target}" ]]; then
            continue
        fi

        safe_name="$(printf '%s' "${target}" | tr '/:' '__')"
        # Image scan needs the host Docker socket. The Trivy image runs as its
        # default non-root-incompatible entrypoint user; do not force --user here
        # or the socket is unusable. Reports dir is chmod'd for the host user after.
        trivy_cmd=(
            docker run --rm
            -e HOME=/tmp
            -e TRIVY_CACHE_DIR=/tmp/trivy-cache
            -v /var/run/docker.sock:/var/run/docker.sock
            -v "${report_dir}:/reports:rw"
            "${image}"
            image
            --scanners vuln
            --severity "${severity}"
            --format json
            --output "/reports/trivy-image-${safe_name}.json"
        )

        if [[ "${ignore_unfixed}" == "1" ]]; then
            trivy_cmd+=(--ignore-unfixed)
        fi

        if [[ "${strict}" == "1" ]]; then
            trivy_cmd+=(--exit-code 1)
        else
            trivy_cmd+=(--exit-code 0)
        fi

        trivy_cmd+=("${target}")

        printf 'Trivy image scan: %s (severity=%s, strict=%s, ignore-unfixed=%s)\n' "${target}" "${severity}" "${strict}" "${ignore_unfixed}"
        set +e
        "${trivy_cmd[@]}"
        trivy_status=$?
        set -e

        if [[ "${trivy_status}" -ne 0 ]]; then
            failures=$((failures + 1))
            if [[ "${strict}" != "1" ]]; then
                printf 'Trivy image scan exit %s for %s with strict off — continuing.\n' "${trivy_status}" "${target}" >&2
            fi
        fi
    done

    if [[ "${failures}" -gt 0 && "${strict}" == "1" ]]; then
        printf 'Trivy reported %d failing image target(s).\n' "${failures}" >&2
        return 1
    fi
}

code_security_run_image_scan() {
    local root_dir="$1"
    local skip_fs="${CODE_SECURITY_TRIVY_SKIP_FS:-0}"

    case "${skip_fs}" in
        1 | true | TRUE | yes | YES)
            printf 'CODE_SECURITY_TRIVY_SKIP_FS=%s — skipping filesystem scan.\n' "${skip_fs}"
            ;;
        *)
            code_security_run_trivy_fs "${root_dir}" || return 1
            ;;
    esac

    code_security_run_trivy_images "${root_dir}" || return 1
}
