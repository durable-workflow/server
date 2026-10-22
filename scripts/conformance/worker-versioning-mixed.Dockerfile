# Fixed TLS toolchains (GHSA-6q48-6c7w-9gw2), pinned by version and digest.
FROM composer:2.10.3@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee AS composer
FROM node:22.23.4-bookworm-slim@sha256:de170b39439eb0e5a3444ad7d60b015614a381e10fecae2e30ec9e735122f38d AS node
FROM python:3.13-slim

ARG PHP_SDK_VERSION
ARG PYTHON_SDK_VERSION
COPY bootstrap-patched-pip.sh blocked_roots.py /opt/dw-ci/
# apt checks package signatures rather than TLS trust. It refreshes the OS CA
# store, which must then exclude the withdrawn roots before any TLS download.
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates php-cli php-curl unzip \
    && rm -r /var/lib/apt/lists/* \
    && python3 /opt/dw-ci/blocked_roots.py /etc/ssl/certs/ca-certificates.crt
COPY --from=composer /usr/bin/composer /usr/bin/composer
# Composer trusts only the pinned image's checked store and an empty directory.
COPY --from=composer /etc/ssl/certs/ca-certificates.crt /etc/composer-trust/ca.pem
RUN install -d -m 0555 /etc/composer-trust/no-ca-directory \
    && python3 /opt/dw-ci/blocked_roots.py /etc/composer-trust/ca.pem
ENV COMPOSER_CAFILE=/etc/composer-trust/ca.pem COMPOSER_CAPATH=/etc/composer-trust/no-ca-directory
COPY --from=node /usr/local/bin/node /usr/local/bin/node
# The Python SDK lives in a venv whose pip is the hash-pinned release and
# trusts only its verified bundle; python3 resolves to that venv.
ENV PATH=/opt/python/venv/bin:$PATH
RUN mkdir -p /opt/php /opt/python && cd /opt/php \
    && composer require --update-no-dev --no-scripts --no-plugins --no-interaction --no-progress "durable-workflow/sdk:${PHP_SDK_VERSION}" \
    && /usr/local/bin/python3 -m venv /opt/python/venv \
    && sh /opt/dw-ci/bootstrap-patched-pip.sh /opt/python/venv/bin/python \
    && pip install --no-cache-dir --report /opt/python/install-report.json "durable-workflow==${PYTHON_SDK_VERSION}"
COPY worker-versioning-rust-probe /fixtures/worker-versioning-rust-probe
COPY worker-versioning-mixed-* /fixtures/
USER 1000:1000
WORKDIR /fixtures
CMD ["node", "worker-versioning-mixed-published-workers.mjs"]
