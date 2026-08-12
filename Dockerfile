FROM php:8.3-apache

RUN apt-get update \
  && apt-get install -y --no-install-recommends \
    default-mysql-client \
    git \
    libfreetype6-dev \
    libicu-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    unzip \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j"$(nproc)" gd intl opcache pdo_mysql zip \
  && a2enmod headers rewrite \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APACHE_DOCUMENT_ROOT=/var/www/html/web
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
  /etc/apache2/sites-available/*.conf \
  /etc/apache2/apache2.conf \
  /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

COPY composer.json ./
COPY web ./web

RUN composer install --no-interaction --prefer-dist --optimize-autoloader \
  && mkdir -p web/sites/default/files \
  && chown -R www-data:www-data web/sites/default
