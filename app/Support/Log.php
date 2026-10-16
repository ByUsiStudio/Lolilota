<?php

declare(strict_types=1);

namespace Lolilota\Support;

use Lolilota\Core\Config;

/**
 * 极简文件日志
 *
 * - 应用日志： error_log() 走 ini 配置的 error_log
 * - Cdifit 联调日志：单独文件，用于核对文档字段
 */
final class Log
{
    /** 记录一条应用日志 */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warn(string $message, array $context = []): void
    {
        self::write('WARN', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /** 始终记录（不受 debug 开关影响） */
    private static function write(string $level, string $message, array $context): void
    {
        $line = sprintf('[%s] %s %s', date('Y-m-d H:i:s'), $level, $message);

        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $line .= ' ' . ($json === false ? '[context encode failed]' : $json);
        }

        error_log($line);
    }

    /**
     * Cdifit 请求/响应追踪
     *
     * 由 CDIFIT_TRACE=true 打开，用于把真实请求与响应原文写盘，
     * 便于对照 docs.cdifit.com/zh 修正字段映射。
     */
    public static function cdifit(string $stage, array $payload): void
    {
        if (!(bool) Config::get('log.cdifit_trace', false)) {
            return;
        }

        $file = (string) Config::get('log.cdifit_file');
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // 抹掉明显的敏感字段
        foreach (['password', 'access_token', 'refresh_token', 'token', 'code'] as $secret) {
            if (isset($payload[$secret])) {
                $payload[$secret] = '***';
            }
        }

        $line = sprintf(
            "[%s] %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($stage),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
        );

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
