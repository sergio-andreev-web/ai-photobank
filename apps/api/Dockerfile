# syntax=docker/dockerfile:1

# Laravel API image. FrankenPHP serves public/ over plain HTTP on :8000, which is
# what nginx (upstream api:8000) and the compose healthcheck (`/up`) expect. The
# same image runs the queue worker via the api-worker service command.
FROM dunglas/frankenphp:1-php8.4 AS runtime

# gd (WebP variants), exif (metadata), sockets (php-amqplib / RabbitMQ), pcntl
# (queue:work --timeout), intl (Unicode tag normalization), pdo_pgsql, opcache.
RUN install-php-extensions pdo_pgsql gd exif intl pcntl sockets opcache zip \
    && apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Upload limit per ADR-0005 (50 MiB, PHOTO_MAX_UPLOAD_BYTES); post_max_size leaves
# room for the multipart envelope. GD decodes up to 50 MP images (ТЗ §62).
RUN { \
      echo 'upload_max_filesize=50M'; \
      echo 'post_max_size=52M'; \
      echo 'memory_limit=512M'; \
      echo 'expose_php=Off'; \
    } > "$PHP_INI_DIR/conf.d/photobank.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache /data/caddy /config/caddy

ENV SERVER_NAME=:8000 \
    APP_ENV=production

USER www-data

EXPOSE 8000
