FROM php:8.2-apache

# Install MySQL support
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copy files
COPY api/ /var/www/html/

# Enable rewrite
RUN a2enmod rewrite

# Fix permissions
RUN chown -R www-data:www-data /var/www/html
