/**
 * Lolilota · 起始页主逻辑
 *
 * 依赖模块（按加载顺序）：
 *   util().js  -> config.js -> api.js -> history().js -> sync().js -> auth().js -> account().js
 *
 * 本文件只负责：搜索、搜索建议、快捷操作、历史面板与导出面板的界面。
 * 数据读写交给 Lolilota.history，云端同步交给 Lolilota.sync，账号交给 Lolilota.account。
 */
if (typeof JCuPupw !== 'undefined') {
    new JCuPupw();
}

(function (global) {
    'use strict';

    var NS = global.Lolilota || {};
    // 依赖在调用时才解析：模块加载顺序不应影响功能（sync 与 auth 相互引用）
    function util() { return NS.util; }
    // 依赖在调用时才解析：模块加载顺序不应影响功能（sync 与 auth 相互引用）
    function history() { return NS.history; }
    // 依赖在调用时才解析：模块加载顺序不应影响功能（sync 与 auth 相互引用）
    function sync() { return NS.sync; }
    // 依赖在调用时才解析：模块加载顺序不应影响功能（sync 与 auth 相互引用）
    function auth() { return NS.auth; }
    // 依赖在调用时才解析：模块加载顺序不应影响功能（sync 与 auth 相互引用）
    function account() { return NS.account; }

    // config 在启动阶段才用到，这里按调用时解析
    function config() { return NS.config; }

    // 与搜索入口保持一致：优先 cn.bing.com，失败再试 api.bing.com
    var BING_SUGGEST_URLS = [
        'https://cn.bing.com/osjson.aspx',
        'https://api.bing.com/osjson.aspx'
    ];
    var SEARCH_URL = 'https://cn.bing.com/search?q=';
    var DEBOUNCE_MS = 220;
    var MAX_SUGGESTIONS = 8;
    var SUGGEST_TIMEOUT = 4000;
    var SUGGEST_CACHE_TTL = 60000;

    // 扩展环境（MV3 页面 CSP 禁止远程脚本，JSONP 不可用，需走 fetch）
    var IS_EXTENSION = util().isExtension();

    var form = document.getElementById('searchForm');
    var input = document.getElementById('q');
    var suggestBox = document.getElementById('suggest');
    var historyBtn = document.getElementById('historyBtn');
    var historyCountPill = document.getElementById('historyCountPill');
    var exportOpenBtn = document.getElementById('exportOpenBtn');
    var importBtn = document.getElementById('importBtn');
    var clearBtn = document.getElementById('clearBtn');
    var importFile = document.getElementById('importFile');
    var aboutLink = document.getElementById('aboutLink');

    var suggestItems = [];
    var activeIndex = -1;
    var debounceTimer = null;
    var jsonpCounter = 0;
    var suggestCache = Object.create(null);
    var suggestCacheSize = 0;
    var suggestSeq = 0;
    var suggestAbort = null;
    var historyModal = null;
    var exportModal = null;
    var historyContainer = null;

    // ---------------------------------------------------------------------
    // 历史记录：计数与推送
    // ---------------------------------------------------------------------

    function updateCountPill() {
        if (historyCountPill) historyCountPill.textContent = history().count();
    }

    function afterHistoryChange() {
        updateCountPill();
        if (sync && sync().schedulePush) sync().schedulePush(history().get);
    }

    // ---------------------------------------------------------------------
    // 轻量交互组件（优先使用 JCuPupw，缺失时降级）
    // ---------------------------------------------------------------------

    function toast(content, type) {
        if (typeof JCuPupw !== 'undefined' && typeof JCuPupw.toast === 'function') {
            JCuPupw.toast({
                content: content,
                type: { success: 'success', warning: 'warning', error: 'error', info: 'info' }[type] || 'info',
                duration: 2200
            });
            return;
        }
        var el = document.createElement('div');
        el.style.cssText = 'position:fixed;top:24px;left:50%;transform:translateX(-50%);'
            + 'padding:10px 22px;font-size:13px;font-weight:600;color:#fff;background:#5a7ba8;'
            + 'border-radius:999px;z-index:99999;box-shadow:0 6px 18px rgba(120,155,210,0.4)';
        el.textContent = content;
        document.body.appendChild(el);
        setTimeout(function () { el.remove(); }, 2200);
    }

    function alertBox(config) {
        if (typeof JCuPupw !== 'undefined' && typeof JCuPupw.alert === 'function') {
            return JCuPupw.alert(config);
        }
        global.alert(typeof config.content === 'string' ? config.content : '');
        return Promise.resolve();
    }

    function confirmBox(config) {
        if (typeof JCuPupw !== 'undefined' && typeof JCuPupw.confirm === 'function') {
            return JCuPupw.confirm(config);
        }
        return Promise.resolve(global.confirm(typeof config.content === 'string' ? config.content : ''));
    }

    // ---------------------------------------------------------------------
    // 搜索
    // ---------------------------------------------------------------------

    function shakeInput() {
        if (!input) return;
        input.classList.remove('shake');
        void input.offsetWidth;
        input.classList.add('shake');
        input.focus();
    }

    function doSearch(keyword) {
        var q = (keyword || (input ? input.value : '')).trim();
        if (!q) {
            shakeInput();
            toast('请输入搜索关键词', 'warning');
            return;
        }
        history().add(q);
        afterHistoryChange();
        hideSuggest();
        var url = SEARCH_URL + encodeURIComponent(q);
        var newWin = global.open(url, '_blank', 'noopener,noreferrer');
        if (!newWin) toast('新窗口被拦截，请允许弹出窗口', 'warning');
    }

    // ---------------------------------------------------------------------
    // 搜索建议
    // ---------------------------------------------------------------------

    /** 把各种响应形状统一成字符串数组 */
    function normalizeSuggestions(data) {
        var raw = [];

        if (Array.isArray(data)) {
            // Bing osjson: ["关键词", ["建议1", "建议2"]]
            raw = Array.isArray(data[1]) ? data[1] : data;
        } else if (data && Array.isArray(data.suggestions)) {
            // 后端 /api/v1/suggest 的形状
            raw = data.suggestions;
        } else if (data && data.AS && Array.isArray(data.AS.Results)) {
            // Bing qsonhs 的形状
            data.AS.Results.forEach(function (group) {
                if (group && Array.isArray(group.Suggests)) {
                    group.Suggests.forEach(function (item) { raw.push(item && item.Txt); });
                }
            });
        }

        var out = [];

        for (var i = 0; i < raw.length && out.length < MAX_SUGGESTIONS; i++) {
            var text = raw[i];

            if (text && typeof text === 'object') text = text.Txt;
            if (typeof text !== 'string') continue;

            text = text.trim();
            if (!text || out.indexOf(text) >= 0) continue;

            out.push(text);
        }

        return out;
    }

    function applySuggestions(list, term) {
        if (!list || !list.length || input.value.trim() !== term) {
            hideSuggest();
            return;
        }
        renderSuggest(list.slice(0, MAX_SUGGESTIONS), term);
    }

    function suggestCacheGet(term) {
        var entry = suggestCache[term];

        if (!entry) return null;

        if (Date.now() - entry.at > SUGGEST_CACHE_TTL) {
            delete suggestCache[term];
            suggestCacheSize--;
            return null;
        }

        return entry.list;
    }

    function suggestCacheSet(term, list) {
        if (!term) return;

        if (!suggestCache[term]) suggestCacheSize++;
        suggestCache[term] = { at: Date.now(), list: list.slice(0, MAX_SUGGESTIONS) };

        // 简单上限，避免长时间输入把内存撑大
        if (suggestCacheSize > 80) {
            suggestCache = Object.create(null);
            suggestCacheSize = 0;
        }
    }

    function abortSuggestRequest() {
        if (!suggestAbort) return;

        try { suggestAbort.abort(); } catch (e) { /* ignore */ }
        suggestAbort = null;
    }

    /** 后端代理：站点与扩展的共同首选通道 */
    function fetchSuggestionsViaBackend(term, seq) {
        // 扩展没有配置后端地址时不存在"同源"后端，直接走带 host 权限的 fetch
        if (IS_EXTENSION && !config().apiBase) {
            return false;
        }

        var apiClient = NS.api;

        if (!apiClient || typeof apiClient.suggest !== 'function') {
            return false;
        }

        try {
            apiClient.suggest(term).then(function (data) {
                if (seq !== suggestSeq) return;

                var list = normalizeSuggestions(data);
                suggestCacheSet(term, list);
                applySuggestions(list, term);
            }, function () {
                if (seq !== suggestSeq) return;
                fallbackSuggestions(term, seq);
            });

            return true;
        } catch (e) {
            return false;
        }
    }

    /** 扩展环境：带 host 权限的跨域 fetch（manifest 已声明 cn / api.bing.com） */
    function fetchSuggestionsViaFetch(term, seq, index) {
        index = index || 0;

        var base = BING_SUGGEST_URLS[index];

        if (!base) {
            hideSuggest();
            return;
        }

        if (typeof fetch !== 'function') {
            hideSuggest();
            return;
        }

        var init = {
            headers: { 'Accept': 'application/json' },
            credentials: 'omit',
            cache: 'no-store'
        };

        if (typeof AbortController !== 'undefined') {
            suggestAbort = new AbortController();
            init.signal = suggestAbort.signal;
        }

        fetch(base + '?query=' + encodeURIComponent(term), init)
            .then(function (res) {
                if (!res.ok) throw new Error('bad status ' + res.status);
                return res.json();
            })
            .then(function (data) {
                if (seq !== suggestSeq) return;

                var list = normalizeSuggestions(data);
                suggestCacheSet(term, list);
                applySuggestions(list, term);
            })
            .catch(function () {
                if (seq !== suggestSeq) return;

                if (index + 1 < BING_SUGGEST_URLS.length) {
                    fetchSuggestionsViaFetch(term, seq, index + 1);
                } else {
                    hideSuggest();
                }
            });
    }

    /** 普通网页环境：JSONP（Bing 不返回 CORS 头） */
    function fetchSuggestionsViaJsonp(term, seq, index) {
        index = index || 0;

        var base = BING_SUGGEST_URLS[index];

        if (!base) {
            hideSuggest();
            return;
        }

        var callbackName = 'bingSuggestCb' + (++jsonpCounter);
        var finished = false;
        var script = document.createElement('script');

        var cleanup = function () {
            try { delete global[callbackName]; } catch (e) { /* ignore */ }
            if (script.parentNode) script.parentNode.removeChild(script);
        };

        var retry = function () {
            if (seq !== suggestSeq) return;

            if (index + 1 < BING_SUGGEST_URLS.length) {
                fetchSuggestionsViaJsonp(term, seq, index + 1);
            } else {
                hideSuggest();
            }
        };

        global[callbackName] = function (data) {
            if (finished) return;
            finished = true;
            cleanup();

            if (seq !== suggestSeq) return;

            var list = normalizeSuggestions(data);
            suggestCacheSet(term, list);
            applySuggestions(list, term);
        };

        script.id = callbackName;
        script.src = base
            + '?query=' + encodeURIComponent(term)
            + '&JsonType=callback&JsonCallback=' + callbackName;
        script.onerror = function () {
            if (finished) return;
            finished = true;
            cleanup();
            retry();
        };

        document.body.appendChild(script);

        setTimeout(function () {
            if (finished) return;
            finished = true;
            cleanup();
            retry();
        }, SUGGEST_TIMEOUT);
    }

    function fallbackSuggestions(term, seq) {
        if (IS_EXTENSION) {
            fetchSuggestionsViaFetch(term, seq, 0);
        } else {
            fetchSuggestionsViaJsonp(term, seq, 0);
        }
    }

    function fetchSuggestions(term) {
        if (term.length < 1) {
            hideSuggest();
            return;
        }

        var cached = suggestCacheGet(term);

        if (cached !== null) {
            // 命中缓存也要作废更早的请求，避免旧响应回来把当前面板关掉
            suggestSeq++;
            abortSuggestRequest();
            applySuggestions(cached, term);
            return;
        }

        var seq = ++suggestSeq;
        abortSuggestRequest();

        // 首选后端代理；不可达时再按运行形态走跨域 fetch / JSONP
        if (!fetchSuggestionsViaBackend(term, seq)) {
            fallbackSuggestions(term, seq);
        }
    }

    function renderSuggest(list, term) {
        if (!list || !list.length) {
            hideSuggest();
            return;
        }

        suggestBox.innerHTML = '';
        suggestItems = [];
        activeIndex = -1;

        var iconSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" '
            + 'stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle>'
            + '<line x1="16.5" y1="16.5" x2="21" y2="21"></line></svg>';

        list.forEach(function (text, i) {
            var item = document.createElement('div');
            item.className = 'suggest-item';
            item.setAttribute('role', 'option');
            item.dataset.index = i;
            item.dataset.text = text;

            var idx = text.toLowerCase().indexOf(term.toLowerCase());
            var html = iconSvg + '<span class="suggest-text">';
            if (idx >= 0 && term.length > 0) {
                html += util().escapeHtml(text.slice(0, idx))
                    + '<mark>' + util().escapeHtml(text.slice(idx, idx + term.length)) + '</mark>'
                    + util().escapeHtml(text.slice(idx + term.length));
            } else {
                html += util().escapeHtml(text);
            }
            html += '</span>';
            item.innerHTML = html;

            item.addEventListener('mousedown', function (e) {
                e.preventDefault();
                input.value = text;
                doSearch(text);
            });

            item.addEventListener('mouseenter', function () { setActive(i); });

            suggestBox.appendChild(item);
            suggestItems.push(item);
        });

        suggestBox.hidden = false;
    }

    function setActive(index) {
        suggestItems.forEach(function (el, i) {
            el.classList.toggle('active', i === index);
        });
        activeIndex = index;
    }

    function hideSuggest() {
        suggestSeq++;
        abortSuggestRequest();

        if (!suggestBox) return;
        suggestBox.hidden = true;
        suggestBox.innerHTML = '';
        suggestItems = [];
        activeIndex = -1;
    }

    function handleInput() {
        var val = input.value;
        clearTimeout(debounceTimer);
        if (!val.trim()) {
            hideSuggest();
            return;
        }
        debounceTimer = setTimeout(function () { fetchSuggestions(val.trim()); }, DEBOUNCE_MS);
    }

    function handleKeydown(e) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!suggestItems.length) return;
            setActive((activeIndex + 1) % suggestItems.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (!suggestItems.length) return;
            setActive((activeIndex - 1 + suggestItems.length) % suggestItems.length);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIndex >= 0 && suggestItems[activeIndex]) {
                var chosen = suggestItems[activeIndex].dataset.text;
                input.value = chosen;
                doSearch(chosen);
            } else {
                doSearch();
            }
        } else if (e.key === 'Escape') {
            hideSuggest();
        }
    }

    // ---------------------------------------------------------------------
    // 历史面板
    // ---------------------------------------------------------------------

    function historyItemRow(item, index, container) {
        var li = document.createElement('li');
        li.dataset.index = index;

        var span = document.createElement('span');
        span.className = 'hist-text';
        span.textContent = item.text;
        span.title = item.text;

        var time = document.createElement('span');
        time.className = 'hist-time';
        time.textContent = util().formatTime(item.time);

        li.appendChild(span);
        li.appendChild(time);

        if (item.id) {
            var badge = document.createElement('span');
            badge.className = 'hist-sync';
            badge.textContent = '云';
            badge.title = '已同步到云端';
            li.appendChild(badge);
        }

        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'hist-del';
        del.textContent = '\u00D7';
        del.title = '删除此条';
        del.addEventListener('click', function (e) {
            e.stopPropagation();
            var removed = history().get()[index];
            history().remove(index);
            if (removed && removed.id && sync) sync().removeRemote(removed.id);
            afterHistoryChange();
            renderHistoryInto(container);
        });

        li.appendChild(del);

        li.addEventListener('click', function () {
            if (historyModal) historyModal.close();
            input.value = item.text;
            doSearch(item.text);
        });

        return li;
    }

    function renderHistoryInto(container) {
        historyContainer = container;
        var list = history().get();
        container.innerHTML = '';

        var summary = document.createElement('div');
        summary.className = 'hist-summary';
        var syncText = '';
        if (auth && auth().getState().status === 'authed' && sync && sync().isEnabled()) {
            syncText = '<span>云端同步已开启</span>';
        }
        summary.innerHTML = '<span>共 <span class="num">' + list.length + '</span> 条记录</span>' + syncText;
        container.appendChild(summary);

        if (!list.length) {
            var empty = document.createElement('div');
            empty.className = 'hist-empty';
            container.appendChild(empty);
            return;
        }

        var ul = document.createElement('ul');
        ul.className = 'hist-list';
        list.forEach(function (item, i) {
            ul.appendChild(historyItemRow(item, i, container));
        });
        container.appendChild(ul);
    }

    function openHistoryModal() {
        if (!historyModal) historyModal = new JCuPupw({ id: 'lolilotaHistoryModal' });

        historyModal.open({
            title: '搜索历史',
            size: 'md',
            draggable: true,
            content: function (container) {
                container.classList.add('hist-dialog');
                renderHistoryInto(container);
            },
            buttons: [{ text: '关闭', type: 'default' }],
            onClose: function () {
                historyContainer = null;
                updateCountPill();
            }
        });
    }

    // ---------------------------------------------------------------------
    // 导入 / 导出
    // ---------------------------------------------------------------------

    function openExportModal() {
        if (!history().count()) {
            toast('暂无历史记录可导出', 'warning');
            return;
        }

        if (!exportModal) exportModal = new JCuPupw({ id: 'lolilotaExportModal' });

        exportModal.open({
            title: '导出搜索历史',
            size: 'md',
            content: function (container) {
                container.innerHTML = ''
                    + '<p class="jc-export-desc">选择格式后文件会自动下载，JSON 格式可再次导入。</p>'
                    + '<div class="jc-export-grid">'
                    + '<button type="button" class="jc-export-option" data-format="json"><b>JSON</b><span>完整结构化数据</span></button>'
                    + '<button type="button" class="jc-export-option" data-format="csv"><b>CSV</b><span>可用表格软件打开</span></button>'
                    + '<button type="button" class="jc-export-option" data-format="txt"><b>TXT</b><span>纯文本列表</span></button>'
                    + '<button type="button" class="jc-export-option" data-format="md"><b>Markdown</b><span>适合笔记与文档</span></button>'
                    + '<button type="button" class="jc-export-option" data-format="html"><b>HTML</b><span>浏览器中直接查看</span></button>'
                    + '</div>';

                var btns = container.querySelectorAll('.jc-export-option');
                for (var i = 0; i < btns.length; i++) {
                    btns[i].addEventListener('click', function () {
                        var ok = history().download(this.getAttribute('data-format'));
                        if (ok) toast('已导出 ' + this.getAttribute('data-format').toUpperCase() + ' 文件', 'success');
                        exportModal.close();
                    });
                }
            },
            buttons: [{ text: '取消', type: 'default' }]
        });
    }

    function importData(file) {
        var reader = new FileReader();
        reader.onload = function (e) {
            try {
                var total = history().importFromText(e.target.result);
                afterHistoryChange();
                toast(total ? ('已导入，现有 ' + total + ' 条记录') : '文件中没有有效的历史记录', total ? 'success' : 'warning');
            } catch (err) {
                toast('导入失败，文件格式不正确', 'error');
            }
        };
        reader.readAsText(file);
    }

    // ---------------------------------------------------------------------
    // 事件绑定
    // ---------------------------------------------------------------------

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        doSearch();
    });

    input.addEventListener('input', handleInput);
    input.addEventListener('keydown', handleKeydown);
    input.addEventListener('focus', function () {
        if (input.value.trim() && !suggestItems.length) fetchSuggestions(input.value.trim());
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.search-wrap')) hideSuggest();
    });

    if (historyBtn) historyBtn.addEventListener('click', openHistoryModal);
    if (exportOpenBtn) exportOpenBtn.addEventListener('click', openExportModal);

    if (importBtn) {
        importBtn.addEventListener('click', function () { importFile.click(); });
    }

    if (importFile) {
        importFile.addEventListener('change', function () {
            if (importFile.files && importFile.files[0]) {
                importData(importFile.files[0]);
                importFile.value = '';
            }
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (!history().count()) {
                toast('暂无历史记录', 'warning');
                return;
            }
            confirmBox({
                title: '清空搜索历史',
                content: '确定要清空所有搜索历史吗？此操作不可恢复。'
                    + (sync && sync().canSync() ? '云端记录也会一并删除。' : ''),
                confirmText: '确定清空',
                cancelText: '取消'
            }).then(function (ok) {
                if (!ok) return;
                var hadCloud = sync && sync().canSync();
                history().clear();
                afterHistoryChange();
                if (hadCloud) {
                    NS.api.clearHistory().then(function () {
                        toast('已清空本地与云端历史', 'success');
                    }, function () {
                        toast('本地已清空，云端删除失败', 'warning');
                    });
                } else {
                    toast('已清空搜索历史', 'success');
                }
            });
        });
    }

    if (aboutLink) {
        aboutLink.addEventListener('click', function (e) {
            e.preventDefault();
            alertBox({
                title: '关于 Lolilota',
                content: '<p style="margin:0 0 10px">Lolilota 是一个简洁的 Bing 搜索起始页。搜索建议由 Bing Autosuggest 实时提供；'
                    + '历史记录默认只保存在本地浏览器，登录后可选跨设备同步。</p>'
                    + '<ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.9">'
                    + '<li>账号系统基于 Cdifit，登录后可同步搜索历史</li>'
                    + '<li>支持导出 JSON / CSV / TXT / Markdown / HTML，并可从 JSON 文件导入</li>'
                    + '<li>快捷键：按 / 聚焦搜索框，方向键上下选择建议，Esc 关闭面板</li>'
                    + '</ul>'
            });
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === '/' && document.activeElement !== input) {
            e.preventDefault();
            input.focus();
        }
        if (e.key === 'Escape') hideSuggest();
    });

    global.addEventListener('load', function () {
        if (global.innerWidth > 768) input.focus();
    });

    // ---------------------------------------------------------------------
    // 启动
    // ---------------------------------------------------------------------

    history().onChange(function () {
        updateCountPill();
        if (historyContainer) renderHistoryInto(historyContainer);
    });

    updateCountPill();

    // 账号 + 云端同步启动（后端不可达时静默降级为纯本地）
    var boot = config().ready()
        .then(function () {
            if (account()) account().init();
            return auth().bootstrap();
        })
        .then(function (state) {
            if (state.status === 'authed' && sync && sync().isEnabled()) {
                return sync().fullSync({
                    getLocal: history().get,
                    saveLocal: history().save,
                    silent: true
                });
            }
            return null;
        })
        .catch(function () { /* 纯本地模式 */ });

    NS.app = {
        boot: boot,
        doSearch: doSearch,
        openHistoryModal: openHistoryModal,
        openExportModal: openExportModal,
        refreshUI: updateCountPill
    };
})(window);
