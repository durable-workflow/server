ARG SERVER_IMAGE
FROM ${SERVER_IMAGE} AS server-trust

FROM composer:2.10.3@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee AS checked-ca

FROM rust:1.86 AS rust-worker
ARG RUST_SDK_VERSION
# This image's CA store still holds a root withdrawn under GHSA-6q48-6c7w-9gw2.
# cargo resolves the unlocked crates through the Server image's checked file
# only: CARGO_HTTP_CAINFO adds to the OpenSSL CA directory rather than
# replacing it, so that directory is pointed at an empty one.
COPY --from=server-trust /etc/ssl/certs/ca-certificates.crt /etc/server-trust/ca-certificates.crt
RUN install -d -m 0555 /etc/server-trust/no-ca-directory
ENV CARGO_HTTP_CAINFO=/etc/server-trust/ca-certificates.crt \
    SSL_CERT_FILE=/etc/server-trust/ca-certificates.crt \
    SSL_CERT_DIR=/etc/server-trust/no-ca-directory
COPY --from=ci blocked_roots.py /etc/server-trust/blocked_roots.py
RUN python3 /etc/server-trust/blocked_roots.py "$CARGO_HTTP_CAINFO" \
    && test -z "$(ls -A "$SSL_CERT_DIR")"
WORKDIR /build
RUN cargo init --bin --name storage-sdk-worker . \
    && cargo add "durable-workflow@=${RUST_SDK_VERSION}" serde_json sha2@0.10 \
    && cargo add tokio --features macros,rt-multi-thread,time
COPY worker.rs src/main.rs
RUN cargo build --release

FROM ${SERVER_IMAGE}
ARG PHP_SDK_VERSION
ARG PYTHON_SDK_VERSION
USER root
RUN install -d -o 1000 -g 1000 /sdk
# Composer trusts only the pinned Composer image's checked CA store
# (GHSA-6q48-6c7w-9gw2), whichever Server image this builds on.
COPY --from=checked-ca /etc/ssl/certs/ca-certificates.crt /etc/composer-trust/ca.pem
RUN install -d -m 0555 /etc/composer-trust/no-ca-directory
ENV COMPOSER_CAFILE=/etc/composer-trust/ca.pem COMPOSER_CAPATH=/etc/composer-trust/no-ca-directory
USER 1000:1000
WORKDIR /sdk
COPY --from=ci bootstrap-patched-pip.sh blocked_roots.py /sdk/ci/
RUN composer require "durable-workflow/sdk:${PHP_SDK_VERSION}" --no-interaction --no-progress --prefer-dist \
    && python3 -m venv /sdk/venv \
    && sh /sdk/ci/bootstrap-patched-pip.sh /sdk/venv/bin/python \
    && /sdk/venv/bin/pip install --no-cache-dir "durable-workflow==${PYTHON_SDK_VERSION}"
COPY --from=rust-worker /build/target/release/storage-sdk-worker /sdk/rust-worker
COPY --chown=1000:1000 worker.php worker.py /sdk/
