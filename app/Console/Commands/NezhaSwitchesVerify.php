<?php

namespace App\Console\Commands;

use App\CentralLogics\NezhaSwitchLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒 M3 开关台账三方防漂移核对(只读)。
 *
 *   php artisan nezha:switches-verify
 *
 * 三方对账:
 *   ① 注册表 key 集 vs docs/PRELAUNCH_SWITCHES.md 出现的 key(差集)
 *   ② 注册表 expected vs live DB 实值(B应1/C应0/D应0 偏离)
 *   ③ md「现值」列 vs live DB 实值(文档漂移)
 * 输出分组: 🔴 偏离预期 / 🟡 文档现值过期 / 🟡 key 覆盖缺口 / 🟢 一致。
 *
 * 🔴 纯只读: 不翻任何闸、不改 md、不写 DB。跑进 QA 例行(QA_MASTER §五 T0/T1), 不新建 cron。
 * 退出码: 有🔴偏离=2, 只有🟡=1, 全绿=0(便于 CI/QA 判读)。
 */
class NezhaSwitchesVerify extends Command
{
    protected $signature = 'nezha:switches-verify {--md=docs/PRELAUNCH_SWITCHES.md : 开关正本文档路径}';

    protected $description = '哪吒开关台账三方防漂移核对(注册表↔PRELAUNCH_SWITCHES.md↔DB, 只读)';

    public function handle(): int
    {
        $mdPath = base_path($this->option('md'));
        $md = is_file($mdPath) ? (string) file_get_contents($mdPath) : '';

        $this->line('');
        $this->info('══════ 哪吒开关台账 · 三方对账 ══════');
        $this->line('注册表 config/nezha_switches.php  ↔  ' . $this->option('md') . '  ↔  live business_settings');
        $this->line('环境: ' . app()->environment() . '  ·  开关总数: ' . count(NezhaSwitchLedger::registry()));
        if ($md === '') {
            $this->warn('⚠️ 未读到正本文档 ' . $mdPath . ' —— 跳过文档对账(①③), 仅跑 expected 偏离(②)。');
        }

        $rows = NezhaSwitchLedger::rows();

        $deviations = [];   // 🔴 ②
        $mdDrift = [];      // 🟡 ③
        $mdMissing = [];    // 🟡 ① 注册表有、md 无
        $notes = [];        // ⓘ 无法机器核对(special/param 的 md 现值)

        foreach ($rows as $r) {
            // ── ② expected 偏离 ──
            if ($r['deviation']) {
                $deviations[] = $r;
            }

            if ($md === '') {
                continue;
            }

            // ── ① key 是否在 md 出现 ──
            if (strpos($md, $r['key']) === false) {
                $mdMissing[] = $r['key'];
            }

            // ── ③ md 现值列 vs DB ──
            if (in_array($r['value_type'], ['special'], true)) {
                $notes[] = $r['key'] . '（special·env/外部驱动, md 现值人工核对）';
                continue;
            }
            $mdCur = $this->extractMdCurrent($md, $r['key']);
            if ($mdCur === null) {
                $notes[] = $r['key'] . '（md 现值列未能自动解析, 人工核对）';
                continue;
            }
            if ($r['value_type'] === 'param') {
                $dbNum = $this->liveRaw($r['key']);
                if ($dbNum !== null && $this->normNum($mdCur) !== $this->normNum($dbNum)) {
                    $mdDrift[] = [$r['key'], $mdCur, $dbNum];
                }
                continue;
            }
            // bool / json_status: 归一到 0/1
            $mdStatus = $this->normStatus($mdCur);
            if ($mdStatus !== null && $r['status'] !== null && $mdStatus !== $r['status']) {
                $mdDrift[] = [$r['key'], $mdCur . '(=' . $mdStatus . ')', ($r['status'] . '/' . $r['value_disp'])];
            }
        }

        // ── 报告 ──
        $this->line('');
        $this->line('── ② 🔴 偏离预期(B应1 / C应0 / D应0, live ≠ expected) ──');
        if (empty($deviations)) {
            $this->info('   ✅ 无偏离');
        } else {
            foreach ($deviations as $r) {
                $this->error(sprintf('   🔴 %-38s %s类·%s   live=%s(%s)',
                    $r['key'], $r['section'], $r['expected_disp'], $r['status'], $r['value_disp']));
            }
        }

        $this->line('');
        $this->line('── ③ 🟡 文档现值过期(md 现值列 ≠ live DB) ──');
        if ($md === '') {
            $this->line('   （跳过·无正本文档）');
        } elseif (empty($mdDrift)) {
            $this->info('   ✅ md 现值列与 DB 一致');
        } else {
            foreach ($mdDrift as $d) {
                $this->warn(sprintf('   🟡 %-38s md现值=%s   →  DB=%s   (修 md 现值列)', $d[0], $d[1], $d[2]));
            }
        }

        $this->line('');
        $this->line('── ① 🟡 注册表 key 未在 md 出现 ──');
        if ($md === '') {
            $this->line('   （跳过·无正本文档）');
        } elseif (empty($mdMissing)) {
            $this->info('   ✅ 注册表全部 key 均在 md 出现');
        } else {
            foreach ($mdMissing as $k) {
                $this->warn('   🟡 ' . $k . ' —— 注册表有、md 无(补 md 或核对拼写)');
            }
        }

        if (! empty($notes)) {
            $this->line('');
            $this->line('── ⓘ 无法机器核对(人工核对) ──');
            foreach ($notes as $n) {
                $this->line('   · ' . $n);
            }
        }

        // ── 汇总 ──
        $s = NezhaSwitchLedger::summary();
        $this->line('');
        $this->line('── 🟢 汇总 ──');
        $this->line(sprintf('   dormant %d · LIVE %d · 偏离预期 %d · 开关总数 %d',
            $s['dormant'], $s['live'], $s['deviation'], $s['total']));
        $this->line('');

        // 持久化最近一次核对结果(台账页顶条"与文档核对"读取; 无缓存则页面显"未跑过", 不造假)
        \Illuminate\Support\Facades\Cache::forever('nezha_switches_verify_last', [
            'at'         => now()->toDateTimeString(),
            'deviation'  => count($deviations),
            'md_drift'   => count($mdDrift),
            'md_missing' => count($mdMissing),
        ]);

        if (! empty($deviations)) {
            $this->error('结论: 存在 ' . count($deviations) . ' 处🔴偏离预期 —— 逐条确认是"有意翻转(→改分区/md)"还是"真误翻(→回滚)", 别静默放过。');
            return 2;
        }
        if (! empty($mdDrift) || ! empty($mdMissing)) {
            $this->warn('结论: 无预期偏离; 有文档漂移/覆盖缺口, 建议同批修 md 现值列。');
            return 1;
        }
        $this->info('结论: ✅ 三方一致, 0 漂移。');
        return 0;
    }

