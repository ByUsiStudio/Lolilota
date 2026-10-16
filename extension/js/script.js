if (typeof JCuPupw !== 'undefined') {
    new JCuPupw();
}

(function () {
    'use strict';

    var STORAGE_KEY = 'lolilota_history';
    var MAX_HISTORY = 200;
    var BING_SUGGEST_URL = 'https://api.bing.com/osjson.aspx';
    var SEARCH_URL = 'https://cn.bing.com/search?q=';
    var DEBOUNCE_MS = 220;
    var MAX_SUGGESTIONS = 8;

    // 扩展环境（Chrome/Edge MV3 页面 CSP 禁止远程脚本，JSONP 不可用，需走 fetch）
    var IS_EXTENSION = (typeof chrome !== 'undefined' && chrome.runtime && !!chrome.runtime.id)
        || (typeof browser !== 'undefined' && browser.runtime && !!browser.runtime.id);

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
    var historyModal = null;
    var exportModal = null;

    function getHistory() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return [];
            var data = JSON.parse(raw);
            return Array.isArray(data) ? data : [];
        } catch (e) {
            return [];
        }
    }

    function saveHistory(list) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
        } catch (e) {
        }
    }

    function updateCountPill() {
        historyCountPill.textContent = getHistory().length;
    }

    function addHistory(keyword) {
        if (!keyword || !keyword.trim()) return;
        var q = keyword.trim();
        var list = getHistory();
        var idx = -1;
        for (var i = 0; i < list.length; i++) {
            if (list[i].text === q) { idx = i; break; }
        }
        if (idx >= 0) list.splice(idx, 1);
        list.unshift({ text: q, time: Date.now() });
        if (list.length > MAX_HISTORY) list = list.slice(0, MAX_HISTORY);
        saveHistory(list);
        updateCountPill();
    }

    function removeHistory(index) {
        var list = getHistory();
        if (index < 0 || index >= list.length) return;
        list.splice(index, 1);
        saveHistory(list);
        updateCountPill();
    }

    function clearHistory() {
        saveHistory([]);
        updateCountPill();
    }

    function toast(content, type) {
        if (typeof JCuPupw !== 'undefined' && typeof JCuPupw.toast === 'function') {
            var map = { success: 'success', warning: 'warning', error: 'error', info: 'info' };
            JCuPupw.toast({
                content: content,
                type: map[type] || 'info',
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
        window.alert(typeof config.content === 'string' ? config.content : '');
        return Promise.resolve();
    }

    function confirmBox(config) {
        if (typeof JCuPupw !== 'undefined' && typeof JCuPupw.confirm === 'function') {
            return JCuPupw.confirm(config);
        }
        return Promise.resolve(window.confirm(typeof config.content === 'string' ? config.content : ''));
    }

    function shakeInput() {
        input.classList.remove('shake');
        void input.offsetWidth;
        input.classList.add('shake');
        input.focus();
    }

    function doSearch(keyword) {
        var q = (keyword || input.value).trim();
        if (!q) {
            shakeInput();
            toast('请输入搜索关键词', 'warning');
            return;
        }
        addHistory(q);
        hideSuggest();
        var url = SEARCH_URL + encodeURIComponent(q);
        var newWin = window.open(url, '_blank', 'noopener,noreferrer');
        if (!newWin) {
            toast('新窗口被拦截，请允许弹出窗口', 'warning');
        }
    }

    function applySuggestions(data, term) {
        var raw = data && data[1];
        if (!raw || !raw.length || input.value.trim() !== term) {
            hideSuggest();
            return;
        }
        renderSuggest(raw.slice(0, MAX_SUGGESTIONS), term);
    }

    // 扩展环境：host_permissions 授权后可直接跨域 fetch，返回 JSON 数组
    function fetchSuggestionsViaFetch(term) {
        fetch(BING_SUGGEST_URL + '?query=' + encodeURIComponent(term))
            .then(function (res) {
                if (!res.ok) throw new Error('bad status');
                return res.json();
            })
            .then(function (data) { applySuggestions(data, term); })
            .catch(function () { hideSuggest(); });
    }

    function fetchSuggestions(term) {
        if (term.length < 1) {
            hideSuggest();
            return;
        }

        if (IS_EXTENSION) {
            fetchSuggestionsViaFetch(term);
            return;
        }

        var callbackName = 'bingSuggestCb' + (++jsonpCounter);

        window[callbackName] = function (data) {
            try {
                applySuggestions(data, term);
            } catch (e) {
                hideSuggest();
            } finally {
                delete window[callbackName];
                var s = document.getElementById(callbackName);
                if (s) s.remove();
            }
        };

        var script = document.createElement('script');
        script.id = callbackName;
        script.src = BING_SUGGEST_URL
            + '?query=' + encodeURIComponent(term)
            + '&JsonType=callback&JsonCallback=' + callbackName;
        script.onerror = function () {
            hideSuggest();
            delete window[callbackName];
            script.remove();
        };

        document.body.appendChild(script);

        setTimeout(function () {
            if (window[callbackName]) {
                delete window[callbackName];
                if (script.parentNode) script.remove();
            }
        }, 4000);
    }

    function renderSuggest(list, term) {
        if (!list || !list.length) {
            hideSuggest();
            return;
        }

        suggestBox.innerHTML = '';
        suggestItems = [];
        activeIndex = -1;

        var iconSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="16.5" y1="16.5" x2="21" y2="21"></line></svg>';

        list.forEach(function (text, i) {
            var item = document.createElement('div');
            item.className = 'suggest-item';
            item.setAttribute('role', 'option');
            item.dataset.index = i;
            item.dataset.text = text;

            var lower = text.toLowerCase();
            var idx = lower.indexOf(term.toLowerCase());
            var html = iconSvg + '<span class="suggest-text">';
            if (idx >= 0 && term.length > 0) {
                html += escapeHtml(text.slice(0, idx))
                    + '<mark>' + escapeHtml(text.slice(idx, idx + term.length)) + '</mark>'
                    + escapeHtml(text.slice(idx + term.length));
            } else {
                html += escapeHtml(text);
            }
            html += '</span>';
            item.innerHTML = html;

            item.addEventListener('mousedown', function (e) {
                e.preventDefault();
                input.value = text;
                doSearch(text);
            });

            item.addEventListener('mouseenter', function () {
                setActive(i);
            });

            suggestBox.appendChild(item);
            suggestItems.push(item);
        });

        suggestBox.hidden = false;
    }

    function escapeHtml(str) {
        return str.replace(/[&<>"']/g, function (m) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m];
        });
    }

    function setActive(index) {
        suggestItems.forEach(function (el, i) {
            el.classList.toggle('active', i === index);
        });
        activeIndex = index;
    }

    function hideSuggest() {
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
        debounceTimer = setTimeout(function () {
            fetchSuggestions(val.trim());
        }, DEBOUNCE_MS);
    }

    function handleKeydown(e) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (suggestItems.length === 0) return;
            setActive((activeIndex + 1) % suggestItems.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (suggestItems.length === 0) return;
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

    function buildExportContent(format, list) {
        var filename = 'lolilota-history-' + new Date().toISOString().slice(0, 10);
        var content = '';
        var mime = 'text/plain';

        if (format === 'json') {
            content = JSON.stringify(list, null, 2);
            filename += '.json';
            mime = 'application/json';
        } else if (format === 'csv') {
            content = '\uFEFF关键词,搜索时间\n';
            list.forEach(function (item) {
                var t = new Date(item.time).toLocaleString('zh-CN');
                content += '"' + item.text.replace(/"/g, '""') + '","' + t + '"\n';
            });
            filename += '.csv';
            mime = 'text/csv';
        } else if (format === 'txt') {
            list.forEach(function (item) { content += item.text + '\n'; });
            filename += '.txt';
        } else if (format === 'md') {
            content = '# Lolilota 搜索历史\n\n| # | 关键词 | 时间 |\n|---|--------|------|\n';
            list.forEach(function (item, i) {
                var t = new Date(item.time).toLocaleString('zh-CN');
                content += '| ' + (i + 1) + ' | ' + item.text + ' | ' + t + ' |\n';
            });
            filename += '.md';
            mime = 'text/markdown';
        } else if (format === 'html') {
            var rows = '';
            list.forEach(function (item, i) {
                var t = new Date(item.time).toLocaleString('zh-CN');
                rows += '<tr><td>' + (i + 1) + '</td><td>' + escapeHtml(item.text)
                    + '</td><td>' + t + '</td></tr>';
            });
            content = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
                + '<title>Lolilota 搜索历史</title>'
                + '<style>body{font-family:sans-serif;padding:24px;background:#eef4fb}'
                + 'table{border-collapse:collapse;width:100%;background:#fff;border-radius:12px;overflow:hidden}'
                + 'th,td{border:1px solid #dce8f5;padding:8px 12px;text-align:left}'
                + 'th{background:#8fb3e0;color:#fff}</style></head><body>'
                + '<h1 style="color:#4a6fa5">Lolilota 搜索历史</h1>'
                + '<table><thead><tr><th>#</th><th>关键词</th><th>时间</th></tr></thead>'
                + '<tbody>' + rows + '</tbody></table></body></html>';
            filename += '.html';
            mime = 'text/html';
        }

        return { filename: filename, content: content, mime: mime };
    }

    function download(format) {
        var list = getHistory();
        if (list.length === 0) {
            toast('暂无历史记录可导出', 'warning');
            return;
        }
        var pack = buildExportContent(format, list);
        var blob = new Blob([pack.content], { type: pack.mime + ';charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = pack.filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        toast('已导出 ' + format.toUpperCase() + ' 文件', 'success');
    }

    function importData(file) {
        var reader = new FileReader();
        reader.onload = function (e) {
            try {
                var data = JSON.parse(e.target.result);
                if (!Array.isArray(data)) throw new Error('invalid');
                var valid = data.filter(function (item) {
                    return item && typeof item.text === 'string';
                });
                if (valid.length === 0) {
                    toast('文件中没有有效的历史记录', 'warning');
                    return;
                }
                var merged = getHistory();
                valid.forEach(function (item) {
                    var exists = merged.some(function (m) { return m.text === item.text; });
                    if (!exists) {
                        merged.unshift({ text: item.text, time: item.time || Date.now() });
                    }
                });
                merged.sort(function (a, b) { return (b.time || 0) - (a.time || 0); });
                if (merged.length > MAX_HISTORY) merged = merged.slice(0, MAX_HISTORY);
                saveHistory(merged);
                updateCountPill();
                toast('已导入 ' + valid.length + ' 条记录', 'success');
            } catch (err) {
                toast('导入失败，文件格式不正确', 'error');
            }
        };
        reader.readAsText(file);
    }

    function openHistoryModal() {
        if (!historyModal) {
            historyModal = new JCuPupw({ id: 'lolilotaHistoryModal' });
        }

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
                updateCountPill();
            }
        });
    }

    function renderHistoryInto(container) {
        var list = getHistory();
        container.innerHTML = '';

        var summary = document.createElement('div');
        summary.className = 'hist-summary';
        summary.innerHTML = '<span>共 <span class="num">' + list.length + '</span> 条记录</span>'
            + '<span>点击任意记录重新搜索</span>';
        container.appendChild(summary);

        if (list.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'hist-empty';
            container.appendChild(empty);
            return;
        }

        var ul = document.createElement('ul');
        ul.className = 'hist-list';

        list.forEach(function (item, i) {
            var li = document.createElement('li');
            li.dataset.index = i;

            var span = document.createElement('span');
            span.className = 'hist-text';
            span.textContent = item.text;
            span.title = item.text;

            var time = document.createElement('span');
            time.className = 'hist-time';
            time.textContent = formatTime(item.time);

            var del = document.createElement('button');
            del.type = 'button';
            del.className = 'hist-del';
            del.textContent = '\u00D7';
            del.title = '删除此条';
            del.addEventListener('click', function (e) {
                e.stopPropagation();
                removeHistory(i);
                renderHistoryInto(container);
            });

            li.appendChild(span);
            li.appendChild(time);
            li.appendChild(del);

            li.addEventListener('click', function () {
                if (historyModal) historyModal.close();
                input.value = item.text;
                doSearch(item.text);
            });

            ul.appendChild(li);
        });

        container.appendChild(ul);
    }

    function formatTime(ts) {
        if (!ts) return '';
        var d = new Date(ts);
        var now = new Date();
        var sameDay = d.toDateString() === now.toDateString();
        var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        if (sameDay) return pad(d.getHours()) + ':' + pad(d.getMinutes());
        return (d.getMonth() + 1) + '/' + d.getDate();
    }

    function openExportModal() {
        if (getHistory().length === 0) {
            toast('暂无历史记录可导出', 'warning');
            return;
        }

        if (!exportModal) {
            exportModal = new JCuPupw({ id: 'lolilotaExportModal' });
        }

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
                        download(this.getAttribute('data-format'));
                        exportModal.close();
                    });
                }
            },
            buttons: [{ text: '取消', type: 'default' }]
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        doSearch();
    });

    input.addEventListener('input', handleInput);
    input.addEventListener('keydown', handleKeydown);
    input.addEventListener('focus', function () {
        if (input.value.trim() && suggestItems.length === 0) {
            fetchSuggestions(input.value.trim());
        }
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.search-wrap')) hideSuggest();
    });

    historyBtn.addEventListener('click', openHistoryModal);
    exportOpenBtn.addEventListener('click', openExportModal);

    importBtn.addEventListener('click', function () {
        importFile.click();
    });

    importFile.addEventListener('change', function () {
        if (importFile.files && importFile.files[0]) {
            importData(importFile.files[0]);
            importFile.value = '';
        }
    });

    clearBtn.addEventListener('click', function () {
        if (getHistory().length === 0) {
            toast('暂无历史记录', 'warning');
            return;
        }
        confirmBox({
            title: '清空搜索历史',
            content: '确定要清空所有搜索历史吗？此操作不可恢复。',
            confirmText: '确定清空',
            cancelText: '取消'
        }).then(function (ok) {
            if (ok) {
                clearHistory();
                toast('已清空搜索历史', 'success');
            }
        });
    });

    aboutLink.addEventListener('click', function (e) {
        e.preventDefault();
        alertBox({
            title: '关于 Lolilota',
            content: '<p style="margin:0 0 10px">Lolilota 是一个简洁的 Bing 搜索起始页，搜索建议由 Bing Autosuggest 接口实时提供，历史记录仅保存在本地浏览器。</p>'
                + '<ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.9">'
                + '<li>历史记录保存在浏览器 localStorage，不会上传到任何服务器</li>'
                + '<li>支持导出 JSON / CSV / TXT / Markdown / HTML，并可从 JSON 文件导入</li>'
                + '<li>快捷键：按 / 聚焦搜索框，方向键上下选择建议，Esc 关闭面板</li>'
                + '</ul>'
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === '/' && document.activeElement !== input) {
            e.preventDefault();
            input.focus();
        }
        if (e.key === 'Escape') hideSuggest();
    });

    updateCountPill();

    window.addEventListener('load', function () {
        if (window.innerWidth > 768) input.focus();
    });

})();