# Use official PHP image with Apache
FROM php:8.2-apache

# Install dependencies
RUN apt-get update && apt-get install -y \
    libonig-dev \
    libzip-dev \
    unzip \
    git \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libxml2-dev \
    zip \
    && docker-php-ext-install pdo_mysql mysqli mbstring zip exif \
    && docker-php-ext-enable pdo_mysql \
    && pecl install redis \
    && pecl install mongodb \
    && docker-php-ext-enable redis mongodb

# Enable Apache rewrite and headers
RUN a2enmod rewrite headers \
    && sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' /etc/apache2/sites-enabled/000-default.conf \
    && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/{s/AllowOverride None/AllowOverride All/}' /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Copy project
COPY . /var/www/html

# Ensure init script is executable
RUN chmod +x /var/www/html/database/init.sh

# Ensure uploads directory is writable
RUN mkdir -p public/assets/uploads && chown -R www-data:www-data public/assets/uploads && chmod -R 755 public/assets/uploads

# Expose port
EXPOSE 80

# Entry point
CMD ["apache2-foreground"]
