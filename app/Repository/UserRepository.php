<?php

declare(strict_types=1);

namespace Lolilota\Repository;

use Lolilota\Core\JsonStore;
use Lolilota\Support\Log;

/**
 * 账号仓库
 *
 * 数据结构： { "users": { "<uid>": { ...profile } } }
 * 说明：Cdifit 仍然是账号的唯一权威来源；本地只缓存登录所需的
 * 最小档案与可选本地密码，用于后端不可达时兜底。
 */
final class UserRepository
{
    private const BUCKET = 'users';

    private JsonStore $store;

    public function __construct(?JsonStore $store = null)
    {
        $this->store = $store ?? new JsonStore('users');
    }

    /** @return array<string, mixed>|null */
    public function find(string $uid): ?array
    {
        $data = $this->store->read();
        $user = $data[self::BUCKET][$uid] ?? null;
        return is_array($user) ? $user : null;
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        if ($email === '') {
            return null;
        }

        $needle = mb_strtolower(trim($email));
        foreach ($this->all() as $user) {
            if (mb_strtolower((string) ($user['email'] ?? '')) === $needle) {
                return $user;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        if ($username === '') {
            return null;
        }

        $needle = mb_strtolower(trim($username));
        foreach ($this->all() as $user) {
            if (mb_strtolower((string) ($user['username'] ?? '')) === $needle) {
                return $user;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $data = $this->store->read();
        $users = $data[self::BUCKET] ?? [];
        return is_array($users) ? array_values(array_filter($users, 'is_array')) : [];
    }

    /**
     * 新建或更新账号档案
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function upsert(string $uid, array $profile): array
    {
        $saved = [];

        $this->store->transaction(function (array $data) use ($uid, $profile, &$saved): array {
            $users = is_array($data[self::BUCKET] ?? null) ? $data[self::BUCKET] : [];
            $existing = is_array($users[$uid] ?? null) ? $users[$uid] : [];

            $merged = array_merge($existing, $profile);
            $merged['id'] = $uid;
            $merged['updated_at'] = time();
            $merged['created_at'] = $existing['created_at'] ?? time();

            $users[$uid] = $merged;
            $data[self::BUCKET] = $users;
            $saved = $merged;

            return $data;
        });

        return $saved;
    }

    /**
     * 记录一次成功登录
     *
     * @param array<string, mixed> $context
     */
    public function touchLogin(string $uid, array $context = []): void
    {
        $this->store->transaction(function (array $data) use ($uid, $context): array {
            $users = is_array($data[self::BUCKET] ?? null) ? $data[self::BUCKET] : [];
            if (is_array($users[$uid] ?? null)) {
                $users[$uid]['last_login_at'] = time();
                $users[$uid]['last_login_ip'] = $context['ip'] ?? null;
                $users[$uid]['last_login_provider'] = $context['provider'] ?? null;
            }
            $data[self::BUCKET] = $users;
            return $data;
        });
    }

    /**
     * 设置本地密码（仅本地 Provider 使用）
     */
    public function setPassword(string $uid, string $hash): void
    {
        $this->store->transaction(function (array $data) use ($uid, $hash): array {
            $users = is_array($data[self::BUCKET] ?? null) ? $data[self::BUCKET] : [];
            if (is_array($users[$uid] ?? null)) {
                $users[$uid]['password_hash'] = $hash;
            }
            $data[self::BUCKET] = $users;
            return $data;
        });
    }

    /**
     * 本地建号（AUTH_ALLOW_SELF_REGISTER=true 或 CLI 建号时使用）
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createLocal(string $username, string $password, array $attributes = []): array
    {
        $uid = 'local_' . bin2hex(random_bytes(8));

        return $this->upsert($uid, array_merge([
            'username'      => $username,
            'email'         => $attributes['email'] ?? '',
            'nickname'      => $attributes['nickname'] ?? $username,
            'provider'      => 'local',
            'password_hash' => \Lolilota\Core\Security::hashPassword($password),
        ], $attributes));
    }

    public function count(): int
    {
        return count($this->all());
    }

    public function forget(string $uid): void
    {
        $this->store->transaction(function (array $data) use ($uid): array {
            if (isset($data[self::BUCKET][$uid])) {
                unset($data[self::BUCKET][$uid]);
            }
            return $data;
        });

        Log::info('账号已删除', ['uid' => $uid]);
    }
}
