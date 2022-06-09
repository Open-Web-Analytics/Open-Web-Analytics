FROM php:7.4-apache@sha256:c04eff41a8cc583d3e09a355d45d6e30db0d25b3492c49a959e8907486034b5a
LABEL Description="Docker image for Open Web Analytics with Apache and php 7.4"

ENV APP_HOME /var/www/html

WORKDIR $APP_HOME
COPY . $APP_HOME

SHELL ["/bin/bash", "-o", "pipefail", "-c"]
#install all the dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
      libicu-dev \
      libpq-dev \
      libmcrypt-dev \
      git \
      zip \
      unzip \
      libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean \
    && docker-php-ext-configure pdo_mysql --with-pdo-mysql=mysqlnd \
    && docker-php-ext-install \
      mysqli \
      intl \
      pcntl \
      pdo_mysql \
      pdo_pgsql \
      pgsql \
      zip \
      opcache \
    && pecl install mcrypt \
    && docker-php-ext-enable mcrypt \
    # Install and run composer
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer \
    && composer install --no-interaction --no-dev \
    # Change uid and gid of apache to docker user uid/gid
    && usermod -u 1000 www-data && groupmod -g 1000 www-data \
    && chown -R www-data:www-data $APP_HOME

USER www-data
