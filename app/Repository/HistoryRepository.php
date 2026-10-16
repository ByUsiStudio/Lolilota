<?php

declare(strict_types=1);

namespace Lolilota\Repository;

use Lolilota\Core\Config;
use Lolilota\Core\JsonStore;

/**
 * 搜索历史仓库
 *
 * 结构： { "history": { "<uid>": [ { id, keyword, created_at, device } ] } }
 * 同一关键词只保留一条（时间取最新），天然去重。
 */
final class HistoryRepository
{
    private const BUCKET = 'history';

    private JsonStore $store;

    public function __construct(?JsonStore $store = null)
    {
        $this->store = $store ?? new JsonStore('history');
    }

    private function maxPerUser(): int
    {
        return max(1, (int) Config::get('history.max_per_user', 500));
    }

    /**
     * 列出用户历史（按时间倒序）
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $uid, int $limit = 200, int $offset = 0): array
    {
        $items = $this->items($uid);

        usort($items, static fn (array $a, array $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));

        return array_slice($items, max(0, $offset), max(1, $limit));
    }

    public function total(string $uid): int
    {
        return count($this->items($uid));
    }

    /**
     * 批量写入，返回实际处理的条数
     *
     * @param list<array<string, mixed>> $rows
     * @return array{accepted:int, total:int}
     */
    public function push(string $uid, array $rows, string $device = 'web'): array
    {
        $accepted = 0;
        $total = 0;

        $this->store->transaction(function (array $data) use ($uid, $rows, $device, &$accepted, &$total): array {
            $all = is_array($data[self::BUCKET] ?? null) ? $data[self::BUCKET] : [];
            $current = is_array($all[$uid] ?? null) ? $all[$uid] : [];

            // 以关键词为键做去重索引
            $index = [];
            foreach ($current as $i => $item) {
                if (is_array($item) && isset($item['keyword'])) {
                    $index[(string) $item['keyword']] = $i;
                }
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $keyword = trim((string) ($row['keyword'] ?? $row['text'] ?? ''));
                if ($keyword === '') {
                    continue;
                }
                if (mb_strlen($keyword) > 200) {
                    $keyword = mb_substr($keyword, 0, 200);
                }

                $createdAt = (int) ($row['created_at'] ?? $row['time'] ?? 0);
                if ($createdAt <= 0) {
                    $createdAt = time();
                }

                if (isset($index[$keyword])) {
                    $pos = $index[$keyword];
                    // 只更新时间较新的记录
                    if ((int) ($current[$pos]['created_at'] ?? 0) < $createdAt) {
                        $current[$pos]['created_at'] = $createdAt;
                        $current[$pos]['device'] = $device;
                    }
                } else {
                    $current[] = [
                        'id'         => $row['id'] ?? ('h_' . bin2hex(random_bytes(6))),
                        'keyword'    => $keyword,
                        'created_at' => $createdAt,
                        'device'     => (string) ($row['device'] ?? $device),
                    ];
                    $index[$keyword] = count($current) - 1;
                }

                $accepted++;
            }

            // 超限裁剪：保留最新的 N 条
            if (count($current) > $this->maxPerUser()) {
                usort($current, static fn (array $a, array $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));
                $current = array_slice($current, 0, $this->maxPerUser());
            }

            $all[$uid] = array_values($current);
            $data[self::BUCKET] = $all;
            $total = count($all[$uid]);

            return $data;
        });

        return ['accepted' => $accepted, 'total' => $total];
    }

    /** 删除单条（按 id 或关键词） */
    public function delete(string $uid, string $id): bool
    {
        $removed = false;

        $this->store->transaction(function (array $data) use ($uid, $id, &$removed): array {
            $all = is_array($data[self::BUCKET] ?? null) ? $data[self::BUCKET] : [];
            $current = is_array($all[$uid] ?? null) ? $all[$uid] : [];

            $kept = [];
            foreach ($current as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if ((string) ($item['id'] ?? '') === $id || (string) ($item['keyword'] ?? '') === $id) {
                    $removed = true;
                    continue;
                }
                $kept[] = $item;
            }

            $all[$uid] = array_values($kept);
            $data[self::BUCKET] = $all;

            return $data;
        });

        return $removed;
    }

    /** 清空用户历史 */
    public function clear(string $uid): int
    {
        $count = 0;

        $this->store->transaction(function (array $data) use ($uid, &$count): array {
            $all = is_array($data[self::BUCKET] ?? null) ? $data[self::BUCKET] : [];
            $count = is_array($all[$uid] ?? null) ? count($all[$uid]) : 0;
            $all[$uid] = [];
            $data[self::BUCKET] = $all;

            return $data;
        });

        return $count;
    }

    /** @return list<array<string, mixed>> */
    private function items(string $uid): array
    {
        $data = $this->store->read();
        $items = $data[self::BUCKET][$uid] ?? [];
        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }
}
