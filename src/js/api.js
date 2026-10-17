/**
 * Lolilota · API 客户端
 *
 * 统一约定：后端所有接口返回
 *   { "ok": true,  "data": {...} }
 *   { "ok": false, "error": { "code": "...", "message": "...", "details": {...} } }
 */
(function (global) {
    'use strict';

    var NS = global.Lolilota = global.Lolilota || {};
    // 依赖在调用时才解析：模块加载顺序不应影响功能（sync 与 auth 相互引用）
    function util() { return NS.util; }
    // 依赖在调用时才解析：模块加载顺序不应影响功能（sync 与 auth 相互引用）
    function config() { return NS.config; }

    /** 接口错误对象 */
    function ApiError(code, message, status, details) {
        this.name = 'ApiError';
        this.code = code || 'unknown_error';
        this.message = message || '请求失败';
        this.status = status || 0;
        this.details = details || null;
    }
    ApiError.prototype = Object.create(Error.prototype);
    ApiError.prototype.constructor = ApiError;

    var token = util().store.get(config().keys.token, null);

    function getToken() {
        return token;
    }

    function setToken(value) {
        token = value || null;
        if (token) {
            util().writeSetting(config().keys.token, token);
        } else {
            util().store.remove(config().keys.token);
            if (util().isExtension() && typeof chrome !== 'undefined' && chrome.storage && chrome.storage.local) {
                chrome.storage.local.remove([config().keys.token]);
            }
        }
    }

    /**
     * 发起请求
     * @param {string} path    接口路径，如 'session/password_sign_in'
     * @param {object} options { method, body, query, timeout, raw }
     */
    function request(path, options) {
        options = options || {};
        var url = config().apiUrl(path);

        if (options.query) {
            var qs = new URLSearchParams();
            Object.keys(options.query).forEach(function (k) {
                var v = options.query[k];
                if (v !== undefined && v !== null && v !== '') qs.append(k, v);
            });
            var q = qs.toString();
            if (q) url += (url.indexOf('?') >= 0 ? '&' : '?') + q;
        }

        var headers = { 'Accept': 'application/json' };
        var init = {
            method: options.method || 'GET',
            headers: headers,
            // 同源部署时靠 Cookie 维持会话；跨源扩展场景靠 Authorization
            credentials: 'include',
            mode: 'cors',
            cache: 'no-store'
        };

        if (token) {
            headers['Authorization'] = 'Bearer ' + token;
        }

        if (options.body !== undefined && options.body !== null) {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }

        var controller = null;
        var timer = null;
        if (typeof AbortController !== 'undefined') {
            controller = new AbortController();
            init.signal = controller.signal;
            timer = setTimeout(function () { controller.abort(); }, options.timeout || 15000);
        }

        return fetch(url, init)
            .then(function (res) {
                return res.text().then(function (text) {
                    var payload = null;
                    if (text) {
                        try { payload = JSON.parse(text); } catch (e) { payload = null; }
                    }

                    if (!res.ok || !payload || payload.ok === false) {
                        var err = (payload && payload.error) || {};
                        throw new ApiError(
                            err.code || 'http_' + res.status,
                            err.message || ('请求失败（HTTP ' + res.status + '）'),
                            res.status,
                            err.details || null
                        );
                    }
                    return payload.data;
                });
            })
            .catch(function (err) {
                if (err instanceof ApiError) throw err;
                if (err && err.name === 'AbortError') {
                    throw new ApiError('timeout', '请求超时，请检查后端服务是否可访问', 0, { url: url });
                }
                throw new ApiError('network_error', '无法连接后端服务（' + config().apiBase + '）', 0, { url: url, cause: String(err && err.message || err) });
            })
            .then(function (data) {
                if (timer) clearTimeout(timer);
                return data;
            }, function (err) {
                if (timer) clearTimeout(timer);
                throw err;
            });
    }

    var api = {
        ApiError: ApiError,
        request: request,
        getToken: getToken,
        setToken: setToken,

        // ---- 基础信息 ----
        health: function () {
            return request('health', { timeout: 6000 });
        },
        getConfig: function () {
            return request('config');
        },

        // ---- 账号会话 ----
        session: function () {
            return request('session');
        },
        /** 密码登录：可能直接返回会话，也可能要求两步验证 */
        passwordSignIn: function (payload) {
            return request('session/password_sign_in', { method: 'POST', body: payload, timeout: 20000 });
        },
        /** 完成两步验证 */
        finishSignInWith2fa: function (payload) {
            return request('session/finish_sign_in_with_2fa', { method: 'POST', body: payload, timeout: 20000 });
        },
        /** 刷新访问令牌 */
        refreshToken: function (payload) {
            return request('session/refresh_token', { method: 'POST', body: payload || {} });
        },
        /** 退出登录 */
        signOut: function () {
            return request('session/sign_out', { method: 'POST', body: {} });
        },
        /** OAuth / 授权码登录的跳转地址 */
        oauthStartUrl: function (redirect) {
            return config().apiUrl('auth/cdifit/start') + (redirect ? '?redirect=' + encodeURIComponent(redirect) : '');
        },

        // ---- 历史记录同步 ----
        listHistory: function (options) {
            return request('history', { query: options || {} });
        },
        pushHistory: function (items, options) {
            return request('history', {
                method: 'POST',
                body: { items: items, device: (options && options.device) || 'web' }
            });
        },
        deleteHistory: function (id) {
            return request('history/' + encodeURIComponent(id), { method: 'DELETE' });
        },
        clearHistory: function () {
            return request('history', { method: 'DELETE' });
        }
    };

    NS.api = api;
})(window);
