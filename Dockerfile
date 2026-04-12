FROM php:8.3-cli AS base

RUN apt-get update && apt-get install -y \
    curl \
    libpq-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql pcntl zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

FROM composer:2 AS workflow-source

ARG WORKFLOW_PACKAGE_SOURCE=https://github.com/durable-workflow/workflow.git
ARG WORKFLOW_PACKAGE_REF=v2

RUN git clone --depth 1 --branch "${WORKFLOW_PACKAGE_REF}" "${WORKFLOW_PACKAGE_SOURCE}" /workflow

# ── Dependencies ──────────────────────────────────────────────────────
FROM base AS vendor

COPY --from=workflow-source /workflow /workflow
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize

# ── Production image ─────────────────────────────────────────────────
FROM base AS production

COPY --from=vendor /app /app
COPY docker/bootstrap.sh /usr/local/bin/server-bootstrap
COPY docker/entrypoint.sh /usr/local/bin/server-entrypoint

RUN chmod +x /usr/local/bin/server-bootstrap /usr/local/bin/server-entrypoint

RUN php artisan config:cache \
    && php artisan route:cache

EXPOSE 8080

ENTRYPOINT ["server-entrypoint"]

# Default: run the API server. Override CMD for workers.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
