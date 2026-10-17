/**
 * Lolilota · 账号系统 UI
 *
 * 右上角入口 + 账号面板：
 *   - 游客：登录表单（用户名/邮箱 + 密码），需要两步验证时切换到验证码视图
 *   - 已登录：资料卡、同步开关、立即同步、退出登录
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
    function sync() { return NS.sync; }
    // 依赖在调用时才解析：模块加载顺序不应影响功能（sync 与 auth 相互引用）
    function config() { return NS.config; }

    var fab = null;
    var modal = null;
    var pendingAction = null;

    // ---------------------------------------------------------------------
    // 右上下拉入口
    // ---------------------------------------------------------------------

    function mountFab() {
        fab = document.getElementById('accountFab');
        if (!fab) return;
        fab.addEventListener('click', function () {
            open();
        });
        renderFab(auth().getState());
    }

    function renderFab(state) {
        if (!fab) return;
        var avatar = fab.querySelector('.account-fab__avatar');
        var label = fab.querySelector('.account-fab__label');
        var user = state.user;

        fab.classList.toggle('is-authed', state.status === 'authed');
        fab.classList.toggle('is-guest', state.status !== 'authed');
        fab.classList.toggle('is-syncing', sync().state.status === 'busy');

        if (state.status === 'authed' && user) {
            var name = user.nickname || user.name || user.username || user.email || '已登录';
            label.textContent = name;
            if (user.avatar_url || user.avatar) {
                avatar.innerHTML = '<img src="' + util().escapeHtml(user.avatar_url || user.avatar) + '" alt="">';
            } else {
                avatar.textContent = util().initial(name);
            }
            fab.title = '账号：' + name;
        } else {
            label.textContent = '登录';
            avatar.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">'
                + '<circle cx="12" cy="8" r="3.6"></circle>'
                + '<path d="M4.5 20a7.5 7.5 0 0 1 15 0"></path></svg>';
            fab.title = '使用 Cdifit 账号登录';
        }
    }

    // ---------------------------------------------------------------------
    // 弹窗
    // ---------------------------------------------------------------------

    function open() {
        if (typeof JCuPupw === 'undefined') return;

        if (!modal) {
            modal = new JCuPupw({ id: 'lolilotaAccountModal' });
        }

        modal.open({
            title: '账号',
            size: 'md',
            content: function (container) {
                container.classList.add('account-dialog');
                pendingAction = { container: container };
                render(container);
            },
            buttons: [{ text: '关闭', type: 'default' }],
            onClose: function () {
                pendingAction = null;
            }
        });
    }

    function render(container) {
        if (!container) return;
        container.innerHTML = buildHtml(auth().getState(), sync().state);
        bind(container);
    }

    function refreshOpenDialog() {
        if (pendingAction && pendingAction.container) {
            // 保留输入框内容：只在状态切换时重绘
            render(pendingAction.container);
        }
    }

    // ---------------------------------------------------------------------
    // 视图
    // ---------------------------------------------------------------------

    function buildHtml(state, syncState) {
        if (state.status === 'authed') return viewProfile(state, syncState);
        if (state.status === 'pending_2fa') return view2fa(state);
        return viewSignIn(state);
    }

    function alertHtml(state) {
        if (!state.error) return '';
        return '<div class="account-alert is-error">' + util().escapeHtml(state.error.message || '操作失败') + '</div>';
    }

    function viewSignIn(state) {
        var name = (global.__LOLILOTA__ && global.__LOLILOTA__.siteName) || 'Cdifit';
        return ''
            + '<div class="account-section">'
            + '  <h3 class="account-section__title">使用 ' + util().escapeHtml(name) + ' 账号登录</h3>'
            + '  <div class="account-form" id="acctForm">'
            +      alertHtml(state)
            + '    <div class="account-field">'
            + '      <label for="acctUser">邮箱</label>'
            + '      <input id="acctUser" name="email" type="email" autocomplete="username" placeholder="you@example.com" autofocus>'
            + '    </div>'
            + '    <div class="account-field">'
            + '      <label for="acctPass">密码</label>'
            + '      <input id="acctPass" name="password" type="password" autocomplete="current-password" placeholder="请输入密码">'
            + '    </div>'
            + '    <div class="account-row">'
            + '      <label class="account-hint" style="display:flex;align-items:center;gap:6px">'
            + '        <input type="checkbox" id="acctRemember" checked> 记住登录状态'
            + '      </label>'
            + '      <button type="button" class="account-link" id="acctHelp">登录遇到问题？</button>'
            + '    </div>'
            + '    <button type="button" class="account-submit" id="acctSubmit">登录</button>'
            + '    <p class="account-hint">登录后可跨设备同步搜索历史。历史记录默认只存在本地，开启同步后才会上传。</p>'
            + '  </div>'
            + '</div>'
            + serverPanel(state);
    }

    function view2fa(state) {
        var pending = state.pending || {};
        var hint = pending.hint ? ('验证码已发送至 ' + util().escapeHtml(pending.hint)) : '请输入两步验证码（TOTP 或短信验证码）';
        return ''
            + '<div class="account-section">'
            + '  <h3 class="account-section__title">两步验证</h3>'
            + '  <div class="account-form" id="acct2faForm">'
            +      alertHtml(state)
            + '    <p class="account-hint">' + hint + '</p>'
            + '    <div class="account-otp" id="acctOtp">'
            + '      <input type="text" inputmode="numeric" maxlength="1" autocomplete="one-time-code">'
            + '      <input type="text" inputmode="numeric" maxlength="1">'
            + '      <input type="text" inputmode="numeric" maxlength="1">'
            + '      <input type="text" inputmode="numeric" maxlength="1">'
            + '      <input type="text" inputmode="numeric" maxlength="1">'
            + '      <input type="text" inputmode="numeric" maxlength="1">'
            + '    </div>'
            + '    <button type="button" class="account-submit" id="acct2faSubmit">验证并登录</button>'
            + '    <div class="account-row">'
            + '      <button type="button" class="account-link" id="acct2faBack">返回重新登录</button>'
            + '    </div>'
            + '  </div>'
            + '</div>';
    }

    function viewProfile(state, syncState) {
        var user = state.user || {};
        var name = user.nickname || user.name || user.username || '用户';
        var avatar = user.avatar_url || user.avatar;
        var avatarHtml = avatar
            ? '<img src="' + util().escapeHtml(avatar) + '" alt="">'
            : util().escapeHtml(util().initial(name));

        var rows = [
            ['账号 ID', user.id || user.uid || user.sub || '—'],
            ['邮箱', user.email || '—'],
            ['用户名', user.username || '—'],
            ['登录方式', (state.session && state.session.provider) || 'Cdifit']
        ];
        if (state.session && state.session.expiresAt) {
            rows.push(['会话有效期至', formatDateTime(state.session.expiresAt)]);
        }

        var kv = rows.map(function (r) {
            return '<dt>' + util().escapeHtml(r[0]) + '</dt><dd>' + util().escapeHtml(String(r[1])) + '</dd>';
        }).join('');

        return ''
            + '<div class="account-section">'
            + '  <div class="account-profile">'
            + '    <div class="account-profile__avatar">' + avatarHtml + '</div>'
            + '    <div class="account-profile__meta">'
            + '      <div class="account-profile__name">' + util().escapeHtml(name) + '</div>'
            + '      <div class="account-profile__sub">' + util().escapeHtml(user.email || 'Cdifit 账号已连接') + '</div>'
            + '    </div>'
            + '  </div>'
            + '  <dl class="account-kv">' + kv + '</dl>'
            + '</div>'
            + '<div class="account-section">'
            + '  <h3 class="account-section__title">搜索历史同步</h3>'
            + '  <div class="account-switch">'
            + '    <div class="account-switch__text">'
            + '      <b>跨设备同步</b>'
            + '      <span>关闭后历史只保存在本机</span>'
            + '    </div>'
            + '    <input type="checkbox" id="acctSyncToggle"' + (sync().isEnabled() ? ' checked' : '') + '>'
            + '  </div>'
            + '  <div class="account-row mt-12">'
            + '    <div class="sync-status ' + syncClass(syncState) + '">'
            + '      <span class="sync-status__dot"></span>'
            + '      <span id="acctSyncMsg">' + util().escapeHtml(syncState.message || '尚未同步') + '</span>'
            + '    </div>'
            + '    <button type="button" class="account-link" id="acctSyncNow">立即同步</button>'
            + '  </div>'
            + '</div>'
            + '<div class="account-section">'
            + '  <div class="account-row">'
            + '    <button type="button" class="account-link" id="acctSignOut">退出登录</button>'
            + '    <span class="account-hint">退出后本机历史保留</span>'
            + '  </div>'
            + '</div>';
    }

    function serverPanel(state) {
        // 未配置后端地址时表示同源部署，直接请求当前站点，不显示具体地址
        var isSameOrigin = !config().apiBase;
        var baseLabel = isSameOrigin ? '同源（当前站点）' : config().apiBase;
        var ok = state.status !== 'error';

        return ''
            + '<div class="account-section">'
            + '  <h3 class="account-section__title">后端服务</h3>'
            + '  <div class="sync-status ' + (state.status === 'error' ? 'is-error' : 'is-ok') + '">'
            + '    <span class="sync-status__dot"></span>'
            + '    <span>' + (ok ? '已连接' : '连接异常') + ' · ' + util().escapeHtml(baseLabel) + '</span>'
            + '  </div>'
            + '  <div class="account-row mt-12">'
            + '    <button type="button" class="account-link" id="acctSetApi">修改服务地址</button>'
            + '    <button type="button" class="account-link" id="acctTestApi">测试连接</button>'
            + '  </div>'
            + '  <p class="account-hint mt-8">' + util().escapeHtml(
                state.error ? state.error.message : '接口前缀 ' + config().apiPrefix
            ) + '</p>'
            + '</div>';
    }

    function syncClass(syncState) {
        if (syncState.status === 'ok') return 'is-ok';
        if (syncState.status === 'busy') return 'is-busy';
        if (syncState.status === 'error') return 'is-error';
        return '';
    }

    function formatDateTime(value) {
        var d = new Date(value);
        if (isNaN(d.getTime())) return String(value);
        var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    // ---------------------------------------------------------------------
    // 交互绑定
    // ---------------------------------------------------------------------

    function setBusy(btn, busy) {
        if (!btn) return;
        btn.disabled = !!busy;
        btn.classList.toggle('is-loading', !!busy);
    }

    function bind(container) {
        var state = auth().getState();

        // ---- 登录表单 ----
        var submit = container.querySelector('#acctSubmit');
        if (submit) {
            var doLogin = function () {
                var u = container.querySelector('#acctUser');
                var p = container.querySelector('#acctPass');
                var remember = container.querySelector('#acctRemember');
                if (!u.value.trim() || !p.value) {
                    auth().state.error = { message: '请输入邮箱和密码' };
                    refreshOpenDialog();
                    return;
                }
                setBusy(submit, true);
                auth().signIn({
                    // Cdifit 用邮箱登录；本地账号也接受邮箱形式的用户名
                    email: u.value.trim(),
                    username: u.value.trim(),
                    password: p.value,
                    remember: !!(remember && remember.checked)
                }).then(function () {
                    afterAuth();
                }).catch(function () {
                    setBusy(submit, false);
                    refreshOpenDialog();
                });
            };

            submit.addEventListener('click', doLogin);
            container.querySelector('#acctForm').addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    doLogin();
                }
            });
            container.querySelector('#acctPass').focus();
        }

        // ---- 两步验证 ----
        var otpBox = container.querySelector('#acctOtp');
        if (otpBox) {
            var inputs = otpBox.querySelectorAll('input');
            var readCode = function () {
                var s = '';
                for (var i = 0; i < inputs.length; i++) s += inputs[i].value;
                return s;
            };
            for (var i = 0; i < inputs.length; i++) {
                (function (idx) {
                    inputs[idx].addEventListener('input', function () {
                        this.value = this.value.replace(/\D/g, '').slice(0, 1);
                        if (this.value && idx < inputs.length - 1) inputs[idx + 1].focus();
                        if (readCode().length === inputs.length) {
                            var btn = container.querySelector('#acct2faSubmit');
                            if (btn) btn.click();
                        }
                    });
                    inputs[idx].addEventListener('keydown', function (e) {
                        if (e.key === 'Backspace' && !this.value && idx > 0) inputs[idx - 1].focus();
                    });
                })(i);
            }
            inputs[0].focus();

            var submit2fa = container.querySelector('#acct2faSubmit');
            submit2fa.addEventListener('click', function () {
                var code = readCode();
                if (code.length < 4) {
                    auth().state.error = { message: '请输入完整的验证码' };
                    refreshOpenDialog();
                    return;
                }
                setBusy(submit2fa, true);
                auth().submit2fa(code).then(function () {
                    afterAuth();
                }).catch(function () {
                    setBusy(submit2fa, false);
                    refreshOpenDialog();
                });
            });

            container.querySelector('#acct2faBack').addEventListener('click', function () {
                auth().cancel2fa();
                refreshOpenDialog();
            });
        }

        // ---- 已登录面板 ----
        var toggle = container.querySelector('#acctSyncToggle');
        if (toggle) {
            toggle.addEventListener('change', function () {
                var on = this.checked;
                Promise.resolve(sync().setEnabled(on)).then(function () {
                    refreshOpenDialog();
                });
            });
        }

        var syncNow = container.querySelector('#acctSyncNow');
        if (syncNow) {
            syncNow.addEventListener('click', function () {
                setBusy(syncNow, true);
                sync().fullSync({
                    getLocal: global.Lolilota.history.get,
                    saveLocal: global.Lolilota.history.save
                }).then(function () {
                    setBusy(syncNow, false);
                    render(container);
                    if (global.Lolilota.history && global.Lolilota.history.refreshUI) {
                        global.Lolilota.history.refreshUI();
                    }
                });
            });
        }

        var signOut = container.querySelector('#acctSignOut');
        if (signOut) {
            signOut.addEventListener('click', function () {
                var run = function () {
                    setBusy(signOut, true);
                    auth().signOut().then(function () {
                        render(container);
                    });
                };
                if (typeof JCuPupw !== 'undefined' && JCuPupw.confirm) {
                    JCuPupw.confirm({
                        title: '退出登录',
                        content: '确定要退出当前账号吗？本机搜索历史会保留。',
                        confirmText: '退出',
                        cancelText: '取消'
                    }).then(function (ok) { if (ok) run(); });
                } else if (global.confirm('确定要退出当前账号吗？')) {
                    run();
                }
            });
        }

        // ---- 服务地址 ----
        var setApi = container.querySelector('#acctSetApi');
        if (setApi) {
            setApi.addEventListener('click', function () {
                var current = config().apiBase;
                var next = global.prompt(
                    '请输入 PHP 后端服务地址（留空表示与当前页面同源）',
                    current
                );
                if (next === null || next.trim() === current) return;

                // 允许清空：清空即恢复同源模式
                var url = next.trim();
                config().setApiBase(url);

                if (!url) {
                    auth().clearSession();
                    auth().bootstrap().then(function () { render(container); });
                    return;
                }

                // 扩展环境需要该域名的 host 权限，否则请求会被浏览器拦掉
                ensureHostPermission(url).then(function (granted) {
                    if (!granted) {
                        if (typeof JCuPupw !== 'undefined' && JCuPupw.toast) {
                            JCuPupw.toast({ content: '未获得该域名的访问权限，请求可能失败', type: 'warning' });
                        }
                    }
                    auth().clearSession();
                    auth().bootstrap().then(function () { render(container); });
                });
            });
        }

        var testApi = container.querySelector('#acctTestApi');
        if (testApi) {
            testApi.addEventListener('click', function () {
                setBusy(testApi, true);
                api().health().then(function (data) {
                    setBusy(testApi, false);
                    if (typeof JCuPupw !== 'undefined' && JCuPupw.toast) {
                        JCuPupw.toast({ content: '后端正常：' + (data && data.name || 'lolilota') + ' ' + (data && data.version || ''), type: 'success' });
                    }
                }).catch(function (err) {
                    setBusy(testApi, false);
                    if (typeof JCuPupw !== 'undefined' && JCuPupw.toast) {
                        JCuPupw.toast({ content: '连接失败：' + err.message, type: 'error' });
                    }
                });
            });
        }

        var help = container.querySelector('#acctHelp');
        if (help) {
            help.addEventListener('click', function () {
                var msg = '登录使用 Cdifit 账号（邮箱 + 密码）。若提示邮箱或密码错误，'
                    + '请先在 Cdifit 侧确认账号可用；如果账号开启了两步验证，'
                    + '登录后需要输入验证器上的 6 位验证码。';
                if (typeof JCuPupw !== 'undefined' && JCuPupw.alert) {
                    JCuPupw.alert({ title: '登录帮助', content: '<p>' + util().escapeHtml(msg) + '</p>' });
                } else {
                    global.alert(msg);
                }
            });
        }
    }

    /** 登录成功后的统一收尾：关闭弹窗提示 + 首次同步 */
    function afterAuth() {
        renderFab(auth().getState());
        if (pendingAction && pendingAction.container) {
            render(pendingAction.container);
        }
        if (typeof JCuPupw !== 'undefined' && JCuPupw.toast) {
            JCuPupw.toast({ content: '登录成功', type: 'success', duration: 1800 });
        }
        sync().fullSync({
            getLocal: global.Lolilota.history.get,
            saveLocal: global.Lolilota.history.save
        });
    }

    // ---------------------------------------------------------------------
    // 扩展权限
    // ---------------------------------------------------------------------

    /**
     * 扩展环境访问非本机后端时，需要该域名的 host 权限，
     * 否则 fetch 会直接被浏览器拦掉。
     *
     * @param {string} base 后端地址
     * @returns {Promise<boolean>} 是否已获得权限
     */
    function ensureHostPermission(base) {
        if (!util().isExtension() || typeof chrome === 'undefined' || !chrome.permissions) {
            return Promise.resolve(true);
        }

        var origin;
        try {
            var u = new URL(base);
            origin = u.origin + '/*';
        } catch (e) {
            return Promise.resolve(false);
        }

        return new Promise(function (resolve) {
            chrome.permissions.contains({ origins: [origin] }, function (has) {
                if (has) {
                    resolve(true);
                    return;
                }
                // 需要用户点击授权，这里直接发起请求
                chrome.permissions.request({ origins: [origin] }, function (granted) {
                    resolve(!!granted);
                });
            });
        });
    }

    // ---------------------------------------------------------------------
    // 初始化
    // ---------------------------------------------------------------------

    function init() {
        mountFab();
        auth().onChange(function (state) {
            renderFab(state);
            if (pendingAction) render(pendingAction.container);
        });
        sync().onState(function () {
            renderFab(auth().getState());
        });
    }

    NS.account = {
        init: init,
        open: open,
        renderFab: renderFab,
        afterAuth: afterAuth,
        ensureHostPermission: ensureHostPermission
    };
})(window);
