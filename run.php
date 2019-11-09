<?php

use App\LogServer;
use function Swoole\Coroutine\run;

require __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

run(function () {
    echo 'socket-log server v-' . APP_VERSION . ' start up' . PHP_EOL;

    $server = new LogServer();
    $server->start();
});
