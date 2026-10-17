<?php

declare(strict_types=1);

namespace Lolilota\Auth;

use Lolilota\Core\Config;
use Lolilota\Support\Arr;
use Lolilota\Support\HttpClient;
use Lolilota\Support\HttpException;
use Lolilota\Support\Log;

/**
 * Cdifit 账号适配器
 *
 * Cdifit 是 Cloudreve 的接口封装，风格上有两点必须特别注意：
 *
 *   1. 路由前缀是 /api/v4/，四个会话接口：
 *        POST   /api/v4/session/token          密码登录
 *        POST   /api/v4/session/token/2fa      完成两步验证
 *        PUT    /api/v4/session/token/refresh  刷新令牌（刷新令牌放在 Authorization 里）
 *        DELETE /api/v4/session/token          退出登录
 *        GET    /api/v4/user/profile           当前用户（可选，用于补齐资料）
 *
 *   2. HTTP 状态码固定为 200，真正的结果在 JSON 里：
 *        { "code": 0,   "data": ..., "msg": "" }              成功
 *        { "code": 203, "data": "<2FA session id>", "msg": "" } 需要两步验证
 *        { "code": 40020, "msg": "..." }                       失败
 *
 * 因此判断成败只看业务码，不看 HTTP 状态。错误码与提示的对照见 errorMessages()。
 *
 * 字段名与路由全部可配置（config/config.php 的 cdifit 段），
 * 打开 CDIFIT_TRACE=true 可把请求/响应结构写进 var/logs/cdifit.log 用于核对。
 */
final class CdifitProvider implements AuthProviderInterface
{
    /** 需要两步验证的业务码 */
    private const CODE_NEED_2FA = 203;

    /** 业务码 -> 面向用户的中文提示（按码值倒序匹配前缀） */
    private const ERROR_MESSAGES = [
        40017 => '该账号已被封禁',
        40018 => '该账号尚未激活',
        40020 => '邮箱或密码错误',
        40021 => '用户不存在',
        40022 => '两步验证码错误，请重新输入',
        40023 => '登录会话不存在或已过期，请重新登录',
        40026 => '图形验证码错误',
        40027 => '图形验证码已失效，请点击刷新后重试',
        40032 => '该邮箱已被注册',
        40069 => '密码错误',
        40080 => '密码错误',
        40089 => '授权范围不足，请重新授权',
    ];

    private HttpClient $http;

