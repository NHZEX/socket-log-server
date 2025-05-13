<?php
declare(strict_types=1);

namespace SocketLog;

use Dotenv\Dotenv;
use Psr\Log\LoggerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use function Zxin\Util\format_byte;

class Server
{
    /**
     * 主监听地址 支持推送传入与ws推送
     */
    protected string $listen = '0.0.0.0:1116';

    /**
     * 辅助监听地址 用于兼容，仅支持ws推送
     */
    protected string                 $listenWS     = '0.0.0.0:1229';
    private \Swoole\WebSocket\Server $server;
    private array                    $broadcastMap = [];
    private array                    $clientIdMap  = [];

    private array                    $config = [
        'worker_num'               => 1,
        'daemonize'                => false,
        'heartbeat_check_interval' => 60,
        'heartbeat_idle_time'      => 300,
        'pid_file'                 => RUNTIME_DIR . '/server.pid',
        'http_compression'         => true,
        'websocket_compression'    => true,
    ];

    private array                    $allowContentTypes = [
        'application/json',
        'application/x-compress',
        'application/x-e2e-compress+json',
        'application/x-e2e-json',
    ];

    private array                    $allowClient = [];

    const BIN_MSG_HEADER  = "\x05\x21";
    const PING_CONTENT    = "\x05\x22\x09";
    const PONG_CONTENT    = "\x05\x22\x0A";
    const FLAG_COMPRESS   = 0x0001;
    const FLAG_ENCRYPTION = 0x0002;
    const FLAG_USE_E2E_ID = 0x0004;

    public function __construct(
        protected LoggerInterface $logger,
    ) {
        $this->resolveConfig();
        $this->create();
    }

    public static function verifyEnv(Dotenv $dotenv): void
    {
        $dotenv
            ->ifPresent('SL_SERVER_LISTEN')
            ->assert(
                fn ($val) => test_address_and_port($val),
                \sprintf('(%s) address is invalid', $_ENV['SL_SERVER_LISTEN'] ?? '')
            );

        $dotenv
            ->ifPresent('SL_SERVER_BC_LISTEN')
            ->assert(function (string $val): bool {
                if ('false' === $val)  {
                    return true;
                }
                return test_address_and_port($val);
            }, \sprintf('(%s) address is invalid', $_ENV['SL_SERVER_BC_LISTEN'] ?? ''));

        $dotenv->ifPresent('SL_WORKER_NUM')
            ->isInteger();
    }

    protected function resolveConfig(): void
    {
        $this->listen = $_ENV['SL_SERVER_LISTEN'] ?? $this->listen;
        $this->listenWS = $_ENV['SL_SERVER_BC_LISTEN'] ?? $this->listenWS;

        if (!empty($_ENV['SL_ALLOW_CLIENT_LIST'])) {
            $this->allowClient = \array_filter(\array_map('\trim', \explode("\n", $_ENV['SL_ALLOW_CLIENT_LIST'])));
        }
    }

    protected function create(): void
    {
        $listen = parse_str_ip_and_port($this->listen, 1116);
        if ($listen[2] ?? false) {
            $sockType = SWOOLE_UNIX_STREAM;
        } else {
            $sockType = SWOOLE_SOCK_TCP | SWOOLE_SOCK_TCP6;
        }
        $server = new \Swoole\WebSocket\Server(
            $listen[0] ?: '127.0.0.1',
            (int) $listen[1],
            SWOOLE_BASE,
            $sockType,
        );

        $this->logger->info(\sprintf(
            'listen(http): %s://%s',
            $sockType === SWOOLE_UNIX_STREAM ? 'unix' : 'tcp',
            $this->listen,
        ));
        $this->logger->info(\sprintf(
            'listen(ws): %s://%s',
            $sockType === SWOOLE_UNIX_STREAM ? 'unix' : 'tcp',
            $this->listen,
        ));
        if (SWOOLE_UNIX_STREAM === $sockType) {
            self::setSocketOwnership($listen[0]);
        }

        $server->set($this->config);
        $listen = parse_str_ip_and_port($this->listenWS, 1229);
        if ($listen[2] ?? false) {
            $sockType = SWOOLE_UNIX_STREAM;
        } else {
            $sockType = SWOOLE_SOCK_TCP | SWOOLE_SOCK_TCP6;
        }
        $server->addlistener(
            $listen[0] ?: '127.0.0.1',
            (int) $listen[1],
            $sockType,
        );
        $this->logger->info(\sprintf(
            'listen(ws): %s://%s',
            $sockType === SWOOLE_UNIX_STREAM ? 'unix' : 'tcp',
            $this->listenWS,
        ));
        if (SWOOLE_UNIX_STREAM === $sockType) {
            self::setSocketOwnership($listen[0]);
        }

        $this->server = $server;

        $this->initEvent();
    }

