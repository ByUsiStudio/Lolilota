<?php

declare(strict_types=1);

namespace Lolilota\Less;

/**
 * Less 编译错误
 *
 * 带上文件名与行号，方便直接定位源码。
 */
final class LessException extends \RuntimeException
{
}
