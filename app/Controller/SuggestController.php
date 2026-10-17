<?php

declare(strict_types=1);

namespace Lolilota\Controller;

use Lolilota\Core\Config;
use Lolilota\Support\HttpClient;
use Lolilota\Support\HttpException;
use Lolilota\Support\Json;
use Lolilota\Support\Log;

/**
 * 搜索建议
 *
 *   GET /api/v1/suggest?q=<关键词>
 *
 * 浏览器直接请求 Bing Autosuggest（cn / api.bing.com）会被 CORS 拦住
 * （响应没有 Access-Control-Allow-Origin），普通网页用 JSONP 又受 CSP 限制。
 * 这里由后端代理一次，站点与扩展就都只跟自己的后端通信。
 *
 * 前端会优先用这个接口；后端不可达时：
 *   - 扩展：退回带 host 权限的跨域 fetch
 *   - 站点：退回 JSONP
 */
final class SuggestController
{
    /** 关键词最大长度（字符） */
    private const MAX_QUERY = 100;

    /** 最多返回多少条建议 */
    private const MAX_ITEMS = 10;

    public function index(): never
    {
        $raw = $_GET['q'] ?? $_GET['query'] ?? '';
        $query = $this->normalizeQuery(is_string($raw) ? $raw : '');

        // 空关键词直接返回空列表，不打上游
        if ($query === '') {
            Json::ok(['query' => '', 'suggestions' => []]);
        }

        $urls = $this->upstreamUrls($query);

        if ($urls === []) {
            Json::error('suggest_not_configured', '搜索建议服务未正确配置', 503);
        }

        $timeout = max(2, (int) Config::get('search.suggest_timeout', 4));
        $client = new HttpClient($timeout);
        $lastStatus = null;
        $lastError = null;

        // 主接口（cn.bing.com）失败时再试备用接口（api.bing.com）
        foreach ($urls as $url) {
            try {
                $response = $client->request('GET', $url, '', [
                    'Accept'          => 'application/json, text/javascript, */*;q=0.8',
                    'Accept-Language' => (string) Config::get('search.suggest_language', 'zh-CN,zh;q=0.9,en;q=0.6'),
                ]);
            } catch (HttpException $e) {
                $lastError = $e->getMessage();
                continue;
            }

            $status = (int) ($response['status'] ?? 0);

            if ($status !== 200) {
                $lastStatus = $status;
                continue;
            }

            $suggestions = $this->parse((string) ($response['raw'] ?? ''));

            Json::ok([
                'query'       => $query,
                'suggestions' => $suggestions,
            ]);
        }

        Log::warn('搜索建议请求失败', [
            'query_hash' => substr(hash('sha256', $query), 0, 8),
            'status'     => $lastStatus,
            'message'    => $lastError,
        ]);

        Json::error('suggest_unavailable', '搜索建议暂时不可用', 502);
    }

    /** 清理关键词：去控制字符、折叠空白、限制长度 */
    private function normalizeQuery(string $query): string
    {
        $query = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $query) ?? $query;
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));
        $query = mb_substr($query, 0, self::MAX_QUERY, 'UTF-8');

        return trim($query);
    }

    /**
     * 组装候选上游地址（主接口 + 备用接口，去重）
     *
     * @return list<string>
     */
    private function upstreamUrls(string $query): array
    {
        $bases = [
            (string) Config::get('search.suggest_url', ''),
            (string) Config::get('search.suggest_fallback_url', ''),
        ];

        $out = [];

        foreach ($bases as $base) {
            $base = trim($base);

            if ($base === '' || preg_match('#^https?://#i', $base) !== 1) {
                continue;
            }

            $separator = str_contains($base, '?') ? '&' : '?';
            $url = $base . $separator . http_build_query(
                ['query' => $query],
                '',
                '&',
                PHP_QUERY_RFC3986
            );

            if (!in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /**
     * 解析上游响应
     *
     * 兼容三种常见形状：
     *   ["关键词", ["建议1", "建议2"]]
     *   {"suggestions": ["建议1", ...]}
     *   {"AS": {"Results": [{"Suggests": [{"Txt": "建议"}]}]}}
     *
     * @return list<string>
     */
    private function parse(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        // 部分接口会返回 JSONP 包装，剥掉回调外壳再解析
        if (preg_match('/\(\s*(\[\s*.*\]|\{\s*.*\})\s*\)\s*;?\s*$/s', $raw, $m) === 1) {
            $raw = $m[1];
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return [];
        }

        $items = [];

        if (isset($data[1]) && is_array($data[1])) {
            $items = $data[1];
        } elseif (isset($data['suggestions']) && is_array($data['suggestions'])) {
            $items = $data['suggestions'];
        } elseif (isset($data['AS']['Results']) && is_array($data['AS']['Results'])) {
            foreach ($data['AS']['Results'] as $group) {
                if (is_array($group) && isset($group['Suggests']) && is_array($group['Suggests'])) {
                    foreach ($group['Suggests'] as $item) {
                        if (is_array($item) && isset($item['Txt'])) {
                            $items[] = $item['Txt'];
                        }
                    }
                }
            }
        }

        $out = [];
        $seen = [];

        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            $text = trim((string) $item);

            if ($text === '' || mb_strlen($text, 'UTF-8') > 120) {
                continue;
            }

            // 按原文去重，避免上游重复项
            if (isset($seen[$text])) {
                continue;
            }

            $seen[$text] = true;
            $out[] = $text;

            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $out;
    }
}
