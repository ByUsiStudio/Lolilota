<?php

declare(strict_types=1);

namespace Lolilota\Support;

use Lolilota\Core\Config;

/**
 * HTTP 请求 / 响应辅助
 */
final class Http
{
    /** 读取 JSON 请求体，非法 JSON 抛 400 */
    public static function json(): array
    {
        $raw = self::rawBody();
        if ($raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            Json::error('invalid_json', '请求体不是合法的 JSON', 400);
        }

        return $data;
    }

    /** 原始请求体（兼容非 PHP 内置服务器） */
    public static function rawBody(): string
    {
        $raw = file_get_contents('php://input');
        return $raw === false ? '' : $raw;
    }

    /** 合并查询串与 JSON 体，供简单接口取值 */
    public static function input(): array
    {
        $input = array_merge($_GET, $_POST);
        if (self::method() !== 'GET') {
            $input = array_merge($input, self::json());
        }
        return $input;
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** 去掉查询串与结尾斜杠的路径 */
    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        return '/' . trim($path, '/');
    }

    /** 取 Cookie */
    public static function cookie(string $name): ?string
    {
        $value = $_COOKIE[$name] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** 取 Bearer 令牌（扩展端用） */
    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if (!is_string($header) || $header === '') {
            // 某些 CGI 环境需要从 apache_request_headers 取
            if (function_exists('apache_request_headers')) {
                $headers = apache_request_headers();
                foreach ($headers as $name => $value) {
                    if (strcasecmp($name, 'Authorization') === 0) {
                        $header = $value;
                        break;
                    }
                }
            }
        }

        if (is_string($header) && preg_match('/^Bearer\s+(.+)$/i', trim($header), $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    public static function isSecure(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        return is_string($proto) && strtolower($proto) === 'https';
    }

    /** 当前站点根地址，例如 https://example.com */
    public static function origin(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return (self::isSecure() ? 'https://' : 'http://') . $host;
    }

    /** 完整请求 URL */
    public static function currentUrl(): string
    {
        return self::origin() . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    /** 只允许站内跳转，防止开放重定向 */
    public static function safeRedirect(string $url, string $fallback = '/'): string
    {
        if ($url === '') {
            return $fallback;
        }

        // 相对路径
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        $target = parse_url($url);
        $self = parse_url(self::origin());

        if (is_array($target) && is_array($self)
            && ($target['host'] ?? '') === ($self['host'] ?? '')
            && ($target['scheme'] ?? '') === ($self['scheme'] ?? '')
        ) {
            return $url;
        }

        return $fallback;
    }

    /** 客户端 IP（仅用于日志） */
    public static function ip(): string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($forwarded) && $forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if ($first !== '') {
                return $first;
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    /** 处理跨域：同源无需头，扩展 / 分离部署需要 */
    public static function applyCors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (!is_string($origin) || $origin === '') {
            return;
        }

        $allowed = (array) Config::get('app.origins', []);

        // 浏览器扩展来源以 chrome-extension:// / moz-extension:// 开头
        $isExtension = str_starts_with($origin, 'chrome-extension://')
            || str_starts_with($origin, 'moz-extension://')
            || str_starts_with($origin, 'safari-web-extension://');

        $sameOrigin = $origin === self::origin();

        if (!$sameOrigin && !$isExtension && !in_array($origin, $allowed, true)) {
            return;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Max-Age: 86400');
    }
}
