<?php

declare(strict_types=1);

namespace Lolilota\Auth;

use Lolilota\Core\Config;
use Lolilota\Core\SessionManager;
use Lolilota\Repository\UserRepository;
use Lolilota\Support\Http;
use Lolilota\Support\Log;

/**
 * 认证门面
 *
 * 上层（控制器）只跟它打交道：它负责挑选 Provider、把 Provider 的结果
 * 换成 Lolilota 自己的会话（Cookie + Bearer 令牌）。
 */
final class Auth
{
    /** @var array<string, AuthProviderInterface> */
    private array $providers = [];

    private UserRepository $users;
    private SessionManager $sessions;

    public function __construct(?UserRepository $users = null, ?SessionManager $sessions = null)
    {
        $this->users = $users ?? new UserRepository();
        $this->sessions = $sessions ?? new SessionManager();

        $this->providers['cdifit'] = new CdifitProvider();
        $this->providers['local'] = new LocalProvider($this->users);
    }

    public function sessions(): SessionManager
    {
        return $this->sessions;
    }

    public function users(): UserRepository
    {
        return $this->users;
    }

    /** @return array<string, AuthProviderInterface> */
    public function providers(): array
    {
        $enabled = (array) Config::get('auth.providers', ['cdifit', 'local']);
        $out = [];

        foreach ($enabled as $name) {
            if (isset($this->providers[$name])) {
                $out[$name] = $this->providers[$name];
            }
        }

        return $out;
    }

    /** 前端展示用的提供方列表 */
    public function providerInfo(): array
    {
        $info = [];
        foreach ($this->providers() as $name => $provider) {
            $info[] = [
                'name'        => $name,
                'label'       => $provider->label(),
                'configured'  => $provider->isConfigured(),
                'supports_2fa' => $name !== 'local',
            ];
        }
        return $info;
    }

    public function provider(?string $name = null): AuthProviderInterface
    {
        $name = $name ?: (string) Config::get('auth.default', 'cdifit');
        $available = $this->providers();

        if (!isset($available[$name])) {
            // 指定的提供方不可用时，退回到第一个可用项
            $name = array_key_first($available) ?? 'local';
        }

        if (!isset($available[$name])) {
            throw new AuthException('no_provider', '后端没有可用的账号提供方', 503);
        }

        return $available[$name];
    }

    /** 当前请求对应的用户（未登录返回 null） */
    public function currentUser(): ?array
    {
        $identity = $this->sessions->resolve();
        if ($identity === null) {
            return null;
        }

        $user = $this->users->find($identity['uid']);
        if ($user === null) {
            return null;
        }

        unset($user['password_hash']);
        $user['provider'] = $identity['provider'];
        $user['via'] = $identity['via'];

        return $this->decorateUser($user, $identity['provider']);
    }

    /**
     * 密码登录：成功后建立 Lolilota 会话；需要两步验证时返回 challenge
     *
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function login(array $credentials, ?string $providerName = null): array
    {
        $provider = $this->provider($providerName);
        $this->assertRateLimit($credentials);

        $result = $provider->passwordSignIn($credentials);

        if ($result->needsTwoFactor()) {
            return [
                'status'      => 'requires_2fa',
                'provider'    => $provider->name(),
                'challenge'   => $result->challenge,
                'identifier'  => (string) ($credentials['username'] ?? $credentials['email'] ?? ''),
            ];
        }

        return $this->establish($provider->name(), $result);
    }

    /**
     * 完成两步验证
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function loginWith2fa(array $payload, ?string $providerName = null): array
    {
        $provider = $this->provider($providerName);
        $result = $provider->finishSignInWith2fa($payload);

        if ($result->needsTwoFactor()) {
            // 仍然需要验证：通常是验证码错误
            throw new AuthException('invalid_code', '验证码不正确，请重新输入', 401, $result->challenge);
        }

        return $this->establish($provider->name(), $result);
    }

    /**
     * 把 Provider 的登录结果落地成本地账号 + 会话
     *
     * @return array<string, mixed>
     */
    private function establish(string $providerName, AuthResult $result): array
    {
        $profile = $result->user ?? [];
        $uid = (string) ($profile['id'] ?? '');

        if ($uid === '') {
            throw new AuthException('missing_user_id', '账号信息缺少唯一标识，无法建立会话', 502);
        }

        if ($this->users->find($uid) === null && !(bool) Config::get('auth.auto_provision', true)) {
            throw new AuthException('user_not_provisioned', '该账号尚未在本站开通', 403);
        }

        $stored = $this->users->upsert($uid, array_merge($profile, ['provider' => $providerName]));
        unset($stored['password_hash']);
        $stored = $this->decorateUser($stored, $providerName);

        $this->users->touchLogin($uid, [
            'ip'       => Http::ip(),
            'provider' => $providerName,
        ]);

        $session = $this->sessions->issue($uid, $providerName, $stored);

        // 把上游（Cdifit）的令牌记在服务端，用于会话续期与退出登录撤销。
        // 这些令牌不下发给前端，避免泄露；前端只拿到 Lolilota 自己的令牌。
        $provider = $this->providers()[$providerName] ?? null;
        if ($provider instanceof CdifitProvider) {
            $tokens = $provider->lastTokens();

            if ($tokens !== []) {
                $this->sessions->setProviderTokens($this->sessions->currentSessionId(), $tokens);
            }
        }

        Log::info('登录成功', [
            'uid'      => $uid,
            'provider' => $providerName,
            'ip'       => Http::ip(),
        ]);

        return array_merge($session, [
            'status'     => 'authed',
            'user'       => $stored,
            'provider'   => $providerName,
            'cdifit'     => $providerName === 'cdifit'
                ? ['tokens' => $this->summarizeTokens($provider instanceof CdifitProvider ? $provider->lastTokens() : [])]
                : null,
        ]);
    }

