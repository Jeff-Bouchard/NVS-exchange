# Base PHP-Apache image
FROM php:8.2-apache-bookworm

# Install PHP extensions you might need (SQLite, JSON, etc.)
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
    libcurl4-openssl-dev \
    libsqlite3-dev \
 && docker-php-ext-install pdo pdo_mysql pdo_sqlite curl \
 && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Copy application code
COPY . /var/www/html

# Set Apache document root to the web/ directory
RUN sed -ri 's#/var/www/html#/var/www/html/web#g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri 's#/var/www/html#/var/www/html/web#g' /etc/apache2/apache2.conf

# Enable Apache mod_rewrite if needed
RUN a2enmod rewrite

# Expose HTTP port
EXPOSE 80

# Default command
CMD ["apache2-foreground"]
