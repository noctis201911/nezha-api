<?php

namespace App\CentralLogics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * 哪吒超管 M3「开关台账」单一真相源。
 *
 * 注册表(config/nezha_switches.php) ⊕ live business_settings 实值 → 每个开关的解析状态。
 * 四处同源消费方(对账验收):
 *   ① 台账只读页 admin/nezha-switches (rows())
 *   ② 驾驶舱快照卡 NezhaAdminDashboard::buildSummary() (summary())
 *   ③ verify 命令 nezha:switches-verify (rows()+summary())
 *   ④ 驾驶舱系统健康卡安全态行 (securityRow())
 *
 * 🔴 纯只读: 只 SELECT business_settings, 不翻任何闸, 不写任何值。
 * 🔴 "当前值"一律 live 读 DB 原始值(绕开 get_business_settings 的 rememberForever 缓存),
 *    反映真实持久化状态——这正是台账要暴露的(缓存漂移由 ops_note 的 USR2/forget 提示解决)。
 * 防御: 每个外部读取 safe 包裹, 单行解析失败退化不打断整页(台账挂在驾驶舱右栏, 抛出=拖垮驾驶舱)。
 */
class NezhaSwitchLedger
{
    /** 注册表全表(A-F)。 */
    public static function registry(): array
    {
        $r = config('nezha_switches.switches', []);
        return is_array($r) ? $r : [];
    }

    /** D4 安全态手工登记字段。 */
    public static function security(): array
    {
        $s = config('nezha_switches.security', []);
        return is_array($s) ? $s : [];
    }

    /* ───────────── live 读取 ───────────── */

