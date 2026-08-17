FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libxml2-dev \
        libonig-dev \
        libzip-dev \
        libicu-dev \
        libpq-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        dom \
        exif \
        gd \
        intl \
        mbstring \
        pdo \
        pdo_pgsql \
        pgsql \
        pcntl \
        zip \
    && rm -rf /var/lib/apt/lists/*

# Laravel's `php artisan serve` builds the child `php -S` process env from
# $_ENV; the default variables_order ("GPCS") excludes E, so container env
# vars (DB_HOST etc.) never reach the web server. Enable E.
RUN echo 'variables_order = "EGPCS"' > /usr/local/etc/php/conf.d/zz-gw.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/gw-system/backend

RUN groupadd -g 1000 app && useradd -u 1000 -g 1000 -m app

COPY docker/entrypoint.sh /usr/local/bin/gw-entrypoint
COPY docker/setup.sh /usr/local/bin/gw-setup
COPY docker/test.sh /usr/local/bin/gw-test
RUN chmod +x /usr/local/bin/gw-entrypoint /usr/local/bin/gw-setup /usr/local/bin/gw-test

USER app

ENTRYPOINT ["/usr/local/bin/gw-entrypoint"]
