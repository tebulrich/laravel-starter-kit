# {{APP_NAME}} frontend (Vue 3 + Vite + TypeScript + Tailwind)

## Menu

- [Install](#install)
- [Quick start](#quick-start)
- [Talking to Laravel](#talking-to-laravel)
- [QA](#qa)

## Install

Dependencies are installed by the Laravel setup wizard via Docker when you opt in.
To install again without host yarn:

```bash
cp .env.example .env
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/app" -w /app node:24-alpine \
  sh -lc 'corepack yarn install'
```

## Quick start

Laravel API must already be running (`./start.sh` in the Laravel repo) so the shared Docker network exists.

```bash
./start.sh
```

Runs detached (`docker compose up -d`) so the terminal is free. Opens **https://localhost:{{VITE_PORT}}** (mkcert — use `localhost`, not `127.0.0.1`).

```bash
./stop.sh                      # stop
docker compose logs -f frontend  # follow logs
./start.sh --foreground        # attach to the terminal (optional)
```

Vite joins network `{{COMPOSE_NETWORK}}` and proxies `/api`, `/oauth`, and `/up` to `https://php:443`.

If your Laravel Compose project name differs, set `LARAVEL_DOCKER_NETWORK`.

Optional host yarn (if installed):

```bash
./scripts/create-certificate.sh
yarn install
yarn dev
```

## Talking to Laravel

| Mode | Configuration |
|------|----------------|
| Docker Compose (default) | `VITE_DOCKER_PROXY_TARGET` (defaults to `https://php:443` on network `{{COMPOSE_NETWORK}}`) |
| Host `yarn dev` | Leave `VITE_PROXY_TARGET` as `{{API_PROXY}}` from `.env` |
| Separate deploy | Set `VITE_API_BASE_URL` to the Laravel origin and allow that origin in Laravel `CORS_ALLOWED_ORIGINS` |

Passport Bearer tokens can be added later on the API client; the scaffolded health page is public.

## QA

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/app" -w /app node:24-alpine \
  sh -lc 'corepack yarn qa'
```

Runs lint, typecheck, unit tests, and production build.
