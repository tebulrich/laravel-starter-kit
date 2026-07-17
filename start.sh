#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

if [ "${EUID}" -eq 0 ]; then
  echo "Do not run this script as root or with sudo."
  echo "Run it as your normal user: ./start.sh"
  exit 1
fi

# COMPOSE_PROFILES (MySQL vs Postgres) must be set by bin/setup before Compose.
sh "${ROOT_DIR}/scripts/require-setup.sh"

# Host PHP is not required for Docker boot. openssl matches Laravel's APP_KEY
# format (base64: + 32 random bytes). The container entrypoint is the fallback.
ensure_app_key() {
  local current key
  current="$(grep -E '^APP_KEY=' .env | head -1 | cut -d= -f2- || true)"
  current="${current%\"}"
  current="${current#\"}"
  current="${current%\'}"
  current="${current#\'}"
  current="$(printf '%s' "${current}" | tr -d '[:space:]')"
  if [ -n "${current}" ]; then
    return
  fi
  if ! command -v openssl >/dev/null 2>&1; then
    echo "openssl not found; the container will generate APP_KEY on boot if it is still empty."
    return
  fi
  key="base64:$(openssl rand -base64 32 | tr -d '\n')"
  if grep -qE '^APP_KEY=' .env; then
    sed -i "s|^APP_KEY=.*$|APP_KEY=${key}|" .env
  else
    printf '\nAPP_KEY=%s\n' "${key}" >> .env
  fi
  echo "Generated APP_KEY"
}
ensure_app_key

mkdir -p docker/mysql/data docker/pgsql/data storage/logs/supervisor \
  storage/framework/{cache/data,sessions,views,testing} \
  storage/app/{public,private} \
  bootstrap/cache \
  certs

"${ROOT_DIR}/scripts/create-certificate.sh"

docker compose -f compose.yaml -f compose.local.yaml down --remove-orphans
docker compose -f compose.yaml up -d --build --force-recreate

read_env() {
  local key="$1"
  local fallback="$2"
  local value=""
  if [ -f .env ]; then
    value="$(grep -E "^${key}=" .env | head -1 | cut -d= -f2- || true)"
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"
    value="$(printf '%s' "${value}" | tr -d '[:space:]')"
  fi
  if [ -z "${value}" ]; then
    value="${fallback}"
  fi
  printf '%s' "${value}"
}

APP_PORT_HTTP="$(read_env APP_PORT_HTTP 80)"
APP_PORT_HTTPS="$(read_env APP_PORT_HTTPS 443)"
MAILPIT_UI="$(read_env FORWARD_MAILPIT_UI_PORT 8025)"

if [ "${APP_PORT_HTTPS}" = "443" ]; then
  APP_HTTPS_URL="https://localhost"
else
  APP_HTTPS_URL="https://localhost:${APP_PORT_HTTPS}"
fi

echo "Stack is starting. HTTPS: ${APP_HTTPS_URL}"
echo "HTTP redirects to HTTPS (host port ${APP_PORT_HTTP})."
echo "Mailpit UI: http://127.0.0.1:${MAILPIT_UI}"
echo "Use https://localhost (not http://127.0.0.1) so the mkcert certificate matches."
echo "Reconfigure later: bin/setup"
