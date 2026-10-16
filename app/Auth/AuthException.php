<?php

declare(strict_types=1);

namespace Lolilota\Auth;

/**
 * 认证流程中的可预期错误（账号密码错误、验证码错误等）
 */
final class AuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly mixed $details = null
    ) {
        parent::__construct($message, $status);
    }
}
