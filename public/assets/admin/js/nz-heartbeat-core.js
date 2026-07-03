/*
 * 哪吒作业台 W4 —— 心跳核心纯函数(单一真相源)。
 * app.blade poll() 用 nzFreshIds 算「真新单」(对照 nz_seen_order_ids_v1 去重·绝不漏单/防双响);
 * 浏览器挂 window.nzHeartbeatCore, Node 用 module.exports —— 同一份逻辑被 nz-heartbeat-core.test.js 覆盖。
 */
(function (global) {
    'use strict';
    // 返回 ids 中不在 seen(Set 或数组)里的项(按字符串比较, 与 poll 的 map(String) 口径一致·保序)。
    function nzFreshIds(ids, seen) {
        var seenSet = (seen && typeof seen.has === 'function') ? seen : new Set(seen || []);
        var out = [];
        var arr = ids || [];
        for (var i = 0; i < arr.length; i++) {
            var id = String(arr[i]);
            if (!seenSet.has(id)) { out.push(id); }
        }
        return out;
    }
    // seen 数组封顶(保留最近 cap 个, 与 poll saveSeen 口径一致·防 localStorage 膨胀)。
    function nzCapSeen(arr, cap) {
        cap = cap || 200;
        var a = (arr || []).slice();
        if (a.length > cap) { a = a.slice(a.length - cap); }
        return a;
    }
    var api = { nzFreshIds: nzFreshIds, nzCapSeen: nzCapSeen };
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
    global.nzHeartbeatCore = api;
})(typeof window !== 'undefined' ? window : this);
