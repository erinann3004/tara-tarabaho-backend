ARG PHP_VERSION=8.5

FROM php:${PHP_VERSION}-apache

# Install the PHP extensions used by the API and its integrations.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev unzip \
    && docker-php-ext-install pdo pdo_mysql curl mbstring \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Enable Apache mod_rewrite and allow the app's .htaccess rules.
RUN a2enmod rewrite \
    && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Install dependencies without copying local vendor files into the image.
COPY LavaLust/composer.json LavaLust/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Copy the LavaLust application from this repository's subdirectory.
COPY LavaLust/ ./

RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -i "s|DocumentRoot /var/www/html|DocumentRoot ${APACHE_DOCUMENT_ROOT}|g" /etc/apache2/sites-available/000-default.conf \
    && sed -i "s|<Directory /var/www/html>|<Directory ${APACHE_DOCUMENT_ROOT}>|g" /etc/apache2/apache2.conf

EXPOSE 80
