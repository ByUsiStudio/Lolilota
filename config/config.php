<?php
/**
 * Lolilota · 配置
 *
 * 优先级：环境变量 > 本文件默认值。
 * 生产部署时不要把密钥写进本文件，改用 .env 或服务器环境变量。
 *
 * 关于 Cdifit：接口地址与字段映射全部可配置，
 * 拿到 docs.cdifit.com/zh 的字段说明后只需改这里，不用改代码。
 */

declare(strict_types=1);

/** 读取环境变量，支持字符串 / 布尔 / 整数 */
$env = static function (string $key, mixed $default = null): mixed {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    $lower = strtolower((string) $value);
    return match ($lower) {
        'true', '(true)'   => true,
        'false', '(false)' => false,
        'null'             => null,
        default            => is_numeric($value) && !str_contains((string) $value, '.')
            ? (int) $value
            : $value,
    };
};

return [
    // ---- 应用 ----------------------------------------------------------
    'app' => [
        'name'     => 'Lolilota',
        'version'  => '1.1.0',
        'timezone' => $env('APP_TIMEZONE', 'Asia/Shanghai'),
        // 调试模式：true 时接口错误会带上堆栈信息（仅限本地开发）
        'debug'    => (bool) $env('APP_DEBUG', false),
        // 允许的前端来源；同源部署留空即可，扩展形态需要显式列出
        'origins'  => array_filter(array_map('trim', explode(',', (string) $env('APP_ORIGINS', '')))),
    ],

    // ---- 会话 ----------------------------------------------------------
    'session' => [
        'name'        => 'lolilota_sid',
        // 网页端 Cookie 会话 + 扩展端 Bearer 令牌双通道
        'ttl'         => (int) $env('SESSION_TTL', 60 * 60 * 24 * 14),
        'refresh_ttl' => (int) $env('SESSION_REFRESH_TTL', 60 * 60 * 24 * 30),
    ],

    // ---- 存储 ----------------------------------------------------------
    'storage' => [
        // json 文件存储：无需数据库扩展，适合单机 / 虚拟主机部署
        'driver' => $env('STORAGE_DRIVER', 'json'),
        'path'   => LOLILOTA_VAR . '/store',
    ],

    // ---- 日志 ----------------------------------------------------------
    'log' => [
        'file'  => LOLILOTA_VAR . '/logs/app.log',
        // 打开后会把 Cdifit 的请求与响应原文写进 var/logs/cdifit.log
        // 用途：核对文档字段、排查映射问题。含敏感信息，排查完请关掉。
        'cdifit_trace' => (bool) $env('CDIFIT_TRACE', false),
        'cdifit_file'  => LOLILOTA_VAR . '/logs/cdifit.log',
    ],

    // ---- 账号系统 ------------------------------------------------------
    'auth' => [
        // 可用的登录方式，按顺序展示给前端：cdifit | local
        'providers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $env('AUTH_PROVIDERS', 'cdifit,local'))
        ))),
        // 默认使用哪个 Provider 完成登录
        'default'   => $env('AUTH_DEFAULT', 'cdifit'),
        // 是否允许新用户通过登录自动创建本地账号记录
        'auto_provision' => (bool) $env('AUTH_AUTO_PROVISION', true),
        // 仅本地 Provider 使用：是否需要管理员先建号
        'allow_self_register' => (bool) $env('AUTH_ALLOW_SELF_REGISTER', false),
    ],

    // ---- Cdifit 适配器 -------------------------------------------------
    'cdifit' => [
        // 服务地址与接口前缀（四个会话接口都挂在下面）
        'base_url'      => $env('CDIFIT_BASE_URL', 'https://www.cdifit.cn'),
        'api_prefix'    => $env('CDIFIT_API_PREFIX', '/api'),
        'session_prefix' => $env('CDIFIT_SESSION_PREFIX', '/session'),

        // 四个接口路径，可在不改代码的情况下对齐文档
        'endpoints' => [
            'password_sign_in'        => $env('CDIFIT_EP_PASSWORD', '/password_sign_in'),
            'finish_sign_in_with_2fa' => $env('CDIFIT_EP_2FA', '/finish_sign_in_with_2fa'),
            'refresh_token'           => $env('CDIFIT_EP_REFRESH', '/refresh_token'),
            'sign_out'                => $env('CDIFIT_EP_SIGNOUT', '/sign_out'),
        ],

        // 认证方式：bearer（默认）| api_key_header | query | none
        'auth' => [
            'type'        => $env('CDIFIT_AUTH_TYPE', 'bearer'),
            'token'       => $env('CDIFIT_API_TOKEN', ''),
            'header'      => $env('CDIFIT_AUTH_HEADER', 'Authorization'),
            'scheme'      => $env('CDIFIT_AUTH_SCHEME', 'Bearer'),
            'query_key'   => $env('CDIFIT_AUTH_QUERY_KEY', 'access_token'),
        ],

        'timeout'        => (int) $env('CDIFIT_TIMEOUT', 15),
        'verify_tls'     => (bool) $env('CDIFIT_VERIFY_TLS', true),
        'default_headers' => [
            'Accept'     => 'application/json',
            'User-Agent' => 'Lolilota/' . '1.1.0',
        ],

        // 请求字段名映射：右值可被文档修正
        'request_fields' => [
            'identifier'    => $env('CDIFIT_F_IDENTIFIER', 'email'),
            'password'      => $env('CDIFIT_F_PASSWORD', 'password'),
            'code'          => $env('CDIFIT_F_CODE', 'code'),
            'challenge_id'  => $env('CDIFIT_F_CHALLENGE', 'challenge_id'),
            'ticket'        => $env('CDIFIT_F_TICKET', 'ticket'),
            'refresh_token' => $env('CDIFIT_F_REFRESH', 'refresh_token'),
            'remember'      => $env('CDIFIT_F_REMEMBER', 'remember'),
            'device'        => $env('CDIFIT_F_DEVICE', 'device'),
            'token'         => $env('CDIFIT_F_TOKEN', 'token'),
        ],

        // 响应字段名映射：从 Cdifit 响应里取值的候选键（按顺序尝试）
        'response_fields' => [
            'access_token'  => ['access_token', 'accessToken', 'token'],
            'refresh_token' => ['refresh_token', 'refreshToken'],
            'expires_in'    => ['expires_in', 'expiresIn'],
            'expires_at'    => ['expires_at', 'expiresAt'],
            'user'          => ['user', 'account', 'profile', 'data.user'],
            'user_id'       => ['id', 'uid', 'user_id', 'sub'],
            'username'      => ['username', 'name', 'nickname', 'display_name'],
            'email'         => ['email', 'mail'],
            'avatar'        => ['avatar_url', 'avatar', 'picture'],
            'requires_2fa'  => ['requires_2fa', 'requires2fa', 'mfa_required', 'two_factor_required'],
            'challenge_id'  => ['challenge_id', 'challengeId', 'mfa_token', 'challenge'],
            'ticket'        => ['ticket', 'login_ticket', 'pending_token'],
            'methods'       => ['methods', 'factors'],
            'hint'          => ['hint', 'masked_target', 'target'],
            // 业务码：部分接口用 code/message 表示成功与失败
            'code'          => ['code', 'status', 'error_code'],
            'message'       => ['message', 'msg', 'error_description', 'detail'],
        ],

        // 判定“登录成功但没有令牌”之外的失败：这些业务码视为需要两步验证
        'two_factor_codes' => ['mfa_required', '2fa_required', 'requires_2fa', 'totp_required'],
        // 视为成功的业务码（空数组表示不看业务码，只看 HTTP 状态）
        'success_codes' => [],
    ],

    // ---- 历史记录 ------------------------------------------------------
    'history' => [
        'max_per_user' => (int) $env('HISTORY_MAX', 500),
        'max_push'     => (int) $env('HISTORY_PUSH_MAX', 200),
    ],
];
