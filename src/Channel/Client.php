<?php

namespace App\Channel;

use Channel\Client as ChannelClient;
use Exception;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Lib\Timer;
use Workerman\Protocols\Frame;

class Client extends ChannelClient
{
    protected static $_remoteUnixName;

    public static function connect($ip = 'default', $port = 2206)
    {
        if(!self::$_remoteConnection)
        {
            self::$_remoteUnixName = $ip;
            self::$_isWorkermanEnv = true;
            // For workerman environment.
            self::$_remoteConnection = new AsyncTcpConnection(sprintf('unix://%s/%s.sock', RUNNING_TMP_ID, $ip));
            self::$_remoteConnection->protocol = Frame::class;
            self::$_remoteConnection->onClose = '\App\Channel\Client::onRemoteClose';
            self::$_remoteConnection->onConnect = '\App\Channel\Client::onRemoteConnect';
            self::$_remoteConnection->onMessage = '\App\Channel\Client::onRemoteMessage';
            self::$_remoteConnection->connect();

            if (empty(self::$_pingTimer)) {
                self::$_pingTimer = Timer::add(self::$pingInterval, '\App\Channel\Client::ping');
            }
        }
    }

    /**
     * onRemoteClose.
     * @return void
     */
    public static function onRemoteClose()
    {
        echo "Waring channel connection closed and try to reconnect\n";
        self::$_remoteConnection = null;
        self::clearTimer();
        self::$_reconnectTimer = Timer::add(1, '\App\Channel\Client::connect', array(self::$_remoteUnixName));
        if (self::$onClose) {
            call_user_func(Client::$onClose);
        }
    }

    /**
     * Send through workerman environment
     * @param $data
     * @throws Exception
     */
    protected static function send($data)
    {
        if (!self::$_isWorkermanEnv) {
            throw new Exception("Channel\\Client not support {$data['type']} method when it is not in the workerman environment.");
        }
        static::connect(self::$_remoteUnixName);
        self::$_remoteConnection->send(serialize($data));
    }

    /**
     * Send from any environment
     * @param $data
     * @throws Exception
     */
    protected static function sendAnyway($data)
    {
        static::connect(self::$_remoteUnixName);
        $body = serialize($data);
        if (self::$_isWorkermanEnv) {
            self::$_remoteConnection->send($body);
        } else {
            throw new Exception('unsupported environment');
        }
    }
}
