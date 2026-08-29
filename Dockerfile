FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring zip bcmath

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-interaction --optimize-autoloader

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 8000

# Start-up is written so that a clean checkout needs no manual steps: create a
# .env if there is none, generate an APP_KEY only if one is missing (so restarts
# never rotate it), run the migrations, and serve regardless of whether the
# migration succeeded on the first attempt against a cold database.
CMD sh -c "\
    [ -f .env ] || cp .env.example .env; \
    grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force --no-interaction; \
    php artisan migrate --force || echo 'migrate failed - serving anyway'; \
    php artisan serve --host=0.0.0.0 --port=8000"
