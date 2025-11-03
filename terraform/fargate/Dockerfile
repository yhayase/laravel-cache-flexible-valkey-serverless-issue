FROM php:8.2-cli

# Install required packages
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip

# Install phpredis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy Composer dependencies and install
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader

# Copy test scripts
COPY test-valkey-cluster.php ./
COPY database.php ./

# Environment variables (overridden at runtime)
ENV VALKEY_ENDPOINT=""
ENV VALKEY_PORT="6379"

# Default command
CMD php test-valkey-cluster.php
