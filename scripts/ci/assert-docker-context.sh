#!/usr/bin/env bash
# Shared by GitLab CI and GitHub Actions.
# vendor must reach the image build context; storage/ may be ignored but the
# Dockerfile must recreate the Laravel tree and chown it for UID 1000.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

DOCKERFILE_PATH="docker/php/8.5/Dockerfile"

if [ -f .dockerignore ] && grep -E '^[[:space:]]*vendor[[:space:]]*$' .dockerignore >/dev/null; then
  echo "ERROR: .dockerignore must not exclude vendor/" >&2
  exit 1
fi

if [ -f .dockerignore ] && grep -E '^[[:space:]]*bootstrap/cache[[:space:]]*$' .dockerignore >/dev/null; then
  echo "ERROR: .dockerignore must not exclude bootstrap/cache" >&2
  exit 1
fi

if [ ! -f "$DOCKERFILE_PATH" ]; then
  echo "ERROR: missing $DOCKERFILE_PATH" >&2
  exit 1
fi

for required_storage_path in \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs/supervisor
do
  if ! grep -F "$required_storage_path" "$DOCKERFILE_PATH" >/dev/null; then
    echo "ERROR: $DOCKERFILE_PATH must create $required_storage_path" >&2
    exit 1
  fi
done

if ! tr '\n' ' ' < "$DOCKERFILE_PATH" | grep -Eq 'chown[[:space:]]+-R[[:space:]]+1000:1000[^&]*\/var\/www\/html\/storage'; then
  echo "ERROR: $DOCKERFILE_PATH must chown /var/www/html/storage to 1000:1000 (supervisord runs as UID 1000)" >&2
  exit 1
fi

if [ ! -f docker/php/entrypoint.sh ]; then
  echo "ERROR: missing docker/php/entrypoint.sh" >&2
  exit 1
fi

if ! grep -q 'ENTRYPOINT \["/usr/local/bin/app-entrypoint"\]' "$DOCKERFILE_PATH"; then
  echo "ERROR: $DOCKERFILE_PATH must ENTRYPOINT app-entrypoint so local bind-mounts install vendor before Octane" >&2
  exit 1
fi

if [ ! -f scripts/require-setup.sh ]; then
  echo "ERROR: missing scripts/require-setup.sh" >&2
  exit 1
fi

if ! grep -q 'setup-required:' compose.yaml; then
  echo "ERROR: compose.yaml must fail up until bin/setup has run (setup-required)" >&2
  exit 1
fi

if ! grep -q 'sh scripts/require-setup.sh' docker/php/entrypoint.sh; then
  echo "ERROR: docker/php/entrypoint.sh must run scripts/require-setup.sh on local bind-mounts" >&2
  exit 1
fi

if ! grep -qE '^certs/' .dockerignore; then
  echo "ERROR: .dockerignore must exclude certs/ so mkcert keys are not baked into images" >&2
  exit 1
fi

if ! grep -qE '^auth.json$' .dockerignore; then
  echo "ERROR: .dockerignore must exclude auth.json" >&2
  exit 1
fi

if [ ! -f docker/php/wait-and-migrate.sh ]; then
  echo "ERROR: missing docker/php/wait-and-migrate.sh" >&2
  exit 1
fi

echo "Docker context and storage tree asserts passed"
