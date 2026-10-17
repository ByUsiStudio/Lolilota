/**
 * Lolilota · 运行时配置
 *
 * API 地址来源优先级：
 *   1. 地址栏 ?api= 参数（临时调试，会写入存储）
 *   2. localStorage / chrome.storage 里的 lolilota_api_base
 *   3. PHP 后端注入的 window.__LOLILOTA__（同源部署时自动生效）
 *   4. 默认值
 */
(function (global) {
    'use strict';

    var NS = global.Lolilota = global.Lolilota || {};
    var util = NS.util;

    var KEY_API_BASE = 'lolilota_api_base';
    var KEY_SYNC = 'lolilota_sync_enabled';
    var KEY_TOKEN = 'lolilota_token';

    var API_PREFIX = '/api/v1';

    var injected = global.__LOLILOTA__ || {};

    var config = {
        /** 后端服务根地址。留空（同源部署）时由浏览器直接请求当前站点 */
        apiBase: util.trimSlash(injected.apiBase || ''),
        /** REST 前缀 */
        apiPrefix: API_PREFIX,
        /** 支持的登录方式，由后端 /api/v1/config 覆盖 */
        providers: injected.providers || ['cdifit'],
        /** 站点首页地址（后端注入；扩展形态下为空） */
        homepage: injected.homepage || '',
        /** 部署模式：site（同源站点）| extension（扩展） */
        mode: injected.mode || (util.isExtension() ? 'extension' : 'site'),
        keys: {
            apiBase: KEY_API_BASE,
            sync: KEY_SYNC,
            token: KEY_TOKEN
        }
    };

    /** 从查询串读取 ?api= 覆盖并持久化 */
    function applyQueryOverride() {
        try {
            var params = new URLSearchParams(global.location.search);
            var api = params.get('api');
            if (api) {
                config.apiBase = util.trimSlash(api);
                util.writeSetting(KEY_API_BASE, config.apiBase);
            }
        } catch (e) { /* ignore */ }
    }

    /** 异步补齐：存储里的配置 > 注入值 */
    function ready() {
        applyQueryOverride();
        return util.readSetting(KEY_API_BASE).then(function (saved) {
            if (saved) {
                config.apiBase = util.trimSlash(saved);
            }
            return config;
        });
    }

    function setApiBase(url) {
        config.apiBase = util.trimSlash(url);
        util.writeSetting(KEY_API_BASE, config.apiBase);
        return config.apiBase;
    }

    /** 拼出完整接口地址 */
    function apiUrl(path) {
        return util.joinUrl(config.apiBase, config.apiPrefix + '/' + String(path || '').replace(/^\/+/, ''));
    }

    /** 后端站点地址（用于跳转登录/回调页面） */
    function siteUrl(path) {
        return util.joinUrl(config.apiBase, path || '');
    }

    config.ready = ready;
    config.apiUrl = apiUrl;
    config.siteUrl = siteUrl;
    config.setApiBase = setApiBase;
    config.applyQueryOverride = applyQueryOverride;

    NS.config = config;
})(window);
