<?php

namespace Workerman\Protocols;

use Exception;
use Throwable;
use Workerman\Connection\ConnectionInterface;
use Workerman\Connection\TcpConnection;
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

    /**
     * Websocket handshake.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function dealHandshake($buffer, TcpConnection $connection)
    {
        // HTTP protocol.
        if (0 === \strpos($buffer, 'GET')) {
            // Find \r\n\r\n.
            $heder_end_pos = \strpos($buffer, "\r\n\r\n");
            if (!$heder_end_pos) {
                return 0;
            }
            $header_length = $heder_end_pos + 4;

            // Get Sec-WebSocket-Key.
            $Sec_WebSocket_Key = '';
            if (\preg_match("/Sec-WebSocket-Key: *(.*?)\r\n/i", $buffer, $match)) {
                $Sec_WebSocket_Key = $match[1];
            } else {
                $connection->close("HTTP/1.1 200 WebSocket\r\nServer: workerman/".Worker::VERSION."\r\n\r\n<div style=\"text-align:center\"><h1>WebSocket</h1><hr>workerman/".Worker::VERSION."</div>",
                    true);
                return 0;
            }
            // Calculation websocket key.
            $new_key = \base64_encode(\sha1($Sec_WebSocket_Key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
            // Handshake response data.
            $handshake_message = "HTTP/1.1 101 Switching Protocols\r\n"
                ."Upgrade: websocket\r\n"
                ."Sec-WebSocket-Version: 13\r\n"
                ."Connection: Upgrade\r\n"
                ."Sec-WebSocket-Accept: " . $new_key . "\r\n";

            // Websocket data buffer.
            $connection->websocketDataBuffer = '';
            // Current websocket frame length.
            $connection->websocketCurrentFrameLength = 0;
            // Current websocket frame data.
            $connection->websocketCurrentFrameBuffer = '';
            // Consume handshake data.
            $connection->consumeRecvBuffer($header_length);

            // blob or arraybuffer
            if (empty($connection->websocketType)) {
                $connection->websocketType = static::BINARY_TYPE_BLOB;
            }

            $has_server_header = false;

            static::parseHttpHeader($buffer);

            static::onHandshake($connection, $buffer);

            if (isset($connection->headers)) {
                if (\is_array($connection->headers))  {
                    foreach ($connection->headers as $header) {
                        if (\strpos($header, 'Server:') === 0) {
                            $has_server_header = true;
                        }
                        $handshake_message .= "$header\r\n";
                    }
                } else {
                    $handshake_message .= "$connection->headers\r\n";
                }
            }
            if (!$has_server_header) {
                $handshake_message .= "Server: workerman/".Worker::VERSION."\r\n";
            }

            $handshake_message .= "\r\n";
            // Send handshake response.
            $connection->send($handshake_message, true);
            // Mark handshake complete..
            $connection->websocketHandshake = true;

            // Try to emit onWebSocketConnect callback.
            $on_websocket_connect = isset($connection->onWebSocketConnect) ? $connection->onWebSocketConnect :
                (isset($connection->worker->onWebSocketConnect) ? $connection->worker->onWebSocketConnect : false);
            if ($on_websocket_connect) {
                try {
                    \call_user_func($on_websocket_connect, $connection, $buffer);
                } catch (\Throwable $e) {
                    Worker::stopAll(250, $e);
                }
            }
            $_GET = $_SERVER = $_SESSION = $_COOKIE = [];

            // There are data waiting to be sent.
            if (!empty($connection->tmpWebsocketData)) {
                $connection->send($connection->tmpWebsocketData, true);
                $connection->tmpWebsocketData = '';
            }
            if (\strlen($buffer) > $header_length) {
                return static::input(\substr($buffer, $header_length), $connection);
            }
            return 0;
        } // Is flash policy-file-request.
        elseif (0 === \strpos($buffer, '<polic')) {
            $policy_xml = '<?xml version="1.0"?><cross-domain-policy><site-control permitted-cross-domain-policies="all"/><allow-access-from domain="*" to-ports="*"/></cross-domain-policy>' . "\0";
            $connection->send($policy_xml, true);
            $connection->consumeRecvBuffer(\strlen($buffer));
            return 0;
        }
        // Bad websocket handshake request.
        $connection->close(
            "HTTP/1.1 200 WebSocket\r\nServer: workerman/".Worker::VERSION."\r\n\r\n<div style=\"text-align:center\"><h1>WebSocket</h1><hr>workerman/".Worker::VERSION."</div>",            true);
        return 0;
    }

    public static function onHandshake(TcpConnection $connection, string $httpHeader)
    {
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
