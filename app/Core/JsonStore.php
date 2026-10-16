<?php

declare(strict_types=1);

namespace Lolilota\Core;

/**
 * JSON 文件存储
 *
 * 为什么不用数据库：目标环境是普通虚拟主机 / 内网服务器，
 * 不能假设装了 pdo_sqlite 或 pdo_mysql。JSON + 文件锁足够支撑
 * 个人与小队规模的账号与历史记录。
 *
 * 并发策略：所有写操作在 LOCK_EX 下“读-改-写”，并先写临时文件再 rename，
 * 避免半截写入损坏数据。
 */
final class JsonStore
{
    private string $file;

    public function __construct(string $name)
    {
        $dir = (string) Config::get('storage.path');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建数据目录：' . $dir);
        }

        $this->file = rtrim($dir, '/\\') . '/' . preg_replace('/[^a-z0-9_\-]/i', '', $name) . '.json';
    }

    public function path(): string
    {
        return $this->file;
    }

    /**
     * 读取全部数据
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $handle = @fopen($this->file, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return [];
            }
            $raw = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * 整体覆盖写入
     *
     * @param array<string, mixed> $data
     */
    public function write(array $data): bool
    {
        $dir = dirname($this->file);
        $tmp = $dir . '/.' . basename($this->file) . '.' . bin2hex(random_bytes(4)) . '.tmp';

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        @chmod($tmp, 0664);

        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * 在独占锁下做一次“读-改-写”事务
     *
     * 回调接收当前数据引用，返回值作为新的数据写回。
     *
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    public function transaction(callable $mutator): mixed
    {
        $handle = @fopen($this->file, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('无法打开数据文件：' . $this->file);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('无法获取数据文件锁：' . $this->file);
            }

            $raw = stream_get_contents($handle);
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($data)) {
                $data = [];
            }

            $result = $mutator($data);

            // 回调可以直接 return 新数组，也可以原地修改后返回 null
            $next = is_array($result) ? $result : $data;

            $json = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false) {
                throw new \RuntimeException('数据编码失败');
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json);
            fflush($handle);
            flock($handle, LOCK_UN);

            return $result;
        } finally {
            fclose($handle);
        }
    }
}
