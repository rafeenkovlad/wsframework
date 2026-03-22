FROM php:8.4-fpm

ENV SWOOLE_CONFIGURE_OPTS="--enable-openssl --enable-sockets"

WORKDIR /var/www/html

COPY event.ini "${PHP_INI_DIR}/conf.d/"
COPY ${PHP_INI} "${PHP_INI_DIR}/conf.d/"

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN apt-get update && \
    apt-get upgrade -y && \
    apt-get install -y git && \
	apt-get install -y net-tools && \
    apt-get install -y mc && \
    apt-get install -y \
            libexif-dev \
            libgd-dev \
            libpq-dev \
        && docker-php-ext-install exif gd && \
        docker-php-ext-install pdo pdo_pgsql pgsql && \
    chmod -R +x /var/www/html/ && \
    mkdir /var/log/m4 && \
    touch /var/log/m4/auth_dev.log && \
    chmod -R 777 /var/log/m4 && \
    chmod 777 /var/log/m4/auth_dev.log && \
    docker-php-ext-configure pcntl --enable-pcntl && docker-php-ext-install pcntl && \
    apt-get install libevent-dev -y && \
    apt-get install libssl-dev && \
    docker-php-ext-install sockets
RUN echo "no" | pecl install event && \
    apt-get update && apt-get install -y liburing-dev && \
    printf "yes\nyes\n" | pecl install swoole && docker-php-ext-enable swoole && \
    pecl install inotify && docker-php-ext-enable inotify

EXPOSE 80
