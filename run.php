#!/usr/bin/env php
<?php

namespace SocketLog;

use App\Server;
use Phar;
use Workerman\Worker;
use function basename;
use function define;
use function file_exists;
use function getenv;
use function is_writable;
use function mkdir;
use function sprintf;
use function str_replace;
use const IN_PHAR;
use const RUNNING_ROOT;

require_once __DIR__ . '/vendor/autoload.php';

const APP_VERSION = '@app-version@';
const COMPILE_DATETIME = '@compile-datetime@';

define('IN_PHAR', boolval(Phar::running(false)));
define('RUNNING_ROOT', realpath(getcwd()));
define('APP_ROOT', IN_PHAR ? Phar::running() : realpath(getcwd()));

$filename = IN_PHAR ? basename(APP_ROOT) : basename(__FILE__);
$unique_prefix = str_replace('/', '_', RUNNING_ROOT . '_' . $filename);

$run_dir = getenv('XDG_RUNTIME_DIR') ?: '/run';
$run_dir = is_writable($run_dir) ? $run_dir : '/tmp';
define('RUNNING_TMP_DIR', "{$run_dir}/wm{$unique_prefix}");

if (!file_exists(RUNNING_TMP_DIR)) {
    mkdir(RUNNING_TMP_DIR, 0755);
}

Worker::$pidFile = RUNNING_TMP_DIR . '/run.pid';
Worker::$logFile = RUNNING_ROOT . '/workerman.log';

echo sprintf('App version: %s, Compile datetime: %s %s', APP_VERSION, COMPILE_DATETIME, PHP_EOL);

new Server();

Worker::runAll();