ARG SERVER_IMAGE
FROM rust:1.86 AS rust-worker
ARG RUST_SDK_VERSION
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
RUN composer require "durable-workflow/sdk:${PHP_SDK_VERSION}" --no-interaction --no-progress --prefer-dist \
    && python3 -m venv /sdk/venv \
    && /sdk/venv/bin/pip install --no-cache-dir "durable-workflow==${PYTHON_SDK_VERSION}"
COPY --from=rust-worker /build/target/release/storage-sdk-worker /sdk/rust-worker
COPY --chown=1000:1000 worker.php worker.py /sdk/
