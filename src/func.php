<?php
declare(strict_types=1);

namespace SocketLog;

function parse_str_ip_and_port(string $str): ?array
{
    if (empty($str)) {
        return null;
    }
    // ipv6
    if (($pos = strrpos($str, ']')) !== false) {
        $ip = substr($str, 1, $pos - 1);
        $port = substr($str, $pos + 2);
    } elseif (($pos = \strrpos($str, ':')) !== false) {
        $ip = substr($str, 0, $pos);
        $port = substr($str, $pos + 1);
    } else {
        $ip = $str;
        $port = '';
    }

    if (!\filter_var($ip, FILTER_VALIDATE_IP)) {
        return null;
    }
    // 验证是有效的端口号范围
    if (
        $port
        && !\filter_var(
            $port,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 65535]]
        )
    ) {
        return null;
    }
    return [$ip, $port];
}

function test_address_and_port(string $val): bool
{
    if ('' === $val) {
        return true;
    }
    return parse_str_ip_and_port($val) !== null;
}
