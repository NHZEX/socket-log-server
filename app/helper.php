<?php

define('IN_PHAR', boolval(Phar::running(false)));
define('APP_VERSION', IN_PHAR ? '@phar-version@' : 'dev');

define('DS', DIRECTORY_SEPARATOR);
define('APP_PATH', dirname(__DIR__) . DS);
define('RUNTIME_PATH', APP_PATH . 'runtime' . DS);