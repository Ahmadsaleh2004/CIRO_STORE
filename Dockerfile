# ══════════════════════════════════════════════════════════════
# Cairo Store — the application image
# ══════════════════════════════════════════════════════════════
#
# php:apache rather than php-fpm+nginx, deliberately. The project relies on .htaccess in two
# places that carry meaning: the rewrite rule directing everything to the router, and the
# mod_headers block carrying the CSP, the security headers and Cache-Control for the
# fingerprinted assets.
#
# Moving those to nginx means rewriting them in another language and keeping two copies in
# step — exactly the kind of divergence this project eliminates at every phase. The apache
# image runs the very file that works today.

FROM php:8.3-apache

# ── The PHP extensions ───────────────────────────────────────
# pdo_mysql is the only one needing installation; mbstring and json are built into the
# official image. The list matches "require" in composer.json letter for letter.
RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite headers

# ── The PHP settings for production ──────────────────────────
# expose_php belongs here rather than in the application: header_remove in config.php covers
# the normal path, but a response Apache generates before PHP never passes through it.
#
# zend.exception_ignore_args = 0 is specifically for Sentry. The default in PHP 7.4+ is 1,
# which means stack traces come out without arguments: every frame says `*args omitted*`. So
# the report arrives knowing **where** the error happened and not knowing **with what
# inputs** — half a report for faults that only reproduce with one particular value.
#
# ⚠️ And it has a privacy cost: the arguments may carry user data. What balances it is that
# `before_send` in app/config/monitoring.php scrubs the sensitive fields before sending, and
# `send_default_pii` is set to false. So do not switch this value on in an installation that
# has disabled that scrubbing.
#
# ⚠️ And this file concerns the Docker image alone. Anyone running the project on XAMPP
# locally adds the same line to php.ini by hand — otherwise they alone see `*args omitted*`
# in their reports.
RUN { \
      echo 'expose_php = Off'; \
      echo 'display_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'error_log = /dev/stderr'; \
      echo 'upload_max_filesize = 8M'; \
      echo 'post_max_size = 10M'; \
      echo 'zend.exception_ignore_args = 0'; \
    } > /usr/local/etc/php/conf.d/cairo-store.ini

# ── The web root is public/ alone ────────────────────────────
# ⚠️ This is not a preference: app/, .env and vendor/ sit above it, and putting the root at
# /var/www/html makes .env downloadable over HTTP.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
      /etc/apache2/sites-available/*.conf \
      /etc/apache2/apache2.conf \
 && printf '<Directory /var/www/html/public>\n\tAllowOverride All\n\tRequire all granted\n</Directory>\n' \
      > /etc/apache2/conf-available/cairo-store.conf \
 && a2enconf cairo-store

WORKDIR /var/www/html

# ── The dependencies first, then the code ────────────────────
# A separate layer for the two composer files: changing a line in a controller does not
# invalidate the install cache, so the build stays seconds rather than minutes.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
 && mkdir -p storage/backups \
 && chown -R www-data:www-data storage public/images

EXPOSE 80

# A real health check: it requests /health, which actually verifies the database, rather
# than merely "is Apache responding". A container answering 200 with its database down is
# not healthy.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS http://127.0.0.1/health || exit 1
