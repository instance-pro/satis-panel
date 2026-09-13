# syntax=docker/dockerfile:1
# Satis Panel: Symfony web UI + composer/satis, served by nginx + php-fpm.

# ---------------------------------------------------------------- assets (Vite + Tailwind)
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY assets ./assets
COPY templates ./templates
RUN npm run build

# ---------------------------------------------------------------- vendor (Composer)
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative && rm -rf var public/build

# ---------------------------------------------------------------- runtime
FROM php:8.5-fpm
ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx git openssh-client unzip curl ca-certificates gettext-base procps libzip-dev \
    && docker-php-ext-install -j"$(nproc)" zip \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log \
    && mkdir -p /etc/ssh \
    && ssh-keyscan -H github.com gitlab.com bitbucket.org >> /etc/ssh/ssh_known_hosts 2>/dev/null

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_CACHE_DIR=/var/www/html/cache \
    HOME=/var/www \
    COMPOSER_HOME=/var/www/.composer \
    COMPOSER_CACHE_DIR=/var/www/.composer/cache \
    COMPOSER_MEMORY_LIMIT=-1 \
    COMPOSER_NO_INTERACTION=1 \
    SATIS_CONFIG=/data/config/satis.json \
    SATIS_OUTPUT_DIR=/data/output \
    SATIS_HTPASSWD_FILE=/data/config/htpasswd \
    SSH_DIR=/var/www/.ssh \
    TRUSTED_PROXIES=127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16 \
    DEFAULT_URI=http://localhost \
    BUILD_TIMEOUT=1800 \
    ADMIN_USER=admin

COPY docker/php/satis-panel.ini /usr/local/etc/php/conf.d/satis-panel.ini
COPY docker/php/zz-satis-panel-pool.conf /usr/local/etc/php-fpm.d/zz-satis-panel-pool.conf
COPY docker/nginx/site.conf.template /etc/nginx/templates/site.conf.template
COPY docker/bin/ /usr/local/bin/

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

RUN chmod 0755 /usr/local/bin/satis-panel-* \
    && mkdir -p var cache /data/config /data/output /var/www/.ssh /var/www/.composer \
    && APP_SECRET=build php bin/console cache:warmup --no-interaction \
    && chown -R www-data:www-data var cache /data /var/www/.ssh /var/www/.composer \
    && chown www-data:www-data /var/www \
    && chmod 700 /var/www/.ssh

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS -o /dev/null http://127.0.0.1/login || exit 1

ENTRYPOINT ["satis-panel-entrypoint.sh"]
CMD ["satis-panel-serve"]
