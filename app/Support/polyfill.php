<?php

declare(strict_types=1);

/**
 * 扩展缺失时的兼容垫片
 *
 * Lolilota 的目标运行环境是普通虚拟主机 / 内网服务器，PHP 常常是最小化编译：
 * 没有 mbstring、没有 curl 甚至没有 openssl。这里在扩展缺失时补上等价实现，
 * 保证后端在这类环境下依然可用，而不是抛一个看不懂的 undefined function。
 *
 * 注意：垫片只覆盖本项目实际用到的少数函数。
 */

// ---- mbstring --------------------------------------------------------------

if (!function_exists('mb_strlen')) {
    /**
     * 无 mbstring 时退化为字节长度（ASCII 场景等价）
     */
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        return strlen($string);
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}

if (!function_exists('mb_strtolower')) {
    /**
     * 仅小写 ASCII；UTF-8 大小写在账号标识（邮箱/用户名）场景极少出现，
     * 这里显式说明以保证行为可预期。
     */
    function mb_strtolower(string $string, ?string $encoding = null): string
    {
        return strtolower($string);
    }
}

if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $string, ?string $encoding = null): string
    {
        return strtoupper($string);
    }
}

if (!function_exists('mb_internal_encoding')) {
    function mb_internal_encoding(?string $encoding = null): string|bool
    {
        return $encoding === null ? 'UTF-8' : true;
    }
}

// ---- 其他常见缺失项 --------------------------------------------------------

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return substr($haystack, -strlen($needle)) === $needle;
    }
}
