<?php

namespace func;

use Closure;
use Workerman\Worker;
use function date;

function call_wrap(callable $call)
{
    return Closure::fromCallable($call);
}

/**
 * @param int $byte
 * @param int $dec
 * @return string
 */
function format_byte (int $byte, int $dec = 2): string
{
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB']; //
    $count = count($units) - 1;
    $pos  = 0;

    while ($byte >= 1024 && $pos < $count) {
        $byte /= 1024;
        $pos++;
    }

    $result = sprintf('%.2f', round($byte, $dec));

    return "{$result}{$units[$pos]}";
}

function log(string $msg)
{
    $data = date('Y-m-d H:i:s');
    Worker::safeEcho("[{$data}] $msg\n");
}