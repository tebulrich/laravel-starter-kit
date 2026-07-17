#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${ROOT}"

PORT="${VITE_PORT:-{{VITE_PORT}}}"
NETWORK="${LARAVEL_DOCKER_NETWORK:-{{COMPOSE_NETWORK}}}"
FOREGROUND=0

if [[ "${1:-}" == "--foreground" ]]; then
  FOREGROUND=1
  shift
fi

echo ""
echo "Starting Vue frontend via Docker Compose (detached)"
echo "  URL:              https://localhost:${PORT}"
echo "  Laravel network:  ${NETWORK}"
echo "  (Start Laravel first with ./start.sh in the API repository)"
echo "  Use https://localhost — not http://127.0.0.1 (mkcert)."
echo ""

if ! docker network inspect "${NETWORK}" >/dev/null 2>&1; then
  echo "ERROR: Docker network '${NETWORK}' was not found." >&2
  echo "Start the Laravel stack first so the network exists, then re-run ./start.sh." >&2
  exit 1
fi

./scripts/create-certificate.sh

if [[ "${FOREGROUND}" -eq 1 ]]; then
  exec docker compose up "$@"
fi

docker compose up -d "$@"

echo ""
echo "Frontend is running in the background."
echo "  Open:  https://localhost:${PORT}"
echo "  Logs:  docker compose logs -f frontend"
echo "  Stop:  ./stop.sh"
echo ""
