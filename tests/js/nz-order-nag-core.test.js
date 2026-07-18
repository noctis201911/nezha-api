/*
 * 哪吒 — 新单「反复提醒到接单」判定纯函数单测。
 * 运行: node tests/js/nz-order-nag-core.test.js   (退出码 0=全绿)
 * 覆盖 nz-heartbeat-core.js 的 nzShouldNag(与后端 NezhaNewOrderNag::shouldNagNow 对称)。
 */
var core = require('../../public/assets/admin/js/nz-heartbeat-core.js');
var pass = 0, fail = 0;
function eq(a, e, name) {
    a = JSON.stringify(a); e = JSON.stringify(e);
    if (a === e) { pass++; console.log('  ok   ' + name); }
    else { fail++; console.log('FAIL   ' + name + '\n  期望 ' + e + '\n  实际 ' + a); }
}
var MIN = 60000;
var base = { enabled: true, pendingSince: 1000, lastRingAt: 0, intervalSec: 20, maxMs: 5 * MIN, now: 1000, unlocked: true, suppressed: false };
function S(o) { var r = {}; for (var k in base) { r[k] = base[k]; } for (var k2 in o) { r[k2] = o[k2]; } return r; }

eq(core.nzShouldNag(S({ enabled: false, now: 1000 + 30000 })), false, '未开反复/当前类别未勾: 不响');
eq(core.nzShouldNag(S({ pendingSince: 0, now: 1000 + 30000 })), false, '无待接单: 不响');
eq(core.nzShouldNag(S({ unlocked: false, now: 1000 + 30000 })), false, '音频未解锁: 不响');
eq(core.nzShouldNag(S({ now: 1000 + 10000, lastRingAt: 1000 })), false, '未到间隔(10s<20s): 不响');
eq(core.nzShouldNag(S({ now: 1000 + 20000, lastRingAt: 1000 })), true, '到间隔(20s): 响');
eq(core.nzShouldNag(S({ now: 1000 + 20000, lastRingAt: 0 })), true, '首个间隔到点(lastRing=0): 响');
eq(core.nzShouldNag(S({ now: 1000 + 5 * MIN + 1 })), false, '超过最长时长: 停');
eq(core.nzShouldNag(S({ now: 1000 + 30000, lastRingAt: 1000, suppressed: true })), false, '在场抑制(作业台/正看该单): 不响');

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail === 0 ? 0 : 1);
