FROM php:8.3-apache

# Install the MySQL drivers PHP needs to talk to the db container
RUN docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite

# Add entrypoint script to run migrations before Apache starts
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

# Apache serves /var/www/html, which is bind-mounted from ./php/src
