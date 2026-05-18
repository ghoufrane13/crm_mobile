FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    curl zip unzip git libicu-dev \
    && docker-php-ext-install mysqli pdo pdo_mysql intl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer config platform.php 8.2.0 && \
    composer update laminas/laminas-escaper --no-dev && \
    composer install --no-dev --optimize-autoloader --ignore-platform-reqs

EXPOSE 8080

CMD php -S 0.0.0.0:8080 -t public