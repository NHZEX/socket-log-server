<?php
declare(strict_types=1);

namespace SocketLog;

use Psr\Log\LoggerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;
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

    private array                    $allowContentTypes = [
        'application/json',
        'application/x-compress',
    ];

    public function __construct(
        protected LoggerInterface $logger,
    ) {
        $this->create();
    }

    protected function create(): void
    {
        $listen = \explode(':', $this->listen);
        $server = new \Swoole\WebSocket\Server($listen[0] ?: '127.0.0.1', (int) ($listen[1] ?? 1116), SWOOLE_BASE);

        $this->logger->info(\sprintf(
            'listen: %s://%s',
            'http',
            $this->listen,
        ));
        $this->logger->info(\sprintf(
            'listen: %s://%s',
            'ws',
            $this->listen,
        ));

        $server->set([
            'worker_num'               => 1,
            'daemonize'                => false,
            'heartbeat_check_interval' => 60,
            'heartbeat_idle_time'      => 300,
            'pid_file'                 => RUNTIME_DIR . '/server.pid',
            'http_compression'         => true,
            'websocket_compression'    => true,
        ]);
        $listen = \explode(':', $this->listenWS);
        $server->addlistener($listen[0] ?: '127.0.0.1', (int) ($listen[1] ?? 1229), $server->mode);
        $this->logger->info(\sprintf(
            'listen: %s://%s',
            'ws',
            $this->listenWS,
        ));

        $this->server = $server;

        $this->initEvent();
    }

    protected function initEvent()
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

        $this->server->on('message', function (\Swoole\WebSocket\Server $server, \Swoole\WebSocket\Frame $frame) {
            $this->server->push(
                $frame->fd,
                'pong: ' . \substr($frame->data, 0, 32) ?: 'null',
                WEBSOCKET_OPCODE_TEXT,
                SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS,
            );
        });

        $this->server->on('disconnect', function (\Swoole\WebSocket\Server $server, int $fd) {
            $this->clientLeave($fd);
        });

        $this->server->on('close', function (\Swoole\WebSocket\Server $server, int $fd) {
            $this->clientLeave($fd);
        });
    }

    private function clientHandshake(Request $request, Response $response): void
    {
        $path = $request->server['request_uri'];

        $clientId = \trim($path, '/');

        if (empty($clientId) || \strlen($clientId) > 32) {
            $response->status(400, 'Bad Request');
            $response->end();
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

    private function onRequest(Request $request, Response $response): void
    {
        $path = $request->server['request_uri'];
        $method = $request->server['request_method'];
        $header = $request->header;

        $contentType = $header['content-type'] ?? '';
        $contentType = \explode(';', $contentType)[0];
        $contentType = \trim($contentType);

        $clientId = \trim($path, '/');

        if ($method !== 'POST'
            || empty($contentType)
            || empty($clientId)
            || \strlen($clientId) > 32
            || !\in_array($contentType, $this->allowContentTypes, true)
        ) {
            $this->logger->warning("receive[#{$request->fd}] invalid request: {$path}");
            $response->status(426, 'Not Acceptable');
            $response->end();
            return;
        }

        $rawBody = $request->getContent();

        if ('application/x-compress' === $contentType) {
            $message = zlib_decode($rawBody);
            $messageSize = sprintf('%s(compress: %s)', format_byte(strlen($message)), format_byte(strlen($rawBody)));
        } else {
            $message = $rawBody;
            $messageSize = format_byte(strlen($message));
        }

        $response->status(200, 'OK');
        $response->end();

        $this->logger->info(
            \sprintf(
                'receive[#%s] message %s, broadcast to %s, total %d.',
                $request->fd,
                $messageSize,
                $clientId,
                \count($this->broadcastMap[$clientId] ?? []),
            )
        );

        $this->broadcast($clientId, $message);
    }

    private function broadcast(string $clientId, string $message): void
    {
        $fds = $this->broadcastMap[$clientId] ?? [];
        if (empty($fds)) {
            return;
        }
        $total = \count($fds);
        $i = 0;
        foreach ($fds as $fd) {
            $i++;
            $this->logger->debug("broadcast message to #{$fd}[{$i}/$total].");
            $this->server->push(
                $fd,
                $message,
                WEBSOCKET_OPCODE_TEXT,
                SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS,
            );
        }
    }

    public function run(): void
    {
        $this->server->start();
    }
}
