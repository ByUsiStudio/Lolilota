<?php

declare(strict_types=1);

namespace Lolilota\Support;

use Lolilota\Core\Config;

/**
 * 统一 JSON 响应
 *
 * 成功： { "ok": true,  "data": <mixed> }
 * 失败： { "ok": false, "error": { "code": string, "message": string, "details": mixed } }
 *
 * 所有接口都走这里，前端 api.js 依赖这个形状。
 */
final class Json
{
    public static function ok(mixed $data = null, int $status = 200): never
    {
        self::send(['ok' => true, 'data' => $data], $status);
    }

    public static function error(
        string $code,
        string $message,
        int $status = 400,
        mixed $details = null
    ): never {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }

        self::send(['ok' => false, 'error' => $error], $status);
    }

    /** @param array<string, mixed> $payload */
    public static function send(array $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ((bool) Config::get('app.debug', false)) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($payload, $flags);

        if ($json === false) {
            // 兜底：编码失败也要返回合法 JSON
            http_response_code(500);
            $json = '{"ok":false,"error":{"code":"encode_failed","message":"响应编码失败"}}';
        }

        echo $json;
        exit;
    }

    /** 页面跳转（OAuth 回调等场景） */
    public static function redirect(string $url, int $status = 302): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Location: ' . $url);
        }
        exit;
    }
}