    /**
     * 一次拉齐注册表所有 business_settings 键的原始值 + updated_at。
     * 只查 value_type ∈ {bool,json_status,param} 的键(special 的 mail/offline_payment 不在表里或另有来源)。
     * @return array<string, object{value:?string, updated_at:?string}>
     */
    protected static function liveMap(): array
    {
        try {
            $keys = [];
            foreach (self::registry() as $sw) {
                if (($sw['value_type'] ?? '') !== 'special') {
                    $keys[] = $sw['key'];
                }
            }
            if (empty($keys)) {
                return [];
            }
            $rows = DB::table('business_settings')->whereIn('key', $keys)
                ->get(['key', 'value', 'updated_at']);
            $map = [];
            foreach ($rows as $r) {
                $map[$r->key] = $r;
            }
            return $map;
        } catch (\Throwable $e) {
            Log::warning('NezhaSwitchLedger.liveMap: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 解析单个开关的当前状态。
     * @return array 见下方键; state ∈ live|dormant|unset|param|special
     */
    public static function resolve(array $sw, array $liveMap): array
    {
        $type = $sw['value_type'] ?? 'bool';
        $key  = $sw['key'];
        $rowObj   = $liveMap[$key] ?? null;
        $raw      = $rowObj->value ?? null;
        $updated  = $rowObj->updated_at ?? null;

        $status    = null;      // 解析出的 0/1(仅 bool/json_status)
        $state     = 'special';
        $valueDisp = '见注记';

        if ($type === 'param') {
            $state = 'param';
            $valueDisp = ($raw !== null && $raw !== '')
                ? self::numberDisp($raw)
                : ('未设置（默认 ' . self::numberDisp((string) ($sw['default'] ?? '')) . '）');
        } elseif ($type === 'special') {
            // 无 business_settings 布尔源。mail 有真实 config 源, 顺手显示; 其余显"见注记"。
            if ($key === 'mail') {
                $on = self::safe(fn () => (bool) config('mail.status'), null);
                $valueDisp = $on === null ? '见注记（env 驱动）' : ($on ? '发信开（env）' : '发信关（env）');
            } else {
                $valueDisp = '见注记';
            }
            $state = 'special';
        } else {
            // bool / json_status
            if ($raw === null) {
                $status = 0;           // 键不存在 = 从未启用 = 关
                $state  = 'unset';
                $valueDisp = '未设置（默认关）';
            } else {
                $status = self::rawStatus($raw);
                $state  = $status === 1 ? 'live' : 'dormant';
                $valueDisp = $status === 1 ? 'LIVE 开' : 'dormant 关';
            }
        }

        $expected = $sw['expected'] ?? null;
        $deviation = ($expected !== null && $status !== null && $status !== (int) $expected);

        return [
            'key'           => $key,
            'label'         => $sw['label'] ?? $key,
            'section'       => $sw['section'] ?? '',
            'level'         => $sw['level'] ?? '',
            'l1_clause'     => $sw['l1_clause'] ?? null,
            'prereq'        => $sw['prereq'] ?? '',
            'ops_note'      => $sw['ops_note'] ?? null,
            'value_type'    => $type,
            'status'        => $status,
            'state'         => $state,
            'value_disp'    => $valueDisp,
            'expected'      => $expected,
            'expected_disp' => self::expectedDisp($expected, $sw['section'] ?? ''),
            'deviation'     => $deviation,
            'settings_url'  => self::routeSafe($sw['settings_route'] ?? null),
            'has_ui'        => ! empty($sw['settings_route']),
            'updated_rel'   => $updated ? self::relTime($updated) : '—',
            'updated_abs'   => $updated ? (string) $updated : '',
            'is_l1'         => str_starts_with((string) ($sw['level'] ?? ''), 'L1'),
        ];
    }

    /** 台账页全量行(已排序: 偏离 > L1 > D 区 > 其余; 同档按 md 顺序)。 */
    public static function rows(): array
    {
        $live = self::liveMap();
        $out = [];
        $i = 0;
        foreach (self::registry() as $sw) {
            $row = self::resolve($sw, $live);
            $row['_ord'] = $i++;
            $out[] = $row;
        }
        usort($out, function ($a, $b) {
            $ra = self::rank($a);
            $rb = self::rank($b);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return $a['_ord'] <=> $b['_ord'];   // 同档保持 md 顺序(PHP8 usort 稳定, 仍显式兜底)
        });
        return $out;
    }

    /** 排序档位: 0 偏离预期 / 1 L1 / 2 D 区 / 3 其余。 */
    protected static function rank(array $r): int
    {
        if ($r['deviation']) {
            return 0;
        }
        if ($r['is_l1']) {
            return 1;
        }
        if ($r['section'] === 'D') {
            return 2;
        }
        return 3;
    }

    /**
     * 快照汇总(台账页顶条 + 驾驶舱快照卡同源)。
     * dormant/LIVE 只数 bool/json 开关; param/special 不计入两桶。
     */
    public static function summary(): array
    {
        $live = self::liveMap();
        $dormant = 0;
        $liveCnt = 0;
        $deviation = 0;
        $deviationKeys = [];
        foreach (self::registry() as $sw) {
            $r = self::resolve($sw, $live);
            if ($r['deviation']) {
                $deviation++;
                $deviationKeys[] = $r['key'];
            }
            if ($r['state'] === 'live') {
                $liveCnt++;
            } elseif ($r['state'] === 'dormant' || $r['state'] === 'unset') {
                $dormant++;
            }
        }
        return [
            'dormant'        => $dormant,
            'live'           => $liveCnt,
            'deviation'      => $deviation,
            'deviation_keys' => $deviationKeys,
            'total'          => count(self::registry()),
            'route'          => self::routeSafe('admin.nezha-switches'),
        ];
    }

    /**
     * D4 安全态行(供驾驶舱系统健康卡追加)。
     * basic auth = 手工登记(无查询源); 2FA = live 读 admins.two_factor_enabled(有真实源, 防漂移)。
     * @return array<int, array{label:string,value:string,ok:bool}>
     */
    public static function securityRow(): array
    {
        $rows = [];
        $sec = self::security();

        $ba = $sec['basic_auth'] ?? [];
        if (($ba['enabled'] ?? false)) {
            $rot = ! empty($ba['rotated_at']) ? ('·轮换 ' . $ba['rotated_at']) : '';
            $rows[] = ['label' => '后台锁 basic auth', 'value' => '启用' . $rot, 'ok' => true];
        } else {
            $rows[] = ['label' => '后台锁 basic auth', 'value' => '未启用', 'ok' => false];
        }

        // 2FA: 任一 admin 开启即视为已启用(真实 DB 状态, 不硬编码)。
        $twoFa = self::safe(function () {
            return (int) DB::table('admins')->where('two_factor_enabled', 1)->count();
        }, -1);
        if ($twoFa > 0) {
            $rows[] = ['label' => '后台 2FA', 'value' => '已启用', 'ok' => true];
        } elseif ($twoFa === 0) {
            $rows[] = ['label' => '后台 2FA', 'value' => '未启用', 'ok' => false];
        }
        // $twoFa === -1(查询失败) → 无源不显(照"有源才显"判例)

        return $rows;
    }

    /* ───────────── 小工具 ───────────── */

    /** 原始 value → 0/1 状态(容错: JSON {"status":..} / 标量 "0"/"1" 均可)。 */
    protected static function rawStatus(string $raw): int
    {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && array_key_exists('status', $decoded)) {
            return (int) $decoded['status'] === 1 ? 1 : 0;
        }
        return (int) trim($raw) === 1 ? 1 : 0;
    }

    protected static function numberDisp(string $raw): string
    {
        if ($raw === '') {
            return '—';
        }
        return is_numeric($raw) ? number_format((float) $raw) : $raw;
    }

    protected static function expectedDisp($expected, string $section): string
    {
        if ($expected === null) {
            return '—';
        }
        return ((int) $expected === 1) ? '应开' : '应关';
    }

    protected static function relTime($ts): string
    {
        try {
            return Carbon::parse($ts)->diffForHumans();
        } catch (\Throwable $e) {
            return (string) $ts;
        }
    }

    protected static function routeSafe(?string $name): ?string
    {
        if (empty($name)) {
            return null;
        }
        try {
            return route($name);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function safe(callable $fn, $default = 0)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning('NezhaSwitchLedger: ' . $e->getMessage());
            return $default;
        }
    }

    /** 分区中文名(台账徽章)。 */
    public static function sectionLabel(string $s): string
    {
        return [
            'A' => '上线开', 'B' => '安全轨', 'C' => '必须关',
            'D' => '未就绪', 'E' => '业务决策', 'F' => '已开记录',
        ][$s] ?? $s;
    }
}
