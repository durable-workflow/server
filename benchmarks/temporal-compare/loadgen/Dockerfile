# Load generator for the Temporal comparison race. Build context:
# scripts/perf. uv installs exactly uv.lock (hash-checked); the base image is
# pinned by digest in ../images.lock.json.
FROM ghcr.io/astral-sh/uv:0.8.24-python3.12-bookworm-slim@sha256:1e63e300ec9908733d44d2eaa37688dd2c51fc7c1350f8fd046413d05f21486c
ENV UV_COMPILE_BYTECODE=1 \
    UV_LINK_MODE=copy \
    UV_PYTHON_DOWNLOADS=never \
    UV_PROJECT_ENVIRONMENT=/opt/venv
# The repository layout, so the driver finds its own paths.
WORKDIR /app/scripts/perf
COPY pyproject.toml uv.lock ./
RUN uv sync --frozen --no-dev --no-install-project
COPY --chmod=0644 server_soak.py temporal_compare.py ./
USER 1000:1000
ENTRYPOINT ["/opt/venv/bin/python", "/app/scripts/perf/temporal_compare.py"]
