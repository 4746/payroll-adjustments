#!/usr/bin/env bash
set -e

cd /var/www

# якщо примонтований код без vendor — не валимося локально
if [ ! -d "vendor" ] && [ -f "composer.json" ]; then
  echo "vendor/ not found — run 'composer install' on host or inside container if needed."
fi

# права на кеш/сторедж (на випадок Windows volume)
#if [ -d "storage" ] && [ -d "bootstrap" ]; then
#    mkdir -p storage/framework/{cache,sessions,views} bootstrap/cache || true
#    chown -R www-data:www-data storage bootstrap/cache || true
#    chmod -R ug+rw storage bootstrap/cache || true
#fi

# локально не кешуємо, щоб не заважати розробці
#if [ -f "artisan" ]; then
#    php artisan optimize:clear || true
#fi

exec /usr/bin/supervisord -c /etc/supervisord.conf
