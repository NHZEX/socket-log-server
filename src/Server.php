<?php

namespace App;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Protocols\WebsocketEx;
use Workerman\Worker;
use function func\call_wrap;
use function func\log;
use function func\str_starts_with;
use function sprintf;

class Server
{
    /**
     * @var Worker
     */
    protected $http;

    /**
     * @var Worker
     */
    protected $websocket;

    protected $broadcastBind = [];
    protected $wsClient = [];

    protected $httpAddress = '0.0.0.0:1116';
    protected $wsAddress = '0.0.0.0:1229';

    public function __construct()
    {
        $this->http = new Worker("http://{$this->httpAddress}");
        $this->http->name = 'logx';
        $this->http->count = 1;
        $this->http->onMessage = call_wrap([$this, 'onRequest']);
        $this->http->onWorkerStart = call_wrap([$this, 'onWorkerStart']);
    }

    public function onWorkerStart(Worker $httpWorker)
    {
        WebsocketEx::$compression = true;

        $this->websocket = new Worker("websocketEx://{$this->wsAddress}");
        $this->websocket->onWebSocketConnect = call_wrap([$this, 'onHandshake']);
        $this->websocket->onConnect = call_wrap([$this, 'onWsConnect']);
        $this->websocket->onMessage = call_wrap([$this, 'onWsMessage']);
        $this->websocket->onClose = call_wrap([$this, 'onWsClose']);

        $this->websocket->listen();

        Worker::safeEcho(sprintf('#%s EventLoop: %s%s', $httpWorker->id, Worker::$eventLoopClass, PHP_EOL));
        Worker::safeEcho(sprintf(
            '#%s Websocket server(ws://%s) listen success%s',
            $httpWorker->id,
            $this->wsAddress,
            PHP_EOL
        ));
    }

    public function onRequest(TcpConnection $connection, Request $request)
    {
        if ($request->method() !== 'POST'
            || !str_starts_with($request->header('content-type', ''), 'application/json')
        ) {
            log("receive[#{$connection->id}] invalid request: {$request->uri()}");
            $response = new Response(426);
            $connection->send($response);
            return;
        }

        $message = $request->rawBody();

        $response = new Response(200);
        $connection->send($response);

        log(sprintf('receive[#%s] message: send %s byte to %s', $connection->id, strlen($message), $request->uri()));
        $this->broadcast($message, $request->uri());
    }

    public function onHandshake(TcpConnection $connection, string $http_header)
    {
        $request_uri = $_SERVER['REQUEST_URI'];
        $this->broadcastBind[$request_uri][$connection->id] = $connection->id;
        $this->wsClient[$connection->id] = $request_uri;
        log("ws client join：{$request_uri}[{$connection->id}]");

        /** @var WebsocketEx $protocol */
        $protocol = $connection->protocol;

        if ($protocol::$compression
            && strpos($_SERVER['HTTP_SEC_WEBSOCKET_EXTENSIONS'] ?? '', 'permessage-deflate') !== false
        ) {
            $connection->headers = [
                'Sec-WebSocket-Extensions: permessage-deflate; client_no_context_takeover; server_no_context_takeover'
            ];
        }

    }

    public function onWsConnect (TcpConnection $connection)
    {
    }

    public function onWsMessage (TcpConnection $connection, string $data) {
    }

    public function onWsClose (TcpConnection $connection) {
        $request_uri = $this->wsClient[$connection->id] ?? '<undefined>';
        unset($this->broadcastBind[$request_uri][$connection->id]);
        unset($this->wsClient[$connection->id]);
        log("ws client disconnect：{$request_uri}[{$connection->id}]");
    }

    protected function broadcast (string $data, string $uri)
    {
        $clients = $this->broadcastBind[$uri] ?? [];
        log("broadcast message to {$uri}, count: " . count($clients));
        foreach ($clients as $id) {
            /** @var TcpConnection $conn */
            $conn = $this->websocket->connections[$id];
            $conn->send($data);
        }
    }
}
