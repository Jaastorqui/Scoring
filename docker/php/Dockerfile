FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    curl \
    git \
    libcurl4-openssl-dev \
    unzip \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install curl

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /workspace

# Set working directory
WORKDIR /workspace

# Copy composer files from build context
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader


# Run composer install on container start
CMD ["sh", "-c", "composer install --no-dev --optimize-autoloader && exec php-fpm"]