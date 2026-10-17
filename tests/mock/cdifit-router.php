<?php
/**
 * Cdifit 模拟服务（仅用于本地联调与自动化测试）
 *
 * 严格按 Cdifit 文档的行为实现：
 *   - 路由前缀 /api/v4/，HTTP 状态固定 200
 *   - 结果由 JSON 里的 code 决定：0 成功，203 需要 2FA，其余为错误码
 *   - 登录成功返回 data.user + data.token.{access_token,refresh_token,access_expires,refresh_expires}
 *   - 需要 2FA 时 data 就是 2FA session id
 *   - 刷新令牌要求 Authorization: Bearer <RefreshToken>
 *   - 退出登录返回 { code: 0, msg: "" }
 *   - 头像接口 GET /api/v4/user/avatar/{id} 直接返回图片内容
 *
 * 用法：
 *   php -S 127.0.0.1:8790 tests/mock/cdifit-router.php
 *
 * 测试账号（密码统一为 Passw0rd!2024）：
 *   user@example.com    直接登录成功
 *   mfa@example.com     需要两步验证，验证码 123456
 *   banned@example.com  返回 40017 账号被封禁
 *   bad@example.com     返回 40020 邮箱或密码错误
 */

declare(strict_types=1);

const MOCK_PASSWORD = 'Passw0rd!2024';

/** 统一的 Cdifit 风格响应：HTTP 200 + code */
function reply(array $payload): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok(mixed $data): never
{
    reply(['code' => 0, 'data' => $data, 'msg' => '']);
}

function fail(int $code, string $message): never
{
    reply(['code' => $code, 'msg' => $message, 'error' => $message . ' (debug)']);
}

function requestBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function bearer(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', trim((string) $header), $m) === 1) {
        return trim($m[1]);
    }
    return '';
}

/** access token 形如 mock-access-<email> */
function issueTokens(string $email): array
{
    $now = time();

    return [
        'access_token'    => 'mock-access-' . $email . '-' . bin2hex(random_bytes(4)),
        'refresh_token'   => 'mock-refresh-' . $email . '-' . bin2hex(random_bytes(4)),
        'access_expires'  => gmdate('c', $now + 3600),
        'refresh_expires' => gmdate('c', $now + 86400 * 30),
    ];
}

function userPayload(string $email): array
{
    $isAdmin = str_starts_with($email, 'admin');

    return [
        'id'         => $isAdmin ? 'lpua' : 'u' . substr(md5($email), 0, 6),
        'email'      => $email,
        'nickname'   => $isAdmin ? 'Aaron Liu' : '测试用户',
        'status'     => 'active',
        'avatar'     => 'file', // 文档里这是类型标识，不是 URL
        'created_at' => '2023-08-06T19:21:59+08:00',
        'credit'     => 23000,
        'language'   => 'zh-CN',
        'group'      => [
            'id'         => $isAdmin ? 'z4u4' : 'g001',
            'name'       => $isAdmin ? '管理员' : '普通用户',
            'permission' => '/f8B',
        ],
    ];
}

/** 1x1 PNG：头像接口返回文件内容而不是 JSON */
function pngAvatar(): never
{
    header('Content-Type: image/png');
    header('Cache-Control: no-store');
    echo base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
    );
    exit;
}

// ---- 路由 --------------------------------------------------------------

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// POST /api/v4/session/token
if ($path === '/api/v4/session/token' && $method === 'POST') {
    $body = requestBody();
    $email = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($email === '' || $password === '') {
        fail(40001, 'Parameter Error');
    }

    if (str_starts_with($email, 'banned')) {
        fail(40017, 'User Banned');
    }

    if ($password !== MOCK_PASSWORD || str_starts_with($email, 'bad')) {
        fail(40020, 'Invalid Credentials');
    }

    if (str_starts_with($email, 'mfa')) {
        // 需要两步验证：data 就是 session id
        reply(['code' => 203, 'data' => 'mock-2fa-session-1728791b', 'msg' => '']);
    }

    ok([
        'user'  => userPayload($email),
        'token' => issueTokens($email),
    ]);
}

// POST /api/v4/session/token/2fa
if ($path === '/api/v4/session/token/2fa' && $method === 'POST') {
    $body = requestBody();
    $session = (string) ($body['session'] ?? '');
    $code = (string) ($body['code'] ?? '');

    if ($session === '' || $code === '') {
        fail(40001, 'Parameter Error');
    }

    if ($code !== '123456') {
        fail(40022, '2FA Code Error');
    }

    ok([
        'user'  => userPayload('mfa@example.com'),
        'token' => issueTokens('mfa@example.com'),
    ]);
}

// PUT /api/v4/session/token/refresh
if ($path === '/api/v4/session/token/refresh' && $method === 'PUT') {
    $token = bearer();
    $body = requestBody();

    if ($token === '' && ($body['refresh_token'] ?? '') === '') {
        fail(401, 'Not Logged In');
    }

    $email = 'user@example.com';
    if (preg_match('/mock-refresh-([^-]+(?:@[^-]+)?)/', $token, $m) === 1) {
        $email = $m[1];
    }

    // 文档：刷新返回的 data 直接就是新的令牌集合
    ok(issueTokens($email));
}

// DELETE /api/v4/session/token
if ($path === '/api/v4/session/token' && $method === 'DELETE') {
    if (bearer() === '') {
        fail(401, 'Not Logged In');
    }

    reply(['code' => 0, 'msg' => '']);
}

// GET /api/v4/user/profile
if ($path === '/api/v4/user/profile' && $method === 'GET') {
    $access = bearer();

    if ($access === '') {
        fail(401, 'Not Logged In');
    }

    if (preg_match('/mock-access-([^-]+(?:@[^-]+)?)/', $access, $m) === 1) {
        ok(userPayload($m[1]));
    }

    ok(userPayload('user@example.com'));
}

// GET /api/v4/user/avatar/{id}
//
// 头像接口返回的是文件内容而不是 JSON：
//   - 普通 ID  -> 直接返回 1x1 PNG
//   - redirect -> 302 到 /mock-avatar.png，用来验证后端的跳转跟随
//   - missing  -> 返回 JSON 404，后端应把它当成"没有头像"
if ($method === 'GET' && preg_match('#^/api/v4/user/avatar/([A-Za-z0-9_-]+)$#', $path, $m) === 1) {
    $id = $m[1];

    if ($id === 'missing') {
        fail(404, 'Avatar Not Found');
    }

    if ($id === 'redirect') {
        header('Location: /mock-avatar.png', true, 302);
        exit;
    }

    // SVG 可能携带脚本，后端必须拒绝（不能以本站同源地址回流）
    if ($id === 'svg') {
        header('Content-Type: image/svg+xml');
        echo '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        exit;
    }

    pngAvatar();
}

// 跳转目标
if ($method === 'GET' && $path === '/mock-avatar.png') {
    pngAvatar();
}

fail(404, 'Resource Not Found');
