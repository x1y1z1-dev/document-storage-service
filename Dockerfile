# ── Node.js + Vite build (frontend assets) ───────────────────────────────────
# Install Node.js 22 LTS to build CSS/JS with Vite before the PHP stage.
FROM node:22-alpine AS node_build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources/ resources/
COPY vite.config.js ./
COPY public/ public/

RUN npm run build

###############################################################################
# Stage 1: PHP-FPM application image
###############################################################################
FROM php:8.4-fpm AS app

# ── System dependencies ───────────────────────────────────────────────────────
# git/unzip: needed by Composer
# libssl-dev: required for some PECL/PHP extension builds
# nginx: web server (serves php-fpm via fastcgi)
# supervisor: process manager that runs both php-fpm and nginx in one container
# Note: librabbitmq-dev is NOT needed — php-amqplib is a pure PHP Composer
#       package and requires no native C extension.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        libssl-dev \
        nginx \
        supervisor \
    && rm -rf /var/lib/apt/lists/*

# ── PHP extensions ────────────────────────────────────────────────────────────
# pdo_mysql : database connectivity (Requirement 7.1)
# fileinfo  : content-based MIME detection via finfo() (Requirement 11.1)
# opcache   : performance
# Note: no `amqp` PECL extension — php-amqplib is a pure-PHP Composer package
#       and does not require the native amqp extension.
RUN docker-php-ext-install pdo_mysql \
    && docker-php-ext-enable pdo_mysql \
    && docker-php-ext-install fileinfo \
    && docker-php-ext-enable fileinfo \
    && docker-php-ext-install opcache \
    && docker-php-ext-enable opcache \
    && docker-php-ext-install sockets \
    && docker-php-ext-enable sockets

# ── OPcache tuning (production-friendly defaults) ────────────────────────────
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.fast_shutdown=1'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# ── PHP upload / post limits ─────────────────────────────────────────────────
# Default PHP limits are 2 MB; raise them to allow the configured 10 MB max.
RUN { \
        echo 'upload_max_filesize = 20M'; \
        echo 'post_max_size = 22M'; \
        echo 'memory_limit = 256M'; \
        echo 'max_execution_time = 60'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# ── Composer ─────────────────────────────────────────────────────────────────
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# ── Working directory ─────────────────────────────────────────────────────────
WORKDIR /var/www

# ── Application source ────────────────────────────────────────────────────────
# Copy composer manifests first so this layer is cached unless deps change.
COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --optimize-autoloader \
        --no-scripts \
        --prefer-dist

# Copy the rest of the application.
COPY . .

# Copy pre-built Vite assets from the node_build stage.
COPY --from=node_build /app/public/build /var/www/public/build

# Run post-install scripts (package discovery, etc.) now that app files exist.
RUN composer run-script post-autoload-dump --no-interaction || true

# ── Directory permissions ─────────────────────────────────────────────────────
RUN chown -R www-data:www-data \
        /var/www/storage \
        /var/www/bootstrap/cache \
    && chmod -R 775 \
        /var/www/storage \
        /var/www/bootstrap/cache

# ── nginx configuration ───────────────────────────────────────────────────────
COPY docker/nginx/default.conf /etc/nginx/sites-available/default
RUN ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default \
    && rm -f /etc/nginx/sites-enabled/000-default.conf || true

# ── Supervisor configuration ──────────────────────────────────────────────────
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# ── Entry-point script ────────────────────────────────────────────────────────
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Port 80 is served by nginx inside the container.
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