    /** 从 md 表格行提取某 key 的「现值」单元格(第 2 列); 非表格行/未找到返回 null。 */
    protected function extractMdCurrent(string $md, string $key): ?string
    {
        foreach (preg_split('/\r?\n/', $md) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '|') {
                continue;
            }
            // 该行须以反引号包住 key 出现(排除仅在说明文字里提到 key 的行)
            if (strpos($line, '`' . $key . '`') === false) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, '|')));
            // 找到含 key 的单元格下标, 现值 = 下一格
            foreach ($cells as $idx => $cell) {
                if (strpos($cell, '`' . $key . '`') !== false) {
                    return $cells[$idx + 1] ?? null;
                }
            }
        }
        return null;
    }

    /** md 现值单元格 → 0/1 状态; 无法归一返回 null。 */
    protected function normStatus(string $cell): ?int
    {
        $c = trim(str_replace(['*', ' ', '　'], '', $cell));
        // JSON {"status":"0"}
        if (preg_match('/"status"\s*:\s*"?(\d)"?/', $c, $m)) {
            return (int) $m[1] === 1 ? 1 : 0;
        }
        if ($c === '应开') {
            return 1;
        }
        if ($c === '应关' || $c === '空(关)' || $c === '空（关）' || $c === '') {
            return 0;
        }
        if ($c === '0' || $c === '1') {
            return (int) $c;
        }
        return null;   // 组合行(如 "5000/2000000")等无法归一
    }

    protected function normNum(?string $v): string
    {
        if ($v === null) {
            return '';
        }
        return preg_replace('/[^\d]/', '', $v);
    }

    protected function liveRaw(string $key): ?string
    {
        try {
            $v = DB::table('business_settings')->where('key', $key)->value('value');
            return $v === null ? null : (string) $v;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
