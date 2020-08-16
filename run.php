#!/usr/bin/env php
<?php

namespace SocketLog;

use App\Server;
use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

new Server();

Worker::runAll();