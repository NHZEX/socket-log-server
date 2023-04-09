<?php
declare(strict_types=1);

namespace SocketLog;

use Dotenv\Dotenv;
use Phar;
use Symfony\Component\Console\Application;
use function define;

define('IN_PHAR', boolval(Phar::running(false)));
define('RUNNING_ROOT', realpath(getcwd()));
define('APP_ROOT', IN_PHAR ? Phar::running() : realpath(getcwd()));
define('RUNTIME_DIR', RUNNING_ROOT . DIRECTORY_SEPARATOR . 'runtime');

define('BUILD_DATETIME', '@compile-datetime@');
define('BUILD_VERSION', '@app-version@');
define('VERSION_TITLE', IN_PHAR ? \sprintf('%s', BUILD_VERSION) : '0.0.0');

require __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (\is_file(__DIR__ . DIRECTORY_SEPARATOR . '.env')) {
    $dotenv = Dotenv::createMutable(__DIR__);
    $dotenv->load();

    Server::verifyEnv($dotenv);
}

$app = new Application('socket-log-server', VERSION_TITLE);
$command = new ServerCommand();
$app->add($command);
$app->setDefaultCommand($command->getName(), true);
$code = $app->run();

exit($code);
