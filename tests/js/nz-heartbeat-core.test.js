/*
 * 哪吒 W4 单测(Backlog⑤·DoD#5): 合并轮询后「绝不漏新单 + 必响铃」的回归护栏。
 * freshIds 计算是响铃触发的唯一依据 —— 只要它对, 合并心跳就不会漏响或双响。
 * 运行: node tests/js/nz-heartbeat-core.test.js  (退出码 0=全绿)
 */
var core = require('../../public/assets/admin/js/nz-heartbeat-core.js');
var pass = 0, fail = 0;
function eq(actual, expected, name) {
    var a = JSON.stringify(actual), e = JSON.stringify(expected);
    if (a === e) { pass++; console.log('  ok   ' + name); }
    else { fail++; console.log('FAIL   ' + name + '\n       期望 ' + e + '\n       实际 ' + a); }
}

// 1) seen 为空 → 全部是新单(必响铃)
eq(core.nzFreshIds(['101', '102'], new Set()), ['101', '102'], '空 seen: 全部新单');
// 2) 部分已见 → 只剩未见的(绝不漏新单)
eq(core.nzFreshIds(['101', '102', '103'], new Set(['101', '103'])), ['102'], '部分已见: 只剩未见');
// 3) 全部已见 → 无新单(不重复响铃)
eq(core.nzFreshIds(['101', '102'], new Set(['101', '102'])), [], '全部已见: 无新单');
// 4) 空 ids → 空(无单不响)
eq(core.nzFreshIds([], new Set(['1'])), [], '空 ids: 空');
// 5) 数字 id 与字符串 seen 混用 → 按字符串比较一致(poll map(String) 口径)
eq(core.nzFreshIds([101, 102], new Set(['101'])), ['102'], '数字/字符串混用: 字符串口径去重');
// 6) seen 传数组也可(健壮性)
eq(core.nzFreshIds(['5', '6'], ['5']), ['6'], 'seen 传数组亦可');
// 7) 新单夹在已见中(顺序保持·不漏)
eq(core.nzFreshIds(['a', 'b', 'c', 'd'], new Set(['a', 'c'])), ['b', 'd'], '夹心新单: 保序不漏');
// 8) null/undefined 输入不炸
eq(core.nzFreshIds(null, null), [], 'null 输入: 空不炸');

// 9) nzCapSeen 封顶保留最近 N
var big = [];
for (var i = 0; i < 250; i++) { big.push(String(i)); }
var capped = core.nzCapSeen(big, 200);
eq(capped.length, 200, 'nzCapSeen: 长度封顶 200');
eq([capped[0], capped[199]], ['50', '249'], 'nzCapSeen: 保留最近 200(50..249)');
// 10) nzCapSeen 未超上限原样
eq(core.nzCapSeen(['1', '2'], 200), ['1', '2'], 'nzCapSeen: 未超上限原样');

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail === 0 ? 0 : 1);
