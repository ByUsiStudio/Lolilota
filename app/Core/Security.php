<?php

declare(strict_types=1);

namespace Lolilota\Core;

/**
 * 签名与随机串
 *
 * 不依赖 openssl / sodium 扩展：PHP 自带 hash_hmac、hash_pbkdf2、
 * random_bytes，足以做会话签名与密码散列。装了 openssl 或 sodium 时
 * 优先用 password_hash（bcrypt / argon2），否则回落到 PBKDF2-SHA256。
 */
final class Security
{
    private const PBKDF2_ITERATIONS = 120000;
    private const PBKDF2_LENGTH = 32;

    private static ?string $secret = null;

    /** 读取（首次使用时生成）应用密钥，用于 HMAC 签名 */
    public static function secret(): string
    {
        if (self::$secret !== null) {
            return self::$secret;
        }

        // 优先环境变量，便于多机部署共享
        $fromEnv = getenv('APP_SECRET');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return self::$secret = $fromEnv;
        }

        $file = LOLILOTA_VAR . '/secret.key';

        if (is_file($file)) {
            $value = trim((string) @file_get_contents($file));
            if ($value !== '') {
                return self::$secret = $value;
            }
        }

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $value = bin2hex(random_bytes(32));
        @file_put_contents($file, $value, LOCK_EX);
        @chmod($file, 0600);

        return self::$secret = $value;
    }

    /** URL 安全的随机令牌 */
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** 固定的时间安全比较 */
    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /** HMAC-SHA256 签名 */
    public static function sign(string $data): string
    {
        return hash_hmac('sha256', $data, self::secret());
    }

    /**
     * 生成密码散列
     *
     * 返回值自带算法标识：$2y$... / $argon2id$... / pbkdf2$迭代次数$盐$散列
     */
    public static function hashPassword(string $password): string
    {
        if (self::canUsePasswordHash()) {
            return password_hash($password, self::preferredAlgorithm());
        }

        $salt = random_bytes(16);
        $hash = hash_pbkdf2('sha256', $password, $salt, self::PBKDF2_ITERATIONS, self::PBKDF2_LENGTH, true);

        return sprintf(
            'pbkdf2$%d$%s$%s',
            self::PBKDF2_ITERATIONS,
            base64_encode($salt),
            base64_encode($hash)
        );
    }

    /** 校验密码 */
    public static function verifyPassword(string $password, string $stored): bool
    {
        if ($stored === '') {
            return false;
        }

        if (str_starts_with($stored, 'pbkdf2$')) {
            $parts = explode('$', $stored);
            if (count($parts) !== 4) {
                return false;
            }

            [, $iterations, $saltB64, $hashB64] = $parts;
            $salt = base64_decode($saltB64, true);
            $expected = base64_decode($hashB64, true);
            if ($salt === false || $expected === false) {
                return false;
            }

            $actual = hash_pbkdf2(
                'sha256',
                $password,
                $salt,
                (int) $iterations,
                strlen($expected),
                true
            );

            return hash_equals($expected, $actual);
        }

        if (self::canUsePasswordHash()) {
            return password_verify($password, $stored);
        }

        return false;
    }

    /** 判断是否需要重算散列（算法升级后自动迁移） */
    public static function needsRehash(string $stored): bool
    {
        if (str_starts_with($stored, 'pbkdf2$')) {
            $parts = explode('$', $stored);
            return count($parts) !== 4 || (int) $parts[1] < self::PBKDF2_ITERATIONS;
        }

        if (self::canUsePasswordHash() && str_starts_with($stored, '$')) {
            return password_needs_rehash($stored, self::preferredAlgorithm());
        }

        return false;
    }

    private static function canUsePasswordHash(): bool
    {
        return function_exists('password_hash') && defined('PASSWORD_BCRYPT');
    }

    private static function preferredAlgorithm(): string
    {
        if (defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true)) {
            return PASSWORD_ARGON2ID;
        }
        return PASSWORD_BCRYPT;
    }
}