    protected static function setSocketOwnership(string $filename): void
    {
        if (isset($_ENV['SL_LISTEN_UNIX_SOCK_CHMOD'])) {
            @chmod($filename, octdec($_ENV['SL_LISTEN_UNIX_SOCK_CHMOD'] ?: '0755'));
        }
        if (!empty($_ENV['SL_LISTEN_UNIX_SOCK_USER'])) {
            @chown($filename, $_ENV['SL_LISTEN_UNIX_SOCK_USER']);
        }
        if (!empty($_ENV['SL_LISTEN_UNIX_SOCK_GROUP'])) {
            @chgrp($filename, $_ENV['SL_LISTEN_UNIX_SOCK_GROUP']);
        }
    }

    protected function initEvent(): void
    {
        $this->server->on('workerStart', function (\Swoole\Server $server, int $workerId) {
            $this->logger->info("workerStart #{$workerId}");


        });
        $this->server->on('request', function (Request $request, Response $response) {
            $this->onRequest($request, $response);
        });

        $this->server->on('handshake', function (Request $request, Response $response) {
            $this->clientHandshake($request, $response);
        });

//        $this->server->on('open', function (\Swoole\WebSocket\Server $server, \Swoole\Http\Request $request) {
//        });

        $this->server->on('message', $this->onMessage(...));

        $this->server->on('disconnect', function (\Swoole\WebSocket\Server $server, int $fd) {
            $this->clientLeave($fd);
        });

        $this->server->on('close', function (\Swoole\WebSocket\Server $server, int $fd) {
            $this->clientLeave($fd);
        });
    }

    private function resolveClientId(Request $request): array
    {
        $path = $request->server['request_uri'];
        $header = $request->header;
        $query = $request->get;

        if (isset($query['clientId'])) {
            $clientId = \trim($query['clientId']);
            $flag = 'qs';
        } elseif (isset($header['x-clientid'])) {
            $clientId = \trim($header['x-clientid']);
            $flag = 'header';
        } elseif (isset($header['x-socket-log-clientid'])) {
            $clientId = \trim($header['x-socket-log-clientid']);
            $flag = 'header';
        } else {
            $clientId = \trim($path, '/');
            $flag = 'path';
            if (str_contains($clientId, '/')) {
                $clientId = substr($clientId, strrpos($clientId, '/') - 1);
                $flag = 'path-fix';
            }
        }

        return [$clientId, $flag];
    }

