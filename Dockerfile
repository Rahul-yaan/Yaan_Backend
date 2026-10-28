# Multi-stage Dockerfile for Laravel Backend on Render

# --- Stage 1: Build & Dependencies ---
FROM php:8.2-apache AS base

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    zip \
    unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions required by Laravel
RUN docker-php-ext-install pdo pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd zip opcache

# Enable Apache rewrite module and headers
RUN a2enmod rewrite headers

# Configure PHP upload limits and memory
RUN echo "upload_max_filesize = 32M\npost_max_size = 35M\nmemory_limit = 256M" > /usr/local/etc/php/conf.d/uploads.ini

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# Configure Apache DocumentRoot to /var/www/html/public
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow htaccess overrides and harden Apache directory configuration
RUN echo '<Directory /var/www/html/public>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/laravel.conf \
    && a2enconf laravel

# Add Yaan Apache Security Configuration: block KYC direct access, block scripts in storage, deny dot-files
RUN echo '# Yaan Security Rules\n\
<Directory /var/www/html/public/storage/kyc_docs>\n\
    Require all denied\n\
</Directory>\n\
<Directory /var/www/html/public/storage>\n\
    Options -Indexes\n\
    <FilesMatch "\.(php|phtml|phar|sh|bash|py|pl|cgi)$">\n\
        Require all denied\n\
    </FilesMatch>\n\
</Directory>\n\
<FilesMatch "^\.">\n\
    Require all denied\n\
</FilesMatch>' > /etc/apache2/conf-available/yaan-security.conf \
    && a2enconf yaan-security

# Ensure proper permissions for storage and bootstrap/cache
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Copy entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Expose default port
EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
