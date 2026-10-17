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

/**
 * 读取本地部署配置（config/local.php，已被 .gitignore 忽略）
 *
 * 仓库里不出现任何真实服务器地址：本文件只放与代码相关的默认值，
 * 域名、认证服务地址等「跟着部署环境变」的值都放在 config/local.php，
 * 或用同名环境变量覆盖。
 */
$local = static function (): array {
    $file = __DIR__ . '/local.php';
    if (!is_file($file)) {
        return [];
    }

    $data = require $file;
    return is_array($data) ? $data : [];
};

/** 递归合并：$local 覆盖 $defaults，数组则逐键合并 */
$merge = static function (array $defaults, array $local) use (&$merge): array {
    foreach ($local as $key => $value) {
        if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])) {
            $defaults[$key] = $merge($defaults[$key], $value);
            continue;
        }
        $defaults[$key] = $value;
    }
    return $defaults;
};

$localConfig = $local();

$config = [
    // ---- 应用 ----------------------------------------------------------
    'app' => [
        'name'     => 'Lolilota',
        'version'  => '1.1.0',
        'timezone' => $env('APP_TIMEZONE', 'Asia/Shanghai'),
        // 调试模式：true 时接口错误会带上堆栈信息（仅限本地开发）
        'debug'    => (bool) $env('APP_DEBUG', false),
        // 站点首页 / 扩展主页（必须是绝对 URL）。
        // 默认是 Lolilota 官方站点；自建部署可用 config/local.php 的
        // app.homepage 或环境变量 APP_HOMEPAGE 覆盖。
        'homepage' => $env('APP_HOMEPAGE', 'https://lolilota.520world.top/'),
        // 允许的前端来源；同源部署留空即可，扩展形态会自动放行扩展来源
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
    // Cdifit 是 Cloudreve 的接口封装，有两点必须注意：
    //   1. 路由前缀是 /api/v4/，四个会话接口都挂在 /session/token 下；
    //   2. HTTP 状态码固定为 200，真正的结果由 JSON 里的 code 表示：
    //        0   = 成功
    //        203 = 需要两步验证（data 是 2FA session id）
    //        40020 / 40021 / 40069 = 凭据或账号问题
    //      错误码对照见 app/Auth/CdifitProvider.php 的 ERROR_MESSAGES。
    'cdifit' => [
        'label'          => $env('CDIFIT_LABEL', 'Cdifit 账号'),
        // 服务地址：填实际部署的 Cdifit / Cloudreve 站点地址
        'base_url'       => $env('CDIFIT_BASE_URL', ''),
        'api_prefix'     => $env('CDIFIT_API_PREFIX', '/api/v4'),
        'session_prefix' => $env('CDIFIT_SESSION_PREFIX', '/session'),
        // 非会话接口（如用户资料）所在的分组
        'user_prefix'    => $env('CDIFIT_USER_PREFIX', '/user'),

        /**
         * 四个会话接口（HTTP 方法固定，见下方说明）+ 一个可选的用户资料接口
         *
         *   POST   /api/v4/session/token          密码登录
         *   POST   /api/v4/session/token/2fa      完成两步验证
         *   PUT    /api/v4/session/token/refresh  刷新令牌（刷新令牌放在 Authorization）
         *   DELETE /api/v4/session/token          退出登录
         *   GET    /api/v4/user/profile           当前用户（资料缺失时兜底）
         */
        'endpoints' => [
            'password_sign_in'        => $env('CDIFIT_EP_PASSWORD', '/token'),
            'finish_sign_in_with_2fa' => $env('CDIFIT_EP_2FA', '/token/2fa'),
            'refresh_token'           => $env('CDIFIT_EP_REFRESH', '/token/refresh'),
            'sign_out'                => $env('CDIFIT_EP_SIGNOUT', '/token'),
            'user_profile'            => $env('CDIFIT_EP_PROFILE', '/profile'),
        ],

        // 使用 user_prefix 而不是 session_prefix 的接口
        'user_endpoints' => ['user_profile'],

        // 调用 Cdifit 的服务级认证：none（默认）| bearer | api_key_header
        // 登录、2FA、刷新、登出都使用请求自身的凭据，不需要服务令牌。
        'auth' => [
            'type'      => $env('CDIFIT_AUTH_TYPE', 'none'),
            'token'     => $env('CDIFIT_API_TOKEN', ''),
            'header'    => $env('CDIFIT_AUTH_HEADER', 'Authorization'),
            'scheme'    => $env('CDIFIT_AUTH_SCHEME', 'Bearer'),
            'query_key' => $env('CDIFIT_AUTH_QUERY_KEY', 'access_token'),
        ],

        'timeout'         => (int) $env('CDIFIT_TIMEOUT', 15),
        'verify_tls'      => (bool) $env('CDIFIT_VERIFY_TLS', true),
        'default_headers' => [
            'Accept'     => 'application/json',
            'User-Agent' => 'Lolilota/1.1.0',
        ],

        // 请求字段名映射（Cdifit 用邮箱登录）
        'request_fields' => [
            'email'          => $env('CDIFIT_F_EMAIL', 'email'),
            'password'       => $env('CDIFIT_F_PASSWORD', 'password'),
            'captcha'        => $env('CDIFIT_F_CAPTCHA', 'captcha'),
            'captcha_ticket' => $env('CDIFIT_F_CAPTCHA_TICKET', 'ticket'),
            'session'        => $env('CDIFIT_F_SESSION', 'session'),
            'code'           => $env('CDIFIT_F_CODE', 'code'),
            'refresh_token'  => $env('CDIFIT_F_REFRESH', 'refresh_token'),
        ],

        // 响应字段名映射：候选键按顺序尝试
        'response_fields' => [
            'code'            => ['code'],
            'message'         => ['msg', 'message', 'error'],
            // 成功响应：data.token.{access_token, refresh_token, access_expires, refresh_expires}
            'token'           => ['token'],
            'access_token'    => ['access_token'],
            'refresh_token'   => ['refresh_token'],
            'access_expires'  => ['access_expires'],
            'refresh_expires' => ['refresh_expires'],
            // 成功响应：data.user.{id, email, nickname, avatar, ...}
            'user'            => ['user'],
            'user_id'         => ['id'],
            'email'           => ['email'],
            'nickname'        => ['nickname'],
            'username'        => ['username'],
            'avatar'          => ['avatar'],
            'status'          => ['status'],
        ],
    ],

    // ---- 历史记录 ------------------------------------------------------
    'history' => [
        'max_per_user' => (int) $env('HISTORY_MAX', 500),
        'max_push'     => (int) $env('HISTORY_PUSH_MAX', 200),
    ],
];

// 本地配置优先级最高（但它只在 config/local.php 存在时才有内容）
return $merge($config, $localConfig);
