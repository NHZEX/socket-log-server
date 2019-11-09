<?php
namespace App;

use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Runtime;

class LogServer
{
    protected $server;

    protected $wsClient = [];

    public function __construct()
    {
        $this->server = new Server('0.0.0.0', 1229, false, false);
        $this->initialize();
    }

    protected function initialize()
    {
        Runtime::enableCoroutine(true);
        $this->server->set([
            'log_file' => RUNTIME_PATH . 'swoole.log',
            'pid_file' => RUNTIME_PATH . 'swoole.pid',
            'enable_coroutine' => true,
            'send_yield'       => true,
            'websocket_compression' => true,
        ]);
        $this->server->handle('/', function (Request $request, Response $response) {
            if ('websocket' === ($request->header['upgrade'] ?? '')) {
                $this->handleWebsocket($request, $response);
            } elseif ('POST' === $request->server['request_method']) {
                $this->handleRequest($request, $response);
            } else {
                $this->log("ws[{$request->fd}#{$request->server['request_uri']}] Invalid protocol");
                $response->setStatusCode(426);
                $response->end();
            }
        });
    }

    public function start()
    {
        $this->server->start();
    }

    protected function broadcast($data, $uri)
    {
        $clients = $this->wsClient[$uri] ?? [];
        $this->log("broadcast message to {$uri}, count: " . count($clients));
        foreach ($clients as $ws) {
            /** @var Response $ws */
            $ws->push($data);
        }
    }

    protected function handleWebsocket(Request $req, Response $ws)
    {
        if (!$ws->upgrade()) {
            $this->log("ws[{$req->fd}#{$req->server['request_uri']}] upgrade error : " . swoole_last_error());
            return;
        }
        $request_uri = $req->server['request_uri'];
        $this->wsClient[$request_uri][$req->fd] = $ws;
        $this->log("ws client join：{$request_uri}[{$req->fd}]");
        while (true) {
            $frame = $ws->recv();
            if ($frame === false || $frame == '') {
                $this->log("ws[{$req->fd}#{$req->server['request_uri']}] recv error : " . swoole_last_error());
                break;
            }
        }
        $this->log("ws client disconnect：{$request_uri}[{$req->fd}]");
        unset($this->wsClient[$request_uri][$req->fd]);
    }

    protected function handleRequest(Request $req, Response $res)
    {
        if(!isset($req->header['content-type']) || (
                false === strpos($req->header['content-type'], 'application/json')
                && false === strpos($req->header['content-type'], 'application/json')
            )
        ) {
            $this->log("receive[#{$req->fd}] invalid request: {$req->server['request_uri']}");
            $res->setStatusCode(426);
            $res->end();
            return;
        }
        $message = $req->rawContent();
        $res->end();
        $len = strlen($message);
        $this->log("receive[#{$req->fd}] message: send {$len} byte to {$req->server['request_uri']}");
        $this->broadcast($message, $req->server['request_uri']);
    }

    protected function log(string $msg)
    {
        $data = date('Y-m-d H:i:s');
        echo "[{$data}] $msg\n";
    }
}
