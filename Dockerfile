# 1. Base Image
FROM php:8.3-fpm

# 2. Set the "home base"
WORKDIR /var/www/html

# 3. Copy your project files
COPY . .

# 4. INSTALL COMPOSER (Add this line!)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 5. Install Symfony dependencies & Redis (Add these!)
RUN apt-get update && apt-get install -y \
    git unzip libicu-dev zlib1g-dev libzip-dev \
    && docker-php-ext-install intl pdo pdo_mysql zip bcmath \
    && pecl install redis && docker-php-ext-enable redis

# 6. Ports & Command
EXPOSE 9000
CMD ["php-fpm"]
