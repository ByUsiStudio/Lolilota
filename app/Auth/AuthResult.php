<?php

declare(strict_types=1);

namespace Lolilota\Auth;

/**
 * 登录结果
 *
 * 三种情形互斥：
 *   - authed：拿到了会话，登录完成
 *   - requiresTwoFactor：还需要提交验证码
 *   - 其余情况由异常表达
 */
final class AuthResult
{
    public const AUTHED = 'authed';
    public const NEEDS_2FA = 'requires_2fa';

    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed>      $tokens
     * @param array<string, mixed>|null $challenge
     * @param array<string, mixed>      $raw
     */
    private function __construct(
        public readonly string $status,
        public readonly ?array $user = null,
        public readonly array $tokens = [],
        public readonly ?array $challenge = null,
        public readonly array $raw = []
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $tokens
     * @param array<string, mixed> $raw
     */
    public static function authed(array $user, array $tokens, array $raw = []): self
    {
        return new self(self::AUTHED, $user, $tokens, null, $raw);
    }

    /**
     * @param array<string, mixed> $challenge
     * @param array<string, mixed> $raw
     */
    public static function challenge(array $challenge, array $raw = []): self
    {
        return new self(self::NEEDS_2FA, null, [], $challenge, $raw);
    }

    public function isAuthed(): bool
    {
        return $this->status === self::AUTHED;
    }

    public function needsTwoFactor(): bool
    {
        return $this->status === self::NEEDS_2FA;
    }

    /** 供调试与字段核对使用（已剔除敏感值） */
    public function summary(): array
    {
        return [
            'status'    => $this->status,
            'user_keys' => $this->user !== null ? array_keys($this->user) : [],
            'token_keys' => array_keys($this->tokens),
            'challenge' => $this->challenge,
        ];
    }
}
