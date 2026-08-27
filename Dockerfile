# ══════════════════════════════════════════════════════════════
# Cairo Store — صورة التطبيق
# ══════════════════════════════════════════════════════════════
#
# php:apache لا php-fpm+nginx عمداً. المشروع يعتمد على .htaccess في
# موضعين حمّالَي معنى: قاعدة إعادة الكتابة التي توجّه كل شيء إلى
# الراوتر، وكتلة mod_headers التي تحمل CSP والترويسات الأمنية
# وCache-Control للأصول المبصومة.
#
# نقلها إلى nginx يعني إعادة كتابتها بلغة أخرى ومزامنة نسختين — وهو
# بالضبط صنف التفرّق الذي يقضي عليه هذا المشروع في كل مرحلة. صورة
# apache تشغّل الملف نفسه الذي يعمل اليوم.

FROM php:8.3-apache

# ── امتدادات PHP ─────────────────────────────────────────────
# pdo_mysql هو الوحيد الذي يحتاج تركيباً؛ mbstring وjson مبنيّان في
# الصورة الرسمية. القائمة تطابق "require" في composer.json حرفاً بحرف.
RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite headers

# ── إعدادات PHP للإنتاج ──────────────────────────────────────
# expose_php هنا لا في التطبيق: header_remove في config.php يغطّي
# المسار العادي، لكن استجابة يولّدها Apache قبل PHP لا تمرّ به.
RUN { \
      echo 'expose_php = Off'; \
      echo 'display_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'error_log = /dev/stderr'; \
      echo 'upload_max_filesize = 8M'; \
      echo 'post_max_size = 10M'; \
    } > /usr/local/etc/php/conf.d/cairo-store.ini

# ── جذر الويب هو public/ وحده ────────────────────────────────
# ⚠️ هذا ليس تفضيلاً: app/ و.env وvendor/ فوقه، ووضع الجذر على
# /var/www/html يجعل .env قابلاً للتنزيل بـHTTP.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
      /etc/apache2/sites-available/*.conf \
      /etc/apache2/apache2.conf \
 && printf '<Directory /var/www/html/public>\n\tAllowOverride All\n\tRequire all granted\n</Directory>\n' \
      > /etc/apache2/conf-available/cairo-store.conf \
 && a2enconf cairo-store

WORKDIR /var/www/html

# ── الاعتماديات أولاً، ثم الشيفرة ────────────────────────────
# طبقة منفصلة لملفَي composer: تغيير سطر في كنترولر لا يُبطل ذاكرة
# التثبيت، فيبقى البناء بثوانٍ بدل دقائق.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
 && mkdir -p storage/backups \
 && chown -R www-data:www-data storage public/images

EXPOSE 80

# فحص صحّة حقيقي: يطلب /health التي تتحقّق من القاعدة فعلاً، لا مجرّد
# «هل يستجيب Apache». حاوية تردّ 200 وقاعدتها ساقطة ليست سليمة.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS http://127.0.0.1/health || exit 1
