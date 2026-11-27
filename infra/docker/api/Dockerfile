# Campus TAB API image: PHP 8.4 CLI with the extensions Laravel, MongoDB,
# Redis, Horizon and Reverb need, plus Composer. Used by the api, horizon
# and reverb services in infra/docker-compose.yml and by nothing else.
FROM php:8.4-cli-bookworm

ARG MONGODB_EXT_VERSION=2.5.9
ARG REDIS_EXT_VERSION=6.2.0

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip curl libzip-dev libicu-dev libssl-dev \
    && docker-php-ext-install -j"$(nproc)" pcntl zip intl bcmath sockets \
    && pecl install mongodb-${MONGODB_EXT_VERSION} redis-${REDIS_EXT_VERSION} \
    && docker-php-ext-enable mongodb redis \
    && apt-get purge -y --auto-remove libssl-dev \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY infra/docker/api/php.ini /usr/local/etc/php/conf.d/zz-campus-tab.ini
COPY infra/docker/api/entrypoint.sh /usr/local/bin/campus-tab-entrypoint
RUN chmod +x /usr/local/bin/campus-tab-entrypoint

ENV COMPOSER_HOME=/composer \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
EXPOSE 8000 8080

ENTRYPOINT ["campus-tab-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