    /**
     * 刷新会话
     *
     * 顺序：
     *   1. 前端 / 扩展传来 refresh_token 时，换发 Lolilota 自己的会话
     *   2. 没有可用凭据时，若当前请求本身是已登录状态（Cookie 或 Bearer），直接续签
     *
     * 注意：前端拿到的是 Lolilota 的令牌，不会接触 Cdifit 的令牌。
     *
     * @return array<string, mixed>
     */
    public function refresh(?string $refreshToken = null): array
    {
        if ($refreshToken !== null && $refreshToken !== '') {
            $refreshed = $this->sessions->refresh($refreshToken);
            if ($refreshed !== null) {
                $uid = (string) ($refreshed['uid'] ?? ($refreshed['user']['id'] ?? ''));
                unset($refreshed['uid']);

                $user = $uid !== '' ? $this->users->find($uid) : null;

                if (is_array($user)) {
                    unset($user['password_hash']);
                    $refreshed['user'] = $this->decorateUser($user, $refreshed['provider'] ?? null);
                }

                return $refreshed;
            }
        }

        $identity = $this->sessions->resolve();

        if ($identity === null) {
            throw new AuthException('not_authenticated', '登录状态已失效，请重新登录', 401);
        }

        $user = $this->users->find($identity['uid']);
        if ($user === null) {
            throw new AuthException('not_authenticated', '账号不存在，请重新登录', 401);
        }

        unset($user['password_hash']);

        return $this->sessions->issue($identity['uid'], $identity['provider'], $this->decorateUser($user, $identity['provider']));
    }

    /**
     * 补齐前端展示用的字段（目前是 Cdifit 头像地址）
     *
     * Cdifit 登录响应里的 avatar 往往只是 "file" 这样的类型标识，
     * 真正的图片在 GET /api/v4/user/avatar/{id}。这里统一改写成
     * 本站的代理地址，前端直接当 <img src> 用，不接触上游域名与令牌。
     *
     * 只做返回值层面的补充，不写回账号仓库。
     */
    private function decorateUser(array $user, ?string $provider = null): array
    {
        $provider = strtolower((string) ($provider ?: ($user['provider'] ?? '')));
        if ($provider !== '') {
            $user['provider'] = $provider;
        }

        if ($provider !== 'cdifit') {
            return $user;
        }

        // 上游已经给了可直接访问的头像地址时优先沿用
        if ((string) ($user['avatar_url'] ?? '') !== '') {
            return $user;
        }

        $uid = (string) ($user['id'] ?? '');

        // cdifit_ 前缀是按邮箱派生的兜底 ID，不能拿去调头像接口
        if ($uid === '' || str_starts_with($uid, 'cdifit_')) {
            return $user;
        }

        $user['avatar_url'] = Http::origin() . '/api/v1/avatar/' . rawurlencode($uid);

        return $user;
    }

    /**
     * 退出登录：先通知 Cdifit 撤销上游 refresh token，再销毁本地会话
     *
     * @param array<string, mixed> $context
     */
    public function logout(array $context = []): void
    {
        $identity = $this->sessions->resolve();

        if ($identity !== null) {
            $provider = $this->providers()[$identity['provider']] ?? null;

            if ($provider !== null) {
                $sid = $identity['session_id'];

                // Cdifit 的退出登录需要 access token，并且会撤销该根令牌下的所有 refresh token
                $provider->signOut([
                    'access_token'  => $this->sessions->providerAccessToken($sid) ?? ($context['access_token'] ?? null),
                    'refresh_token' => $this->sessions->providerRefreshToken($sid) ?? ($context['refresh_token'] ?? null),
                ]);
            }

            Log::info('退出登录', ['uid' => $identity['uid']]);
        }

        $this->sessions->destroy();
    }

    /** 简单的登录频率限制：同一 IP 15 分钟内最多 20 次失败 */
    private function assertRateLimit(array $credentials): void
    {
        $key = 'lolilota_login_fail_' . md5(Http::ip());
        $now = time();
        $window = 900;
        $limit = 20;

        $record = $_SESSION[$key] ?? ['count' => 0, 'first' => $now];
        if (!is_array($record) || ($now - (int) ($record['first'] ?? $now)) > $window) {
            $record = ['count' => 0, 'first' => $now];
        }

        if ((int) $record['count'] >= $limit) {
            throw new AuthException('too_many_requests', '尝试次数过多，请 15 分钟后再试', 429);
        }

        // 记一次尝试；成功后由 establish 之外的调用方决定是否清零
        $record['count'] = (int) $record['count'] + 1;
        $_SESSION[$key] = $record;
    }

    /**
     * 把上游令牌整理成「只用于展示的指纹」，不返回真实令牌值
     *
     * @param array<string, mixed> $tokens
     * @return array<string, mixed>
     */
    private function summarizeTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $key => $value) {
            if (in_array($key, ['expires_at', 'refresh_expires_at'], true)) {
                $out[$key] = $value;
                continue;
            }
            $out[$key] = is_scalar($value) ? substr(hash('sha256', (string) $value), 0, 8) : gettype($value);
        }
        return $out;
    }
}
