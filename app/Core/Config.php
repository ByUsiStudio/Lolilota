<?php

declare(strict_types=1);

namespace Lolilota\Core;

/**
 * 全局配置容器
 *
 * 用法：
 *   Config::get('cdifit.base_url')
 *   Config::get('history.max_per_user', 200)
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    /** @param array<string, mixed> $items */
    public static function load(array $items): void
    {
        self::$items = $items;
    }

    /**
     * 用点号路径读取配置，支持默认值
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function has(string $key): bool
    {
        return self::get($key, '__missing__') !== '__missing__';
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return self::$items;
    }
}
