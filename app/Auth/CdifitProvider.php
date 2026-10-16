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
 * 对接四个会话接口（docs.cdifit.com/zh）：
 *   POST {api_prefix}{session_prefix}/password_sign_in
 *   POST {api_prefix}{session_prefix}/finish_sign_in_with_2fa
 *   POST {api_prefix}{session_prefix}/refresh_token
 *   POST {api_prefix}{session_prefix}/sign_out
 *
 * 设计要点：
 *   1. 请求字段名与响应字段名全部走配置，字段与文档不一致时改 config.php
 *      或设置环境变量即可，不用改代码。
 *   2. 打开 CDIFIT_TRACE=true 会把请求/响应原文写入 var/logs/cdifit.log，
 *      用于核对文档。
 *   3. 两条登录路径都能自动识别：
 *      - Option A：Cdifit 返回令牌，Lolilota 自己管会话
 *      - Option B：Cdifit 用 Cookie 会话，Lolilota 透传底层 Cookie
 */
final class CdifitProvider implements AuthProviderInterface
{
    private HttpClient $http;

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

    // -----------------------------------------------------------------
    // 四个接口
    // -----------------------------------------------------------------

    public function passwordSignIn(array $credentials): AuthResult
    {
        $body = $this->buildPasswordPayload($credentials);

        $response = $this->call('password_sign_in', $body);
        $data = $response['body'];

        $this->trace('password_sign_in.response', [
            'status'   => $response['status'],
            'body'     => $this->scrub($data),
            'cookies'  => array_keys($response['cookies'] ?? []),
        ]);

        return $this->interpret($data, $response);
    }

    public function finishSignInWith2fa(array $payload): AuthResult
    {
        $body = $this->mapFields($payload, [
            'code'         => 'code',
            'challenge_id' => 'challenge_id',
            'ticket'       => 'ticket',
            'identifier'   => 'identifier',
            'remember'     => 'remember',
            'device'       => 'device',
        ]);

        $response = $this->call('finish_sign_in_with_2fa', $body);
        $data = $response['body'];

        $this->trace('finish_sign_in_with_2fa.response', [
            'status' => $response['status'],
            'body'   => $this->scrub($data),
        ]);

        return $this->interpret($data, $response);
    }

    public function refreshToken(array $payload): array
    {
        $refreshToken = (string) ($payload['refresh_token'] ?? '');

        $body = $this->mapFields(
            ['refresh_token' => $refreshToken, 'device' => $payload['device'] ?? null],
            ['refresh_token' => 'refresh_token', 'device' => 'device']
        );

        $response = $this->call('refresh_token', $body);
        $data = $response['body'];

        $this->trace('refresh_token.response', [
            'status' => $response['status'],
            'body'   => $this->scrub($data),
        ]);

        $tokens = $this->extractTokens($data);
        if ($tokens === [] && $response['status'] >= 400) {
            throw new AuthException(
                'refresh_failed',
                $this->extractMessage($data) ?? '刷新登录状态失败，请重新登录',
                401
            );
        }

        return $tokens;
    }

