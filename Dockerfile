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

# Use the signed upstream fix while Docker Official Image publication catches up.
# Configure options match docker-library/php at f6048b5f6e3c2202ee082665a4ccc0d90de291a3.
FROM php:8.3.35-apache@sha256:c59c808172ee3672cd334f8f2a5d7b1480a9d7273a368f412b8ee9b4ef418184 AS patched-php

LABEL dev.durable-workflow.php.source.version="8.3.36" \
      dev.durable-workflow.php.source.sha256="dd54693aa334c5c4d72e9aee96b082c2473750a18a6c1d105789c93a3b1692fd"

ARG PHP_BUILD_JOBS=2
ENV PHP_VERSION="8.3.36" \
    PHP_URL="https://www.php.net/distributions/php-8.3.36.tar.xz" \
    PHP_ASC_URL="https://www.php.net/distributions/php-8.3.36.tar.xz.asc" \
    PHP_SHA256="dd54693aa334c5c4d72e9aee96b082c2473750a18a6c1d105789c93a3b1692fd"

COPY docker/php-keyring.gpg /tmp/php-keyring.gpg

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS apache2-dev ca-certificates curl gnupg \
        libargon2-dev libcurl4-openssl-dev libonig-dev libreadline-dev \
        libsodium-dev libsqlite3-dev libssl-dev libxml2-dev xz-utils zlib1g-dev; \
    printf '%s  %s\n' \
        '43fcb3eaf65536cb0833c229b492c70cfad8562138164dd13673e08925d6c56c' \
        /tmp/php-keyring.gpg | sha256sum --check -; \
    curl --fail --location --show-error --silent --output /usr/src/php.tar.xz "${PHP_URL}"; \
    printf '%s  %s\n' "${PHP_SHA256}" /usr/src/php.tar.xz | sha256sum --check -; \
    curl --fail --location --show-error --silent --output /tmp/php.tar.xz.asc "${PHP_ASC_URL}"; \
    export GNUPGHOME="$(mktemp -d)"; \
    gpg --batch --import /tmp/php-keyring.gpg; \
    gpg --batch --verify /tmp/php.tar.xz.asc /usr/src/php.tar.xz; \
    gpgconf --kill all; \
    rm -rf "${GNUPGHOME}" /usr/src/php; \
    mkdir -p /usr/src/php; \
    tar --extract --file /usr/src/php.tar.xz --xz --strip-components=1 --directory /usr/src/php; \
    cd /usr/src/php; \
    gnu_arch="$(dpkg-architecture --query DEB_BUILD_GNU_TYPE)"; \
    deb_multiarch="$(dpkg-architecture --query DEB_BUILD_MULTIARCH)"; \
    export CFLAGS="${PHP_CFLAGS}" CPPFLAGS="${PHP_CPPFLAGS}" LDFLAGS="${PHP_LDFLAGS}" \
        PHP_BUILD_PROVIDER='https://github.com/docker-library/php' PHP_UNAME='Linux - Docker'; \
    ./configure \
        --build="${gnu_arch}" \
        --sysconfdir="${PHP_INI_DIR%/php}" \
        --with-config-file-path="${PHP_INI_DIR}" \
        --with-config-file-scan-dir="${PHP_INI_DIR}/conf.d" \
        --enable-option-checking=fatal --with-mhash --with-pic \
        --enable-mbstring --enable-mysqlnd --with-password-argon2 --with-sodium=shared \
        --with-pdo-sqlite=/usr --with-sqlite3=/usr --with-curl --with-iconv \
        --with-openssl --with-readline --with-zlib --disable-phpdbg --with-pear \
        --with-libdir="lib/${deb_multiarch}" --disable-cgi --with-apxs2; \
    make -j "${PHP_BUILD_JOBS}"; \
    make install; \
    cp php.ini-development php.ini-production "${PHP_INI_DIR}/"; \
    strip --strip-all /usr/local/bin/php /usr/lib/apache2/modules/libphp.so; \
    test "$(php -r 'echo PHP_VERSION;')" = "${PHP_VERSION}"; \
    rm -rf /var/lib/apt/lists/* /tmp/php.tar.xz.asc /tmp/php-keyring.gpg

FROM php:8.3.35-apache@sha256:c59c808172ee3672cd334f8f2a5d7b1480a9d7273a368f412b8ee9b4ef418184 AS base

# Replace every PHP runtime/build artifact, including the Apache module and
# extension sources used below. A changed image environment is not a PHP update.
COPY --from=patched-php /usr/local/bin/php /usr/local/bin/php
COPY --from=patched-php /usr/local/bin/php-config /usr/local/bin/php-config
COPY --from=patched-php /usr/local/bin/phpize /usr/local/bin/phpize
COPY --from=patched-php /usr/local/lib/php/ /usr/local/lib/php/
COPY --from=patched-php /usr/local/include/php/ /usr/local/include/php/
COPY --from=patched-php /usr/lib/apache2/modules/libphp.so /usr/lib/apache2/modules/libphp.so
COPY --from=patched-php /usr/src/php.tar.xz /usr/src/php.tar.xz
COPY --from=patched-php /usr/local/etc/php/php.ini-development /usr/local/etc/php/php.ini-development
COPY --from=patched-php /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini-production

ENV PHP_VERSION="8.3.36" \
    PHP_URL="https://www.php.net/distributions/php-8.3.36.tar.xz" \
    PHP_ASC_URL="https://www.php.net/distributions/php-8.3.36.tar.xz.asc" \
    PHP_SHA256="dd54693aa334c5c4d72e9aee96b082c2473750a18a6c1d105789c93a3b1692fd"

LABEL dev.durable-workflow.php.source.version="8.3.36" \
      dev.durable-workflow.php.source.sha256="dd54693aa334c5c4d72e9aee96b082c2473750a18a6c1d105789c93a3b1692fd"

# Both web and CLI roles require the fixed PHP runtime, including cached builds.
RUN php -r 'if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 3 || PHP_VERSION_ID < 80336) { fwrite(STDERR, "Patched PHP 8.3.36 or later on the 8.3 line is required; found ".PHP_VERSION.PHP_EOL); exit(1); }'

COPY --from=phpredis-source /phpredis /usr/src/php/ext/redis

# Published conformance runners execute from the exact Server image.
# Keep their Node and Python runtimes available alongside the service.
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
    && rm -rf /var/lib/apt/lists/*

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
    CACHE_STORE=file

ENTRYPOINT ["server-entrypoint"]

# Default: run the concurrent API server. Compose and Kubernetes override this
# command with PHP CLI processes for bootstrap, queue workers, and schedulers.
CMD ["apache2-foreground"]
