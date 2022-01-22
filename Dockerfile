FROM php:7.4-cli-alpine3.14

RUN set -eux \
    && apk add --no-cache libevent openssl \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libevent-dev \
        openssl-dev \
    && docker-php-source extract \
    && docker-php-ext-install -j$(nproc) sockets pcntl \
    && pecl install event \
    && docker-php-ext-enable --ini-name zz-event.ini event \
    && docker-php-source delete \
    && apk del --no-network .build-deps \
    && rm -rf /var/cache/apk/* \
    && php -v \
    && php -m \
    && mkdir -p /opt/socket-log

COPY ./socket-log.phar /opt/socket-log/
WORKDIR /opt/socket-log

EXPOSE 1116 1229

CMD ["php", "socket-log.phar", "start"]