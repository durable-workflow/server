FROM composer:2 AS composer
FROM node:22-slim AS node
FROM python:3.13-slim

ARG PHP_SDK_VERSION
ARG PYTHON_SDK_VERSION
RUN apt-get update && apt-get install -y --no-install-recommends php-cli php-curl unzip \
    && rm -r /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
RUN mkdir -p /opt/php /opt/python && cd /opt/php \
    && composer require --no-dev --no-scripts --no-plugins --no-interaction --no-progress "durable-workflow/sdk:${PHP_SDK_VERSION}" \
    && pip install --no-cache-dir --report /opt/python/install-report.json "durable-workflow==${PYTHON_SDK_VERSION}"
COPY worker-versioning-rust-probe /fixtures/worker-versioning-rust-probe
COPY worker-versioning-mixed-* /fixtures/
USER 1000:1000
WORKDIR /fixtures
CMD ["node", "worker-versioning-mixed-published-workers.mjs"]
