# Fixed TLS toolchains (GHSA-6q48-6c7w-9gw2), pinned by version and digest.
FROM composer:2.10.3@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee AS composer
FROM node:22.23.4-bookworm-slim@sha256:de170b39439eb0e5a3444ad7d60b015614a381e10fecae2e30ec9e735122f38d AS node

FROM composer AS phpredis-source

ARG PHPREDIS_VERSION=6.3.0
ARG PHPREDIS_COMMIT=df4fab2de7fc327c54c94a13af2b9542e4fbd720

# Check the store this stage downloads through before its first download.
COPY docker/trust/ /usr/local/lib/server-trust/
RUN sh /usr/local/lib/server-trust/verify-trust.sh system

# git clones through libcurl, whose Brotli decoder must carry the
# CVE-2026-111497 fix: Alpine's signed brotli-libs 1.2.1-r0 or later.
RUN apk add --no-cache --upgrade "brotli-libs>=1.2.1-r0" \
    && apk info -e "brotli-libs>=1.2.1-r0"

RUN git clone --depth 1 --branch "${PHPREDIS_VERSION}" https://github.com/phpredis/phpredis.git /phpredis \
    && cd /phpredis \
    && RESOLVED_COMMIT="$(git rev-parse HEAD)" \
    && if [ "${RESOLVED_COMMIT}" != "${PHPREDIS_COMMIT}" ]; then \
         echo "ERROR: Resolved phpredis commit ${RESOLVED_COMMIT} does not match pinned PHPREDIS_COMMIT=${PHPREDIS_COMMIT}" >&2; \
         exit 1; \
       fi

# Debian 13 has no fixed libbrotli1 for CVE-2026-111497 yet: rebuild Debian's
# own source with upstream's fix, for each release architecture (see
# docker/brotli/build.sh). The runtime stage installs only that package.
FROM php@sha256:d89e8b3d2033c3179dcd9999d913670eb12a07410a95e60205c64e7d0fac7aef AS brotli
COPY docker/trust/ /usr/local/lib/server-trust/
RUN sh /usr/local/lib/server-trust/verify-trust.sh system
COPY docker/brotli/ /usr/src/server-brotli/
RUN sh /usr/src/server-brotli/build.sh

# The fixed official Apache runtime is pinned for both release architectures.
FROM php@sha256:d89e8b3d2033c3179dcd9999d913670eb12a07410a95e60205c64e7d0fac7aef AS base

# Both web and CLI roles require the fixed PHP runtime, including cached builds.
RUN php -r 'if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 3 || PHP_VERSION_ID < 80336) { \
    fwrite(STDERR, "Patched PHP 8.3.36 or later on the 8.3 line is required; found ".PHP_VERSION.PHP_EOL); \
    exit(1); }'

COPY --from=phpredis-source /phpredis /usr/src/php/ext/redis

# The image's trust checks; `server-verify-trust` reruns them on any image.
COPY docker/trust/ /usr/local/lib/server-trust/
# The fixed Brotli decoder and its check, `server-verify-brotli`.
COPY docker/brotli/ /usr/local/lib/server-brotli/
COPY --from=brotli /out/ /usr/src/server-brotli-deb/

# Published conformance runners execute from the exact Server image.
# Keep their Node and Python runtimes available alongside the service. Node
# comes from the pinned stage, not Debian's end-of-life package. The system
# store is checked before the first download.
RUN chmod 0755 /usr/local/lib/server-trust/verify-trust.sh \
    && ln -s /usr/local/lib/server-trust/verify-trust.sh /usr/local/bin/server-verify-trust \
    && server-verify-trust system \
    && apt-get update && apt-get install -y --no-install-recommends \
    curl \
    libpq-dev \
    libstdc++6 \
    libzip-dev \
    python3 \
    python3-venv \
    unzip \
    util-linux \
    && dpkg -i /usr/src/server-brotli-deb/libbrotli1_*.deb \
    && rm -rf /usr/src/server-brotli-deb \
    && ln -s /usr/local/lib/server-brotli/verify-brotli.sh /usr/local/bin/server-verify-brotli \
    && docker-php-ext-install opcache redis pdo pdo_mysql pdo_pgsql pcntl zip bcmath \
    && groupmod --gid 1000 www-data \
    && usermod --uid 1000 --gid 1000 www-data \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node

# Every store the image's runtimes use, and every Brotli decoder they load,
# before the production stage downloads.
RUN server-verify-trust final \
    && server-verify-brotli

# Source qualification exercises the actual startup path from this base too.
COPY --chmod=0755 docker/ensure-sqlite-database.sh /usr/local/bin/server-ensure-sqlite

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
         > /workflow/.package-provenance \
    && rm -rf /workflow/.git \
    && apt-get purge -y git \
    && apt-get autoremove -y --purge \
    && rm -rf /var/lib/apt/lists/* \
    && server-verify-brotli

RUN php scripts/ci/prepare-release-workflow-composer-metadata.php \
    && composer update durable-workflow/workflow --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress \
    && cp composer.json /tmp/release-composer.json \
    && cp composer.lock /tmp/release-composer.lock \
    && test -f /workflow/.package-provenance

COPY . .
RUN cp /tmp/release-composer.json composer.json \
    && cp /tmp/release-composer.lock composer.lock \
    && rm -f /tmp/release-composer.json /tmp/release-composer.lock \
    && install -m 0664 /dev/null database/database.sqlite \
    && composer dump-autoload --optimize \
    && cmp /workflow/.package-provenance vendor/durable-workflow/workflow/.package-provenance \
    && cp vendor/durable-workflow/workflow/.package-provenance /app/.package-provenance

# ── Production image ─────────────────────────────────────────────────
COPY docker/bootstrap.sh /usr/local/bin/server-bootstrap
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
    CACHE_STORE=file \
    LOG_CHANNEL=stderr

ENTRYPOINT ["server-entrypoint"]

# Default: run the concurrent API server. Compose and Kubernetes override this
# command with PHP CLI processes for bootstrap, queue workers, and schedulers.
CMD ["apache2-foreground"]
