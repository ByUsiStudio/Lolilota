/**
 * Lolilota · 历史记录同步
 *
 * 同步策略（双向，冲突按时间戳新者优先）：
 *   1. 任意一次改动都推送到云端（推送失败只记状态，不影响本地搜索）
 *   2. 登录后 / 打开页面时拉取云端，与本地合并
 *   3. 合并结果写回两端，保证多设备一致
 *
 * 本地记录在 localStorage（离线可用）；云端记录存 PHP 后端 var/lolilota.json
 */
(function (global) {
    'use strict';

    var NS = global.Lolilota = global.Lolilota || {};
    // 依赖在调用时才解析：模块加载顺序不应影响功能（sync 与 auth 相互引用）
    function util() { return NS.util; }
    // 依赖在调用时才解析：模块加载顺序不应影响功能（sync 与 auth 相互引用）
    function api() { return NS.api; }
    // 依赖在调用时才解析：模块加载顺序不应影响功能（sync 与 auth 相互引用）
    function auth() { return NS.auth; }
    // 依赖在调用时才解析：模块加载顺序不应影响功能（sync 与 auth 相互引用）
    function config() { return NS.config; }

    var KEY_ENABLED = 'lolilota_sync_enabled';
    var KEY_STATE = 'lolilota_sync_state';
    var MAX_PUSH_BATCH = 100;

    var syncState = util().store.get(KEY_STATE, {
        status: 'idle',       // idle | busy | ok | error | off
        message: '',
        at: null,
        pulled: 0,
        pushed: 0
    });

    var listeners = [];
    var timer = null;

    function emit() {
        for (var i = 0; i < listeners.length; i++) {
            try { listeners[i](syncState); } catch (e) { /* ignore */ }
        }
    }

    function setState(patch) {
        Object.keys(patch).forEach(function (k) { syncState[k] = patch[k]; });
        util().writeSetting(KEY_STATE, syncState);
        emit();
    }

    function onState(fn) {
        listeners.push(fn);
        return function () {
            var idx = listeners.indexOf(fn);
            if (idx >= 0) listeners.splice(idx, 1);
        };
    }

    function isEnabled() {
        var v = util().store.get(KEY_ENABLED, null);
        return v === null ? true : !!v; // 默认开启，登录后自动同步
    }

    function setEnabled(enabled) {
        util().writeSetting(KEY_ENABLED, !!enabled);
        if (!enabled) {
            setState({ status: 'off', message: '云端同步已关闭' });
        } else if (auth().getState().status === 'authed') {
            return fullSync();
        }
        return Promise.resolve(syncState);
    }

    function canSync() {
        return isEnabled() && auth().getState().status === 'authed';
    }

    /** 规范化一条记录，便于跨端比较 */
    function normalize(item) {
        if (!item) return null;
        if (typeof item === 'string') return { text: item.trim(), time: Date.now(), id: null, source: 'local', device: null };
        var text = String(item.text != null ? item.text : (item.keyword != null ? item.keyword : '')).trim();
        if (!text) return null;
        var rawTime = item.time || item.updated_at || item.created_at || 0;
        return {
            id: item.id || null,
            text: text,
            time: Number(rawTime) || Date.now(),
            source: item.source || (item.id ? 'remote' : 'local'),
            device: item.device || null
        };
    }

    /** 本地 + 云端合并：同文本取较新时间，结果按时间倒序 */
    function merge(local, remote, max) {
        var map = Object.create(null);

        function put(raw) {
            var item = normalize(raw);
            if (!item) return;
            var key = item.text;
            var exist = map[key];
            if (!exist) {
                map[key] = item;
                return;
            }
            if (item.time > exist.time) {
                exist.time = item.time;
            }
            if (item.id) exist.id = item.id;
            if (item.source === 'remote') exist.source = 'remote';
        }

        (local || []).forEach(put);
        (remote || []).forEach(put);

        var out = Object.keys(map).map(function (k) { return map[k]; });
        out.sort(function (a, b) { return b.time - a.time; });
        return typeof max === 'number' ? out.slice(0, max) : out;
    }

    /**
     * 拉取云端并合并
     * @param {object} opts { getLocal, saveLocal, max, silent }
     */
    function pull(opts) {
        opts = opts || {};
        if (!canSync()) return Promise.resolve(syncState);

        setState({ status: 'busy', message: '正在拉取云端记录…' });

        return api().listHistory({ limit: opts.max || 200 }).then(function (data) {
            var remote = (data && data.items) || [];
            var local = opts.getLocal ? opts.getLocal() : [];
            var merged = merge(local, remote, opts.max);

            if (opts.saveLocal) opts.saveLocal(merged);

            setState({
                status: 'ok',
                message: '已同步 ' + remote.length + ' 条云端记录',
                at: Date.now(),
                pulled: remote.length
            });
            auth().markSynced(Date.now());
            return syncState;
        }, function (err) {
            setState({ status: 'error', message: err.message, at: Date.now() });
            if (!opts.silent) throw err;
            return syncState;
        });
    }

    /** 把本地记录推送到云端 */
    function push(items, opts) {
        opts = opts || {};
        if (!canSync()) return Promise.resolve(syncState);

        var list = (items || []).map(normalize).filter(Boolean);
        if (!list.length) return Promise.resolve(syncState);

        setState({ status: 'busy', message: '正在上传本地记录…' });

        var batch = list.slice(0, MAX_PUSH_BATCH);
        return api().pushHistory(batch, { device: config().mode }).then(function (data) {
            setState({
                status: 'ok',
                message: '已上传 ' + ((data && data.accepted) || batch.length) + ' 条记录',
                at: Date.now(),
                pushed: batch.length
            });
            auth().markSynced(Date.now());
            return syncState;
        }, function (err) {
            setState({ status: 'error', message: err.message, at: Date.now() });
            if (!opts.silent) throw err;
            return syncState;
        });
    }

    /** 双向同步：先推后拉，避免首次登录丢本地记录 */
    function fullSync(opts) {
        opts = opts || {};
        if (!canSync()) return Promise.resolve(syncState);

        var local = opts.getLocal ? opts.getLocal() : [];
        return push(local, { silent: true })
            .then(function () { return pull(opts); })
            .catch(function (err) {
                setState({ status: 'error', message: err.message, at: Date.now() });
                return syncState;
            });
    }

    /**
     * 本地发生改动后的防抖推送
     * 只推新增项，避免每次都上传全量
     */
    function schedulePush(getLocal) {
        if (!canSync()) return;
        clearTimeout(timer);
        timer = setTimeout(function () {
            // 推最近 20 条即可覆盖新发生的搜索
            var recent = (getLocal ? getLocal() : []).slice(0, 20);
            push(recent, { silent: true });
        }, 1500);
    }

    /** 更新单条记录（当前仅本地，等后端支持逐条更新再扩展） */
    function removeRemote(id) {
        if (!canSync() || !id) return Promise.resolve(false);
        return api().deleteHistory(id).then(function () { return true; }, function () { return false; });
    }

    NS.sync = {
        state: syncState,
        onState: onState,
        isEnabled: isEnabled,
        setEnabled: setEnabled,
        canSync: canSync,
        merge: merge,
        normalize: normalize,
        pull: pull,
        push: push,
        fullSync: fullSync,
        schedulePush: schedulePush,
        removeRemote: removeRemote
    };
})(window);
