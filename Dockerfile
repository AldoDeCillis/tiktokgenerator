# 1. Usa un'immagine base con PHP, Apache e Composer
FROM php:8.2-apache

# 2. Installa estensioni PHP necessarie
RUN apt-get update && apt-get install -y \
    unzip zip git curl libzip-dev libpng-dev libonig-dev libxml2-dev npm nodejs \
    && docker-php-ext-install pdo pdo_mysql zip

# 3. Installa Composer globalmente
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. Imposta la cartella del progetto
WORKDIR /var/www/html

# 5. Copia tutto il tuo progetto nel container
COPY . .

# 6. Installa dipendenze backend
RUN composer install --no-dev --optimize-autoloader

# 7. Installa dipendenze frontend e builda assets con Vite
RUN npm install && npm run build

# 8. Cache config Laravel e imposta permessi
RUN php artisan config:cache && \
    chown -R www-data:www-data storage bootstrap/cache

# 9. Cambia root di Apache su /public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' \
    /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# 10. Abilita mod_rewrite di Apache per le rotte Laravel
RUN a2enmod rewrite
