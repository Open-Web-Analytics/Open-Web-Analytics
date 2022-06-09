FROM php:7.2-apache@sha256:4dc0f0115acf8c2f0df69295ae822e49f5ad5fe849725847f15aa0e5802b55f8
LABEL Description="Docker image for Open Web Analytics with Apache and php 7.2"

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
    && docker-php-ext-enable mcrypt

#install composer
SHELL ["/bin/bash", "-o", "pipefail", "-c"]
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer

#create project folder
ENV APP_HOME /var/www/html
WORKDIR $APP_HOME


#change uid and gid of apache to docker user uid/gid
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data


#copy source files and run composer
COPY . $APP_HOME
RUN composer install --no-interaction --no-dev

#change ownership
RUN chown -R www-data:www-data $APP_HOME

USER www-data

ENTRYPOINT ["bash", "entrypoint.sh"]
