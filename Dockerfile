# PHP-Version: immer der neueste stabile Branch (Stand 2026-11-14: 8.5 → 8.5.12)
ARG PHP_VERSION=8.5

# --- Stufe 1: Frontend-Assets (Vite, Passkey-Client) ---
FROM node:22-alpine AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

# --- Stufe 2: Composer (gleiche PHP-Version wie Runtime) ---
FROM serversideup/php:${PHP_VERSION}-cli-alpine AS vendor
USER root
RUN install-php-extensions intl
USER www-data
WORKDIR /app
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY --chown=www-data:www-data . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan filament:assets

# --- Stufe 3: Runtime ---
FROM serversideup/php:${PHP_VERSION}-fpm-nginx-alpine
ENV AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    PHP_OPCACHE_ENABLE=1
USER root
RUN install-php-extensions intl gd zip bcmath pdo_pgsql pdo_mysql
USER www-data
WORKDIR /var/www/html
COPY --chown=www-data:www-data --from=vendor /app /var/www/html
COPY --chown=www-data:www-data --from=assets /app/public/build /var/www/html/public/build
