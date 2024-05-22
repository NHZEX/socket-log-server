FROM phpswoole/swoole:5.1.2-php8.1-alpine

RUN set -eux \
    && mkdir -p /opt/socket-log

COPY ./socket-log-server.phar /opt/socket-log/
WORKDIR /opt/socket-log

EXPOSE 1116 1229

CMD ["php", "socket-log-server.phar"]