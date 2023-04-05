<?php
declare(strict_types=1);

namespace SocketLog;

use Phar;
use Symfony\Component\Console\Application;
use function define;

define('IN_PHAR', boolval(Phar::running(false)));
define('RUNNING_ROOT', realpath(getcwd()));
define('APP_ROOT', IN_PHAR ? Phar::running() : realpath(getcwd()));
define('RUNTIME_DIR', RUNNING_ROOT . DIRECTORY_SEPARATOR . 'runtime');

require __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$app = new Application('socket-log-server', '1.0.0');
$command = new ServerCommand();
$app->add($command);
$app->setDefaultCommand($command->getName(), true);
$code = $app->run();

exit($code);
