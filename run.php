#!/usr/bin/env php
<?php

namespace SocketLog;

use App\Server;
use Phar;
use Workerman\Worker;
use const IN_PHAR;
use const RUNNING_ROOT;

require_once __DIR__ . '/vendor/autoload.php';

define('IN_PHAR', boolval(Phar::running(false)));
define('RUNNING_ROOT', realpath(getcwd()));
define('APP_ROOT', IN_PHAR ? Phar::running() : realpath(getcwd()));

$unique_prefix = \str_replace('/', '_', __FILE__);
Worker::$pidFile = "/tmp/w_{$unique_prefix}.pid";
Worker::$logFile = RUNNING_ROOT . '/workerman.log';

new Server();

Worker::runAll();