    public function signOut(array $payload): void
    {
        $body = $this->mapFields([
            'refresh_token' => $payload['refresh_token'] ?? null,
            'token'         => $payload['access_token'] ?? null,
        ], ['refresh_token' => 'refresh_token', 'token' => 'token']);

        // 退出登录属于“尽力而为”：远端失败不阻塞本地登出
        try {
            $response = $this->call('sign_out', $body, false);
            $this->trace('sign_out.response', [
                'status' => $response['status'],
                'body'   => $this->scrub($response['body']),
            ]);
        } catch (HttpException $e) {
            Log::warn('Cdifit 退出登录调用失败，已忽略', [
                'code'    => $e->errorCode,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // -----------------------------------------------------------------
    // 请求构造
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    private function buildPasswordPayload(array $credentials): array
    {
        $identifier = trim((string) ($credentials['username'] ?? $credentials['email'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');

        $body = $this->mapFields([
            'identifier' => $identifier,
            'password'   => $password,
            'remember'   => $credentials['remember'] ?? null,
            'device'     => $credentials['device'] ?? 'lolilota-web',
            'code'       => $credentials['code'] ?? null,
        ], [
            'identifier' => 'identifier',
            'password'   => 'password',
            'remember'   => 'remember',
            'device'     => 'device',
            'code'       => 'code',
        ]);

        // 部分实现要求同时提交 email 与 username，配置 CDIFIT_F_IDENTIFIER=email 即可覆盖
        if ($identifier !== '' && !str_contains($identifier, '@')) {
            $body['username'] = $body['username'] ?? $identifier;
        }

        return $body;
    }

    /**
     * 按配置把内部字段名映射为接口字段名
     *
     * @param array<string, mixed> $values  内部键 => 值
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

    /**
     * 发起调用并统一处理传输层错误
     *
     * @param array<string, mixed> $body
     * @return array{status:int, body:mixed, raw:string, headers:array<string,string>, cookies:array<string,string>}
     */
    private function call(string $endpointKey, array $body, bool $throwOnHttpError = true): array
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
            'url'      => $url,
            'body'     => $this->scrub($body),
            'auth'     => (string) Config::get('cdifit.auth.type', 'bearer'),
        ]);

        $result = $this->post($url, $body);

        if ($throwOnHttpError && $result['status'] >= 500) {
            throw new HttpException(
                'cdifit_server_error',
                'Cdifit 服务返回 ' . $result['status'] . '，请稍后再试',
                502,
                ['endpoint' => $endpointKey]
            );
        }

        return $result;
    }

    /**
     * 按配置的认证方式发请求
     *
     * @param array<string, mixed> $body
     * @return array{status:int, body:mixed, raw:string, headers:array<string,string>, cookies:array<string,string>}
     */
    private function post(string $url, array $body): array
    {
        $authType = (string) Config::get('cdifit.auth.type', 'bearer');
        $token = (string) Config::get('cdifit.auth.token', '');
        $headers = (array) Config::get('cdifit.default_headers', []);

        if ($authType === 'bearer' && $token !== '') {
            $scheme = (string) Config::get('cdifit.auth.scheme', 'Bearer');
            $headers[(string) Config::get('cdifit.auth.header', 'Authorization')] = $scheme . ' ' . $token;
        } elseif ($authType === 'api_key_header' && $token !== '') {
            $headers[(string) Config::get('cdifit.auth.header', 'X-Api-Key')] = $token;
        } elseif ($authType === 'query' && $token !== '') {
            $key = (string) Config::get('cdifit.auth.query_key', 'access_token');
            $url .= (str_contains($url, '?') ? '&' : '?') . rawurlencode($key) . '=' . rawurlencode($token);
        }

        try {
            $result = $this->http->postJson($url, $body, $headers);
        } catch (HttpException $e) {
            throw new AuthException($e->errorCode, $e->getMessage(), 502, $e->details);
        }

        // 收集响应 Cookie：支持 Cdifit 用 Cookie 会话的场景
        $cookies = [];
        foreach ($result['headers'] as $name => $value) {
            if ($name === 'set-cookie') {
                $pair = explode(';', (string) $value, 2)[0];
                $pos = strpos($pair, '=');
                if ($pos !== false) {
                    $cookies[trim(substr($pair, 0, $pos))] = trim(substr($pair, $pos + 1));
                }
            }
        }

        $result['cookies'] = $cookies;

        return $result;
    }

    private function endpointUrl(string $endpointKey): string
    {
        $base = rtrim((string) Config::get('cdifit.base_url', ''), '/');
        $apiPrefix = '/' . trim((string) Config::get('cdifit.api_prefix', '/api'), '/');
        $sessionPrefix = '/' . trim((string) Config::get('cdifit.session_prefix', '/session'), '/');
        $endpoint = (string) Config::get('cdifit.endpoints.' . $endpointKey, '/' . $endpointKey);

        return $base . $apiPrefix . $sessionPrefix . $endpoint;
    }

    // -----------------------------------------------------------------
    // 响应解析
    // -----------------------------------------------------------------

    /**
     * 把 Cdifit 的响应翻译成统一的 AuthResult
     *
     * @param mixed $data 已解码的响应体
     * @param array<string, mixed> $response 完整响应（含状态码、Cookie）
     */
    private function interpret(mixed $data, array $response): AuthResult
    {
        $status = (int) $response['status'];

        if (!is_array($data)) {
            if ($status >= 200 && $status < 300) {
                // 2xx 但没有 JSON：可能是纯 Cookie 会话
                if (!empty($response['cookies'])) {
                    $user = $this->fallbackUser($response);
                    return AuthResult::authed($user, ['cookie_session' => true], []);
                }

                throw new AuthException(
                    'unexpected_response',
                    'Cdifit 返回了非 JSON 内容，请打开 CDIFIT_TRACE 核对接口',
                    502,
                    ['status' => $status, 'raw' => substr((string) $response['raw'], 0, 300)]
                );
            }

            throw new AuthException('sign_in_failed', '登录失败，请稍后再试', 401, ['status' => $status]);
        }

        if ($status >= 400) {
            throw $this->failure($data, $status);
        }

        $businessCode = Arr::first($data, (array) Config::get('cdifit.response_fields.code', ['code']));

        // 业务码提示需要两步验证
        $twoFactorCodes = (array) Config::get('cdifit.two_factor_codes', []);
        if (is_scalar($businessCode) && in_array((string) $businessCode, $twoFactorCodes, true)) {
            return AuthResult::needsTwoFactor($this->extractChallenge($data), $data);
        }

        $tokens = $this->extractTokens($data);
        $user = $this->extractUser($data, $response);

        if ($tokens !== []) {
            $user['provider'] = $this->name();
            return AuthResult::authed($user, $tokens, $data);
        }

        // 需要两步验证：响应里带了 challenge
        $challenge = $this->extractChallenge($data);
        $requires = Arr::first($data, (array) Config::get('cdifit.response_fields.requires_2fa', []));
        if ($requires === true || $challenge !== []) {
            return AuthResult::needsTwoFactor($challenge, $data);
        }

        // 没有任何凭据也没有 challenge：Cdifit 可能用 Cookie 会话
        if (!empty($response['cookies'])) {
            return AuthResult::authed($user, ['cookie_session' => true], $data);
        }

        throw new AuthException(
            'unexpected_response',
            $this->extractMessage($data) ?? '无法识别 Cdifit 的登录响应，请打开 CDIFIT_TRACE 核对字段',
            502,
            ['keys' => array_keys($data)]
        );
    }

    /** @param array<string, mixed> $data */
    private function failure(array $data, int $status): AuthException
    {
        $code = (string) (Arr::first($data, (array) Config::get('cdifit.response_fields.code', ['code'])) ?? 'sign_in_failed');
        $message = $this->extractMessage($data);

        if ($status === 429) {
            return new AuthException('too_many_requests', $message ?? '尝试过于频繁，请稍后再试', 429);
        }

        if ($status === 401 || $status === 403) {
            return new AuthException(
                $code !== '' ? $code : 'invalid_credentials',
                $message ?? '账号或密码不正确',
                401
            );
        }

        return new AuthException($code, $message ?? ('Cdifit 返回错误（HTTP ' . $status . '）'), 400, $data);
    }

    /** @param array<string, mixed> $data */
    private function extractMessage(array $data): ?string
    {
        $message = Arr::first($data, (array) Config::get('cdifit.response_fields.message', ['message', 'msg']));
        return is_scalar($message) ? (string) $message : null;
    }

    /**
     * 提取令牌
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function extractTokens(array $data): array
    {
        $fields = (array) Config::get('cdifit.response_fields', []);
        $tokens = [];

        $access = Arr::first($data, (array) ($fields['access_token'] ?? ['access_token']));
        if (is_scalar($access) && (string) $access !== '') {
            $tokens['access_token'] = (string) $access;
        }

        $refresh = Arr::first($data, (array) ($fields['refresh_token'] ?? ['refresh_token']));
        if (is_scalar($refresh) && (string) $refresh !== '') {
            $tokens['refresh_token'] = (string) $refresh;
        }

        $expiresIn = Arr::first($data, (array) ($fields['expires_in'] ?? ['expires_in']));
        if (is_numeric($expiresIn)) {
            $tokens['expires_in'] = (int) $expiresIn;
        }

        $expiresAt = Arr::first($data, (array) ($fields['expires_at'] ?? ['expires_at']));
        if (is_numeric($expiresAt)) {
            $tokens['expires_at'] = (int) $expiresAt;
        }

        return $tokens;
    }

    /**
     * 提取用户档案
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function extractUser(array $data, array $response = []): array
    {
        $fields = (array) Config::get('cdifit.response_fields', []);

        // 先看嵌套的 user 对象
        $node = null;
        foreach ((array) ($fields['user'] ?? ['user']) as $path) {
            $candidate = Arr::get($data, (string) $path);
            if (is_array($candidate)) {
                $node = $candidate;
                break;
            }
        }

        $source = $node ?? $data;

        $uid = Arr::first($source, (array) ($fields['user_id'] ?? ['id', 'uid']));
        if (($uid === null || $uid === '') && $node === null) {
            // 顶层没有 id，说明响应是扁平结构，回落到 data 本身
            $uid = Arr::first($data, (array) ($fields['user_id'] ?? ['id', 'uid']));
        }

        $username = Arr::first($source, (array) ($fields['username'] ?? ['username']));
        $email = Arr::first($source, (array) ($fields['email'] ?? ['email']));
        $avatar = Arr::first($source, (array) ($fields['avatar'] ?? ['avatar_url']));

        $user = Arr::compact([
            'id'         => is_scalar($uid) ? (string) $uid : null,
            'username'   => is_scalar($username) ? (string) $username : null,
            'email'      => is_scalar($email) ? (string) $email : null,
            'nickname'   => is_scalar($username) ? (string) $username : null,
            'avatar_url' => is_scalar($avatar) ? (string) $avatar : null,
        ]);

        // 没有 id 时用邮箱/用户名派生一个稳定标识
        if (empty($user['id'])) {
            $seed = (string) ($user['email'] ?? $user['username'] ?? '');
            if ($seed !== '') {
                $user['id'] = 'cdifit_' . substr(hash('sha256', $seed), 0, 16);
            }
        }

        if ($user === [] && $response !== []) {
            return $this->fallbackUser($response);
        }

        return $user;
    }

    /**
     * Cdifit 只回 Cookie 会话、没给用户信息时的兜底
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function fallbackUser(array $response): array
    {
        return [
            'id'       => 'cdifit_session_' . substr(hash('sha256', (string) ($response['raw'] ?? '')), 0, 16),
            'username' => null,
            'email'    => null,
            'nickname' => 'Cdifit 用户',
            'source'   => 'cookie_session',
        ];
    }

    /**
     * 提取两步验证上下文
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function extractChallenge(array $data): array
    {
        $fields = (array) Config::get('cdifit.response_fields', []);

        $challengeId = Arr::first($data, (array) ($fields['challenge_id'] ?? ['challenge_id']));
        $ticket = Arr::first($data, (array) ($fields['ticket'] ?? ['ticket']));
        $methods = Arr::first($data, (array) ($fields['methods'] ?? ['methods']), []);
        $hint = Arr::first($data, (array) ($fields['hint'] ?? ['hint']));

        $challenge = Arr::compact([
            'challenge_id' => is_scalar($challengeId) ? (string) $challengeId : null,
            'ticket'       => is_scalar($ticket) ? (string) $ticket : null,
            'hint'         => is_scalar($hint) ? (string) $hint : null,
        ]);

        if (is_array($methods) && $methods !== []) {
            $challenge['methods'] = array_values(array_filter(array_map(
                static fn ($m) => is_scalar($m) ? (string) $m : null,
                $methods
            )));
        }

        return $challenge;
    }

    // -----------------------------------------------------------------
    // 日志脱敏
    // -----------------------------------------------------------------

    /**
     * 递归抹掉敏感字段，只保留结构用于核对字段名
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
            if (preg_match('/password|secret|token|code|otp|cookie|authorization/i', $lower) === 1) {
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
