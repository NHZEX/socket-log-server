<?php
declare(strict_types=1);

namespace SocketLog;

function parse_str_ip_and_port(string $str, int $default_port = 0): ?array
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
        if (is_valid_linux_path($str)) {
            return [$str, 0, true];
        }

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
    return [$ip, $port ?: $default_port];
}

function test_address_and_port(string $val): bool
{
    if ('' === $val) {
        return true;
    }
    return parse_str_ip_and_port($val) !== null;
}

function is_valid_linux_path(string $path): bool {
    // 正则表达式匹配合法的Linux路径
    $pattern = '%^(/[^/\0]+)+/?$%';

    // 检查空路径
    if (empty($path)) {
        return false;
    }

    // 检查路径是否匹配正则表达式
    if (!preg_match($pattern, $path)) {
        return false;
    }

    // 检查路径中是否包含非法字符
    if (strpos($path, "\0") !== false) {
        return false;
    }

    return true;
}
