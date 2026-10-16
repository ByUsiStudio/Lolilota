<?php

declare(strict_types=1);

namespace Lolilota\Auth;

use Lolilota\Core\Config;
use Lolilota\Core\Security;
use Lolilota\Repository\UserRepository;

/**
 * 本地账号提供方
 *
 * 用途：
 *   1. 后端连不上内网 Cdifit 时的兜底登录（本地建号）
 *   2. 单元测试与离线演示
 *
 * 关闭方式：AUTH_PROVIDERS=cdifit
 */
final class LocalProvider implements AuthProviderInterface
{
    private UserRepository $users;

    public function __construct(?UserRepository $users = null)
    {
        $this->users = $users ?? new UserRepository();
    }

    public function name(): string
    {
        return 'local';
    }

    public function label(): string
    {
        return '本地账号';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function passwordSignIn(array $credentials): AuthResult
    {
        $identifier = trim((string) ($credentials['username'] ?? $credentials['email'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');

        if ($identifier === '' || $password === '') {
            throw new AuthException('missing_credentials', '请输入用户名和密码', 422);
        }

        $user = str_contains($identifier, '@')
            ? $this->users->findByEmail($identifier)
            : $this->users->findByUsername($identifier);

        if ($user === null && (bool) Config::get('auth.allow_self_register', false)) {
            // 允许自助注册：直接建号并登录
            $user = $this->users->createLocal($identifier, $password, [
                'email' => str_contains($identifier, '@') ? $identifier : '',
            ]);
        }

        if ($user === null) {
            throw new AuthException('user_not_found', '账号不存在', 401);
        }

        $hash = (string) ($user['password_hash'] ?? '');
        if (!Security::verifyPassword($password, $hash)) {
            throw new AuthException('invalid_credentials', '账号或密码不正确', 401);
        }

        // 算法升级时顺手重算散列
        if (Security::needsRehash($hash)) {
            $this->users->setPassword((string) $user['id'], Security::hashPassword($password));
        }

        return AuthResult::authed($this->publicProfile($user), ['local_session' => true]);
    }

    public function finishSignInWith2fa(array $payload): AuthResult
    {
        throw new AuthException('two_factor_not_supported', '本地账号未启用两步验证', 400);
    }

    public function refreshToken(array $payload): array
    {
        // 本地会话由 SessionManager 负责续期，这里不涉及远端令牌
        return [];
    }

    public function signOut(array $payload): void
    {
        // 本地账号无需通知远端
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function publicProfile(array $user): array
    {
        unset($user['password_hash']);
        $user['provider'] = $this->name();
        return $user;
    }
}
