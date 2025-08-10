FROM phpswoole/swoole:6.0.2-php8.3-alpine

RUN set -eux \
    && mkdir -p /opt/socket-log

COPY ./socket-log-server.phar /opt/socket-log/
WORKDIR /opt/socket-log

EXPOSE 1116 1229

CMD ["php", "socket-log-server.phar"]