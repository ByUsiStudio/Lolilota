<?php

declare(strict_types=1);

namespace Lolilota\Support;

/**
 * .env 加载器（纯 PHP，不依赖 vlucas/phpdotenv）
 *
 * 为什么需要它：很多虚拟主机 / 内置服务器不会替你注入环境变量，
 * 用户按 README 把配置写进 .env 却完全不生效，会误以为"配置没被读取"。
 *
 * 支持的格式：
 *
 *   # 注释
 *   KEY=value
 *   KEY="带空格或 # 的值"
 *   KEY='单引号里不解析转义'
 *   export KEY=value
 *   KEY=            # 空值（会被当作未设置）
 *
 * 优先级（高 -> 低）：
 *   1. 真实进程环境变量（服务器 / docker / shell export）
 *   2. .env 文件
 *   3. config/local.php
 *   4. config/config.php 里的默认值
 *
 * 因此 .env 不会覆盖服务器上已经存在的同名变量，符合部署直觉。
 */
final class Env
{
    /** @var array<string, string> 由 .env 提供（进程环境里原本没有）的键值 */
    private static array $loaded = [];

    /** @var list<string> 读取 .env 之前就存在的真实环境变量名 */
    private static array $fromProcess = [];

    private static ?string $sourcePath = null;

    /**
     * 加载 .env（若存在）
     *
     * @param string $path .env 文件路径
     * @return int 实际写入的变量个数
     */
    public static function load(string $path): int
    {
        self::$sourcePath = $path;

        if (!is_file($path)) {
            return 0;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return 0;
        }

        // 去掉 BOM（Windows 记事本保存的文件常带 BOM）
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

        $count = 0;

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $parsed = self::parseLine($line);
            if ($parsed === null) {
                continue;
            }

            [$key, $value] = $parsed;

            // 已由真实环境变量提供时不覆盖，只记录下来源
            if (getenv($key) !== false) {
                if (!in_array($key, self::$fromProcess, true)) {
                    self::$fromProcess[] = $key;
                }
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;

            self::$loaded[$key] = $value;
            $count++;
        }

        return $count;
    }

    /**
     * 解析单行，返回 [key, value] 或 null（注释/空行/格式错误）
     *
     * @return array{0: string, 1: string}|null
     */
    private static function parseLine(string $line): ?array
    {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        // 允许 `export KEY=value`
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $eq = strpos($line, '=');
        if ($eq === false) {
            return null;
        }

        $key = trim(substr($line, 0, $eq));
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
            return null;
        }

        $value = trim(substr($line, $eq + 1));

        // 引号包裹的值：解析后整段保留，不当作注释
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];
            $end = strrpos($value, $quote);

            if ($end !== false && $end > 0) {
                $inner = substr($value, 1, $end - 1);
                $value = $quote === '"' ? self::unescapeDouble($inner) : $inner;
            }
        } else {
            // 未加引号时，行内 ` #` 之后视为注释
            $hash = strpos($value, ' #');
            if ($hash !== false) {
                $value = rtrim(substr($value, 0, $hash));
            }
        }

        return [$key, $value];
    }

    /** 双引号内的常见转义 */
    private static function unescapeDouble(string $value): string
    {
        return strtr($value, [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\"' => '"',
            '\\\\' => '\\',
        ]);
    }

    /** 由 .env 提供的键值（不含真实环境变量） */
    public static function loaded(): array
    {
        return self::$loaded;
    }

    /** 某个键是否来自 .env */
    public static function fromEnvFile(string $key): bool
    {
        return array_key_exists($key, self::$loaded);
    }

    /** 某个键是否来自真实环境变量（.env 之前就存在） */
    public static function fromProcess(string $key): bool
    {
        return in_array($key, self::$fromProcess, true);
    }

    public static function sourcePath(): ?string
    {
        return self::$sourcePath;
    }

    /** .env 是否存在 */
    public static function hasFile(): bool
    {
        return self::$sourcePath !== null && is_file(self::$sourcePath);
    }
}
