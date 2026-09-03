# syntax=docker/dockerfile:1
#
# One image carrying the whole application: web server, queue worker and
# scheduler. Built for a single small container — see docs/deployment-demo.md.

# ---- assets ------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /build

COPY package.json package-lock.json ./
RUN npm ci

# Tailwind scans views and the Filament/Livewire classes, so those have to be
# present for the build to keep the classes they use.
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
COPY app ./app

RUN npm run build

# ---- php dependencies --------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /build

COPY composer.json composer.lock ./

# --no-scripts: the artisan scripts need the full source, which is not here yet.
RUN composer install \
    --no-dev --prefer-dist --no-interaction --no-progress \
    --no-scripts --ignore-platform-reqs

# ---- runtime -----------------------------------------------------------------
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        nginx supervisor \
        icu-libs libzip libpng libjpeg-turbo freetype libpq oniguruma \
    && apk add --no-cache --virtual .build \
        $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev \
        freetype-dev libpq-dev oniguruma-dev linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql pdo_mysql gd intl zip bcmath pcntl exif opcache \
    && apk del .build \
    && rm -rf /var/cache/apk/*

COPY docker/php.ini /usr/local/etc/php/conf.d/99-sanabel.ini
COPY docker/nginx.conf /etc/nginx/nginx.conf.template
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

WORKDIR /app

COPY . .
COPY --from=vendor /build/vendor ./vendor
COPY --from=assets /build/public/build ./public/build

# The autoloader is optimised now that the source is in place.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative 2>/dev/null \
    || php -r "echo 'composer not present in runtime image; using the vendored autoloader';" \
    ; mkdir -p storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs storage/app/private/media bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/entrypoint

# The interface is Arabic-only by design (CLAUDE.md 5): every user-facing
# string lives in lang/ar. Without this the app falls back to English and
# renders raw translation keys.
ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_LOCALE=ar \
    APP_FALLBACK_LOCALE=ar \
    APP_FAKER_LOCALE=ar_SA \
    APP_TIMEZONE=Asia/Damascus \
    LOG_CHANNEL=stderr \
    PORT=10000

EXPOSE 10000

# The health endpoint Render polls.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s \
    CMD wget -qO- "http://127.0.0.1:${PORT}/up" >/dev/null || exit 1

ENTRYPOINT ["entrypoint"]
