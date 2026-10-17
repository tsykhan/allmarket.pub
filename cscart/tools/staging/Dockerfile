# Staging web server for testing the allmarket_sync add-on on a copy of a CS-Cart store.
# Minimal on purpose (no apt packages): CS-Cart runs without gd for this test.
FROM php:7.4-apache
RUN docker-php-ext-install -j4 mysqli pdo_mysql \
 && a2enmod rewrite headers \
 && { echo 'memory_limit=512M'; echo 'max_execution_time=300'; echo 'date.timezone=Europe/Kyiv'; echo 'display_errors=Off'; echo 'log_errors=On'; } > /usr/local/etc/php/conf.d/cscart.ini \
 && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
