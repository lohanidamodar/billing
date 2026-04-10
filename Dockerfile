FROM composer:2.8 AS composer

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install \
    --ignore-platform-reqs \
    --optimize-autoloader \
    --no-plugins \
    --no-scripts \
    --prefer-dist

FROM php:8.3-cli-alpine AS final

WORKDIR /usr/src/code

RUN apk add --no-cache autoconf gcc g++ make \
    && docker-php-ext-install pdo_mysql \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del autoconf gcc g++ make

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
RUN echo "memory_limit=512M" >> $PHP_INI_DIR/php.ini

COPY --from=composer /usr/local/src/vendor /usr/src/code/vendor
COPY ./src /usr/src/code/src
COPY ./tests /usr/src/code/tests
COPY ./templates /usr/src/code/templates
COPY ./phpunit.xml /usr/src/code/phpunit.xml

CMD ["tail", "-f", "/dev/null"]
