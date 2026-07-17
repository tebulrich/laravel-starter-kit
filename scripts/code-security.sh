#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: scripts/code-security.sh [command]

Security gate separate from code-quality (Pint / Rector / PHPStan / Pest).

Commands:
  check        SAST + secrets + SCA; also ZAP DAST when the compose stack is up (default)
  sast         Semgrep only (PHP/Laravel + JS/TS rules)
  secrets      Gitleaks directory scan (working tree)
  sca          Composer audit; yarn/npm audit when a lockfile exists
  dast         OWASP ZAP baseline (requires compose stack; fails if down)
  sbom         Generate CycloneDX + SPDX SBOM via Syft (Docker)
  image-scan   Trivy filesystem CVE scan; also images listed in CODE_SECURITY_TRIVY_IMAGES
  all          SAST + secrets + SCA + SBOM + Trivy + ZAP (requires compose stack)

Environment:
  CODE_SECURITY_STRICT   1 (default) fail on ERROR findings / advisories / ZAP FAIL / Trivy hits
                            0 report only (exit 0 unless tooling errors)
  CODE_SECURITY_SEMGREP_IMAGE          Docker image (default: semgrep/semgrep:1.173.0)
  CODE_SECURITY_GITLEAKS_IMAGE         Docker image (default: ghcr.io/gitleaks/gitleaks:v8.30.1)
  CODE_SECURITY_ZAP_IMAGE              Docker image (default: ghcr.io/zaproxy/zaproxy:stable)
  CODE_SECURITY_SYFT_IMAGE             Docker image (default: anchore/syft:v1.51.0)
  CODE_SECURITY_TRIVY_IMAGE            Docker image (default: aquasec/trivy:0.74.0)
  CODE_SECURITY_NODE_IMAGE             Docker image for JS SCA fallback (default: node:24-alpine)
  CODE_SECURITY_TRIVY_FS_SEVERITY      Trivy fs severities (default: CRITICAL,HIGH)
  CODE_SECURITY_TRIVY_IMAGE_SEVERITY   Trivy image severities (default: CRITICAL)
  CODE_SECURITY_TRIVY_IGNORE_UNFIXED   1 (default) image scan ignores CVEs with no fix yet
  CODE_SECURITY_TRIVY_IMAGES           Comma-separated local image tags for image-scan
  CODE_SECURITY_TRIVY_SKIP_FS          1 skip filesystem scan (image-only CI step)
  CODE_SECURITY_TRIVY_FS_SKIP_DIRS     Comma-separated skip globs (default includes **/vendor)
  CODE_SECURITY_ZAP_TARGET             Scan URL on compose network (default: http://php:80/up)
  CODE_SECURITY_NPM_AUDIT_LEVEL        npm --audit-level / yarn audit --level (default: high)
  CODE_SECURITY_PHP_SERVICE            Compose PHP container name (auto-detected when unset)
  CODE_SECURITY_COMPOSE_NETWORK  Compose network (auto-detected when unset)

Examples:
  ./scripts/code-security.sh
  ./scripts/code-security.sh sast
  ./scripts/code-security.sh secrets
  ./scripts/code-security.sh sca
  ./scripts/code-security.sh dast
  ./scripts/code-security.sh sbom
  CODE_SECURITY_TRIVY_IMAGES=laravel-starter-kit-php:8.5 ./scripts/code-security.sh image-scan
  docker compose exec php composer code-security
  docker compose exec php composer code-security:sca

Notes:
  - SAST/secrets/SBOM/Trivy/ZAP need Docker on the host. Inside the PHP container those steps are skipped; SCA still runs.
  - check runs ZAP automatically when the php service is up on the Compose network; otherwise skips DAST.
  - dast / all require ./start.sh and fail if the stack is down.
  - SBOM and image-scan are separate from check so the default gate stays fast; CI and `all` run them.
  - Override images and strict mode with CODE_SECURITY_* env vars.
EOF
}

COMMAND="${1:-check}"
case "${COMMAND}" in
    check | sast | secrets | sca | dast | sbom | image-scan | all) ;;
    -h | --help | help)
        usage
        exit 0
        ;;
    *)
        printf 'Unknown command: %s\n\n' "${COMMAND}" >&2
        usage >&2
        exit 1
        ;;
esac

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CODE_SECURITY_ROOT_DIR="${ROOT_DIR}"
# shellcheck source=scripts/lib/code-security-runtime.sh
source "${ROOT_DIR}/scripts/lib/code-security-runtime.sh"

cd "${ROOT_DIR}"

run_step() {
    local label="$1"
    shift

    printf '\n==> %s\n' "${label}"
    "$@"
}

case "${COMMAND}" in
    sast)
        run_step 'SAST (Semgrep)' code_security_run_semgrep "${ROOT_DIR}"
        ;;
    secrets)
        run_step 'Secrets (Gitleaks)' code_security_run_gitleaks "${ROOT_DIR}"
        ;;
    sca)
        run_step 'SCA (composer / yarn / npm audit)' code_security_run_sca "${ROOT_DIR}"
        ;;
    dast)
        run_step 'DAST (OWASP ZAP baseline)' code_security_run_zap "${ROOT_DIR}"
        ;;
    sbom)
        run_step 'SBOM (Syft CycloneDX + SPDX)' code_security_run_sbom "${ROOT_DIR}"
        ;;
    image-scan)
        run_step 'Image / filesystem CVE scan (Trivy)' code_security_run_image_scan "${ROOT_DIR}"
        ;;
    check)
        run_step 'SAST (Semgrep)' code_security_run_semgrep "${ROOT_DIR}"
        run_step 'Secrets (Gitleaks)' code_security_run_gitleaks "${ROOT_DIR}"
        run_step 'SCA (composer / yarn / npm audit)' code_security_run_sca "${ROOT_DIR}"
        run_step 'DAST (OWASP ZAP baseline, if stack is up)' code_security_run_zap_if_stack_up "${ROOT_DIR}"
        ;;
    all)
        run_step 'SAST (Semgrep)' code_security_run_semgrep "${ROOT_DIR}"
        run_step 'Secrets (Gitleaks)' code_security_run_gitleaks "${ROOT_DIR}"
        run_step 'SCA (composer / yarn / npm audit)' code_security_run_sca "${ROOT_DIR}"
        run_step 'SBOM (Syft CycloneDX + SPDX)' code_security_run_sbom "${ROOT_DIR}"
        run_step 'Image / filesystem CVE scan (Trivy)' code_security_run_image_scan "${ROOT_DIR}"
        run_step 'DAST (OWASP ZAP baseline)' code_security_run_zap "${ROOT_DIR}"
        ;;
esac

printf '\nCode security finished (%s).\n' "${COMMAND}"
