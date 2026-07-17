#!/bin/sh
# Shared PHP container prep for GitHub Actions quality jobs.
# Prefer this over a local composite action: `uses: ./` fails inside job.container.
set -eu

apk add --no-cache git curl bash unzip icu-dev libzip-dev oniguruma-dev $PHPIZE_DEPS
install-php-extensions intl pcntl pdo_mysql pdo_pgsql pdo_sqlite zip
