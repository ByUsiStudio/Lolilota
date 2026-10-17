/**
 * Lolilota · 工具函数
 * 纯函数 + 少量浏览器 API 封装，不依赖其他模块
 */
(function (global) {
    'use strict';

    var NS = global.Lolilota = global.Lolilota || {};

    var util = {
        /** HTML 转义 */
        escapeHtml: function (str) {
            return String(str == null ? '' : str).replace(/[&<>"']/g, function (m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
            });
        },

        /** 本地存储读写（带降级，无痕模式不抛错） */
        store: {
            get: function (key, fallback) {
                try {
                    var raw = global.localStorage.getItem(key);
                    if (raw === null || raw === undefined) return fallback;
                    return JSON.parse(raw);
                } catch (e) {
                    return fallback;
                }
            },
            set: function (key, value) {
                try {
                    global.localStorage.setItem(key, JSON.stringify(value));
                    return true;
                } catch (e) {
                    return false;
                }
            },
            remove: function (key) {
                try {
                    global.localStorage.removeItem(key);
                } catch (e) { /* ignore */ }
            }
        },

        /** 是否运行在浏览器扩展环境 */
        isExtension: function () {
            return (typeof chrome !== 'undefined' && chrome.runtime && !!chrome.runtime.id)
                || (typeof browser !== 'undefined' && browser.runtime && !!browser.runtime.id);
        },

        /** 扩展环境读取 chrome.storage，网页环境回落到 localStorage */
        readSetting: function (key) {
            var fromLocal = util.store.get(key, null);
            if (fromLocal !== null) return Promise.resolve(fromLocal);

            if (util.isExtension() && typeof chrome !== 'undefined' && chrome.storage && chrome.storage.local) {
                return new Promise(function (resolve) {
                    chrome.storage.local.get([key], function (res) {
                        resolve(res && res[key] !== undefined ? res[key] : null);
                    });
                });
            }
            return Promise.resolve(null);
        },

        writeSetting: function (key, value) {
            util.store.set(key, value);
            if (util.isExtension() && typeof chrome !== 'undefined' && chrome.storage && chrome.storage.local) {
                var patch = {};
                patch[key] = value;
                chrome.storage.local.set(patch);
            }
        },

        /** 去掉末尾斜杠 */
        trimSlash: function (url) {
            return String(url || '').replace(/\/+$/, '');
        },

        /** 拼接 URL，自动处理斜杠 */
        joinUrl: function (base, path) {
            return util.trimSlash(base) + '/' + String(path || '').replace(/^\/+/, '');
        },

        /** 时间格式化：同一天显示 HH:MM，否则 M/D */
        formatTime: function (ts) {
            if (!ts) return '';
            var d = new Date(ts);
            if (isNaN(d.getTime())) return '';
            var now = new Date();
            var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
            if (d.toDateString() === now.toDateString()) {
                return pad(d.getHours()) + ':' + pad(d.getMinutes());
            }
            return (d.getMonth() + 1) + '/' + d.getDate();
        },

        /** 取昵称首字符做头像占位 */
        initial: function (name) {
            var s = String(name || '?').trim();
            return s ? s.charAt(0).toUpperCase() : '?';
        },

        /** 节流 */
        throttle: function (fn, wait) {
            var last = 0, timer = null;
            return function () {
                var args = arguments, self = this, now = Date.now();
                var remain = wait - (now - last);
                if (remain <= 0) {
                    clearTimeout(timer);
                    timer = null;
                    last = now;
                    fn.apply(self, args);
                } else if (!timer) {
                    timer = setTimeout(function () {
                        last = Date.now();
                        timer = null;
                        fn.apply(self, args);
                    }, remain);
                }
            };
        }
    };

    NS.util = util;
})(window);
