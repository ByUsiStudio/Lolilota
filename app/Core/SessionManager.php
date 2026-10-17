<?php

declare(strict_types=1);

namespace Lolilota\Core;

use Lolilota\Support\Http;
use Lolilota\Support\Log;

/**
 * 会话管理
 *
 * 两条通道并存：
 *   1. 网页：HttpOnly Cookie（同源部署，浏览器自动携带）
 *   2. 扩展：Bearer 令牌 + refresh_token（跨源，扩展自己保存）
 *
 * 令牌只存散列值，落盘文件泄露也无法直接登录。
 */
final class SessionManager
{
    private const BUCKET_SESSIONS = 'sessions';
    private const BUCKET_TOKENS = 'tokens';
    private const BUCKET_REFRESH = 'refresh_index';

    private JsonStore $store;

    public function __construct(?JsonStore $store = null)
    {
        $this->store = $store ?? new JsonStore('sessions');
    }

    private function cookieName(): string
    {
        return (string) Config::get('session.name', 'lolilota_sid');
    }

    private function ttl(): int
    {
        return max(300, (int) Config::get('session.ttl', 1209600));
    }

    private function refreshTtl(): int
    {
        return max(3600, (int) Config::get('session.refresh_ttl', 2592000));
    }

    /**
     * 建立会话（Cookie + 令牌同时签发）
     *
     * @return array<string, mixed> 返回给客户端的凭据
     */
    public function issue(string $uid, string $provider, array $user = []): array
    {
        $now = time();
        $sid = Security::token(24);
        $accessToken = Security::token(32);
        $refreshToken = Security::token(32);
        $accessHash = hash('sha256', $accessToken);
        $refreshHash = hash('sha256', $refreshToken);

        $this->store->transaction(function (array $data) use (
            $uid, $provider, $sid, $accessHash, $refreshHash, $now
        ): array {
            $data[self::BUCKET_SESSIONS] ??= [];
            $data[self::BUCKET_TOKENS] ??= [];
            $data[self::BUCKET_REFRESH] ??= [];

            $data[self::BUCKET_SESSIONS][$sid] = [
                'uid'        => $uid,
                'provider'   => $provider,
                'created_at' => $now,
                'expires_at' => $now + $this->ttl(),
                'ip'         => Http::ip(),
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
            ];

            $data[self::BUCKET_TOKENS][$accessHash] = [
                'uid'               => $uid,
                'provider'          => $provider,
                'session_id'        => $sid,
                'refresh_hash'      => $refreshHash,
                'created_at'        => $now,
                'expires_at'        => $now + $this->ttl(),
                'refresh_expires_at' => $now + $this->refreshTtl(),
                'last_used_at'      => $now,
            ];

            $data[self::BUCKET_REFRESH][$refreshHash] = [
                'uid'          => $uid,
                'access_hash'  => $accessHash,
                'expires_at'   => $now + $this->refreshTtl(),
            ];

            return $data;
        });

        $this->writeCookie($sid, $now + $this->ttl());

        return [
            'authenticated'      => true,
            'provider'           => $provider,
            'user'               => $user,
            'access_token'       => $accessToken,
            'refresh_token'      => $refreshToken,
            'expires_at'         => $now + $this->ttl(),
            'refresh_expires_at' => $now + $this->refreshTtl(),
        ];
    }

    /**
     * 解析当前请求的身份
     *
     * @return array{uid: string, provider: string, via: string, session_id: ?string}|null
     */
    public function resolve(): ?array
    {
        $now = time();

        // 1) Bearer 令牌（扩展）
        $bearer = Http::bearerToken();
        if ($bearer !== null) {
            $hash = hash('sha256', $bearer);
            $data = $this->store->read();
            $record = $data[self::BUCKET_TOKENS][$hash] ?? null;

            if (is_array($record) && (int) $record['expires_at'] > $now) {
                return [
                    'uid'        => (string) $record['uid'],
                    'provider'   => (string) $record['provider'],
                    'via'        => 'bearer',
                    'session_id' => $record['session_id'] ?? null,
                ];
            }

            if (is_array($record)) {
                Log::info('令牌已过期', ['uid' => $record['uid'] ?? null]);
            }
        }

        // 2) Cookie 会话（网页）
        $sid = Http::cookie($this->cookieName());
        if ($sid !== null) {
            $data = $this->store->read();
            $record = $data[self::BUCKET_SESSIONS][$sid] ?? null;

            if (is_array($record) && (int) $record['expires_at'] > $now) {
                return [
                    'uid'        => (string) $record['uid'],
                    'provider'   => (string) $record['provider'],
                    'via'        => 'cookie',
                    'session_id' => $sid,
                ];
            }
        }

        return null;
    }

