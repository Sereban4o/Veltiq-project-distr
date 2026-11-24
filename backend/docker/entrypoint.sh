#!/usr/bin/env sh
set -e

# Discover packages (safe to run repeatedly)
php artisan package:discover --ansi >/dev/null 2>&1 || true

# Ensure the public storage symlink exists
if [ ! -L public/storage ]; then
    php artisan storage:link >/dev/null 2>&1 || true
fi

# Ensure the updates directory (bind-mounted from the host) exists and is
# writable by PHP-FPM, otherwise admin release uploads fail with
# "Unable to write in the ... directory".
if [ -d /var/www/updates ] || mkdir -p /var/www/updates 2>/dev/null; then
    mkdir -p /var/www/updates/android /var/www/updates/windows 2>/dev/null || true
    chown -R www-data:www-data /var/www/updates 2>/dev/null || true
    chmod -R u+rwX,g+rwX /var/www/updates 2>/dev/null || true
fi

# Uploadable templates (docs/13-template-system.md): the directory must be
# writable by PHP-FPM, because archives are unpacked by an HTTP request. It is
# created at boot so an install performed by CLI (root) does not leave it
# root-owned and break the next upload with "mkdir(): Permission denied".
mkdir -p storage/app/templates 2>/dev/null || true
chown -R www-data:www-data storage/app/templates 2>/dev/null || true
chmod -R u+rwX,g+rwX storage/app/templates 2>/dev/null || true

# Production cache warmup (only in the php-fpm/web container, once at boot).
# route:cache may be skipped for apps that declare routes via closures; the
# failures are tolerated so the container still starts.
if [ "$APP_ENV" = "production" ] && [ "$1" = "php-fpm" ]; then
    php artisan config:cache >/dev/null 2>&1 || true
    php artisan route:cache >/dev/null 2>&1 || true
    php artisan view:cache >/dev/null 2>&1 || true
fi

exec "$@"
