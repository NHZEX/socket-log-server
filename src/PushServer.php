<?php

namespace App;

use App\Channel\ChannelServer;
use App\Channel\Client as ChannelClient;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\WebsocketEx;
use Workerman\Worker;
use function count;
use function func\call_wrap;
use function func\log;
use function strpos;

class PushServer
{
    /**
     * @var Worker
     */
    protected $websocket;

    protected $channelServer;

    protected $broadcastBind = [];
    protected $wsClient = [];

    public static function init($wsAddress): PushServer
    {
        return new PushServer($wsAddress);
    }

    public function __construct($wsAddress)
    {
        WebsocketEx::$compression = true;
        $this->wsAddress = $wsAddress;

        $this->websocket = new Worker("websocketEx://{$this->wsAddress}");
        $this->websocket->name = 'push';
        $this->websocket->count = 1;
        $this->websocket->onWorkerStart = function () {
            ChannelClient::connect('event');
            ChannelClient::on('broadcast', function ($data) {
                $this->broadcast($data[0], $data[1], $data[2]);
            });
        };
        $this->websocket->onWebSocketConnect = call_wrap([$this, 'onHandshake']);
        $this->websocket->onConnect = call_wrap([$this, 'onWsConnect']);
        $this->websocket->onMessage = call_wrap([$this, 'onWsMessage']);
        $this->websocket->onClose = call_wrap([$this, 'onWsClose']);

        $this->channelServer = new ChannelServer('event');
    }

    public function onHandshake(TcpConnection $connection, string $http_header)
    {
        $request_uri = $_SERVER['REQUEST_URI'];
        $this->broadcastBind[$request_uri][$connection->id] = $connection->id;
        $this->wsClient[$connection->id] = $request_uri;
        log("client join：{$request_uri}[{$connection->id}]");

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

    public static function publish ($data)
    {
        ChannelClient::publish('broadcast', $data);
    }

    protected function broadcast (string $data, string $uri, int $cid)
    {
        $clients = $this->broadcastBind[$uri] ?? [];
        log("broadcast message[#{$cid}] to {$uri}, count: " . count($clients));
        foreach ($clients as $id) {
            /** @var TcpConnection $conn */
            $conn = $this->websocket->connections[$id];
            $conn->send($data);
        }
    }
}
