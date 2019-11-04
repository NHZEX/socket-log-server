<?php

use App\LogServer;
use function Swoole\Coroutine\run;

require __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

run(function () {
    $server = new LogServer();
    $server->start();
});
