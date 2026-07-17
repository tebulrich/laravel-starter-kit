FROM dunglas/frankenphp:1.12.7-php8.5-alpine

ARG DOCKER_WWWGROUP=www
ARG DOCKER_WWWUSER=www
ARG CI_COMMIT_TAG=
ARG CI_COMMIT_SHA=local
ARG CI_COMMIT_REF_NAME=local
ARG APP_VERSION=

WORKDIR /var/www/html

ENV TZ=UTC \
    CADDY_ADMIN=off \
    XDG_CONFIG_HOME=/tmp \
    APP_COMMIT=${CI_COMMIT_SHA} \
    APP_REF_NAME=${CI_COMMIT_REF_NAME} \
    OCTANE_WORKERS=4

RUN apk add --no-cache tzdata \
 && ln -snf /usr/share/zoneinfo/$TZ /etc/localtime \
 && echo $TZ > /etc/timezone

RUN apk add --no-cache \
      bash curl wget nano git iputils htop zip unzip \
      logrotate supervisor inotify-tools \
      nodejs yarn \
      mariadb-client \
      postgresql-client postgresql-libs libzip libxml2 libxslt icu-libs oniguruma sqlite-libs \
      postgresql-dev libzip-dev libxml2-dev libxslt-dev icu-dev oniguruma-dev

RUN install-php-extensions fileinfo pcntl pdo_mysql pdo_pgsql intl redis

COPY docker/php/8.5/php.ini /usr/local/etc/php/php.ini

COPY docker/certs/ /tmp/docker-certs/
RUN if ls /tmp/docker-certs/*.crt >/dev/null 2>&1; then \
      cp /tmp/docker-certs/*.crt /usr/local/share/ca-certificates/; \
      update-ca-certificates; \
    fi; \
    rm -rf /tmp/docker-certs

RUN mkdir -p /var/lib/logrotate && chmod 755 /var/lib/logrotate
COPY docker/configs/laravel-log-rotate /etc/logrotate.d/laravel-log-rotate

RUN mkdir -p /etc/supervisor/conf.d
COPY docker/configs/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/configs/supervisor/worker-frankenphp.conf /etc/supervisor/conf.d/worker-frankenphp.conf
COPY docker/configs/supervisor/worker-laravel-cron.conf /etc/supervisor/conf.d/worker-laravel-cron.conf
COPY docker/configs/supervisor/worker-laravel-queue.conf /etc/supervisor/conf.d/worker-laravel-queue.conf
COPY docker/configs/supervisor/task-artisan-migrate.conf /etc/supervisor/conf.d/task-artisan-migrate.conf
COPY docker/configs/supervisor/task-composer.conf /etc/supervisor/conf.d/task-composer.conf

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY --chmod=0755 docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN addgroup -g 1000 "$DOCKER_WWWGROUP" \
 && adduser -D -u 1000 -G "$DOCKER_WWWGROUP" "$DOCKER_WWWUSER" \
 && chown 1000:1000 /var/lib/logrotate -R

COPY --chown=${DOCKER_WWWUSER}:${DOCKER_WWWGROUP} . /var/www/html

RUN set -euo pipefail; \
    VERSION="${APP_VERSION:-}"; \
    if [ -z "$VERSION" ]; then \
      VERSION="$(printf '%s' "${CI_COMMIT_SHA:-local}" | cut -c1-8)"; \
    fi; \
    mkdir -p /var/www/html/public; \
    printf '{\n' > /var/www/html/public/version.json; \
    printf '  "version": "%s",\n' "$VERSION" >> /var/www/html/public/version.json; \
    printf '  "commit": "%s",\n' "${CI_COMMIT_SHA:-local}" >> /var/www/html/public/version.json; \
    printf '  "refName": "%s",\n' "${CI_COMMIT_REF_NAME:-local}" >> /var/www/html/public/version.json; \
    printf '  "tag": "%s",\n' "${CI_COMMIT_TAG:-}" >> /var/www/html/public/version.json; \
    printf '  "builtAtUtc": "%s"\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> /var/www/html/public/version.json; \
    printf '}\n' >> /var/www/html/public/version.json

# storage/ is .dockerignored. Feature instances have no storage PVC, so the empty
# Laravel directory tree must exist in the image or Octane fails on cache paths.
RUN mkdir -p /var/www/html/bootstrap/cache \
 && mkdir -p \
      /var/www/html/storage/app/public \
      /var/www/html/storage/app/private \
      /var/www/html/storage/framework/cache/data \
      /var/www/html/storage/framework/sessions \
      /var/www/html/storage/framework/testing \
      /var/www/html/storage/framework/views \
      /var/www/html/storage/logs/supervisor \
 && mkdir -p /data/caddy /tmp/.local/share/caddy \
 && chown -R 1000:1000 /var/www/html/bootstrap/cache /var/www/html/storage /data/caddy /tmp/.local/share/caddy \
 && chown 1000:1000 /var/www/html/public/version.json

USER $DOCKER_WWWUSER

ENTRYPOINT ["/usr/local/bin/app-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
