#!/bin/bash
set -e

# Render sets PORT dynamically (e.g. 10000)
PORT="${PORT:-80}"

echo "Configuring Apache to listen on port ${PORT}..."
sed -i "s/80/${PORT}/g" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

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

echo "Starting Apache server on port ${PORT}..."
exec "$@"