    /**
     * 当前请求刚签发的会话 ID
     *
     * issue() 会写 Cookie，这里直接读回来，便于紧接着给会话挂载上游令牌。
     */
    public function currentSessionId(): ?string
    {
        return Http::cookie($this->cookieName());
    }

    /**
     * 记录上游（Cdifit）颁发的 refresh token，供会话续期与退出登录撤销使用
     *
     * 存在服务端文件里，不下发给浏览器/扩展，避免令牌泄露。
     */
    public function setProviderTokens(?string $sid, array $tokens): void
    {
        if ($sid === null || $sid === '' || $tokens === []) {
            return;
        }

        $this->store->transaction(function (array $data) use ($sid, $tokens): array {
            $sessions = is_array($data[self::BUCKET_SESSIONS] ?? null) ? $data[self::BUCKET_SESSIONS] : [];

            if (isset($sessions[$sid]) && is_array($sessions[$sid])) {
                $sessions[$sid]['provider_tokens'] = [
                    'access_token'     => $tokens['access_token'] ?? null,
                    'refresh_token'    => $tokens['refresh_token'] ?? null,
                    'expires_at'       => $tokens['expires_at'] ?? null,
                    'refresh_expires_at' => $tokens['refresh_expires_at'] ?? null,
                ];
            }

            $data[self::BUCKET_SESSIONS] = $sessions;
            return $data;
        });
    }

