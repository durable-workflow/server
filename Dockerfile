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

# ── Workflow package source ──────────────────────────────────────────
#
# This stage resolves the durable-workflow/workflow package source.
#
# Default: clones from git. Set WORKFLOW_PACKAGE_COMMIT to a full SHA to
# verify the resolved commit matches (build fails on mismatch).
#
# Offline / reproducible builds: override this stage with a pre-built
# directory using BuildKit's --build-context flag:
#
#   docker build --build-context workflow-source=./path/to/workflow ...
#
# The replacement context must contain the package source at its root
# (composer.json, src/, etc.) and optionally a .git directory for
# provenance recording.
# ─────────────────────────────────────────────────────────────────────
FROM composer:2 AS workflow-source

ARG WORKFLOW_PACKAGE_SOURCE=https://github.com/durable-workflow/workflow.git
ARG WORKFLOW_PACKAGE_REF=2.0.0-alpha.215
ARG WORKFLOW_PACKAGE_COMMIT=

RUN git clone --depth 1 --branch "${WORKFLOW_PACKAGE_REF}" "${WORKFLOW_PACKAGE_SOURCE}" /workflow \
    && cd /workflow \
    && RESOLVED_COMMIT="$(git rev-parse HEAD)" \
    && echo "${WORKFLOW_PACKAGE_SOURCE}" > /workflow/.package-provenance \
    && echo "${WORKFLOW_PACKAGE_REF}" >> /workflow/.package-provenance \
    && echo "${RESOLVED_COMMIT}" >> /workflow/.package-provenance \
    && if [ -n "${WORKFLOW_PACKAGE_COMMIT}" ] && [ "${RESOLVED_COMMIT}" != "${WORKFLOW_PACKAGE_COMMIT}" ]; then \
         echo "ERROR: Resolved commit ${RESOLVED_COMMIT} does not match pinned WORKFLOW_PACKAGE_COMMIT=${WORKFLOW_PACKAGE_COMMIT}" >&2; \
         exit 1; \
       fi

# ── Dependencies ──────────────────────────────────────────────────────
FROM base AS vendor

ARG WORKFLOW_PACKAGE_REF=2.0.0-alpha.215
ARG WORKFLOW_PACKAGE_COMMIT=

COPY --from=workflow-source /workflow /workflow
COPY composer.json composer.lock ./
COPY scripts/ci/prepare-release-workflow-composer-metadata.php scripts/ci/prepare-release-workflow-composer-metadata.php
RUN php scripts/ci/prepare-release-workflow-composer-metadata.php \
    && composer update durable-workflow/workflow --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress \
    && cp composer.json /tmp/release-composer.json \
    && cp composer.lock /tmp/release-composer.lock

COPY . .
RUN cp /tmp/release-composer.json composer.json \
    && cp /tmp/release-composer.lock composer.lock \
    && rm -f /tmp/release-composer.json /tmp/release-composer.lock \
    && composer dump-autoload --optimize

# ── Production image ─────────────────────────────────────────────────
FROM base AS production

ARG WORKFLOW_PACKAGE_SOURCE=https://github.com/durable-workflow/workflow.git
ARG WORKFLOW_PACKAGE_REF=2.0.0-alpha.215
ARG WORKFLOW_PACKAGE_COMMIT=

COPY --from=vendor /app /app
COPY --from=workflow-source /workflow/.package-provenance /app/.package-provenance
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

# Default to 8 CLI server worker processes so long-poll requests do not
# block the rest of the API surface. `php artisan serve` only honours
# PHP_CLI_SERVER_WORKERS when `--no-reload` is set (otherwise it warns
# and falls back to a single server thread), so both must be present
# together. The runtime long-poll wait gate also reserves two of these
# request workers for health and control-plane traffic.
ENV PHP_CLI_SERVER_WORKERS=8 \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/database/database.sqlite \
    QUEUE_CONNECTION=database \
    CACHE_STORE=file

ENTRYPOINT ["server-entrypoint"]

# Default: run the API server. Override CMD for workers.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080", "--no-reload"]
