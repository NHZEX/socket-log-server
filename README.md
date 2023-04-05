# Socket Log Server

thinkphp socket-log 日志转发服务

## 环境需求

- php >= 8.1
- ext-zlib
- ext-ctype
- ext-mbstring

## 启动服务 

### Phar
1. 下载 [socket-log.phar](https://github.com/NHZEX/socket-log-server/releases/latest/download/socket-log.phar)  
2. ```php socket-log-server.phar```

### Swoole-Cli

待补充

## 服务端口 
  - http server: 1116
  - websocket: 1229 (提供兼容支持)

## 构建`Phar`

```bash
swoole-cli -dphar.readonly=false ./box.phar compile
```