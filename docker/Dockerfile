# syntax=docker/dockerfile:1
#
# Red Book image: Apache + mod_php serving public/, with the PHP dependencies
# and the built frontend assets baked in. One image runs two roles, chosen by
# the command (see entrypoint.sh): the web app with its scheduled jobs
# (default) and first-time setup ("init").
#
# Build from the repository root's parent context, as docker-compose.yml does:
#   docker compose -f docker/docker-compose.yml build

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

# ---------------------------------------------------------------------------
# base: PHP + Apache + the extensions and tools every stage needs
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-apache AS base

# Try the normal Debian mirrors first; once the base image's Debian release
# is retired its packages move to archive.debian.org, so fall back to that.
RUN set -eux; \
    install_packages() { \
        apt-get update --error-on=any "$@" \
        && apt-get install -y --no-install-recommends "$@" git unzip; \
    }; \
    if ! install_packages; then \
        sed -i 's|deb.debian.org|archive.debian.org|g' /etc/apt/sources.list; \
        install_packages -o Acquire::Check-Valid-Until=false; \
    fi; \
    rm -rf /var/lib/apt/lists/*; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql opcache; \
    a2enmod rewrite headers

# ---------------------------------------------------------------------------
# deps: the Composer packages alone, so they're rebuilt only when composer.lock
# changes (Ketura, 2026-11-03: faster rebuilds)
# ---------------------------------------------------------------------------
FROM base AS deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=prod

WORKDIR /var/www/html

COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# ---------------------------------------------------------------------------
# vendor: the application code on top, its autoloader, and the bundles' public
# files; what the app needs is gathered in /out, without the packages (those are
# copied from deps in their own layer)
# ---------------------------------------------------------------------------
FROM deps AS vendor

COPY .env ./
COPY bin ./bin
COPY config ./config
COPY migrations ./migrations
COPY public ./public
COPY src ./src
COPY templates ./templates
COPY translations ./translations
RUN set -eux; \
    composer dump-autoload --no-dev --optimize; \
    php bin/console assets:install public --no-interaction; \
    mkdir -p /out/vendor; \
    cp -a .env composer.json bin config migrations public src templates translations /out/; \
    cp -a vendor/autoload.php vendor/autoload_runtime.php vendor/composer /out/vendor/

# ---------------------------------------------------------------------------
# test: the vendor stage plus dev dependencies and the tests, for running PHPUnit:
#   docker build -f docker/Dockerfile --target test -t redbook_check:test .
#   docker run --rm redbook_check:test
# ---------------------------------------------------------------------------
FROM vendor AS test

ENV APP_ENV=test
COPY .env.test phpunit.xml.dist ./
COPY tests ./tests
RUN composer install --prefer-dist --no-scripts --no-interaction --no-progress
CMD ["php", "vendor/bin/phpunit"]

# ---------------------------------------------------------------------------
# assets: gulp builds public/css, public/js and public/manifest.json. It needs
# only the front-end sources and the translation files (gulp turns those into
# js/translations.js), so a PHP-only change doesn't rerun it.
# ---------------------------------------------------------------------------
FROM node:${NODE_VERSION}-bookworm-slim AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund
COPY gulpfile.mjs ./
COPY translations ./translations
COPY assets ./assets
RUN npx gulp

# ---------------------------------------------------------------------------
# app: the runtime image
# ---------------------------------------------------------------------------
FROM base AS app

ARG SUPERCRONIC_VERSION=v0.2.51
ARG TARGETARCH

# supercronic runs the scheduled jobs alongside Apache: cron for containers,
# which keeps the environment and logs to stdout.
RUN set -eux; \
    case "${TARGETARCH:-amd64}" in \
        amd64) sum=1692066e350a88ff4d7d6474fe3c7a6c41ec5e89 ;; \
        arm64) sum=9d4c2000493215a1898502b2fc2dada3e8ea8c7a ;; \
        *) echo "unsupported architecture: ${TARGETARCH}"; exit 1 ;; \
    esac; \
    curl -fsSL -o /usr/local/bin/supercronic \
        "https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-${TARGETARCH:-amd64}"; \
    echo "${sum}  /usr/local/bin/supercronic" | sha1sum -c -; \
    chmod +x /usr/local/bin/supercronic

ENV APP_ENV=prod \
    APACHE_DOCUMENT_ROOT=/var/www/html/public \
    APACHE_MAX_WORKERS=10 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

RUN set -eux; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' -e 's!(CustomLog .*) combined!\1 proxied!' \
        /etc/apache2/sites-available/*.conf

COPY docker/php/app.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/apache/app.conf /etc/apache2/conf-available/app.conf
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
COPY docker/init.sh /usr/local/bin/app-init
COPY docker/crontab /etc/app.crontab
COPY docker/dbcheck.php /usr/local/lib/app/dbcheck.php

# Scripts may arrive with CRLF line endings from a Windows checkout
RUN set -eux; \
    sed -i 's/\r$//' /usr/local/bin/app-entrypoint /usr/local/bin/app-init /etc/app.crontab \
        /etc/apache2/conf-available/app.conf "$PHP_INI_DIR/conf.d/zz-app.ini"; \
    chmod 755 /usr/local/bin/app-entrypoint /usr/local/bin/app-init; \
    chmod 644 /etc/app.crontab /etc/apache2/conf-available/app.conf \
        "$PHP_INI_DIR/conf.d/zz-app.ini" /usr/local/lib/app/dbcheck.php; \
    a2enconf app

WORKDIR /var/www/html
# the packages in a layer of their own: unchanged unless composer.lock changes
COPY --from=deps --chown=www-data:www-data /var/www/html/vendor ./vendor
# then the (small) application layer, and the built front end
COPY --from=vendor --chown=www-data:www-data /out ./
COPY --from=assets --chown=www-data:www-data /app/public ./public
RUN mkdir -p var/cache var/log var/sessions && chown -R www-data:www-data var

EXPOSE 80
ENTRYPOINT ["app-entrypoint"]
CMD ["apache2-foreground"]
