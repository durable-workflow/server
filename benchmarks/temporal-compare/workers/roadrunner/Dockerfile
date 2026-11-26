# Temporal PHP SDK worker run by RoadRunner for the Temporal comparison race.
# Every base image is pinned by digest in ../../images.lock.json, which the
# harness checks before it builds. Composer installs exactly composer.lock.
FROM composer:2.10.3@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee AS deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
      --no-scripts --classmap-authoritative --ignore-platform-reqs

FROM ghcr.io/roadrunner-server/roadrunner:3.0.1@sha256:379a1821073abd3c02a31e2b404bd1b671ab2158d9db71ad93991b5e652b8c01 AS roadrunner

FROM mirror.gcr.io/library/php:8.3.37-cli-bookworm@sha256:ab657f49471efd8ec265ce14ee20a855e19aee3f3ed3c55910528dab9fa3ffd8
# ext-sockets ships with the PHP source in this image. The protobuf extension
# is the one Temporal recommends for PHP workers; without it the SDK falls back
# to pure-PHP protobuf decoding and the race would understate Temporal. The
# PECL archive is checked against a pinned SHA-256 before it is built.
ARG PROTOBUF_EXT_VERSION=5.36.2
ARG PROTOBUF_EXT_SHA256=f0a17c67de29df0fb0e5215a381df9b155125e1ace6252dd617bf16b4194dd5b
RUN docker-php-ext-install -j"$(nproc)" sockets \
    && curl -fsSL --proto '=https' --tlsv1.2 -o /tmp/protobuf.tgz \
         "https://pecl.php.net/get/protobuf-${PROTOBUF_EXT_VERSION}.tgz" \
    && echo "${PROTOBUF_EXT_SHA256}  /tmp/protobuf.tgz" | sha256sum -c - \
    && MAKEFLAGS="-j$(nproc)" pecl install /tmp/protobuf.tgz \
    && docker-php-ext-enable protobuf \
    && rm -rf /tmp/protobuf.tgz /tmp/pear
COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr
COPY --from=deps /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY --chmod=0644 composer.json composer.lock worker.php .rr.yaml ./
COPY --from=deps /app/vendor ./vendor
RUN composer check-platform-reqs --no-dev && rm /usr/local/bin/composer
USER 1000:1000
CMD ["rr", "serve", "-c", "/app/.rr.yaml"]
