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

# ── unzip and curl ───────────────────────────────────────────
#
# ⚠️ `unzip` is not a convenience — without it this image cannot be built at all, and it
# never could be. `composer install --prefer-dist` fetches every package as a zip archive
# and needs either PHP's zip extension or an unzip binary to open it. php:8.3-apache ships
# neither, so the build died on the first package:
#
#     Failed to download dasprid/enum from dist:
#     The zip extension and unzip/7z commands are both missing, skipping.
#     In ZipDownloader.php line 81
#
# The file had carried that fault since it was written, unnoticed because nothing had ever
# built it: Docker is not installed on the development machine, and CI does not build the
# image either. The first real attempt — a deploy to Railway — found it in nineteen
# seconds. A Dockerfile nobody builds is a document, not a build.
#
# `curl` is the smaller reason: the HEALTHCHECK at the foot of this file calls it. The base
# image happens to ship it, but that is an implementation detail rather than a promise, and
# the failure mode is quiet — the check exits non-zero forever and the platform marks a
# working container unhealthy with nothing in the application log to explain it.
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip curl \
 && rm -rf /var/lib/apt/lists/*

# Composer refuses to load plugins when it runs as root and says so on every install. In a
# container root is the only user there is, so the warning describes the situation rather
# than a mistake — and composer's own error output points at the disabled plugins as a
# possible cause of an install failure, which makes the noise actively misleading.
ENV COMPOSER_ALLOW_SUPERUSER=1

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
# mysqlnd.net_read_timeout = 30 is the half of the database timeout that PDO cannot set.
# PDO::ATTR_TIMEOUT in app/Core/Database.php bounds the connect; this bounds waiting for the
# server's reply, and its default is 86400 — a full day. The difference is not academic: a
# MariaDB that binds its port and then aborts its startup completes the TCP handshake and
# sends nothing, so PDO alone still hung past 140 seconds against it, while 30 here turns
# the same case into a 503 the visitor can read. It cannot live in the application because
# it is PHP_INI_SYSTEM — ini_set() on it is silently ignored.
#
# ⚠️ And this file concerns the Docker image alone. Anyone running the project on XAMPP
# locally adds the same lines to php.ini by hand — otherwise they alone see `*args omitted*`
# in their reports, and they alone keep the day-long database timeout.
RUN { \
      echo 'expose_php = Off'; \
      echo 'display_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'error_log = /dev/stderr'; \
      echo 'upload_max_filesize = 8M'; \
      echo 'post_max_size = 10M'; \
      echo 'zend.exception_ignore_args = 0'; \
      echo 'mysqlnd.net_read_timeout = 30'; \
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

# ── The port is decided at run time, not here ────────────────
# EXPOSE 80 is the compose default and stays. But Railway, Render, Fly and Cloud Run all
# inject a $PORT and route only there, so docker/entrypoint.sh rewrites Apache's Listen
# and VirtualHost on every start. Without it the container serves on 80, the platform
# health-checks a port nothing is bound to, and the deploy is killed before it answers a
# request — with no error in the application log to explain why.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]

# A real health check: it requests /health, which actually verifies the database, rather
# than merely "is Apache responding". A container answering 200 with its database down is
# not healthy.
#
# ⚠️ Shell form deliberately, not exec form: ${PORT:-80} has to be expanded by a shell at
# run time, and the exec form would look for a file literally named "${PORT:-80}".
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS "http://127.0.0.1:${PORT:-80}/" || exit 1
