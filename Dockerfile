# syntax=docker/dockerfile:1
# Production image: FrankenPHP (Caddy + PHP 8.3 in one binary, classic mode, no worker mode).
# See docs/DOCKER.md for the rationale and how to run the queue worker and scheduler.

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.3-bookworm

# ---- Stage 1: PHP dependencies (no dev packages) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# ---- Stage 2: runtime ----
FROM ${PHP_IMAGE} AS runtime

RUN install-php-extensions pdo_pgsql pgsql intl bcmath zip opcache pcntl gd \
    && apt-get update && apt-get install -y --no-install-recommends libcap2-bin \
    # The official image gives frankenphp the cap_net_bind_service file capability (to bind :80).
    # Hosts that run containers with no-new-privileges (Render) refuse to exec such a binary
    # ("Operation not permitted"). We listen on 8080, so the capability is not needed.
    && setcap -r /usr/local/bin/frankenphp \
    && apt-get purge -y libcap2-bin && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

WORKDIR /app
COPY --from=vendor /app /app

RUN rm -rf tests .github docs docker .env .env.* phpunit.xml phpstan.neon \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && useradd --system --uid 10001 --home /app app \
    && chown -R app:app storage bootstrap/cache /data /config

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PORT=8080 \
    CONTAINER_ROLE=app

USER app
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1:".getenv("PORT")."/api/health") ? 0 : 1);' || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint"]
