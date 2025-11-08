# Use the official PHP image with Apache web server
FROM php:8.2-apache

# Enable Apache's rewrite module
RUN a2enmod rewrite

# --- THIS IS THE IMPORTANT LINE ---
# Copy the custom Apache configuration to allow .htaccess
COPY apache/000-default.conf /etc/apache2/sites-available/000-default.conf
# --- END IMPORTANT LINE ---

# Install system dependencies required for PHP extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

# Configure and install the gd, zip, and curl extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install pdo pdo_pgsql zip curl

# Install Composer for PHP dependency management
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Set the working directory for our application
WORKDIR /var/www/html

# Copy composer files and install dependencies
COPY composer.json .
RUN composer install

# Copy the rest of the application source code
COPY src/ .

# Ensure the uploads directory and all files are writable by the web server
RUN mkdir -p uploads && chown -R www-data:www-data /var/www/html