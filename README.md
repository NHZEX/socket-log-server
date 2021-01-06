# Socket Log Server

thinkphp socket-log 日志转发服务

## 环境需求

- php >= 7.1
- ext-zlib

## 启动服务 

### Phar
1. 下载 [socket-log.phar](https://github.com/NHZEX/socket-log-server/releases/latest/download/socket-log.phar)  
2. ```php socket-log.phar start -d```

### Docker

Docker Hub: [socket-log-server](https://hub.docker.com/r/ozxin/socket-log-server)

##### cli
```bash
docker run \
  -t \
  -p 1116:1116 \
  -p 1229:1229 \
  ozxin/socket-log-server:latest
```

#### docker-compose
```yaml
version: "3"

services:
  log-server:
    image: ozxin/socket-log-server:latest
    restart: always
    ports:
      - "1116:1116"
      - "1229:1229"
```

## 服务端口 
  - http server: 1116
  - websocket: 1229
