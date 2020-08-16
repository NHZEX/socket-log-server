<?php

namespace Workerman\Protocols;

use Exception;
use Throwable;
use Workerman\Connection\ConnectionInterface;
use Workerman\Worker;
use function call_user_func;
use function chr;
use function gettype;
use function pack;
use function strlen;
use const WORKERMAN_SEND_FAIL;

class WebsocketEx extends Websocket
{
    /** @var bool */
    public static $compression = false;

    public static function encode($buffer, ConnectionInterface $connection)
    {
        if (!is_scalar($buffer)) {
            throw new Exception("You can't send(" . gettype($buffer) . ") to client, you need to convert it to a string. ");
        }
        $len = strlen($buffer);
        if (empty($connection->websocketType)) {
            $connection->websocketType = static::BINARY_TYPE_BLOB;
        }

        $first_byte = $connection->websocketType;

        /** @var WebsocketEx $protocol */
        $protocol = $connection->protocol;

        if ($protocol::$compression && strlen($buffer) > 16) {
            if (is_string($first_byte)) {
                $first_byte = ord($first_byte);
            }

            $first_byte |= 0x40;
            $buffer = gzdeflate($buffer) . "\x00";
            $len = strlen($buffer);

            $first_byte = chr($first_byte);
        }

        if ($len <= 125) {
            $encode_buffer = $first_byte . chr($len) . $buffer;
        } else {
            if ($len <= 65535) {
                $encode_buffer = $first_byte . chr(126) . pack("n", $len) . $buffer;
            } else {
                $encode_buffer = $first_byte . chr(127) . pack("xxxxN", $len) . $buffer;
            }
        }

        // Handshake not completed so temporary buffer websocket data waiting for send.
        if (empty($connection->websocketHandshake)) {
            if (empty($connection->tmpWebsocketData)) {
                $connection->tmpWebsocketData = '';
            }
            // If buffer has already full then discard the current package.
            if (strlen($connection->tmpWebsocketData) > $connection->maxSendBufferSize) {
                if ($connection->onError) {
                    try {
                        call_user_func($connection->onError, $connection, WORKERMAN_SEND_FAIL, 'send buffer full and drop package');
                    } catch (Throwable $e) {
                        Worker::log($e);
                        exit(250);
                    }
                }
                return '';
            }
            $connection->tmpWebsocketData .= $encode_buffer;
            // Check buffer is full.
            if ($connection->maxSendBufferSize <= strlen($connection->tmpWebsocketData)) {
                if ($connection->onBufferFull) {
                    try {
                        call_user_func($connection->onBufferFull, $connection);
                    } catch (Throwable $e) {
                        Worker::log($e);
                        exit(250);
                    }
                }
            }

            // Return empty string.
            return '';
        }

        return $encode_buffer;
    }

}
