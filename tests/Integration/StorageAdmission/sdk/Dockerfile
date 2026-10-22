ARG SERVER_IMAGE
FROM ${SERVER_IMAGE} AS server-trust

FROM rust:1.86 AS rust-worker
ARG RUST_SDK_VERSION
# This image's CA store still holds a root withdrawn under GHSA-6q48-6c7w-9gw2.
# cargo resolves the unlocked crates through the Server image's store instead.
COPY --from=server-trust /etc/ssl/certs/ca-certificates.crt /etc/server-trust/ca-certificates.crt
ENV CARGO_HTTP_CAINFO=/etc/server-trust/ca-certificates.crt
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
USER 1000:1000
WORKDIR /sdk
COPY --from=ci bootstrap-patched-pip.sh blocked_roots.py /sdk/ci/
RUN composer require "durable-workflow/sdk:${PHP_SDK_VERSION}" --no-interaction --no-progress --prefer-dist \
    && python3 -m venv /sdk/venv \
    && sh /sdk/ci/bootstrap-patched-pip.sh /sdk/venv/bin/python \
    && /sdk/venv/bin/pip install --no-cache-dir "durable-workflow==${PYTHON_SDK_VERSION}"
COPY --from=rust-worker /build/target/release/storage-sdk-worker /sdk/rust-worker
COPY --chown=1000:1000 worker.php worker.py /sdk/
