FROM jrottenberg/ffmpeg:8.0-ubuntu

RUN apt-get update && \
    apt-get install -y software-properties-common && \
    add-apt-repository ppa:ondrej/php && \
    apt-get update && \
    apt-get install -y \
        php8.4-cli \
        php8.4-curl \
        php8.4-xml \
        php8.4-pgsql \
        php8.4-intl \
        php8.4-mbstring \
        php8.4-sockets \
        git curl net-tools mc && \
    apt-get clean

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN mkdir -p /var/log/m4 && \
    touch /var/log/m4/auth_dev.log && \
    chmod -R 777 /var/log/m4

WORKDIR /var/www/html

ENTRYPOINT []
