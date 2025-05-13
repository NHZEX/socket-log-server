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

### 方法3: Docker（需要网络代理）

```shell
docker pull ghcr.io/nhzex/socket-log-server:latest
```

## 服务端口 
  - http server: 1116
  - websocket: 1229 (提供老浏览器扩展兼容支持)

## 配置使用环境变量或`.env`

```dotenv
# 工作进程数量，默认1就行，没有调大的价值
SL_WORKER_NUM=1
# 主端口监听，支持ipv6、unix（http+ws双协议。不区分客户端连入）
SL_SERVER_LISTEN=[::]:1116
# 兼容老客户端的独立端口，默认启用，后续会弃用
SL_SERVER_BC_LISTEN=0.0.0.0:1229
# 监听unix socket 权限设置，需要开启才生效
SL_LISTEN_UNIX_SOCK_MODE=0755
SL_LISTEN_UNIX_SOCK_USER=www-data
SL_LISTEN_UNIX_SOCK_GROUP=www-data
# 允许中转连入的客户端ID白名单，为空则不启用
# 匹配语法参考php函数`fnmatch`：https://www.php.net/manual/en/function.fnmatch.php
SL_ALLOW_CLIENT_LIST="
debug?
test*
sl*
"
```

## 公网使用建议

建议套`nginx`代理，反代走`https`,`wss`。

## 自行构建`Phar`

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
