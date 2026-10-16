<?php

declare(strict_types=1);

namespace Lolilota\Auth;

/**
 * 账号提供方接口
 *
 * 目前实现两个：
 *   - CdifitProvider 对接 Cdifit 账号系统（四个会话接口）
 *   - LocalProvider  本地账号（后端无法连外网 / Cdifit 未配置时可用）
 *
 * 想接别的账号系统，只要实现这个接口并注册到 Auth 门面。
 */
interface AuthProviderInterface
{
    /** 提供方标识，如 cdifit / local */
    public function name(): string;

    /** 面向用户的显示名 */
    public function label(): string;

    /** 该提供方是否已具备可用配置（缺配置时前端不展示） */
    public function isConfigured(): bool;

    /**
     * 密码登录
     *
     * @param array<string, mixed> $credentials 至少包含 username / password
     */
    public function passwordSignIn(array $credentials): AuthResult;

    /**
     * 完成两步验证
     *
     * @param array<string, mixed> $payload 包含 code 与 challenge 上下文
     */
    public function finishSignInWith2fa(array $payload): AuthResult;

    /**
     * 刷新令牌
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> 新的令牌集合
     */
    public function refreshToken(array $payload): array;

    /**
     * 退出登录（通知远端，失败不应抛异常）
     *
     * @param array<string, mixed> $payload
     */
    public function signOut(array $payload): void;
}
