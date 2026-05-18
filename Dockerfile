FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    curl zip unzip git libicu-dev \
    && docker-php-ext-install mysqli pdo pdo_mysql intl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs --no-security-blocking

RUN mkdir -p /app/writable/cache /app/writable/logs /app/writable/session /app/writable/uploads \
    && chmod -R 777 /app/writable

EXPOSE 8080

CMD php -S 0.0.0.0:8080 -t public