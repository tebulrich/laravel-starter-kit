#!/usr/bin/env bash
# Local Compose bind-mounts the project over /var/www/html, so the image's
# vendor/ is hidden. Production images bake vendor in CI and inject APP_KEY.
# compose.yaml is dockerignored from the image and is the local-dev signal.
set -euo pipefail

cd /var/www/html

read_env_value() {
  local key="$1"
  local value="${!key:-}"
  if [ -n "${value}" ]; then
    printf '%s' "${value}"
    return
  fi
  if [ -f .env ]; then
    value="$(grep -E "^${key}=" .env | head -1 | cut -d= -f2- || true)"
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"
    printf '%s' "${value}"
  fi
}

# A Compose bind-mount includes compose.yaml; the image dockerignores it.
is_local_bind_mount() {
  [ -f compose.yaml ] || [ -f compose.yml ]
}

if is_local_bind_mount; then
  sh scripts/require-setup.sh
fi

if [ ! -f vendor/autoload.php ]; then
  if is_local_bind_mount; then
    echo "vendor/autoload.php is missing; running composer install..."
    composer install --no-interaction --no-progress --prefer-dist --optimize-autoloader
  else
    echo "vendor/autoload.php is missing. Production images must bake vendor in CI." >&2
    exit 1
  fi
fi

if [ -z "$(read_env_value APP_KEY)" ]; then
  if is_local_bind_mount; then
    echo "APP_KEY is empty; generating one..."
    php artisan key:generate --force --no-interaction --ansi
  else
    echo "APP_KEY is not set. Inject it via the environment before starting Octane." >&2
    exit 1
  fi
fi

exec "$@"
