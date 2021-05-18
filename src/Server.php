<?php

namespace App;

use App\Channel\Client as ChannelClient;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;
use function func\call_wrap;
use function func\format_byte;
use function func\log;
use function sprintf;
use function str_starts_with;
use function zlib_decode;

class Server
{
    /**
     * @var Worker
     */
    protected $http;

    protected $httpAddress = '0.0.0.0:1116';
    protected $wsAddress = '0.0.0.0:1229';

    public function __construct()
    {

        $this->http = new Worker("http://{$this->httpAddress}");
        $this->http->name = 'logx';
        $this->http->count = 1;
        $this->http->onMessage = call_wrap([$this, 'onRequest']);
        $this->http->onWorkerStart = call_wrap([$this, 'onWorkerStart']);

        PushServer::init($this->wsAddress);
    }

    public function onWorkerStart(Worker $httpWorker)
    {
        ChannelClient::connect('event');
        Worker::safeEcho(sprintf('#%s EventLoop: %s%s', $httpWorker->id, Worker::$eventLoopClass, PHP_EOL));
    }

    public function onRequest(TcpConnection $connection, Request $request)
    {
        if ($request->method() !== 'POST'
            || empty($contentType = $request->header('content-type', ''))
            || !(
                str_starts_with($contentType, 'application/json')
                || str_starts_with($contentType, 'application/x-compress')
            )
        ) {
            log("receive[#{$connection->id}] invalid request: {$request->uri()}");
            $response = new Response(426);
            $connection->send($response);
            return;
        }

        if ($contentType === 'application/x-compress') {
            $message = zlib_decode($request->rawBody());
            $messageSize = sprintf('%s (compress: %s)', format_byte(strlen($message)), format_byte(strlen($request->rawBody())));
        } else {
            $message = $request->rawBody();
            $messageSize = format_byte(strlen($message));
        }

        $response = new Response(200);
        $connection->send($response);

        log(sprintf(
            'receive[%s] message[#%s]: send %s to %s',
            $connection->getRemoteIp(),
            $connection->id,
            $messageSize,
            $request->uri()
        ));

        ChannelClient::publish('broadcast', [$message, $request->uri(), $connection->id]);
    }
}
