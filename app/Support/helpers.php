<?php

declare(strict_types=1);

use Lolilota\Core\Config;

/**
 * 全局辅助函数
 *
 * 只放真正需要全局可用的少量函数，其余逻辑都放在类里。
 */

if (!function_exists('lolilota_config')) {
    /**
     * 读取配置：lolilota_config('cdifit.base_url', '默认值')
     */
    function lolilota_config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('lolilota_e')) {
    /**
     * HTML 转义，模板里输出变量时统一使用
     */
    function lolilota_e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('lolilota_json')) {
    /**
     * 安全的 JSON 编码（用于内联到 <script> 里）
     */
    function lolilota_json(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );

        return $json === false ? 'null' : $json;
    }
}

if (!function_exists('lolilota_is_cli')) {
    function lolilota_is_cli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }
}
