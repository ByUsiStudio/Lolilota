<?php

declare(strict_types=1);

namespace Lolilota\Controller;

use Lolilota\Auth\Auth;
use Lolilota\Core\Config;
use Lolilota\Core\SessionManager;
use Lolilota\Repository\HistoryRepository;
use Lolilota\Support\Http;
use Lolilota\Support\Json;

/**
 * 搜索历史同步接口
 *
 *   GET    history            拉取（支持 limit / offset）
 *   POST   history            批量推送（合并去重）
 *   DELETE history            清空
 *   DELETE history/{id}       删除单条
 *
 * 全部需要登录，未登录返回 401，前端会静默降级为纯本地模式。
 */
final class HistoryController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly HistoryRepository $history,
        private readonly SessionManager $sessions
    ) {
    }

    /** GET history */
    public function index(): never
    {
        $uid = $this->requireUser();
        $limit = min(
            (int) Config::get('history.max_per_user', 500),
            max(1, (int) ($_GET['limit'] ?? 200))
        );
        $offset = max(0, (int) ($_GET['offset'] ?? 0));

        Json::ok([
            'items' => $this->history->list($uid, $limit, $offset),
            'total' => $this->history->total($uid),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** POST history */
    public function push(): never
    {
        $uid = $this->requireUser();
        $input = Http::json();

        $items = $input['items'] ?? $input['history'] ?? [];
        if (!is_array($items)) {
            Json::error('invalid_payload', 'items 必须是数组', 422);
        }

        $max = (int) Config::get('history.max_push', 200);
        $items = array_slice(array_values($items), 0, $max);

        // created_at 归一化：前端可能传毫秒时间戳
        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $keyword = (string) ($item['text'] ?? $item['keyword'] ?? '');
            $createdAt = (int) ($item['time'] ?? $item['created_at'] ?? 0);

            // 毫秒 -> 秒
            if ($createdAt > 100000000000) {
                $createdAt = (int) round($createdAt / 1000);
            }

            $rows[] = [
                'id'         => $item['id'] ?? null,
                'keyword'    => $keyword,
                'created_at' => $createdAt,
                'device'     => (string) ($item['device'] ?? $input['device'] ?? 'web'),
            ];
        }

        $result = $this->history->push($uid, $rows, (string) ($input['device'] ?? 'web'));

        Json::ok([
            'accepted' => $result['accepted'],
            'total'    => $result['total'],
            'items'    => $this->history->list($uid, min(200, $result['total'])),
        ]);
    }

    /** DELETE history/{id} */
    public function destroy(array $params): never
    {
        $uid = $this->requireUser();
        $id = (string) ($params['id'] ?? '');

        if ($id === '') {
            Json::error('missing_id', '缺少要删除的记录 ID', 422);
        }

        $removed = $this->history->delete($uid, $id);

        Json::ok([
            'removed' => $removed,
            'total'   => $this->history->total($uid),
        ]);
    }

    /** DELETE history */
    public function clear(): never
    {
        $uid = $this->requireUser();
        $removed = $this->history->clear($uid);

        Json::ok(['removed' => $removed, 'total' => 0]);
    }

    /** 取当前用户 id，未登录直接 401 并结束请求 */
    private function requireUser(): string
    {
        $user = $this->auth->currentUser();

        if ($user === null || empty($user['id'])) {
            Json::error('not_authenticated', '请先登录', 401);
        }

        return (string) $user['id'];
    }
}
