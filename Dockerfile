FROM php:8.4-fpm-alpine

# Helper that installs PHP extensions with their system dependencies.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN apk add --no-cache git unzip \
    && install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        redis \
        zip

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Development settings: show errors, re-read changed files on every request.
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=64M'; \
        echo 'post_max_size=64M'; \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=0'; \
    } > "$PHP_INI_DIR/conf.d/zz-dev.ini"

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# Application code is bind-mounted at runtime (see docker-compose.yml).
EXPOSE 9000
CMD ["php-fpm"]
