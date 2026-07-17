# Laravel Starter Kit

[![CI](https://github.com/tebulrich/laravel-starter-kit/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/tebulrich/laravel-starter-kit/actions/workflows/ci.yml?query=branch%3Amain)

Private Laravel API starter: PHP 8.5, Laravel 13, FrankenPHP/Octane,
Passport, Redis (Valkey) for cache/queue/session, Mailpit locally,
local HTTPS via mkcert (`https://localhost`), host-side quality/security
gates, and GitHub Actions plus GitLab CI that run on clone with no extra
setup (Argo/image push stay opt-in).

## Menu

- [Requirements](#requirements)
- [Local setup](#local-setup)
- [Local HTTPS](#local-https)
- [Setup wizard](#setup-wizard)
- [Vue frontend](#vue-frontend)
- [Stack](#stack)
- [Configuration](#configuration)
- [HTTP surface](#http-surface)
- [Auth](#auth)
- [Console commands](#console-commands)
- [Cron / scheduled jobs](#cron--scheduled-jobs)
- [Code quality](#code-quality)
- [Code security](#code-security)
- [Wizard matrix tests](#wizard-matrix-tests)
- [CI and deployment](#ci-and-deployment)
- [Staging vs production](#staging-vs-production)

## Requirements

- git (to fetch the snapshot and `git init` the new app)
- Docker Engine with Compose v2
- PHP 8.5+ and Composer 2 on the host (for `bin/setup` and QA)
- [mkcert](https://github.com/FiloSottile/mkcert) for local HTTPS (`scripts/create-certificate.sh` installs it when missing)
- [GitHub CLI](https://cli.github.com/) (`gh`) only if you want a GitHub repository now or later
- Container registry credentials when building and pushing deployable images

## Local setup

### Create a project

Do not `git clone` this repository as your app. One command fetches the snapshot, runs `git init`, and does **not** create a GitHub repository:

```bash
curl -fsSL https://raw.githubusercontent.com/tebulrich/laravel-starter-kit/main/bin/create-app | bash -s -- my-app
cd my-app
bin/setup
./start.sh
```

When you want a GitHub remote later:

```bash
gh repo create my-app --private --source=. --remote=origin --push
```

Do not use `laravel new --using=`. That installer is built for official Vue/React/Livewire kits: it migrates on the host, rewrites `.github/workflows/tests.yml`, runs `npm run build`, and installs Laravel Boost. This kit already has Pest, Docker, `bin/setup`, and GitHub/GitLab CI.

`bin/setup` is a **host-side** configurator (not an Artisan command). It writes `.env`, `COMPOSE_PROFILES`, and `storage/app/setup-manifest.json`. `./start.sh` and `docker compose up` **fail** until that has run. Re-run `bin/setup` later to change database, auth, or Vue layout (an existing `APP_KEY` is kept).

`./start.sh` creates mkcert TLS certs under `certs/` (once), writes `APP_KEY` when empty, and ensures storage dirs exist. The PHP container installs Composer dependencies on boot when `vendor/` is missing (local bind-mounts do not include a baked `vendor/`).

Xdebug (optional second compose file; separate Apache image, not FrankenPHP):

```bash
docker compose -f compose.yaml -f compose.local.yaml up -d
```

Stop:

```bash
./stop.sh
```

### Quick start

| Endpoint | Purpose |
|----------|---------|
| `https://localhost/up` | Framework liveness |
| `https://localhost/api/health` | Readiness (app + DB + Redis) |
| `https://localhost/api/system/status` | Sample API (Passport token when Passport is enabled) |
| `https://localhost:5173` | Vue SPA (sibling or `frontend/`; see [Vue frontend](#vue-frontend)) |
| `http://127.0.0.1:8025` | Mailpit UI |

MySQL or PostgreSQL (profile), Redis (Valkey), and Mailpit bind to localhost only.

Before every push (PHP and Composer run in the `php` service; host PHP is not required):

```bash
docker compose exec php composer code-quality:check
./scripts/code-security.sh
```

Details: [Code quality](#code-quality), [Code security](#code-security).

## Local HTTPS

Local Compose terminates TLS in FrankenPHP/Caddy with **mkcert** certificates.
Production/staging TLS stays at the cluster ingress;
the deployable image Caddyfile listens on HTTP only.

| Item | Detail |
|------|--------|
| Browser URL | `https://localhost` (not `http://127.0.0.1` — the cert is issued for `localhost`) |
| Cert generation | `./start.sh` runs `scripts/create-certificate.sh` once; PEMs land in `certs/` (gitignored) |
| Manual regen | `./scripts/create-certificate.sh` (skips if `certs/localhost.pem` already exists) |
| HTTP | Host `APP_PORT_HTTP` (default `80`) permanently redirects to HTTPS |
| HTTPS | Host `APP_PORT_HTTPS` (default `443`), HTTP/2 + HTTP/3 (UDP 443) |
| Trust store | `mkcert -install` (script does this); Firefox/Chrome pick up the local CA |

Non-standard HTTPS port example: set `APP_PORT_HTTPS=8443` and `APP_URL=https://localhost:8443`, then restart.

## Setup wizard

Run this once **before** `./start.sh` or `docker compose up`. Re-run later to change options:

```bash
bin/setup
# or
php setup/cli.php
```

Non-interactive example:

```bash
bin/setup --no-interaction \
  --name="Orders API" \
  --url=https://localhost \
  --port=80 \
  --https-port=443 \
  --database=pgsql \
  --auth=passport \
  --layout=separate \
  --sentry \
  --no-telescope \
  --queue-worker \
  --scheduler \
  --sample-api \
  --generate-key \
  --yarn-install \
  --on-existing-frontend=keep
```

`--layout=separate` creates `../{project}-frontend` (sibling of this repo) with its own GitHub/GitLab CI and a Docker Compose Vite stack. Monolith uses `frontend/` in this repo (same Compose files; CI jobs activate when that tree exists). Frontend is always a Vue SPA.

When that Vue tree already exists, setup asks what to do (or use flags):

| Action | Flag | Effect |
|--------|------|--------|
| keep | `--on-existing-frontend=keep` (default) | Reuse tree; skip scaffold |
| backup | `--on-existing-frontend=backup` | Rename to `*-backup-<UTC>`, then scaffold |
| delete | `--on-existing-frontend=delete` or `--force-frontend` | Remove, then scaffold |
| abort | `--on-existing-frontend=abort` | Exit without changing the frontend tree |

Use `--dry-run` to print planned actions without writing files.

| Choice | Effect |
|--------|--------|
| Application name / URL / HTTP+HTTPS ports | `.env` `APP_*` / `APP_PORT_HTTP` / `APP_PORT_HTTPS` |
| Database | `DB_*` plus `COMPOSE_PROFILES=mysql\|pgsql` (only that DB service starts) |
| Auth stack | Passport, Passport + Authentik placeholders, or session-only |
| Frontend | Always Vue.js SPA (Vite + TypeScript + Tailwind) |
| Layout | Monolith (`frontend/` here) or Separate (`../{project}-frontend` with own CI) |
| Sentry / Telescope / Debugbar | Env toggles |
| Queue / scheduler / migrate-on-boot | Supervisor `autostart` flags |
| Sample API | `STARTER_SAMPLE_API` + `config/starter.php` |
| yarn install | Installs frontend dependencies via Docker (php image / `node:24` fallback) |
| Existing Vue frontend | keep / backup / delete / abort (`--on-existing-frontend`) |

A machine-readable copy of the last run is stored at `storage/app/setup-manifest.json` (gitignored).

## Vue frontend

After `bin/setup`, start the Laravel API first (`./start.sh` here), then the SPA:

```bash
# Separate layout (default path for this repo name):
cd ../laravel-starter-kit-frontend
./start.sh          # detached: docker compose up -d + mkcert
./stop.sh           # stop Vite
docker compose logs -f frontend
```

| Item | Detail |
|------|--------|
| Browser URL | `https://localhost:5173` (mkcert — use `localhost`, not `127.0.0.1`) |
| Runtime | Node 24 container; host yarn is not required |
| API proxy | Vite proxies `/api`, `/oauth`, `/up` to `https://php:443` on network `{project}_app` |
| TLS | `./scripts/create-certificate.sh` (run by `./start.sh`) writes `certs/` in the frontend tree |
| HTTP/3 | Laravel/Caddy on `:443` supports h3; Vite on `:5173` is HTTP/2 only |
| Docs in scaffold | See that repo’s `README.md` |

Monolith layout: same commands from `frontend/` inside this repository.

Optional foreground attach: `./start.sh --foreground`.

## Stack

| Component | Choice |
|-----------|--------|
| Runtime | `dunglas/frankenphp:1.12.7-php8.5-alpine` + Laravel Octane (+ Node/yarn in the image for frontend installs) |
| Local TLS | mkcert + Caddy (`https://localhost`; see [Local HTTPS](#local-https)) |
| Process manager | Supervisor (FrankenPHP, Redis queue, scheduler, migrate + Passport keys) |
| Frontend (Vue) | Sibling or `frontend/` Compose service (`./start.sh` → Vite HTTPS) |
| Database | MySQL 8.4 **or** PostgreSQL 18 via Compose profiles |
| Cache / queue / session | Valkey (Redis-compatible), `predis` |
| Mail | Mailpit `v1.30.7` |
| Observability | Sentry, Telescope (dev) |

Local Compose mounts watch-mode Supervisor/Caddy configs. The deployable image uses non-watch FrankenPHP and ships without Xdebug.

## Configuration

Run `bin/setup` (not a hand-copied `.env.example`) so Compose gets `COMPOSE_PROFILES`. Important variables:

| Variable | Role |
|----------|------|
| `APP_URL` | Browser origin — local default `https://localhost` |
| `APP_PORT_HTTP` / `APP_PORT_HTTPS` | Host ports for Caddy (80 redirects → 443) |
| `COMPOSE_PROFILES` | `mysql` or `pgsql` — which database service starts |
| `TRUSTED_PROXIES` | `*` locally (Caddy); set CIDRs behind a real ingress |
| `QUEUE_CONNECTION` / `CACHE_STORE` / `SESSION_DRIVER` | Default `redis` |
| `MAIL_HOST` | `mailpit` locally |
| `AUTH_PROVIDER` | `native` or `authentik` |
| `STARTER_SAMPLE_API` | Toggles sample status route |
| `STARTER_FEATURE_PASSPORT` | Enables Passport token middleware on sample API |
| `STARTER_FRONTEND_LAYOUT` | `monolith` or `separate` |
| `CORS_ALLOWED_ORIGINS` | Comma-separated browser origins |
| `SENTRY_LARAVEL_DSN` | Leave empty until configured |
| `DOCKER_WWWUSER` / `DOCKER_WWWGROUP` | Image user (UID/GID 1000) |
| `OCTANE_WORKERS` | FrankenPHP worker count (default 4) |

Do not commit secrets. Production credentials belong in KeePass / cluster secrets.

## HTTP surface

| Method / path | Auth | Purpose |
|---------------|------|---------|
| `GET /up` | none | Framework liveness |
| `GET /api/health` | none | Readiness: app, database, Redis (503 if degraded) |
| `GET /api/system/status` | `auth:api` when Passport enabled | Sample status JSON |
| `GET /` | none | Redirects to `/up` |

API routes use throttle (`api` limiter, 60/min), trusted proxies, CORS, and JSON error bodies for `api/*`.

Controllers stay thin: Form Request → service → JSON.

## Auth

Passport migrations ship under `database/migrations/`. The `api` guard uses the `passport` driver. When `STARTER_FEATURE_PASSPORT=true`:

- Sample `/api/system/status` requires a Bearer token
- Migrate-on-boot runs `passport:keys` if `storage/oauth-private.key` is missing

Create a personal access client / tokens as needed after first boot (`php artisan passport:client`).

Session-only wizard choice sets `STARTER_FEATURE_PASSPORT=false` (no `passport:keys`; sample status stays public if enabled).

Authentik: wizard writes URL/client placeholders. Install and wire an Authentik JWT middleware package separately when SSO is required.

## Console commands

| Command | Purpose |
|---------|---------|
| `bin/create-app` | Fetch or copy the kit, `git init`, no kit history; `--github` is optional |
| `bin/setup` / `php setup/cli.php` | Host-side project configurator (`.env`, Compose profile, Supervisor, flags) |
| `composer code-quality` / `:check` | Pint / Rector / PHPStan / Pest (run inside `php`: `docker compose exec php composer …`) |
| `./scripts/code-security.sh` | Host SAST / secrets / SCA / optional ZAP (see [Code security](#code-security)) |
| `logs:rotate` | Rotate Laravel log files under `storage/logs` (also scheduled daily) |
| `octane:frankenphp` | FrankenPHP/Octane HTTP workers (Supervisor) |
| `queue:work redis` | Redis queue worker (Supervisor) |
| `schedule:work` | Run the scheduler loop (Supervisor) |
| `migrate --force` | Migrate-on-boot (Supervisor one-shot) |
| `passport:keys` | Generate OAuth key pair when missing (migrate-on-boot) |

## Cron / scheduled jobs

| Schedule | Command | Environments |
|----------|---------|--------------|
| Daily | `logs:rotate` | All (via `schedule:work` in Supervisor when enabled) |

No cluster CronJobs are defined in this repo; the in-app scheduler runs inside the app container when the wizard enables it.

## Code quality

Configs live under `qa/`. Prefer **`docker compose exec php composer code-quality:check`** before push; use **`composer code-quality`** (same, inside `php`) when you want Pint/Rector to fix.

| Script | Behaviour |
|--------|-----------|
| `composer code-quality` | Fix mode (Pint / Rector / PHPStan / Pest) |
| `composer code-quality:check` | Verify only (CI parity — run before every push) |
| `composer test` | Pest via `qa/phpunit.xml` (excludes wizard matrix; also runs existing PHPUnit TestCase classes) |
| `composer test:wizard-matrix` | Full wizard combination matrix (local only) |
| `composer setup` | Runs `bin/setup` |

PHPStan is Larastan **level 9** (max) on `app/`, `config/`, `database/`, `routes/`, and `setup/src/`. Rector uses the same trees plus `tests/`. Pint uses the Laravel preset on the whole repo (minus `vendor` / `storage` / `docker`).

## Code security

Requires Docker for SAST / secrets / SBOM / Trivy / ZAP. On the host (no working PHP needed): **`./scripts/code-security.sh`**.

`composer code-security` is the same script, but it needs a working PHP/`composer` (use it inside the `php` container). There, Docker scanners are skipped and SCA still runs.

| Script | Behaviour |
|--------|-----------|
| `./scripts/code-security.sh` / `check` | Semgrep SAST + Gitleaks + composer audit (+ yarn/npm audit when a lockfile exists); ZAP if stack is up |
| `./scripts/code-security.sh sast` | Semgrep only (PHP/Laravel + JS/TS rules) |
| `./scripts/code-security.sh secrets` | Gitleaks working-tree scan (config: `.gitleaks.toml`) |
| `./scripts/code-security.sh sca` | Composer audit (fails on abandoned packages); yarn or npm audit for discovered lockfiles |
| `./scripts/code-security.sh dast` | OWASP ZAP baseline against `http://php:80/up` (stack must be up) |
| `./scripts/code-security.sh sbom` | CycloneDX + SPDX via Syft |
| `./scripts/code-security.sh image-scan` | Trivy filesystem (+ images in `CODE_SECURITY_TRIVY_IMAGES`) |
| `./scripts/code-security.sh all` | SAST + secrets + SCA + SBOM + Trivy + ZAP (fails if stack is down) |
| `./scripts/tests/code-security-runtime.test.sh` | Shell unit tests for the security runtime |
| `./scripts/tests/require-setup.test.sh` | Shell tests for the Compose / start.sh setup gate |
| `./scripts/tests/create-app.test.sh` | Shell tests for `bin/create-app` (local default) |

Reports land under `qa/semgrep/reports`, `qa/gitleaks/reports`, `qa/zap/reports`, `qa/sbom/reports`, `qa/trivy/reports` (gitignored). Tool image and strict-mode overrides use the `CODE_SECURITY_*` env vars (see `scripts/code-security.sh --help`).

## Wizard matrix tests

These tests apply **every structural wizard combination** (layout × database × auth) plus each boolean flag true/false. They write into a temp directory and assert scaffolds, `.env`, Supervisor, manifest, and CLI parsing.

They are **excluded from CI** (`wizard-matrix` group in `qa/phpunit.xml`) because they are host-heavy and optional yarn installs via Docker are slow.

```bash
# Scaffold assertions only (default; no yarn install)
composer test:wizard-matrix

# Same, plus yarn install for each frontend scaffold (slow; needs Docker)
RUN_WIZARD_MATRIX_YARN=1 composer test:wizard-matrix
```

Equivalent Pest invocation:

```bash
php vendor/bin/pest -c qa/phpunit.xml --group wizard-matrix
```

## CI and deployment

Clone-and-push is enough. No CI variables, registry tokens, or GitHub Advanced Security are required for the default gate.

| Host | File | Runs on clone with zero extra config |
|------|------|--------------------------------------|
| GitHub | `.github/workflows/ci.yml` | Yes — every push and pull request |
| GitLab | `.gitlab-ci.yml` | Yes — push, merge request, and web pipelines |

The two files run the **same jobs** (Pint, Rector, PHPStan, Pest, scripts-qa, ShellCheck, Semgrep/Gitleaks/SCA/SBOM/Trivy). Vue sibling CI is copied by the wizard into that repo (`setup/templates/vue-spa-ci/`). Dependabot is `.github/dependabot.yml` (Composer, Actions, PHP Dockerfile).

Shared assert: `scripts/ci/assert-docker-context.sh`.

| Job | Tool |
|-----|------|
| install-dependencies | Composer install + Docker context assert |
| laravel-pint | Pint verify |
| laravel-rector | Rector dry-run |
| laravel-phpstan | PHPStan |
| laravel-pest | Pest (PHPUnit TestCase files included) |
| scripts-qa | Bash tests for setup gate, create-app, and security runtime |
| shellcheck | `shellcheck -S warning` on `scripts/**/*.sh` |
| code-security | Semgrep + Gitleaks + SCA + SBOM + Trivy FS (artifacts uploaded) |
| frontend-qa | `yarn qa` when `frontend/package.json` exists (Vue monolith; sibling SPA CI lives in that repo) |

**Not** on by default (need credentials / cluster wiring):

- GitHub `.github/workflows/docker-image.yml` — `workflow_dispatch` only, job `if: false` until GHCR push is configured
- GitLab `build-branch-image` — commented; enable when Kaniko + registry variables exist
- GitLab `.gitlab-ci-common.yml` — Argo/Sentry only when `DEPLOY_ARGO_ENABLED=1` plus `DEPLOY_CI_COMMON_REPO_URL` and related vars

Without `DEPLOY_ARGO_ENABLED`, Argo/Sentry jobs do not run.

Image Dockerfile: `docker/php/8.5/Dockerfile` (no Xdebug, no `--watch`). Local Compose mounts `docker/Caddyfile.local` and `docker/configs/supervisor/local/worker-frankenphp.conf`.

## Staging vs production

| Concern | Local / feature | Staging / production |
|---------|-----------------|----------------------|
| TLS | mkcert under `certs/` (FrankenPHP/Caddy) | Cluster ingress terminates TLS |
| HTTP runtime | Compose bind-mount + Octane `--watch` | Image Supervisor without watch; tune `OCTANE_WORKERS` |
| Database | Compose profile `mysql` or `pgsql` | Managed MySQL/Postgres; same `DB_*` shape |
| Storage | Bind mount / image empty tree | PVC or image tree (feature instances often have no PVC) |
| Queue / cache / session | Compose Valkey | Cluster Redis/Valkey |
| Mail | Mailpit | Real SMTP |
| Auth | Passport keys on first migrate | Passport keys from secret store; Authentik when required |
| Secrets | `.env` (gitignored) | KeePass / cluster secrets — never bake into the image |
| Argo | Off unless `DEPLOY_ARGO_ENABLED=1` | Staging on `develop`; feature instances on other branches |
