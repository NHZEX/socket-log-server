#!/usr/bin/env php
<?php

namespace SocketLog;

use App\Server;
use Phar;
use Workerman\Worker;

define('IN_PHAR', boolval(Phar::running(false)));
define('RUNNING_ROOT', realpath(getcwd()));
define('APP_ROOT', IN_PHAR ? Phar::running() : realpath(getcwd()));

require_once __DIR__ . '/vendor/autoload.php';

$unique_prefix = \str_replace('/', '_', __FILE__);
Worker::$pidFile = "/tmp/w_{$unique_prefix}.pid";
Worker::$logFile = RUNNING_ROOT . '/workerman.log';

new Server();

Worker::runAll();