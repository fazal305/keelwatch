# Keelwatch API: PHP-FPM serving api/public/index.php. Caddy (web image)
# forwards /api, /webhooks, /healthz and /readyz to it over FastCGI.
# The same image runs migrations and the bin/ CLIs.

FROM composer:2 AS vendor
WORKDIR /app/api
COPY api/composer.json api/composer.lock ./
# Runtime dependencies are only the autoloader; the platform is checked in
# the runtime stage (PHP 8.4 with pdo_mysql).
RUN composer install --no-dev --no-interaction --no-progress --no-scripts \
        --ignore-platform-reqs --no-autoloader
COPY api/src ./src
RUN composer dump-autoload --no-dev --classmap-authoritative --ignore-platform-reqs

FROM php:8.4-fpm
RUN docker-php-ext-install pdo_mysql opcache >/dev/null
COPY deploy/docker/php.ini "$PHP_INI_DIR/conf.d/zz-keelwatch.ini"
COPY deploy/docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-keelwatch.conf

WORKDIR /app
COPY --from=vendor /app/api/vendor api/vendor
COPY api/composer.json api/
COPY api/bin api/bin
COPY api/public api/public
COPY api/src api/src
COPY db/migrations db/migrations

USER www-data
EXPOSE 9000
