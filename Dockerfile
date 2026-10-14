# Dev image: PHP 8.5 CLI + pcov (coverage for Infection) + Composer.
FROM php:8.5-cli

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS git unzip libzip-dev; \
    docker-php-ext-install zip; \
    pecl install pcov; \
    docker-php-ext-enable pcov; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
