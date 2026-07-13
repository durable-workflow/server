FROM composer:2 AS phpredis-source

ARG PHPREDIS_VERSION=6.3.0
ARG PHPREDIS_COMMIT=df4fab2de7fc327c54c94a13af2b9542e4fbd720

RUN git clone --depth 1 --branch "${PHPREDIS_VERSION}" https://github.com/phpredis/phpredis.git /phpredis \
    && cd /phpredis \
    && RESOLVED_COMMIT="$(git rev-parse HEAD)" \
    && if [ "${RESOLVED_COMMIT}" != "${PHPREDIS_COMMIT}" ]; then \
         echo "ERROR: Resolved phpredis commit ${RESOLVED_COMMIT} does not match pinned PHPREDIS_COMMIT=${PHPREDIS_COMMIT}" >&2; \
         exit 1; \
       fi

FROM php:8.3-cli AS base

COPY --from=phpredis-source /phpredis /usr/src/php/ext/redis

RUN apt-get update && apt-get install -y \
    curl \
    libpq-dev \
    libzip-dev \
    nodejs \
    python3 \
    python3-venv \
    unzip \
    && docker-php-ext-install redis pdo pdo_mysql pdo_pgsql pcntl zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ── Dependencies ──────────────────────────────────────────────────────
# Workflow source verification, dependency installation, and the runtime
# filesystem deliberately share this final stage. A named build context
# therefore cannot replace a post-verification /workflow or /app copy.
FROM base AS production

ARG WORKFLOW_PACKAGE_SOURCE=https://github.com/durable-workflow/workflow.git
ARG WORKFLOW_PACKAGE_REF=2.0.0-alpha.266
ARG WORKFLOW_PACKAGE_COMMIT=bbb0fed0179994754ee395f3685a1f2cc260556a

RUN apt-get update \
    && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/* \
    && if ! printf '%s' "${WORKFLOW_PACKAGE_COMMIT}" | grep -Eq '^[0-9a-f]{40}$'; then \
         echo "ERROR: WORKFLOW_PACKAGE_COMMIT must be a full lowercase Git SHA" >&2; \
         exit 1; \
       fi \
    && git clone --depth 1 --branch "${WORKFLOW_PACKAGE_REF}" "${WORKFLOW_PACKAGE_SOURCE}" /workflow \
    && RESOLVED_COMMIT="$(git -C /workflow rev-parse HEAD)" \
    && if [ "${RESOLVED_COMMIT}" != "${WORKFLOW_PACKAGE_COMMIT}" ]; then \
         echo "ERROR: Resolved commit ${RESOLVED_COMMIT} does not match pinned WORKFLOW_PACKAGE_COMMIT=${WORKFLOW_PACKAGE_COMMIT}" >&2; \
         exit 1; \
       fi \
    && git -C /workflow diff --quiet HEAD -- \
    && printf '%s\n' \
         "${WORKFLOW_PACKAGE_SOURCE}" \
         "${WORKFLOW_PACKAGE_REF}" \
         "${RESOLVED_COMMIT}" \
         > /workflow/.package-provenance

COPY composer.json composer.lock ./
COPY scripts/ci/prepare-release-workflow-composer-metadata.php scripts/ci/prepare-release-workflow-composer-metadata.php
RUN php scripts/ci/prepare-release-workflow-composer-metadata.php \
    && composer update durable-workflow/workflow --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress \
    && cp composer.json /tmp/release-composer.json \
    && cp composer.lock /tmp/release-composer.lock \
    && rm -rf /workflow/.git

COPY . .
RUN cp /tmp/release-composer.json composer.json \
    && cp /tmp/release-composer.lock composer.lock \
    && rm -f /tmp/release-composer.json /tmp/release-composer.lock \
    && composer dump-autoload --optimize \
    && cp /workflow/.package-provenance /app/.package-provenance

# ── Production image ─────────────────────────────────────────────────
COPY docker/bootstrap.sh /usr/local/bin/server-bootstrap
COPY docker/ensure-sqlite-database.sh /usr/local/bin/server-ensure-sqlite
COPY docker/entrypoint.sh /usr/local/bin/server-entrypoint
COPY docker/php-custom.ini /usr/local/etc/php/conf.d/99-custom.ini

RUN chmod +x /usr/local/bin/server-bootstrap /usr/local/bin/server-ensure-sqlite /usr/local/bin/server-entrypoint

# Route cache is safe at build time (no env dependency).
# Config cache is deferred to the entrypoint so runtime env vars take effect.
RUN php artisan route:cache \
    && mkdir -p \
        storage/logs \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/testing \
        bootstrap/cache \
    && chown -R 1000:1000 storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

LABEL org.opencontainers.image.title="Durable Workflow Server" \
      org.opencontainers.image.description="Standalone Durable Workflow server" \
      dev.durable-workflow.package.source="${WORKFLOW_PACKAGE_SOURCE}" \
      dev.durable-workflow.package.ref="${WORKFLOW_PACKAGE_REF}" \
      dev.durable-workflow.package.commit="${WORKFLOW_PACKAGE_COMMIT}"

EXPOSE 8080

# Default to 24 CLI server worker processes so concurrent workflow starts,
# bounded worker polls, and control-plane requests cannot consume every
# request worker ahead of liveness probes. `php artisan serve` only honours
# PHP_CLI_SERVER_WORKERS when `--no-reload` is set (otherwise it warns and
# falls back to a single
# server thread), so both must be present together. The runtime long-poll
# wait gate derives a smaller idle-wait budget from this count and keeps
# the rest for health, workflow start/list, and worker completion traffic.
ENV PHP_CLI_SERVER_WORKERS=24 \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/database/database.sqlite \
    QUEUE_CONNECTION=database \
    CACHE_STORE=file

ENTRYPOINT ["server-entrypoint"]

# Default: run the API server. Override CMD for workers.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080", "--no-reload"]
