<?php

declare(strict_types=1);

namespace Lolilota\Support;

use Lolilota\Core\Config;

/**
 * 极简文件日志
 *
 * 为什么自己写文件而不是用 error_log()：
 * PHP 的 error_log() 会按系统 ANSI 代码页落盘，在中文 Windows（GBK）上
 * 中文日志会变成乱码。这里统一以 UTF-8 追加写入，跨平台都是可读的。
 *
 * 两个日志文件：
 *   - var/logs/app.log    应用日志（本类负责）
 *   - var/logs/cdifit.log 与 Cdifit 的请求/响应结构追踪（CDIFIT_TRACE=true 时）
 *
 * PHP 自身的错误（Warning / Fatal）仍由 php.ini 的 error_log 处理，
 * 指向同一个文件，并且是 UTF-8 追加写入的。
 */
final class Log
{
    /** 当前请求内已解析出的日志路径 */
    private static ?string $file = null;

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

    /** 记录异常，附带位置与简短堆栈 */
    public static function exception(\Throwable $e, array $context = []): void
    {
        self::write('ERROR', $e->getMessage(), $context + [
            'exception' => get_class($e),
            'at'        => $e->getFile() . ':' . $e->getLine(),
        ]);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $line = sprintf('[%s] %s %s', date('Y-m-d H:i:s'), $level, $message);

        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $line .= ' ' . ($json === false ? '[context encode failed]' : $json);
        }

        self::append(self::file(), $line . PHP_EOL);
    }

    /**
     * Cdifit 请求/响应追踪
     *
     * 由 CDIFIT_TRACE=true 打开，把真实请求与响应结构写盘，
     * 便于对照 docs.cdifit.com/zh 修正字段映射。
     */
    public static function cdifit(string $stage, array $payload): void
    {
        if (!(bool) Config::get('log.cdifit_trace', false)) {
            return;
        }

        $file = (string) Config::get('log.cdifit_file', self::file() . '.cdifit');
        $line = sprintf(
            '[%s] %s %s',
            date('Y-m-d H:i:s'),
            strtoupper($stage),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
        );

        self::append($file, $line . PHP_EOL);
    }

    private static function file(): string
    {
        if (self::$file !== null) {
            return self::$file;
        }

        $configured = (string) Config::get('log.file', '');
        if ($configured === '') {
            $configured = LOLILOTA_VAR . '/logs/app.log';
        }

        $dir = dirname($configured);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return self::$file = $configured;
    }

    /** UTF-8 追加写入，带文件锁；写失败不抛异常（日志不能反过来影响业务） */
    private static function append(string $file, string $line): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // 字符串本身已是 UTF-8（json_encode 用了 JSON_UNESCAPED_UNICODE），
        // 直接按字节追加即可，不做任何代码页转换。
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
