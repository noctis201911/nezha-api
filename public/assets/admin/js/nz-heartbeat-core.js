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
    // 哪吒: 新单「反复提醒到接单」判定纯函数(单一真相源·Node 单测覆盖·与后端 NezhaNewOrderNag::shouldNagNow 对称)。
    // s: { enabled, pendingSince, lastRingAt, intervalSec, maxMs, now, unlocked, suppressed }
    // 返回 true = 本 tick 应再响一次 new_order 提示音。
    function nzShouldNag(s) {
        s = s || {};
        if (!s.enabled) { return false; }            // 未开反复 / 当前 target 类别商家未勾选
        if (!s.pendingSince) { return false; }       // 当前无待接单
        if (!s.unlocked) { return false; }           // 音频未解锁(浏览器自动播放限制)
        if (s.suppressed) { return false; }          // 在场抑制(作业台 / 正看该单)
        if ((s.now - s.pendingSince) >= s.maxMs) { return false; }                    // 超最长反复时长 → 停
        if ((s.now - (s.lastRingAt || 0)) < (s.intervalSec * 1000)) { return false; } // 未到间隔
        return true;
    }
    var api = { nzFreshIds: nzFreshIds, nzCapSeen: nzCapSeen, nzShouldNag: nzShouldNag };
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
    global.nzHeartbeatCore = api;
})(typeof window !== 'undefined' ? window : this);
