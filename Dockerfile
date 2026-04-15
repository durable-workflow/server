FROM php:8.3-cli AS base

RUN apt-get update && apt-get install -y \
    curl \
    libpq-dev \
    libzip-dev \
    unzip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql pcntl zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ── Workflow package source ──────────────────────────────────────────
#
# This stage resolves the laravel-workflow/laravel-workflow package source.
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
ARG WORKFLOW_PACKAGE_REF=v2
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

COPY --from=workflow-source /workflow /workflow
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize

# ── Production image ─────────────────────────────────────────────────
FROM base AS production

ARG WORKFLOW_PACKAGE_SOURCE=https://github.com/durable-workflow/workflow.git
ARG WORKFLOW_PACKAGE_REF=v2

COPY --from=vendor /app /app
COPY --from=workflow-source /workflow/.package-provenance /app/.package-provenance
COPY docker/bootstrap.sh /usr/local/bin/server-bootstrap
COPY docker/entrypoint.sh /usr/local/bin/server-entrypoint
COPY docker/php-custom.ini /usr/local/etc/php/conf.d/99-custom.ini

RUN chmod +x /usr/local/bin/server-bootstrap /usr/local/bin/server-entrypoint

# Route cache is safe at build time (no env dependency).
# Config cache is deferred to the entrypoint so runtime env vars take effect.
RUN php artisan route:cache

LABEL org.opencontainers.image.title="Durable Workflow Server" \
      org.opencontainers.image.description="Standalone Durable Workflow server" \
      dev.durable-workflow.package.source="${WORKFLOW_PACKAGE_SOURCE}" \
      dev.durable-workflow.package.ref="${WORKFLOW_PACKAGE_REF}"

EXPOSE 8080

ENTRYPOINT ["server-entrypoint"]

# Default: run the API server. Override CMD for workers.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
