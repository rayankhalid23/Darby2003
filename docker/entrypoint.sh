#!/bin/sh
set -e

# Render يمرر رقم البورت عبر متغير PORT وقت التشغيل (وليس وقت البناء)
PORT="${PORT:-80}"
sed -ri "s/Listen [0-9]+/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -ri "s/:[0-9]+>/:${PORT}>/g" /etc/apache2/sites-available/*.conf

if [ ! -f /var/www/html/storage/oauth-private.key ] && [ -z "$APP_KEY" ]; then
    echo "تحذير: APP_KEY غير مضبوط في متغيرات البيئة."
fi

php artisan config:clear >/dev/null 2>&1 || true

if [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force || true
fi

php artisan storage:link 2>/dev/null || true

if [ "$APP_ENV" = "production" ]; then
    php artisan config:cache
    php artisan view:cache
fi

# ضمان صلاحيات المجلدات لـ www-data في كل تشغيل للحاوية
mkdir -p /var/www/html/storage/logs \
         /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/bootstrap/cache
touch /var/www/html/storage/logs/laravel.log || true
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache


# Render (الخطة المجانية) ما توفر cron جاهز، فنشغّل حلقة تستدعي جدولة Laravel
# كل دقيقة داخل نفس الحاوية بدل الاعتماد على cron خارجي.
if [ "$RUN_SCHEDULER" != "false" ]; then
    (
        while true; do
            php artisan schedule:run --no-interaction >> /dev/stdout 2>&1
            sleep 60
        done
    ) &
fi

if [ "$RUN_QUEUE_WORKER" != "false" ]; then
    (
        php artisan queue:work --sleep=3 --tries=3 --timeout=90 --no-interaction >> /dev/stdout 2>&1
    ) &
fi

exec apache2-foreground
