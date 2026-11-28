FROM composer:2.10.3@sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48
WORKDIR /work
RUN apk add --no-cache iproute2-tc=7.0.0-r0 su-exec=0.3-r0 \
    libcap2=2.78-r0 libelf=0.195-r0 libmnl=1.0.5-r2 libxtables=1.8.13-r0
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-plugins --no-scripts --no-interaction --prefer-dist --no-progress
COPY benchmark.php ./
