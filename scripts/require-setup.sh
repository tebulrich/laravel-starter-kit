#!/bin/sh
# Fail if bin/setup has not been applied (needed before Compose / start.sh).
set -eu

script_dir=$(cd "$(dirname "$0")" && pwd)
root_dir=$(cd "${script_dir}/.." && pwd)

env_file="${root_dir}/.env"
manifest="${root_dir}/storage/app/setup-manifest.json"

if [ ! -s "${env_file}" ] || [ ! -s "${manifest}" ]; then
  echo "bin/setup has not been run. Configure the project on the host first:" >&2
  echo "  bin/setup" >&2
  echo "  ./start.sh" >&2
  exit 1
fi

profile=$(grep -E '^COMPOSE_PROFILES=' "${env_file}" | head -n 1 | cut -d= -f2- || true)
profile=${profile#\"}
profile=${profile%\"}
profile=${profile#\'}
profile=${profile%\'}

case "${profile}" in
  mysql|pgsql) ;;
  *)
    echo "COMPOSE_PROFILES must be mysql or pgsql. Run bin/setup to choose a database." >&2
    exit 1
    ;;
esac
