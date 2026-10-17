/**
 * Lolilota · 搜索历史（本地优先）
 *
 * 单独的模块，原因：
 *   - 搜索页需要增删查、导出、渲染历史面板
 *   - 账号系统需要读取/写回全量（同步用）
 *   - 同步模块需要合并结果后整体替换
 *
 * 记录结构：{ text: string, time: number, id?: string }   id 存在表示云端已有
 */
(function (global) {
    'use strict';

    var NS = global.Lolilota = global.Lolilota || {};
    var util = NS.util;

    var STORAGE_KEY = 'lolilota_history';
    var MAX_HISTORY = 200;
    var uiHooks = [];

    // ---------------------------------------------------------------------
    // 读写
    // ---------------------------------------------------------------------

    function get() {
        var raw = util.store.get(STORAGE_KEY, []);
        if (!Array.isArray(raw)) return [];
        return raw.filter(function (item) {
            return item && typeof item.text === 'string' && item.text.trim();
        });
    }

    function save(list) {
        var clean = (list || [])
            .filter(function (item) { return item && typeof item.text === 'string' && item.text.trim(); })
            .map(function (item) {
                var out = { text: item.text.trim(), time: Number(item.time) || Date.now() };
                if (item.id) out.id = item.id;
                return out;
            })
            .sort(function (a, b) { return b.time - a.time; });

        if (clean.length > MAX_HISTORY) clean = clean.slice(0, MAX_HISTORY);
        util.store.set(STORAGE_KEY, clean);
        emit();
        return clean;
    }

    function count() {
        return get().length;
    }

    /** 新增一条（同关键词去重并置顶） */
    function add(keyword) {
        if (!keyword || !String(keyword).trim()) return get();
        var q = String(keyword).trim();
        var list = get();
        var idx = -1;
        for (var i = 0; i < list.length; i++) {
            if (list[i].text === q) { idx = i; break; }
        }
        if (idx >= 0) list.splice(idx, 1);
        list.unshift({ text: q, time: Date.now() });
        return save(list);
    }

    function remove(index) {
        var list = get();
        if (index < 0 || index >= list.length) return list;
        list.splice(index, 1);
        return save(list);
    }

    function clear() {
        return save([]);
    }

    /** 合并一批远端记录并写回本地 */
    function mergeIn(items) {
        var merged = NS.sync
            ? NS.sync.merge(get(), items, MAX_HISTORY)
            : items;
        return save(merged);
    }

    // ---------------------------------------------------------------------
    // 导出
    // ---------------------------------------------------------------------

    function buildExport(format, list) {
        var stamp = new Date().toISOString().slice(0, 10);
        var filename = 'lolilota-history-' + stamp;
        var content = '';
        var mime = 'text/plain';

        if (format === 'json') {
            content = JSON.stringify(list, null, 2);
            filename += '.json';
            mime = 'application/json';
        } else if (format === 'csv') {
            content = '\uFEFF关键词,搜索时间\n';
            list.forEach(function (item) {
                content += '"' + item.text.replace(/"/g, '""') + '","' + new Date(item.time).toLocaleString('zh-CN') + '"\n';
            });
            filename += '.csv';
            mime = 'text/csv';
        } else if (format === 'txt') {
            list.forEach(function (item) { content += item.text + '\n'; });
            filename += '.txt';
        } else if (format === 'md') {
            content = '# Lolilota 搜索历史\n\n| # | 关键词 | 时间 |\n|---|--------|------|\n';
            list.forEach(function (item, i) {
                content += '| ' + (i + 1) + ' | ' + item.text + ' | ' + new Date(item.time).toLocaleString('zh-CN') + ' |\n';
            });
            filename += '.md';
            mime = 'text/markdown';
        } else if (format === 'html') {
            var rows = '';
            list.forEach(function (item, i) {
                rows += '<tr><td>' + (i + 1) + '</td><td>' + util.escapeHtml(item.text) + '</td><td>'
                    + new Date(item.time).toLocaleString('zh-CN') + '</td></tr>';
            });
            content = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
                + '<title>Lolilota 搜索历史</title>'
                + '<style>body{font-family:sans-serif;padding:24px;background:#eef4fb}'
                + 'table{border-collapse:collapse;width:100%;background:#fff;border-radius:12px;overflow:hidden}'
                + 'th,td{border:1px solid #dce8f5;padding:8px 12px;text-align:left}'
                + 'th{background:#8fb3e0;color:#fff}</style></head><body>'
                + '<h1 style="color:#4a6fa5">Lolilota 搜索历史</h1>'
                + '<table><thead><tr><th>#</th><th>关键词</th><th>时间</th></tr></thead><tbody>'
                + rows + '</tbody></table></body></html>';
            filename += '.html';
            mime = 'text/html';
        }

        return { filename: filename, content: content, mime: mime };
    }

    /** 触发浏览器下载 */
    function download(format) {
        var list = get();
        if (!list.length) return false;
        var pack = buildExport(format, list);
        var blob = new Blob([pack.content], { type: pack.mime + ';charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = pack.filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        return true;
    }

    /** 从 JSON 文本导入，返回新增条数 */
    function importFromText(text) {
        var data = JSON.parse(text);
        if (!Array.isArray(data)) throw new Error('invalid');
        var valid = data.filter(function (item) { return item && typeof item.text === 'string' && item.text.trim(); });
        if (!valid.length) return 0;
        return mergeIn(valid).length;
    }

    // ---------------------------------------------------------------------
    // UI 联动：任何写操作后触发，搜索页据此刷新计数/面板
    // ---------------------------------------------------------------------

    function onChange(fn) {
        uiHooks.push(fn);
        return function () {
            var idx = uiHooks.indexOf(fn);
            if (idx >= 0) uiHooks.splice(idx, 1);
        };
    }

    function emit() {
        for (var i = 0; i < uiHooks.length; i++) {
            try { uiHooks[i](); } catch (e) { /* ignore */ }
        }
    }

    NS.history = {
        STORAGE_KEY: STORAGE_KEY,
        MAX: MAX_HISTORY,
        get: get,
        save: save,
        list: get,
        add: add,
        remove: remove,
        clear: clear,
        count: count,
        mergeIn: mergeIn,
        buildExport: buildExport,
        download: download,
        importFromText: importFromText,
        onChange: onChange,
        emit: emit
    };
})(window);
