FROM php:8.2-fpm-alpine

# Встановлення необхідних розширень PHP
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Встановлення додаткових пакетів
RUN apk add --no-cache \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd mbstring

# Налаштування PHP
RUN echo "upload_max_filesize = 32M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "post_max_size = 32M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html

# Права доступу
RUN chown -R www-data:www-data /var/www/html
