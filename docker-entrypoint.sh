#!/bin/bash
set -e

# Render sets PORT dynamically (e.g. 10000)
PORT="${PORT:-80}"

echo "Configuring Apache to listen on port ${PORT}..."
sed -i "s/80/${PORT}/g" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf
echo "UseCanonicalName Off" >> /etc/apache2/apache2.conf

echo "Preparing Laravel application..."

# Ensure storage directories exist and have proper permissions
mkdir -p /var/www/html/storage/framework/cache /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views /var/www/html/storage/logs
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Create storage symlink if not present
php artisan storage:link --force || true

# Optimize & Cache Laravel configuration and routes
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Execute migrations if RUN_MIGRATIONS is true (default: true)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "Running database migrations..."
    php artisan migrate --force || echo "Migration command returned non-zero status."
    echo "Seeding admin user and amenities..."
    php artisan db:seed --class=AdminUserSeeder --force || echo "Admin seeder completed."
    php artisan db:seed --class=AmenitySeeder --force || echo "Amenity seeder completed."
fi

# Start Laravel background scheduler
echo "Starting Laravel scheduler..."
runuser -u www-data -- php artisan schedule:work 2>&1 \
  | grep --line-buffered -v "No scheduled commands are ready to run" &

# Keep-alive: Render's free plan puts the service to sleep after 15 minutes without traffic.
# Ping our own public URL every 5 minutes so it stays awake. /api/ping does not touch the
# database, so Neon can still sleep when nobody uses the app.
# RENDER_EXTERNAL_URL is set by Render only, so this never runs locally.
# Turn off with KEEP_ALIVE=false in Render -> Environment.
KEEP_ALIVE_URL="${KEEP_ALIVE_URL:-${RENDER_EXTERNAL_URL:+${RENDER_EXTERNAL_URL%/}/api/ping}}"
if [ "${KEEP_ALIVE:-true}" = "true" ] && [ -n "${KEEP_ALIVE_URL}" ]; then
    echo "Starting keep-alive ping to ${KEEP_ALIVE_URL} every 5 minutes..."
    (
        set +e
        while true; do
            sleep 300
            curl -fsS -o /dev/null --max-time 20 --retry 2 --retry-delay 5 \
                -A "yaan-keep-alive" "${KEEP_ALIVE_URL}" \
                || echo "Keep-alive ping failed: ${KEEP_ALIVE_URL}"
        done
    ) &
fi

echo "Starting Apache server on port ${PORT}..."
exec "$@"
