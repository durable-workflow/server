FROM composer:2.10.3@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee
WORKDIR /work
RUN apk add --no-cache iproute2-tc=7.0.0-r0 su-exec=0.3-r0 \
    libcap2=2.78-r0 libelf=0.195-r0 libmnl=1.0.5-r2 libxtables=1.8.13-r0
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-plugins --no-scripts --no-interaction --prefer-dist --no-progress
COPY benchmark.php ./
