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

if [ "$APP_ENV" = "production" ] || [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force
fi

php artisan storage:link 2>/dev/null || true

if [ "$APP_ENV" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

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

exec apache2-foreground
