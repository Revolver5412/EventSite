FROM php:8.2-fpm-alpine

ENV PAGER=more
ENV WP_CLI_CACHE_DIR=/tmp/.wp-cli
ENV WP_CLI_CUSTOM_SHELL=/bin/bash

# Install composer and wp cli
COPY --from=composer:2.5 /usr/bin/composer /usr/bin/composer
COPY --from=wordpress:cli-php7.4 /usr/local/bin/wp /usr/bin/wp

# Install dependencies for GD and install GD with support for jpeg, png webp and freetype
# Info about installing GD in PHP https://www.php.net/manual/en/image.installation.php
RUN apk add --no-cache \
        libjpeg-turbo-dev \
        libpng-dev \
        libwebp-dev \
        freetype-dev

# As of PHP 7.4 we don't need to add --with-png
RUN docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype
RUN docker-php-ext-install gd

# install extensions
# intl, zip, soap
# mysqli, pdo, pdo_mysql, pdo_pgsql
# gmp
# mysql-client is needed for wp cli db export
RUN apk add --update --no-cache gcc ${PHPIZE_DEPS} imagemagick imagemagick-dev bash build-base autoconf ghostscript-fonts mysql-client ghostscript nano libintl icu icu-dev libxml2-dev libzip-dev oniguruma-dev postgresql-dev gmp gmp-dev \
    && docker-php-ext-install -j$(nproc) intl zip soap mysqli pdo pdo_mysql gmp

# php-redis
RUN pecl install -o -f redis \
    &&  rm -rf /tmp/pear \
    &&  docker-php-ext-enable redis

# apcu
RUN docker-php-source extract \
    && apk add --no-cache --virtual .phpize-deps-configure $PHPIZE_DEPS \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && apk del .phpize-deps-configure \
    && docker-php-source delete


# git client
RUN apk add --update --no-cache

# imagick
RUN pecl install -o -f imagick\
    && docker-php-ext-enable imagick

# install bcmath extension
RUN docker-php-ext-install -j$(nproc) bcmath exif

#################
# CONFIGURE PHP #
#################
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
RUN cd /usr/local/etc/php-fpm.d/ && \
    sed -i 's/pm\.max_children = 5/pm\.max_children = 500/g' * && \
    sed -i 's/pm\.max_spare_servers = 3/pm\.max_spare_servers = 10/g' * && \
    sed -i 's/\;catch_workers_output = yes/catch_workers_output = yes/g' * && \
    echo "memory_limit=512M" >> $PHP_INI_DIR/conf.d/uploads.ini && \
    echo "upload_max_filesize=5000M" >> $PHP_INI_DIR/conf.d/uploads.ini && \
    echo "max_execution_time=90" >> $PHP_INI_DIR/conf.d/uploads.ini && \
    echo "max_input_vars=5000" >> $PHP_INI_DIR/conf.d/uploads.ini && \
    echo "post_max_size=5000M" >> $PHP_INI_DIR/conf.d/uploads.ini && \
    echo "max_input_time=0" >> $PHP_INI_DIR/conf.d/uploads.ini

####################
# SETUP ENTRYPOINT #
####################
USER www-data
WORKDIR /var/www/html
CMD ["php-fpm"]
