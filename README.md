# Socket Log Server

thinkphp socket-log 日志转发服务

## 环境需求

- php >= 7.1
- ext-zip

## 启动服务 

### Phar
1. 下载 [socket-log.phar](https://github.com/NHZEX/socket-log-server/releases/latest/download/socket-log.phar)  
2. ```php socket-log.phar start -d```

### Docker

```bash
docker run -it socket-log-server:latest
```
Docker Hub: [socket-log-server](https://hub.docker.com/r/ozxin/socket-log-server)

## 服务端口 
  - http server: 1116
  - websocket: 1229