    /** 上一次调用中从 Cdifit 拿到的令牌（用于退出登录时撤销 refresh token） */
    private array $lastTokens = [];

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?? new HttpClient();
    }

    public function name(): string
    {
        return 'cdifit';
    }

    public function label(): string
    {
        return (string) Config::get('cdifit.label', 'Cdifit 账号');
    }

    public function isConfigured(): bool
    {
        $base = (string) Config::get('cdifit.base_url', '');
        return $base !== '' && filter_var($base, FILTER_VALIDATE_URL) !== false;
    }

    /** 最近一次调用拿到的令牌（已脱敏前供内部使用） */
    public function lastTokens(): array
    {
        return $this->lastTokens;
    }

    // -----------------------------------------------------------------
    // 四个会话接口
    // -----------------------------------------------------------------

    /**
     * POST /api/v4/session/token
     *
     * @param array<string, mixed> $credentials
     */
    public function passwordSignIn(array $credentials): AuthResult
    {
        $body = $this->mapFields([
            'email'    => $credentials['email'] ?? $credentials['username'] ?? null,
            'password' => $credentials['password'] ?? null,
            'captcha'  => $credentials['captcha'] ?? null,
            'ticket'   => $credentials['captcha_ticket'] ?? null,
        ], [
            'email'    => 'email',
            'password' => 'password',
            'captcha'  => 'captcha',
            'ticket'   => 'captcha_ticket',
        ]);

        $response = $this->call('password_sign_in', $body, 'POST');
        $result = $this->interpret($response);

        if ($result->isAuthed()) {
            $result = $this->enrichUser($result);
        }

        return $result;
    }

    /**
     * POST /api/v4/session/token/2fa
     *
     * @param array<string, mixed> $payload 含 code 与 session（即登录返回的 data）
     */
    public function finishSignInWith2fa(array $payload): AuthResult
    {
        $body = $this->mapFields([
            'session' => $payload['session'] ?? $payload['challenge_id'] ?? null,
            'code'    => $payload['code'] ?? null,
        ], [
            'session' => 'session',
            'code'    => 'code',
        ]);

        $response = $this->call('finish_sign_in_with_2fa', $body, 'POST');
        $result = $this->interpret($response);

        if ($result->isAuthed()) {
            $result = $this->enrichUser($result);
        }

        return $result;
    }

    /**
     * PUT /api/v4/session/token/refresh
     *
     * 注意：刷新令牌要放在 Authorization 头里（Bearer <RefreshToken>），
     * 请求体里同时带上 refresh_token。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> 新的令牌集合
     */
    public function refreshToken(array $payload): array
    {
        $refreshToken = (string) ($payload['refresh_token'] ?? '');

        if ($refreshToken === '') {
            throw new AuthException('missing_refresh_token', '缺少刷新令牌，请重新登录', 401);
        }

        $response = $this->call(
            'refresh_token',
            $this->mapFields(['refresh_token' => $refreshToken], ['refresh_token' => 'refresh_token']),
            'PUT',
            ['Authorization' => 'Bearer ' . $refreshToken]
        );

        $data = $this->unwrap($response, false);

        $tokens = $this->extractTokens(is_array($data) ? $data : []);
        if ($tokens === []) {
            throw new AuthException('refresh_failed', '刷新登录状态失败，请重新登录', 401);
        }

        $this->lastTokens = $tokens;

        return $tokens;
    }

    /**
     * DELETE /api/v4/session/token
     *
     * @param array<string, mixed> $payload
     */
    public function signOut(array $payload): void
    {
        $accessToken = (string) ($payload['access_token'] ?? '');

        if ($accessToken === '') {
            return;
        }

        // 退出登录属于“尽力而为”：远端失败不阻塞本地登出
        try {
            $this->call('sign_out', [], 'DELETE', ['Authorization' => 'Bearer ' . $accessToken]);
        } catch (AuthException | HttpException $e) {
            Log::warn('Cdifit 退出登录调用失败，已忽略', [
                'code'    => $e instanceof AuthException ? $e->errorCode : $e->errorCode,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // -----------------------------------------------------------------
    // 用户头像
    // -----------------------------------------------------------------

    /**
     * GET /api/v4/user/avatar/{user_id}
     *
     * 响应体就是头像文件内容（不是 JSON），所以不能复用 call()：
     * call() 会按 JSON 解析并记录响应体，二进制内容既解析不了也不该进日志。
     *
     * 头像文件可能通过 302 指向对象存储，这里最多跟随 3 跳；
     * 只接受常见的位图类型，避免把 SVG / HTML 当成头像回流到本站。
     *
     * @param string      $userId      Cdifit 用户 ID
     * @param string|null $accessToken 当前登录用户的上游令牌（可选，兼容需要鉴权的部署）
     * @return array{body:string, content_type:string}|null
     */
    public function avatar(string $userId, ?string $accessToken = null): ?array
    {
        $userId = trim($userId);

        if ($userId === '' || !$this->isConfigured()) {
            return null;
        }

        $url = $this->avatarUrl($userId);

        $headers = [
            'Accept'     => 'image/avif,image/webp,image/png,image/jpeg,image/gif,image/*;q=0.8',
            // 流式请求（未启用 cURL）不会自动带 UA，这里显式补上
            'User-Agent' => (string) Config::get('cdifit.default_headers.User-Agent', 'Lolilota/1.1.0'),
        ];
        if ($accessToken !== null && $accessToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $accessToken;
        }

        $response = $this->requestImage($url, $headers);

        // 有些实现对头像做 302 跳转（例如转存到对象存储）
        for ($hop = 0; $hop < 3 && $response !== null; $hop++) {
            $status = (int) ($response['status'] ?? 0);

            if ($status < 300 || $status >= 400) {
                break;
            }

            $location = $response['headers']['location'] ?? null;
            if (!is_string($location) || $location === '') {
                return null;
            }

            $next = $this->resolveLocation($url, $location);
            if ($next === null) {
                return null;
            }

            $url = $next;
            $response = $this->requestImage($url, $headers);
        }

        if ($response === null || (int) ($response['status'] ?? 0) !== 200) {
            $this->trace('avatar', [
                'endpoint' => 'user_avatar',
                'url'      => $url,
                'status'   => $response === null ? null : ($response['status'] ?? null),
            ]);
            return null;
        }

        $body = (string) ($response['raw'] ?? '');
        if ($body === '') {
            return null;
        }

        $contentType = $this->imageContentType(
            (string) ($response['headers']['content-type'] ?? ''),
            $body
        );

        if ($contentType === null) {
            $this->trace('avatar', [
                'endpoint'     => 'user_avatar',
                'url'          => $url,
                'status'       => (int) ($response['status'] ?? 0),
                'content_type' => (string) ($response['headers']['content-type'] ?? ''),
                'bytes'        => strlen($body),
                'accepted'     => false,
            ]);
            return null;
        }

        $this->trace('avatar', [
            'endpoint'     => 'user_avatar',
            'url'          => $url,
            'status'       => 200,
            'content_type' => $contentType,
            'bytes'        => strlen($body),
        ]);

        return ['body' => $body, 'content_type' => $contentType];
    }

    /** 头像接口的完整地址（走 /user 分组） */
    public function avatarUrl(string $userId): string
    {
        return $this->endpointUrl('user_avatar') . '/' . rawurlencode($userId);
    }

    /**
     * 发一次图片请求；网络异常时返回 null（头像失败不应影响页面）
     *
     * @param array<string, string> $headers
     * @return array{status:int, body:mixed, raw:string, headers:array<string,string>}|null
     */
    private function requestImage(string $url, array $headers): ?array
    {
        try {
            return $this->http->request('GET', $url, '', $headers);
        } catch (HttpException $e) {
            Log::warn('拉取 Cdifit 头像失败', [
                'url'     => $url,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 把 Location 解析成绝对地址；只接受 http / https
     */
    private function resolveLocation(string $base, string $location): ?string
    {
        $location = trim($location);

        if ($location === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $origin = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        // 协议相对地址：//cdn.example.com/a.png
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        // 站内绝对路径：/files/a.png
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        // 相对路径：a.png / ../a.png
        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');
        return $origin . $dir . '/' . ltrim($location, '/');
    }

    /**
     * 校验并归一化图片类型
     *
     * 只放行位图：SVG / HTML 一旦以本站同源地址返回，可能变成存储型 XSS。
     */
    private function imageContentType(string $header, string $body): ?string
    {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp'];

        if (preg_match('#^image/[a-z0-9.+-]+#i', trim($header), $m) === 1) {
            $type = strtolower($m[0]);
            // 兼容 image/jpg 这种非标准写法
            $type = $type === 'image/jpg' ? 'image/jpeg' : $type;
            if (in_array($type, $allowed, true)) {
                return $type;
            }
        }

        // 服务端没有给出可信 Content-Type 时，按文件头判断
        if (str_starts_with($body, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($body, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (str_starts_with($body, 'GIF87a') || str_starts_with($body, 'GIF89a')) {
            return 'image/gif';
        }
        if (str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (substr($body, 4, 4) === 'ftyp' && in_array(substr($body, 8, 4), ['avif', 'avis'], true)) {
            return 'image/avif';
        }
        if (str_starts_with($body, 'BM')) {
            return 'image/bmp';
        }

        return null;
    }

    // -----------------------------------------------------------------
    // 可选：补齐用户资料
    // -----------------------------------------------------------------

    /**
     * GET /api/v4/user/profile
     *
     * 登录响应里已经带了 user，这里仅在缺失关键字段时兜底拉一次。
     */
    private function enrichUser(AuthResult $result): AuthResult
    {
        $user = $result->user ?? [];
        $accessToken = (string) ($result->tokens['access_token'] ?? '');

        if (!empty($user['email']) && !empty($user['id'])) {
            return $result;
        }

        if ($accessToken === '' || Config::get('cdifit.endpoints.user_profile') === null) {
            return $result;
        }

        try {
            $response = $this->call('user_profile', [], 'GET', ['Authorization' => 'Bearer ' . $accessToken]);
            $data = $this->unwrap($response, false);

            if (is_array($data)) {
                $profile = $this->extractUser(['user' => $data]);
                $merged = array_merge($profile, array_filter($user, static fn ($v) => $v !== null && $v !== ''));
                $user = $merged;
            }
        } catch (\Throwable $e) {
            Log::warn('拉取 Cdifit 用户资料失败，沿用登录返回的字段', ['message' => $e->getMessage()]);
        }

        return AuthResult::authed($user, $result->tokens, $result->raw);
    }

    // -----------------------------------------------------------------
    // 请求
    // -----------------------------------------------------------------

    /**
     * 发起调用并处理传输层与业务层错误
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $extraHeaders
     * @return array{status:int, body:mixed, raw:string, headers:array<string,string>, cookies:array<string,string>}
     */
    private function call(string $endpointKey, array $body, string $method, array $extraHeaders = []): array
    {
        if (!$this->isConfigured()) {
            throw new AuthException(
                'provider_not_configured',
                'Cdifit 服务地址未配置，请设置 CDIFIT_BASE_URL 环境变量',
                503
            );
        }

        $url = $this->endpointUrl($endpointKey);

        $this->trace('request', [
            'endpoint' => $endpointKey,
            'method'   => $method,
            'url'      => $url,
            'body'     => $this->scrub($body),
        ]);

        $headers = array_merge($this->authHeaders($extraHeaders), $extraHeaders);

        try {
            $result = $this->http->request(
                $method,
                $url,
                $body === [] ? '' : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                array_merge(
                    $body === [] ? [] : ['Content-Type' => 'application/json'],
                    $headers
                )
            );
        } catch (HttpException $e) {
            throw new AuthException($e->errorCode, $e->getMessage(), 502, $e->details);
        }

        $this->trace('response', [
            'endpoint' => $endpointKey,
            'status'   => $result['status'],
            'body'     => $this->scrub($result['body']),
        ]);

        return $result;
    }

    /**
     * 基础认证头（配置了服务令牌时自动带上）
     *
     * @param array<string, string> $extraHeaders 显式传入的头优先
     * @return array<string, string>
     */
    private function authHeaders(array $extraHeaders): array
    {
        $type = (string) Config::get('cdifit.auth.type', 'none');
        $token = (string) Config::get('cdifit.auth.token', '');

        // 调用方已经显式指定了 Authorization（如刷新令牌），不再覆盖
        if ($token === '' || $type === 'none' || isset($extraHeaders['Authorization'])) {
            return [];
        }

        return match ($type) {
            'bearer' => ['Authorization' => (string) Config::get('cdifit.auth.scheme', 'Bearer') . ' ' . $token],
            'api_key_header' => [(string) Config::get('cdifit.auth.header', 'X-Api-Key') => $token],
            default => [],
        };
    }

    private function endpointUrl(string $endpointKey): string
    {
        $base = rtrim((string) Config::get('cdifit.base_url', ''), '/');
        $apiPrefix = '/' . trim((string) Config::get('cdifit.api_prefix', '/api/v4'), '/');

        // 用户相关的接口在 /user 分组下，其余在 /session 下
        $userEndpoints = (array) Config::get('cdifit.user_endpoints', ['user_profile']);
        $groupPrefix = in_array($endpointKey, $userEndpoints, true)
            ? (string) Config::get('cdifit.user_prefix', '/user')
            : (string) Config::get('cdifit.session_prefix', '/session');

        $groupPrefix = trim($groupPrefix, '/');
        $endpoint = (string) Config::get('cdifit.endpoints.' . $endpointKey, '/' . $endpointKey);

        $path = $apiPrefix;
        if ($groupPrefix !== '') {
            $path .= '/' . $groupPrefix;
        }
        $path .= $endpoint;

        return $base . $path;
    }

    /**
     * 按配置把内部字段名映射为接口字段名
     *
     * @param array<string, mixed>  $values 内部键 => 值
     * @param array<string, string> $keys   内部键 => 配置里的字段名键
     * @return array<string, mixed>
     */
    private function mapFields(array $values, array $keys): array
    {
        $map = (array) Config::get('cdifit.request_fields', []);
        $out = [];

        foreach ($values as $internal => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $fieldKey = $keys[$internal] ?? $internal;
            $fieldName = (string) ($map[$fieldKey] ?? $fieldKey);

            if ($fieldName === $fieldKey && !isset($map[$fieldKey])) {
                // 没有配置映射时仍按内部名提交
                $fieldName = $fieldKey;
            }

            // 支持 "a.b" 形式写嵌套字段
            if (str_contains($fieldName, '.')) {
                $segments = explode('.', $fieldName);
                $cursor = &$out;
                foreach ($segments as $i => $segment) {
                    if ($i === count($segments) - 1) {
                        $cursor[$segment] = $value;
                    } else {
                        $cursor[$segment] ??= [];
                        $cursor = &$cursor[$segment];
                    }
                }
                unset($cursor);
            } else {
                $out[$fieldName] = $value;
            }
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // 响应解析
    // -----------------------------------------------------------------

    /**
     * 从响应里取出业务码；123 表示没有 code 字段
     *
     * @param array<string, mixed> $response
     */
    private function codeOf(array $response): int
    {
        $body = $response['body'];

        if (!is_array($body)) {
            // Cdifit 正常都会返回 JSON；非 JSON 时按 HTTP 状态粗略判断
            return $response['status'] >= 200 && $response['status'] < 300 ? 0 : 123;
        }

        $code = Arr::first($body, (array) Config::get('cdifit.response_fields.code', ['code']));

        return is_numeric($code) ? (int) $code : 123;
    }

    /**
     * 校验业务码并返回 data 部分
     *
     * @param array<string, mixed> $response
     */
    private function unwrap(array $response, bool $requireSuccess = true): mixed
    {
        $body = $response['body'];
        $code = $this->codeOf($response);

        if ($code === 0) {
            return is_array($body) ? ($body['data'] ?? null) : null;
        }

        if ($code === self::CODE_NEED_2FA) {
            throw new AuthException('requires_2fa', '需要两步验证', 401, is_array($body) ? $body : null);
        }

        if (!$requireSuccess) {
            return is_array($body) ? ($body['data'] ?? null) : null;
        }

        throw $this->failure($body, $response['status'], $code);
    }

    /**
     * 把 Cdifit 的响应翻译成统一的 AuthResult
     *
     * @param array<string, mixed> $response
     */
    private function interpret(array $response): AuthResult
    {
        $body = $response['body'];
        $code = $this->codeOf($response);

        // ---- 需要两步验证：data 就是 2FA session id ----
        if ($code === self::CODE_NEED_2FA) {
            $session = is_array($body) ? ($body['data'] ?? null) : null;

            return AuthResult::challenge([
                'session' => is_scalar($session) ? (string) $session : null,
                'hint'    => '请输入两步验证应用中显示的 6 位验证码',
            ], is_array($body) ? $body : []);
        }

        if ($code !== 0) {
            throw $this->failure($body, $response['status'], $code);
        }

        $data = is_array($body) ? ($body['data'] ?? null) : null;

        if (!is_array($data)) {
            // 登录成功了但结构不符合预期，保留原文便于排查
            throw new AuthException(
                'unexpected_response',
                '无法识别 Cdifit 的登录响应，请打开 CDIFIT_TRACE 核对字段',
                502,
                ['code' => $code, 'raw' => substr((string) $response['raw'], 0, 300)]
            );
        }

        $tokens = $this->extractTokens($data);
        $user = $this->extractUser($data);

        if ($tokens === []) {
            throw new AuthException(
                'unexpected_response',
                '登录响应里没有访问令牌，请打开 CDIFIT_TRACE 核对字段',
                502,
                ['keys' => array_keys($data)]
            );
        }

        $user['provider'] = $this->name();
        $this->lastTokens = $tokens;

        return AuthResult::authed($user, $tokens, $data);
    }

    /**
     * 构造面向用户的失败异常
     *
     * @param mixed $body
     */
    private function failure(mixed $body, int $status, int $code): AuthException
    {
        $data = is_array($body) ? $body : [];
        $message = Arr::first($data, (array) Config::get('cdifit.response_fields.message', ['msg', 'message', 'error']));

        $errorCode = match (true) {
            $code === 401 => 'not_authenticated',
            in_array($code, [40017, 40018], true) => 'account_unavailable',
            in_array($code, [40020, 40021, 40069, 40080], true) => 'invalid_credentials',
            $code === 40022 => 'invalid_code',
            $code === 40023 => 'session_expired',
            in_array($code, [40026, 40027], true) => 'captcha_required',
            $code >= 50000 => 'cdifit_server_error',
            default => 'sign_in_failed',
        };

        $httpStatus = match (true) {
            in_array($code, [40017, 40018, 40089], true) => 403,
            in_array($code, [40020, 40021, 40023, 40069, 40080], true) => 401,
            $code >= 50000 => 502,
            default => 400,
        };

        // 自带提示优先用中文映射，未覆盖的码回落到服务端 msg
        $localized = self::ERROR_MESSAGES[$code] ?? null;
        $text = $localized
            ?? (is_scalar($message) && (string) $message !== '' ? (string) $message : null)
            ?? ('Cdifit 返回错误（code=' . $code . '）');

        return new AuthException($errorCode, $text, $httpStatus, [
            'code'      => $code,
            'cdifit_msg' => is_scalar($message) ? (string) $message : null,
        ]);
    }

    /**
     * 提取令牌
     *
     * 结构：data.token.{access_token, refresh_token, access_expires, refresh_expires}
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function extractTokens(array $data): array
    {
        $fields = (array) Config::get('cdifit.response_fields', []);

        // 令牌既可能在 data.token 里，也可能直接铺在 data 上
        $node = null;
        foreach ((array) ($fields['token'] ?? ['token']) as $path) {
            $candidate = Arr::get($data, (string) $path);
            if (is_array($candidate)) {
                $node = $candidate;
                break;
            }
        }

        $source = $node ?? $data;

        $access = Arr::first($source, (array) ($fields['access_token'] ?? ['access_token']));
        $refresh = Arr::first($source, (array) ($fields['refresh_token'] ?? ['refresh_token']));

        $tokens = [];

        if (is_scalar($access) && (string) $access !== '') {
            $tokens['access_token'] = (string) $access;
        }
        if (is_scalar($refresh) && (string) $refresh !== '') {
            $tokens['refresh_token'] = (string) $refresh;
        }

        $accessExpires = Arr::first($source, (array) ($fields['access_expires'] ?? ['access_expires']));
        $refreshExpires = Arr::first($source, (array) ($fields['refresh_expires'] ?? ['refresh_expires']));

        $accessTs = $this->toTimestamp($accessExpires);
        $refreshTs = $this->toTimestamp($refreshExpires);

        if ($accessTs !== null) {
            $tokens['expires_at'] = $accessTs;
        }
        if ($refreshTs !== null) {
            $tokens['refresh_expires_at'] = $refreshTs;
        }

        return $tokens;
    }

    /**
     * ISO 8601 / 时间戳 -> Unix 时间戳
     */
    private function toTimestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $num = (int) $value;
            // 毫秒级时间戳
            return $num > 100000000000 ? (int) round($num / 1000) : $num;
        }

        if (!is_string($value)) {
            return null;
        }

        $ts = strtotime($value);
        return $ts === false ? null : $ts;
    }

    /**
     * 提取用户档案
     *
     * 结构：data.user.{id, email, nickname, avatar, ...}
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function extractUser(array $data): array
    {
        $fields = (array) Config::get('cdifit.response_fields', []);

        $node = null;
        foreach ((array) ($fields['user'] ?? ['user']) as $path) {
            $candidate = Arr::get($data, (string) $path);
            if (is_array($candidate)) {
                $node = $candidate;
                break;
            }
        }

        $source = $node ?? $data;

        $uid = Arr::first($source, (array) ($fields['user_id'] ?? ['id']));
        $email = Arr::first($source, (array) ($fields['email'] ?? ['email']));
        $nickname = Arr::first($source, (array) ($fields['nickname'] ?? ['nickname']));
        $username = Arr::first($source, (array) ($fields['username'] ?? ['username']));
        $avatar = Arr::first($source, (array) ($fields['avatar'] ?? ['avatar']));
        $status = Arr::first($source, (array) ($fields['status'] ?? ['status']));

        // Cdifit 的 avatar 可能是文件类型标识（如 "file"）而不是 URL，
        // 只有看起来像链接时才当成头像地址，否则交给前端显示首字母头像。
        $avatarUrl = null;
        if (is_scalar($avatar)) {
            $candidate = (string) $avatar;
            if (preg_match('#^(https?:)?//#', $candidate) === 1 || str_starts_with($candidate, 'data:image')) {
                $avatarUrl = $candidate;
            }
        }

        $display = is_scalar($nickname) && (string) $nickname !== ''
            ? (string) $nickname
            : (is_scalar($email) ? (string) $email : null);

        $user = Arr::compact([
            'id'         => is_scalar($uid) ? (string) $uid : null,
            'email'      => is_scalar($email) ? (string) $email : null,
            'nickname'   => $display,
            'username'   => is_scalar($username) && (string) $username !== ''
                ? (string) $username
                : (is_scalar($email) ? (string) strstr((string) $email, '@', true) : null),
            'avatar_url' => $avatarUrl,
            'status'     => is_scalar($status) ? (string) $status : null,
        ]);

        // 没有 id 时用邮箱派生稳定标识，避免每次登录都变成新用户
        if (empty($user['id']) && !empty($user['email'])) {
            $user['id'] = 'cdifit_' . substr(hash('sha256', (string) $user['email']), 0, 16);
        }

        return $user;
    }

    // -----------------------------------------------------------------
    // 日志脱敏
    // -----------------------------------------------------------------

    /**
     * 递归抹掉敏感字段，只保留结构用于核对字段名
     *
     * 注意：Cdifit 用 code 表示业务结果（0/203/40020…），这是排查时
     * 最关键的字段，所以数值型 code 必须保留可见，只有字符串型的
     * 验证码 / 授权码才打码。
     */
    private function scrub(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return '…';
        }

        if (!is_array($value)) {
            return is_scalar($value) || $value === null ? $value : gettype($value);
        }

        $out = [];
        foreach ($value as $key => $item) {
            $lower = strtolower((string) $key);

            // 业务码：数值保留，字符串打码
            if ($lower === 'code') {
                $out[$key] = is_int($item) || is_float($item) ? $item : '***';
                continue;
            }

            if (preg_match('/password|secret|token|otp|cookie|authorization|captcha/', $lower) === 1) {
                $out[$key] = is_scalar($item) ? '***(' . strlen((string) $item) . ')' : '***';
                continue;
            }

            $out[$key] = $this->scrub($item, $depth + 1);
        }

        return $out;
    }

    /** @param array<string, mixed> $payload */
    private function trace(string $stage, array $payload): void
    {
        Log::cdifit($this->name() . '.' . $stage, $payload);
    }
}
