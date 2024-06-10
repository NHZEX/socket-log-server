# Socket Log Server

thinkphp socket-log 日志转发服务

## 环境需求

- php >= 8.1
- ext-zlib
- ext-ctype
- ext-mbstring

## 启动服务 

### 方法1: Phar
1. 下载最新 [socket-log.phar](https://github.com/NHZEX/socket-log-server/releases/latest/download/socket-log-server.phar)  
2. ```php socket-log-server.phar```

### 方法2: Swoole-CLI SFX
1. 下载最新 [socket-log-linux-sfx](https://github.com/NHZEX/socket-log-server/releases/latest/download/socket-log-linux-sfx)
2. ```chmod +x socket-log-linux-sfx```
3. ```./socket-log-linux-sfx --self```

#### 自执行传参方式举例
```bash
# 查构建版本号
./socket-log-linux-sfx --self -- -V
```

## 服务端口 
  - http server: 1116
  - websocket: 1229 (提供老浏览器扩展兼容支持)

## 构建`Phar`

```bash
# 下载
wget https://github.com/box-project/box/releases/download/4.3.8/box.phar
# 构建
php -dphar.readonly=false ./box.phar compile
# 结果
./bin/socket-log-server.phar
```

## 构建 swoole-cli sfx

```bash
swoole-cli ./pack-sfx.php ./bin/socket-log-server.phar ./bin/socket-log-linux-sfx
```

## systemctl 守护

使用 swoole-cli sfx 二进制包

```ini
[Unit]
Description=socket-log
After=network.target syslog.target

[Service]
Type=simple
LimitNOFILE=655350
ExecStart=/opt/socket-log/socket-log-linux-sfx --self
ExecReload=/bin/kill -USR1 $MAINPID
Restart=always

[Install]
WantedBy=multi-user.target graphical.target
```

## test

```bash
swoole-cli-5 -dopcache.enable_cli=on -dopcache.jit_buffer_size=64M main.php -q
```
