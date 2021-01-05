<?php

namespace func;

use Closure;
use Workerman\Worker;
use function date;
use function strlen;
use function strncmp;

function call_wrap(callable $call)
{
    return Closure::fromCallable($call);
}

function str_starts_with(string $haystack, string $needle): bool
{
    return (strlen($haystack) !== 0 && $haystack[0] === $needle[0])
        ? strncmp($haystack, $needle, strlen($needle)) === 0
        : false;
}

function log(string $msg)
{
    $data = date('Y-m-d H:i:s');
    Worker::safeEcho("[{$data}] $msg\n");
}