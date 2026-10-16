<?php

declare(strict_types=1);

namespace Lolilota\Controller;

use Lolilota\Auth\Auth;
use Lolilota\Auth\AuthException;
use Lolilota\Core\Config;
use Lolilota\Support\Http;
use Lolilota\Support\Json;
use Lolilota\Support\Log;

/**
 * 账号 / 会话接口
 *
 * 对应前端 api.js 里的方法：
 *   GET  config                          服务与提供方信息
 *   GET  health                          健康检查
 *   GET  session                         当前登录状态
 *   POST session/password_sign_in        密码登录
 *   POST session/finish_sign_in_with_2fa 完成两步验证
 *   POST session/refresh_token           刷新会话
 *   POST session/sign_out                退出登录
 */
final class AuthController
{
    public function __construct(private readonly Auth $auth)
    {
    }

    /** GET config */
    public function config(): never
    {
        $providers = $this->auth->providerInfo();
        $default = (string) Config::get('auth.default', 'cdifit');

        // 默认提供方不可用时，前端展示第一个可用的
        $usable = array_values(array_filter($providers, static fn (array $p) => (bool) $p['configured']));

        Json::ok([
            'app'             => (string) Config::get('app.name', 'Lolilota'),
            'version'         => (string) Config::get('app.version', '1.1.0'),
            'providers'       => $providers,
            'default_provider' => $default,
            'login_available' => $usable !== [],
            'history'         => [
                'max'      => (int) Config::get('history.max_per_user', 500),
                'push_max' => (int) Config::get('history.max_push', 200),
            ],
            'storage'         => (string) Config::get('storage.driver', 'json'),
            'php'             => PHP_VERSION,
        ]);
    }

    /** GET health */
    public function health(): never
    {
        $provider = $this->auth->providers()['cdifit'] ?? null;

        Json::ok([
            'name'      => (string) Config::get('app.name', 'Lolilota'),
            'version'   => (string) Config::get('app.version', '1.1.0'),
            'status'    => 'ok',
            'time'      => time(),
            'php'       => PHP_VERSION,
            'storage'   => (string) Config::get('storage.driver', 'json'),
            'accounts'  => $this->auth->users()->count(),
            'sessions'  => $this->auth->sessions()->activeCount(),
            'cdifit'    => [
                'configured' => $provider !== null && $provider->isConfigured(),
                'base_url'   => (string) Config::get('cdifit.base_url', ''),
                'trace'      => (bool) Config::get('log.cdifit_trace', false),
            ],
        ]);
    }

    /** GET session */
    public function session(): never
    {
        $user = $this->auth->currentUser();

        if ($user === null) {
            Json::ok([
                'authenticated' => false,
                'providers'     => $this->auth->providerInfo(),
            ]);
        }

        Json::ok([
            'authenticated' => true,
            'user'          => $user,
            'provider'      => $user['provider'] ?? null,
            'via'           => $user['via'] ?? null,
        ]);
    }

    /** POST session/password_sign_in */
    public function passwordSignIn(): never
    {
        $input = Http::json();
        $username = trim((string) ($input['username'] ?? $input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($username === '' || $password === '') {
            Json::error('missing_credentials', '请输入账号和密码', 422);
        }

        try {
            $result = $this->auth->login([
                'username' => $username,
                'password' => $password,
                'remember' => (bool) ($input['remember'] ?? true),
                'device'   => (string) ($input['device'] ?? 'lolilota-web'),
                'code'     => $input['code'] ?? null,
            ], $this->providerName($input));

            Json::ok($this->normalizeResult($result));
        } catch (AuthException $e) {
            Json::error($e->errorCode, $e->getMessage(), $e->status, $e->details);
        }
    }

    /** POST session/finish_sign_in_with_2fa */
    public function finishSignInWith2fa(): never
    {
        $input = Http::json();
        $code = trim((string) ($input['code'] ?? ''));

        if ($code === '') {
            Json::error('missing_code', '请输入验证码', 422);
        }

        try {
            $result = $this->auth->loginWith2fa([
                'code'         => $code,
                'challenge_id' => $input['challenge_id'] ?? null,
                'ticket'       => $input['ticket'] ?? null,
                'identifier'   => $input['identifier'] ?? null,
                'remember'     => (bool) ($input['remember'] ?? true),
                'device'       => (string) ($input['device'] ?? 'lolilota-web'),
            ], $this->providerName($input));

            Json::ok($this->normalizeResult($result));
        } catch (AuthException $e) {
            Json::error($e->errorCode, $e->getMessage(), $e->status, $e->details);
        }
    }

    /** POST session/refresh_token */
    public function refreshToken(): never
    {
        $input = Http::json();

        try {
            $result = $this->auth->refresh($input['refresh_token'] ?? null);
            Json::ok($this->normalizeResult($result));
        } catch (AuthException $e) {
            Json::error($e->errorCode, $e->getMessage(), $e->status, $e->details);
        }
    }

    /** POST session/sign_out */
    public function signOut(): never
    {
        $input = Http::json();

        try {
            $this->auth->logout([
                'refresh_token' => $input['refresh_token'] ?? null,
                'access_token'  => Http::bearerToken(),
            ]);
        } catch (\Throwable $e) {
            // 远端通知失败不影响本地登出
            Log::warn('退出登录时发生异常', ['message' => $e->getMessage()]);
        }

        Json::ok(['authenticated' => false]);
    }

    /** @param array<string, mixed> $input */
    private function providerName(array $input): ?string
    {
        $name = $input['provider'] ?? null;
        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * 统一响应形状，前端 auth.js 依赖：
     *   { status, authenticated, access_token, refresh_token, expires_at, user, challenge }
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function normalizeResult(array $result): array
    {
        $status = (string) ($result['status'] ?? 'authed');

        if ($status === 'requires_2fa') {
            return [
                'status'       => 'requires_2fa',
                'requires_2fa' => true,
                'provider'     => $result['provider'] ?? null,
                'challenge_id' => $result['challenge']['challenge_id'] ?? null,
                'ticket'       => $result['challenge']['ticket'] ?? null,
                'methods'      => $result['challenge']['methods'] ?? [],
                'hint'         => $result['challenge']['hint'] ?? null,
                'identifier'   => $result['identifier'] ?? null,
            ];
        }

        unset($result['status'], $result['cdifit']);

        $result['authenticated'] = true;
        $result['status'] = 'authed';

        return $result;
    }
}