    /**
     * 读取会话上记录的上游 refresh token
     */
    public function providerRefreshToken(?string $sid): ?string
    {
        if ($sid === null || $sid === '') {
            return null;
        }

        $data = $this->store->read();
        $record = $data[self::BUCKET_SESSIONS][$sid] ?? null;
        $token = is_array($record) ? ($record['provider_tokens']['refresh_token'] ?? null) : null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** 读取会话上记录的上游 access token */
    public function providerAccessToken(?string $sid): ?string
    {
        if ($sid === null || $sid === '') {
            return null;
        }

        $data = $this->store->read();
        $record = $data[self::BUCKET_SESSIONS][$sid] ?? null;
        $token = is_array($record) ? ($record['provider_tokens']['access_token'] ?? null) : null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * 用 refresh_token 换新令牌
     *
     * @return array<string, mixed>|null
     */
    public function refresh(string $refreshToken, array $user = []): ?array
    {
        $now = time();
        $refreshHash = hash('sha256', $refreshToken);
        $data = $this->store->read();

        $index = $data[self::BUCKET_REFRESH][$refreshHash] ?? null;
        if (!is_array($index) || (int) $index['expires_at'] <= $now) {
            return null;
        }

        $uid = (string) $index['uid'];
        $provider = 'unknown';
        $oldAccessHash = (string) ($index['access_hash'] ?? '');

        if ($oldAccessHash !== '' && isset($data[self::BUCKET_TOKENS][$oldAccessHash])) {
            $provider = (string) $data[self::BUCKET_TOKENS][$oldAccessHash]['provider'];
        }

        // 轮换：旧令牌立即失效，防止重放
        $this->revokeByRefresh($refreshHash);

        $issued = $this->issue($uid, $provider, $user);

        // 供上层（Auth）补齐用户资料；接口响应前会移除这个字段
        $issued['uid'] = $uid;

        return $issued;
    }

    /** 退出登录：清掉 Cookie 会话与对应令牌 */
    public function destroy(): void
    {
        $sid = Http::cookie($this->cookieName());
        $bearer = Http::bearerToken();
        $bearerHash = $bearer !== null ? hash('sha256', $bearer) : null;

        $this->store->transaction(function (array $data) use ($sid, $bearerHash): array {
            $sessions = is_array($data[self::BUCKET_SESSIONS] ?? null) ? $data[self::BUCKET_SESSIONS] : [];
            $tokens = is_array($data[self::BUCKET_TOKENS] ?? null) ? $data[self::BUCKET_TOKENS] : [];
            $refresh = is_array($data[self::BUCKET_REFRESH] ?? null) ? $data[self::BUCKET_REFRESH] : [];

            $killSessionIds = [];
            if ($sid !== null && isset($sessions[$sid])) {
                $killSessionIds[] = $sid;
            }

            if ($bearerHash !== null && isset($tokens[$bearerHash])) {
                if (!empty($tokens[$bearerHash]['session_id'])) {
                    $killSessionIds[] = $tokens[$bearerHash]['session_id'];
                }
                if (!empty($tokens[$bearerHash]['refresh_hash'])) {
                    unset($refresh[$tokens[$bearerHash]['refresh_hash']]);
                }
                unset($tokens[$bearerHash]);
            }

            foreach ($tokens as $hash => $record) {
                if (is_array($record) && in_array($record['session_id'] ?? null, $killSessionIds, true)) {
                    unset($refresh[$record['refresh_hash'] ?? '']);
                    unset($tokens[$hash]);
                }
            }

            foreach ($killSessionIds as $id) {
                unset($sessions[$id]);
            }

            $data[self::BUCKET_SESSIONS] = $sessions;
            $data[self::BUCKET_TOKENS] = $tokens;
            $data[self::BUCKET_REFRESH] = $refresh;

            return $data;
        });

        $this->clearCookie();
    }

    /** 撤销单个 refresh token */
    private function revokeByRefresh(string $refreshHash): void
    {
        $this->store->transaction(function (array $data) use ($refreshHash): array {
            $tokens = is_array($data[self::BUCKET_TOKENS] ?? null) ? $data[self::BUCKET_TOKENS] : [];
            $refresh = is_array($data[self::BUCKET_REFRESH] ?? null) ? $data[self::BUCKET_REFRESH] : [];

            $accessHash = $refresh[$refreshHash]['access_hash'] ?? null;
            unset($refresh[$refreshHash]);

            if (is_string($accessHash)) {
                unset($tokens[$accessHash]);
            }

            $data[self::BUCKET_TOKENS] = $tokens;
            $data[self::BUCKET_REFRESH] = $refresh;

            return $data;
        });
    }

    /** 清理过期记录，返回清理条数 */
    public function gc(): int
    {
        $now = time();
        $removed = 0;

        $this->store->transaction(function (array $data) use ($now, &$removed): array {
            foreach ([self::BUCKET_SESSIONS, self::BUCKET_TOKENS, self::BUCKET_REFRESH] as $bucket) {
                $items = is_array($data[$bucket] ?? null) ? $data[$bucket] : [];
                foreach ($items as $key => $record) {
                    if (is_array($record) && (int) ($record['expires_at'] ?? 0) <= $now) {
                        unset($items[$key]);
                        $removed++;
                    }
                }
                $data[$bucket] = $items;
            }
            return $data;
        });

        return $removed;
    }

    /** 统计当前有效会话数 */
    public function activeCount(): int
    {
        $now = time();
        $data = $this->store->read();
        $count = 0;

        foreach ($data[self::BUCKET_SESSIONS] ?? [] as $record) {
            if (is_array($record) && (int) ($record['expires_at'] ?? 0) > $now) {
                $count++;
            }
        }

        return $count;
    }

    private function writeCookie(string $sid, int $expiresAt): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie($this->cookieName(), $sid, [
            'expires'  => $expiresAt,
            'path'     => '/',
            'secure'   => Http::isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[$this->cookieName()] = $sid;
    }

    private function clearCookie(): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie($this->cookieName(), '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => Http::isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        unset($_COOKIE[$this->cookieName()]);
    }
}