    private function clientHandshake(Request $request, Response $response): void
    {
        [$clientId, $flag] = $this->resolveClientId($request);

        if (empty($clientId) || \strlen($clientId) > 128) {
            $response->status(400, 'Bad Request');
            $response->end();
            return;
        }
        if (!$this->checkClientIsAllow($clientId)) {
            $response->status(401, 'Unauthorized');
            $response->end();
            $this->logger->warning("client refuse: {$clientId}[#{$request->fd}][{$flag}]");
            return;
        }
        // websocket握手连接算法验证
        $secWebSocketKey = $request->header['sec-websocket-key'];
        $patten = '#^[+/0-9A-Za-z]{21}[AQgw]==$#';
        if (0 === preg_match($patten, $secWebSocketKey) || 16 !== strlen(base64_decode($secWebSocketKey))) {
            $response->status(400, 'Bad Request');
            $response->end();
            return;
        }
        $key = base64_encode(
            sha1(
                $request->header['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
                true,
            )
        );

        $headers = [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Accept' => $key,
            'Sec-WebSocket-Version' => '13',
        ];

        if (isset($request->header['sec-websocket-protocol'])) {
            $headers['Sec-WebSocket-Protocol'] = $request->header['sec-websocket-protocol'];
        }

        foreach ($headers as $key => $val) {
            $response->header($key, $val);
        }

        $this->clientJoin($clientId, $request->fd);
        $response->status(101);
        $response->end();
    }

    private function onMessage(\Swoole\WebSocket\Server $server, Frame $frame): void
    {
        if (SWOOLE_WEBSOCKET_OPCODE_BINARY === $frame->opcode) {
            if (self::PING_CONTENT === substr($frame->data, 0, 3)) {
                $this->server->push(
                    $frame->fd,
                    self::PONG_CONTENT,
                    SWOOLE_WEBSOCKET_OPCODE_BINARY,
                    SWOOLE_WEBSOCKET_FLAG_FIN,
                );
            }
        }
    }

    private function clientJoin(string $clientId, int $fd): void
    {
        $this->broadcastMap[$clientId][$fd] = $fd;
        $this->clientIdMap[$fd]             = $clientId;
        $this->logger->info("client join: {$clientId}[#{$fd}]");
    }

    private function clientLeave(int $fd): void
    {
        $clientId = $this->clientIdMap[$fd] ?? null;
        if (empty($clientId)) {
            return;
        }
        unset($this->broadcastMap[$clientId][$fd]);
        unset($this->clientIdMap[$fd]);
        $this->logger->info("client leave: {$clientId}[#{$fd}]");
    }

    private function checkClientIsAllow(string $clientId): bool
    {
        if (empty($this->allowClient)) {
            return true;
        }

        return \array_reduce($this->allowClient, fn($carry, $item) => $carry || \fnmatch($item, $clientId), false);
    }

    private function onRequest(Request $request, Response $response): void
    {
        $path = $request->server['request_uri'];
        $method = $request->server['request_method'];
        $header = $request->header;

        $contentType = $header['content-type'] ?? '';
        $contentType = \explode(';', $contentType)[0];
        $contentType = \trim($contentType);

        [$clientId, $flag] = $this->resolveClientId($request);

        if ($method !== 'POST'
            || empty($contentType)
            || empty($clientId)
            || \strlen($clientId) > 128
            || !\in_array($contentType, $this->allowContentTypes, true)
        ) {
            $this->logger->warning("receive[#{$request->fd}] invalid request: " . ($path === $clientId ? $clientId : $path)) . " [{$flag}]";
            $response->status(426, 'Not Acceptable');
            $response->end();
            return;
        }

        if (!$this->checkClientIsAllow($clientId)) {
            $this->logger->warning("receive[#{$request->fd}] unauthorized request: {$path} [{$flag}]");
            $response->status(401, 'Unauthorized');
            $response->end();
            return;
        }

        $rawBody = $request->getContent();

        $isCompress   = 'application/x-compress' === $contentType || 'application/x-e2e-compress+json' === $contentType;
        $isEncryption = 'application/x-e2e-json' === $contentType || 'application/x-e2e-compress+json' === $contentType;

        if ($isCompress && !$isEncryption) {
            $message = zlib_decode($rawBody);
            $messageSize = sprintf('%s(compress: %s)', format_byte(strlen($message)), format_byte(strlen($rawBody)));
            $isCompress = false;
        } else {
            $message = $rawBody;
            $messageSize = format_byte(strlen($message));
        }

        $e2eId = $isEncryption ? ($header['x-e2e-id'] ?? null) : null;

        $response->status(200, 'OK');
        $response->end();

        $this->logger->info(
            \sprintf(
                'receive[#%s] message %s (c:%s,e:%s), broadcast to %s [%s], total %d.',
                $request->fd,
                $messageSize,
                $isCompress ? 'y' : 'n',
                $isEncryption ? 'y' : 'n',
                $clientId,
                $flag,
                \count($this->broadcastMap[$clientId] ?? []),
            )
        );

        $this->broadcast($clientId, $message, $isCompress, $isEncryption, $e2eId);
    }

    private function broadcast(string $clientId, string $message, bool $isCompress, bool $isEncryption, ?string $e2eId): void
    {
        $fds = $this->broadcastMap[$clientId] ?? [];
        if (empty($fds)) {
            return;
        }
        $total = \count($fds);
        $i = 0;

        $frameFlags = $isCompress
            ? SWOOLE_WEBSOCKET_FLAG_FIN
            : SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS;

        $opcode = $isEncryption ? WEBSOCKET_OPCODE_BINARY : WEBSOCKET_OPCODE_TEXT;

        if (WEBSOCKET_OPCODE_BINARY === $opcode) {
            $_f = 0;
            if ($isCompress) {
                $_f |= self::FLAG_COMPRESS;
            }
            if ($isEncryption) {
                $_f |= self::FLAG_ENCRYPTION;
            }
            if ($e2eId !== null) {
                $_f |= self::FLAG_USE_E2E_ID;
                $message = self::BIN_MSG_HEADER . pack('nC', $_f, strlen($e2eId)) . substr($e2eId, 0, 127) . $message;
            } else {
                $message = self::BIN_MSG_HEADER . pack('n', $_f) . $message;
            }
        }

        foreach ($fds as $fd) {
            $i++;
            $this->logger->debug("broadcast message to #{$fd}[{$i}/$total].");
            $this->server->push($fd, $message, $opcode, $frameFlags);
        }
    }

    public function run(): void
    {
        $this->server->start();
    }
}
