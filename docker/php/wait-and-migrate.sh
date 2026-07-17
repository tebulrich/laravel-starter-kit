#!/usr/bin/env bash
# Supervisor one-shot: wait for FrankenPHP, migrate, optionally mint Passport keys.
set -euo pipefail

cd /var/www/html

timeout_s=60
elapsed=0
until supervisorctl -c /etc/supervisor/supervisord.conf status frankenphp 2>/dev/null | grep -q 'RUNNING'; do
  if [ "${elapsed}" -ge "${timeout_s}" ]; then
    echo "FrankenPHP did not reach RUNNING within ${timeout_s}s." >&2
    exit 1
  fi
  sleep 1
  elapsed=$((elapsed + 1))
done

php artisan migrate --force

passport=""
if [ -f .env ]; then
  passport="$(grep -E '^STARTER_FEATURE_PASSPORT=' .env | head -1 | cut -d= -f2- || true)"
  passport="${passport%\"}"
  passport="${passport#\"}"
  passport="${passport%\'}"
  passport="${passport#\'}"
  passport="$(printf '%s' "${passport}" | tr -d '[:space:]' | tr '[:upper:]' '[:lower:]')"
fi

if [ "${passport}" = "true" ] || [ "${passport}" = "1" ]; then
  if [ ! -f storage/oauth-private.key ]; then
    php artisan passport:keys --no-interaction
  fi
fi
