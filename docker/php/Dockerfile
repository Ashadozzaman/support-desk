# Stages: base (PHP + extensions) → assets (composer + npm build) → app (runtime) / web (nginx).
FROM node:22-bookworm-slim AS node

FROM php:8.3-fpm-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev libpng-dev libfreetype6-dev libjpeg62-turbo-dev libfcgi-bin procps \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl pcntl zip gd opcache sockets \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-support-desk.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-support-desk.conf
WORKDIR /var/www

FROM base AS assets
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
# VITE_* are compiled into the JS bundle, so they are build args (the browser reaches Reverb on localhost).
ARG VITE_APP_NAME="Support Desk"
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8080
ARG VITE_REVERB_SCHEME=http
COPY . .
# Dev packages included: seeders need fakerphp/faker and Pest runs in the container.
# The Wayfinder Vite plugin runs `php artisan wayfinder:generate`, which only needs a key and no DB.
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts \
    && npm ci --no-audit --no-fund \
    && APP_KEY=base64:$(head -c 32 /dev/urandom | base64) DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array \
        npm run build

FROM base AS app
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=assets /var/www/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /var/www/public/build ./public/build
COPY --chmod=755 docker/php/entrypoint.sh /usr/local/bin/entrypoint
# Empty .env: config comes from the container environment, but Dotenv/Pest expect the file to exist.
RUN composer dump-autoload --optimize --no-scripts && touch .env \
    && mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data .env storage bootstrap/cache
USER www-data
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

FROM nginx:1.27-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/public /var/www/public
