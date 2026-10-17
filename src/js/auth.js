/**
 * Lolilota · 认证状态机
 *
 * 对接 Cdifit 账号系统的四个接口（详见 docs.cdifit.com/zh）：
 *   POST session/password_sign_in            密码登录
 *   POST session/finish_sign_in_with_2fa     完成两步验证
 *   POST session/refresh_token               刷新令牌
 *   POST session/sign_out                    退出登录
 *
 * 本模块只负责“状态与流程”，不碰 DOM。
 */
(function (global) {
    'use strict';

    var NS = global.Lolilota = global.Lolilota || {};
    var util = NS.util;
    var api = NS.api;
    var config = NS.config;

    var KEY_REMEMBER = 'lolilota_remember';

    var state = {
        status: 'unknown',      // unknown | guest | pending_2fa | authed | error
        user: null,
        session: null,          // { expires_at, provider, ... }
        pending: null,          // 两步验证上下文
        error: null,
        lastSyncAt: util.store.get('lolilota_last_sync', null)
    };

    var listeners = [];

    function emit() {
        for (var i = 0; i < listeners.length; i++) {
            try { listeners[i](state); } catch (e) { /* 单个订阅者异常不影响其他 */ }
        }
    }

    function onChange(fn) {
        listeners.push(fn);
        return function () {
            var idx = listeners.indexOf(fn);
            if (idx >= 0) listeners.splice(idx, 1);
        };
    }

    function getState() {
        return state;
    }

    /** 把后端返回的会话写入状态 */
    function applySession(data) {
        if (!data) return;
        if (data.access_token) {
            api.setToken(data.access_token);
        }
        if (data.refresh_token) {
            util.writeSetting('lolilota_refresh_token', data.refresh_token);
        }
        state.session = {
            provider: data.provider || 'cdifit',
            expiresAt: data.expires_at || null,
            refreshAt: data.refresh_expires_at || null,
            scope: data.scope || null
        };
        state.user = data.user || null;
        state.status = 'authed';
        state.pending = null;
        state.error = null;
        emit();
    }

    /** 会话失效清理 */
    function clearSession() {
        api.setToken(null);
        state.user = null;
        state.session = null;
        state.pending = null;
        state.status = 'guest';
        emit();
    }

    /**
     * 启动时恢复会话：
     *   有 token -> 调 session 校验；过期则尝试 refresh；都失败则视为游客
     */
    function bootstrap() {
        return config.ready()
            .then(function () { return api.session(); })
            .then(function (data) {
                if (data && data.authenticated && data.user) {
                    applySession({ user: data.user, access_token: data.access_token, expires_at: data.expires_at });
                } else if (api.getToken()) {
                    // token 失效，尝试刷新一次
                    return refresh().catch(function () { clearSession(); });
                } else {
                    state.status = 'guest';
                    emit();
                }
                return state;
            })
            .catch(function (err) {
                // 后端不可达时不阻塞页面，仅标记为游客 + 记录错误
                state.status = 'guest';
                state.error = { code: err.code, message: err.message };
                emit();
                return state;
            });
    }

    /** 密码登录（第一步） */
    function signIn(credentials) {
        state.error = null;
        var remember = credentials.remember !== false;
        util.writeSetting(KEY_REMEMBER, remember);

        var account = (credentials.email || credentials.username || '').trim();

        var body = {
            // Cdifit 用邮箱登录；同时带上 username 以兼容本地账号
            email: account,
            username: account,
            password: credentials.password || '',
            remember: remember,
            device: credentials.device || 'lolilota-web'
        };
        if (credentials.captcha) {
            body.captcha = credentials.captcha;
            body.ticket = credentials.ticket || '';
        }

        return api.passwordSignIn(body).then(function (data) {
            // 情形一：直接登录成功
            if (data && (data.access_token || (data.user && !data.requires_2fa))) {
                applySession(data);
                return { status: 'authed', state: state };
            }

            // 情形二：需要两步验证（Cdifit 返回 code=203，data 是 2FA 会话 ID）
            if (data && (data.requires_2fa || data.session)) {
                state.status = 'pending_2fa';
                state.pending = {
                    session: data.session || null,
                    methods: data.methods || [],
                    hint: data.hint || null
                };
                emit();
                return { status: 'pending_2fa', state: state };
            }

            // 情形三：返回了未知结构，交给调用方提示
            throw new api.ApiError('unexpected_response', '登录返回结构无法识别，请核对 Cdifit 接口字段', 0, data);
        }, function (err) {
            state.error = { code: err.code, message: err.message, details: err.details };
            emit();
            throw err;
        });
    }

    /** 两步验证（第二步） */
    function submit2fa(code) {
        if (!state.pending) {
            return Promise.reject(new api.ApiError('no_pending_challenge', '当前没有待验证的登录请求'));
        }

        return api.finishSignInWith2fa({
            // Cdifit：session 是密码登录返回的 2FA 会话 ID，code 是 6 位验证码
            session: state.pending.session,
            challenge_id: state.pending.session,
            code: code
        }).then(function (data) {
            applySession(data);
            return { status: 'authed', state: state };
        }, function (err) {
            state.error = { code: err.code, message: err.message, details: err.details };
            emit();
            throw err;
        });
    }

    /** 取消两步验证，回到登录表单 */
    function cancel2fa() {
        state.pending = null;
        state.status = 'guest';
        state.error = null;
        emit();
    }

    /** 刷新令牌 */
    function refresh() {
        var payload = {};
        var rt = util.store.get('lolilota_refresh_token', null);
        if (rt) payload.refresh_token = rt;
        return api.refreshToken(payload).then(function (data) {
            if (data && data.refresh_token) {
                util.writeSetting('lolilota_refresh_token', data.refresh_token);
            }
            applySession(data);
            return state;
        });
    }

    /** 退出登录：先通知后端，再本地清理（后端失败也要保证本地登出） */
    function signOut() {
        return api.signOut()
            .catch(function () { /* 网络异常也允许本地登出 */ })
            .then(function () {
                clearSession();
                return state;
            });
    }

    /** 供同步模块记录时间戳 */
    function markSynced(at) {
        state.lastSyncAt = at || Date.now();
        util.writeSetting('lolilota_last_sync', state.lastSyncAt);
        emit();
    }

    NS.auth = {
        state: state,
        getState: getState,
        onChange: onChange,
        bootstrap: bootstrap,
        signIn: signIn,
        submit2fa: submit2fa,
        cancel2fa: cancel2fa,
        refresh: refresh,
        signOut: signOut,
        clearSession: clearSession,
        markSynced: markSynced
    };
})(window);
