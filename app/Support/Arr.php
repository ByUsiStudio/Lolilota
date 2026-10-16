<?php

declare(strict_types=1);

namespace Lolilota\Support;

/**
 * 数组取值辅助
 */
final class Arr
{
    /**
     * 用点号路径从嵌套数组取值，例如 Arr::get($data, 'data.user.id')
     */
    public static function get(array $data, string $path, mixed $default = null): mixed
    {
        if ($path === '') {
            return $default;
        }

        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * 按候选键顺序取值，第一个存在且非 null 的返回
     *
     * @param list<string> $keys
     */
    public static function first(array $data, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            $value = self::get($data, $key, '__missing__');
            if ($value !== '__missing__' && $value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    /** 只保留指定键 */
    public static function only(array $data, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }
        return $out;
    }

    /** 去掉空值 */
    public static function compact(array $data): array
    {
        return array_filter($data, static fn ($v) => $v !== null && $v !== '');
    }
}
