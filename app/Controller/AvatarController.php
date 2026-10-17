<?php

declare(strict_types=1);

namespace Lolilota\Controller;

use Lolilota\Auth\Auth;
use Lolilota\Auth\CdifitProvider;
use Lolilota\Support\Log;

/**
 * 用户头像
 *
 *   GET /api/v1/avatar/{id}
 *
 * 前端拿到的 user.avatar_url 就指向这里：
 *   - 同源站点：https://<本站>/api/v1/avatar/<uid>
 *   - 扩展形态：https://<后端>/api/v1/avatar/<uid>
 *
 * 由后端去拉 Cdifit 的 GET /api/v4/user/avatar/{id}，把图片内容原样返回：
 *   1. 浏览器不需要直连 Cdifit，也不用处理跨域 / 第三方 Cookie；
 *   2. Cdifit 的访问令牌始终留在服务端；
 *   3. 可以统一做类型校验与缓存，拿不到头像时返回 404，前端回落首字母头像。
 */
final class AvatarController
{
    /** 缓存时间：头像很少变，1 小时足够，同时避免频繁回源 */
    private const MAX_AGE = 3600;

    public function __construct(private readonly Auth $auth)
    {
    }

    /** @param array<string, string> $params */
    public function show(array $params): never
    {
        $id = trim((string) ($params['id'] ?? ''));

        // Cdifit 的用户 ID 是短字符串（数字或 nanoid 风格），先做白名单校验，
        // 避免把用户输入拼进上游 URL。
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
            $this->notFound();
        }

        $provider = new CdifitProvider();

        if (!$provider->isConfigured()) {
            $this->notFound();
        }

        $avatar = null;

        try {
            $avatar = $provider->avatar($id, $this->providerAccessToken());
        } catch (\Throwable $e) {
            Log::warn('获取用户头像失败', [
                'id_hash' => substr(hash('sha256', $id), 0, 8),
                'message' => $e->getMessage(),
            ]);
        }

        if ($avatar === null) {
            $this->notFound();
        }

        $body = $avatar['body'];
        $etag = '"' . substr(hash('sha256', $body), 0, 16) . '"';

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if (is_string($ifNoneMatch) && trim($ifNoneMatch) === $etag) {
            if (!headers_sent()) {
                http_response_code(304);
                header('ETag: ' . $etag);
                header('Cache-Control: public, max-age=' . self::MAX_AGE);
            }
            exit;
        }

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . $avatar['content_type']);
            header('Content-Length: ' . strlen($body));
            header('Cache-Control: public, max-age=' . self::MAX_AGE);
            header('ETag: ' . $etag);
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
        }

        echo $body;
        exit;
    }

    /**
     * 当前会话对应的 Cdifit 访问令牌
     *
     * 大多数 Cdifit 部署的头像接口是公开的；如果部署要求鉴权，
     * 这里把登录时保存在服务端的令牌带上，前端依然接触不到它。
     */
    private function providerAccessToken(): ?string
    {
        try {
            $identity = $this->auth->sessions()->resolve();

            if ($identity === null || strtolower((string) $identity['provider']) !== 'cdifit') {
                return null;
            }

            return $this->auth->sessions()->providerAccessToken($identity['session_id'] ?? null);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function notFound(): never
    {
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }

        echo 'avatar not found';
        exit;
    }
}
