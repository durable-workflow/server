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

FROM php:8.3-apache AS base

COPY --from=phpredis-source /phpredis /usr/src/php/ext/redis

RUN apt-get update && apt-get install -y \
    curl \
    libpq-dev \
    libzip-dev \
    nodejs \
    python3 \
    python3-venv \
    unzip \
    && docker-php-ext-install opcache redis pdo pdo_mysql pdo_pgsql pcntl zip bcmath \
    && groupmod --gid 1000 www-data \
    && usermod --uid 1000 --gid 1000 www-data \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ── Dependencies ──────────────────────────────────────────────────────
# Workflow source verification, dependency installation, and the runtime
# filesystem deliberately share this final stage. A named build context
# therefore cannot replace a post-verification /workflow or /app copy.
FROM base AS production

ARG WORKFLOW_PACKAGE_SOURCE
ARG WORKFLOW_PACKAGE_REF
ARG WORKFLOW_PACKAGE_COMMIT
ARG WORKFLOW_PACKAGE_QUALIFICATION_REF

COPY composer.json composer.lock ./
COPY scripts/ci/WorkflowPackageAuthority.php scripts/ci/WorkflowPackageAuthority.php
COPY scripts/ci/resolve-workflow-package-authority.php scripts/ci/resolve-workflow-package-authority.php
COPY scripts/ci/prepare-release-workflow-composer-metadata.php scripts/ci/prepare-release-workflow-composer-metadata.php

RUN apt-get update \
    && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/* \
    && eval "$(php scripts/ci/resolve-workflow-package-authority.php --format=shell)" \
    && git init /workflow \
    && git -C /workflow remote add origin "${WORKFLOW_PACKAGE_SOURCE}" \
    && git -C /workflow fetch --depth 1 origin "${WORKFLOW_PACKAGE_COMMIT}" \
    && git -C /workflow checkout --detach FETCH_HEAD \
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
    && cmp /workflow/.package-provenance vendor/durable-workflow/workflow/.package-provenance \
    && cp vendor/durable-workflow/workflow/.package-provenance /app/.package-provenance

# ── Production image ─────────────────────────────────────────────────
COPY docker/bootstrap.sh /usr/local/bin/server-bootstrap
COPY docker/ensure-sqlite-database.sh /usr/local/bin/server-ensure-sqlite
COPY docker/entrypoint.sh /usr/local/bin/server-entrypoint
COPY docker/healthcheck.sh /usr/local/bin/server-healthcheck
COPY docker/process-healthcheck.sh /usr/local/bin/server-process-healthcheck
COPY docker/apache-mpm-prefork.conf /etc/apache2/mods-available/mpm_prefork.conf
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php-custom.ini /usr/local/etc/php/conf.d/99-custom.ini

RUN chmod +x \
        /usr/local/bin/server-bootstrap \
        /usr/local/bin/server-ensure-sqlite \
        /usr/local/bin/server-entrypoint \
        /usr/local/bin/server-healthcheck \
        /usr/local/bin/server-process-healthcheck \
    && sed -ri 's!^Listen 80$!Listen 8080!' /etc/apache2/ports.conf

# Route cache is safe at build time (no env dependency).
# Config cache is deferred to the entrypoint so runtime env vars take effect.
RUN php artisan route:cache \
    && mkdir -p \
        database \
        storage/logs \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/testing \
        bootstrap/cache \
        /var/run/apache2 \
        /var/lock/apache2 \
        /var/log/apache2 \
    && chown -R www-data:www-data \
        database \
        storage \
        bootstrap/cache \
        /var/run/apache2 \
        /var/lock/apache2 \
        /var/log/apache2 \
    && chmod -R ug+rwX \
        database \
        storage \
        bootstrap/cache \
        /var/run/apache2 \
        /var/lock/apache2 \
        /var/log/apache2

LABEL org.opencontainers.image.title="Durable Workflow Server" \
      org.opencontainers.image.description="Standalone Durable Workflow server" \
      dev.durable-workflow.package.authority="composer.lock"

EXPOSE 8080

# Plain `docker run` users get the same bounded readiness signal as Compose and
# Kubernetes. Keep a compact blocker summary in Docker's health log so first-run
# bootstrap and authentication remediation remains visible through `docker inspect`.
HEALTHCHECK --interval=10s --timeout=5s --start-period=5s --retries=3 \
    CMD ["server-healthcheck"]

# Apache owns concurrent HTTP admission. Keep idle workflow/activity polls and
# query-task polls below the prefork request capacity so synchronous queries,
# completions, control-plane requests, and health checks always have workers.
ENV DW_WORKER_LONG_POLL_MAX_CONCURRENT=2 \
    DW_QUERY_TASK_POLL_MAX_CONCURRENT=1 \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/database/database.sqlite \
    QUEUE_CONNECTION=database \
    CACHE_STORE=file

ENTRYPOINT ["server-entrypoint"]

# Default: run the concurrent API server. Compose and Kubernetes override this
# command with PHP CLI processes for bootstrap, queue workers, and schedulers.
CMD ["apache2-foreground"]
