FROM php:8.3-fpm

# 系统依赖
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    libpq-dev \
    libicu-dev \
    libwebp-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# PHP 扩展
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    intl

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 工作目录
WORKDIR /var/www/html

# 用户权限
RUN groupadd -g 1000 www \
    && useradd -u 1000 -g www -m -s /bin/bash www \
    && chown -R www:www /var/www/html

USER www

EXPOSE 9000
CMD ["php-fpm"]
