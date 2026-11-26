FROM php:8.4-fpm-alpine

# pdo_pgsql: driver de PostgreSQL (la pieza central del proyecto).
# opcache/pcntl: rendimiento y señales para comandos de larga duración.
RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql opcache pcntl \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-slotlock.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-slotlock.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
