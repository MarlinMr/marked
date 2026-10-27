FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql && a2enmod rewrite headers \
    && sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/marked.ini
WORKDIR /var/www/html
COPY . .
RUN chown -R www-data:www-data storage
