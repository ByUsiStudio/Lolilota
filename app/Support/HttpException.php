<?php

declare(strict_types=1);

namespace Lolilota\Support;

/**
 * 对外调用（Cdifit）失败时抛出的异常
 *
 * 与业务异常区分开：这类错误应该以 502/503 返回，并带上可读原因。
 */
final class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 0,
        public readonly mixed $details = null
    ) {
        parent::__construct($message, $status);
    }
}
