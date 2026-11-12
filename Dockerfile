# syntax=docker/dockerfile:1
FROM php:8.2-apache

WORKDIR /opt/devatlas
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libpq-dev libicu-dev libonig-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 pdo_pgsql pgsql intl mbstring zip gd \
    && a2enmod rewrite headers expires deflate \
    && sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /opt/devatlas>\n AllowOverride All\n Require all granted\n Options FollowSymLinks\n</Directory>\n' > /etc/apache2/conf-available/devatlas.conf \
    && a2enconf devatlas \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY . /opt/devatlas
# The template contains placeholders; process environment variables supply installation values.
# Optional build-time CA trust supports networks that inspect TLS without disabling verification.
RUN --mount=type=secret,id=proxy_ca \
    if [ -f /run/secrets/proxy_ca ]; then export COMPOSER_CAFILE=/run/secrets/proxy_ca; fi; \
    chmod -R a+rX /opt/devatlas \
    && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader \
    && cp .env.example .env \
    && mkdir -p writable/cache writable/logs writable/session \
    && chown -R www-data:www-data writable \
    && ln -s /opt/devatlas /var/www/html/AnalystToolkit
EXPOSE 10000
CMD ["apache2-foreground"]
