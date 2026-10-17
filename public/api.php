<?php
/**
 * Lolilota · REST 接口入口
 *
 * 所有接口挂在 /api/v1 下，统一由 Router 分发。
 * 响应形状由 Lolilota\Support\Json 保证：
 *   { "ok": true,  "data": ... }
 *   { "ok": false, "error": { "code", "message", "details" } }
 *
 * 本文件属于后端源码，构建脚本的同步逻辑会保护所有 .php 文件，不会被清理。
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Lolilota\Auth\Auth;
use Lolilota\Auth\AuthException;
use Lolilota\Controller\AuthController;
use Lolilota\Controller\AvatarController;
use Lolilota\Controller\HistoryController;
use Lolilota\Controller\SuggestController;
use Lolilota\Core\Config;
use Lolilota\Core\Router;
use Lolilota\Repository\HistoryRepository;
use Lolilota\Support\Http;
use Lolilota\Support\HttpException;
use Lolilota\Support\Json;
use Lolilota\Support\Log;

// ---- 跨域（同源无需处理，扩展 / 分离部署需要）--------------------------
Http::applyCors();

$method = Http::method();

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---- 依赖装配 ----------------------------------------------------------
try {
    $auth = new Auth();
    $history = new HistoryRepository();
} catch (Throwable $e) {
    Log::error('后端初始化失败', ['message' => $e->getMessage()]);
    Json::error('storage_unavailable', '数据目录不可写：' . $e->getMessage(), 500);
}

$authController = new AuthController($auth);
$avatarController = new AvatarController($auth);
$historyController = new HistoryController($auth, $history, $auth->sessions());
$suggestController = new SuggestController();

$router = new Router();
$router->setPrefix('/api/v1');

// ---- 基础信息 ----------------------------------------------------------
$router->get('health', static fn () => $authController->health());
$router->get('config', static fn () => $authController->config());

// ---- 搜索建议（服务端代理 Bing Autosuggest，绕开浏览器 CORS）----------
$router->get('suggest', static fn () => $suggestController->index());

// ---- 会话（与 Cdifit 的四个会话接口一一对应）---------------------------
$router->get('session', static fn () => $authController->session());
$router->post('session/password_sign_in', static fn () => $authController->passwordSignIn());
$router->post('session/finish_sign_in_with_2fa', static fn () => $authController->finishSignInWith2fa());
$router->post('session/refresh_token', static fn () => $authController->refreshToken());
$router->post('session/sign_out', static fn () => $authController->signOut());

// ---- 用户头像（代理 Cdifit 的 GET /api/v4/user/avatar/{id}）-----------
$router->get('avatar/{id}', static fn (array $params) => $avatarController->show($params));

// ---- 历史记录同步 ------------------------------------------------------
$router->get('history', static fn () => $historyController->index());
$router->post('history', static fn () => $historyController->push());
$router->delete('history', static fn () => $historyController->clear());
$router->delete('history/{id}', static fn (array $params) => $historyController->destroy($params));

// ---- 分发 --------------------------------------------------------------
try {
    $router->dispatch($method, Http::path());
} catch (AuthException $e) {
    Json::error($e->errorCode, $e->getMessage(), $e->status, $e->details);
} catch (HttpException $e) {
    Json::error($e->errorCode, $e->getMessage(), $e->status ?: 502, $e->details);
} catch (Throwable $e) {
    Log::error('接口异常', [
        'path'    => Http::path(),
        'message' => $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);

    Json::error(
        'internal_error',
        (bool) Config::get('app.debug', false)
            ? $e->getMessage()
            : '服务器内部错误，请查看 var/logs/app.log',
        500
    );
}
