# Durable Workflow PHP SDK worker for the Temporal comparison race. Both base
# images are pinned by digest in ../../images.lock.json, which the harness
# checks before it builds. Composer installs exactly composer.lock.
FROM composer:2.10.3@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee AS deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
      --no-scripts --classmap-authoritative --ignore-platform-reqs

FROM mirror.gcr.io/library/php:8.3.37-cli-bookworm@sha256:ab657f49471efd8ec265ce14ee20a855e19aee3f3ed3c55910528dab9fa3ffd8
COPY --from=deps /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY --chmod=0644 composer.json composer.lock worker.php ./
COPY --from=deps /app/vendor ./vendor
# The runtime, not the Composer image, must satisfy the locked platform.
RUN composer check-platform-reqs --no-dev && rm /usr/local/bin/composer
USER 1000:1000
CMD ["php", "/app/worker.php"]
