<?php

namespace App\CentralLogics;

use App\Models\BusinessSetting;
use App\Models\NezhaSanctionName;
use App\Models\NezhaRiskRecord;
use App\Models\VendorKycProfile;

/**
 * 哪吒 商家 KYC 制裁名字筛查 (L1-6, 阶段1).
 *
 *  - normalize_name() : 人名规范化(大写 / 去音标→ASCII / 去标点 / 压空白) —— 入库与比对同一规范.
 *  - screen_name()    : 对单个人名做 OFAC SDN 人名比对, 返回:
 *        clear    精确 + token 近似均无命中.
 *        possible 近似(token 子集 或 ≥2 token 重叠) → 转人工(写 NezhaRiskRecord review/pending, 进风控审核队列).
 *        hit      规范化后完全相等 → 命中(应拒, 写 NezhaRiskRecord reject/auto).
 *  - screen_names()   : 对多名(法人+受益人)取最严结论(hit > possible > clear).
 *  - apply_to_profile : 把结论写回 vendor_kyc_profiles.screen_*.
 *  - record_risk()    : possible/hit 写 nezha_risk_records, 复用风控① 审核队列/日志(rule 含 'sanction').
 *
 * 🔴 已知局限(已记 docs/compliance/CHANGELOG.md): 仅【规范化精确 + token 重叠/子集】匹配,
 *    会漏【音译变体】(Mohammed/Muhammad/Mohamed、Aleksandr/Alexander、中文姓名不同拼音)、
 *    语音相近、错字。possible 是兜底但音译变体可能连 possible 都不触发 →
 *    本筛查不可视为完备制裁合规, 仅作入驻初筛 + 人工复核线索; 完整方案(模糊/语音/商业名单)待后续。
 */
class NezhaKycScreen
{
    protected static function cfg(string $key, $default = null)
    {
        $v = BusinessSetting::where('key', $key)->first()?->value;
        return ($v === null || $v === '') ? $default : $v;
    }

    /** 制裁名字筛查总开关 nezha_kyc_sanction_screen_status (默认开). */
    public static function enabled(): bool
    {
        return (string) self::cfg('nezha_kyc_sanction_screen_status', '1') === '1';
    }

    /** 人名规范化: 去音标(→ASCII) + 大写 + 去非字母数字 + 压空白. 入库/比对同一规范. */
    public static function normalize_name(string $name): string
    {
        $s = trim($name);
        if ($s === '') return '';
        // 去音标: é→E, ü→U. 失败/为空则保留原串.
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($ascii !== false && trim($ascii) !== '') {
            $s = $ascii;
        }
        $s = strtoupper($s);
        $s = preg_replace('/[^A-Z0-9 ]+/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', trim($s));
        return (string) $s;
    }

    /** 规范化名的有效 token(长度≥2, 去重保序). */
    protected static function tokens(string $norm): array
    {
        $t = array_filter(explode(' ', $norm), fn ($x) => mb_strlen($x) >= 2);
        return array_values(array_unique($t));
    }

    /**
     * 对单个人名做制裁筛查.
     * @return array{status:string, detail:string, matched:array<int,array>}
     */
    public static function screen_name(string $name): array
    {
        $norm = self::normalize_name($name);
        if ($norm === '') {
            return ['status' => 'clear', 'detail' => '空名, 跳过', 'matched' => []];
        }
        $qt = self::tokens($norm);
        if (empty($qt)) {
            return ['status' => 'clear', 'detail' => '无有效 token, 跳过', 'matched' => []];
        }

        $key = mb_substr($norm, 0, 191);

        // 1) 精确命中(规范化全等)
        $exact = NezhaSanctionName::where('name_norm', $key)->limit(5)->get();
        if ($exact->count() > 0) {
            $m = $exact->map(fn ($r) => [
                'name_raw' => $r->name_raw, 'name_type' => $r->name_type,
                'sdn_uid'  => $r->sdn_uid,  'programs'  => $r->programs,
            ])->all();
            $uids = array_filter(array_map(fn ($x) => $x['sdn_uid'], $m));
            return [
                'status'  => 'hit',
                'detail'  => '姓名规范化后与 OFAC SDN 名单完全一致: "' . $norm . '"' . ($uids ? '; sdn_uid=' . implode('/', $uids) : ''),
                'matched' => $m,
            ];
        }

        // 2) 近似: 用最长 1-2 个 token 取候选, token 子集 或 ≥2 重叠 → possible
        $byLen = $qt;
        usort($byLen, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $probe = array_slice($byLen, 0, 2);

        $cand = collect();
        foreach ($probe as $tk) {
            $rows = NezhaSanctionName::where('name_norm', 'like', '%' . $tk . '%')->limit(200)->get();
            $cand = $cand->concat($rows);
        }
        $cand = $cand->unique('id');

        $possible = [];
        foreach ($cand as $r) {
            $ct = self::tokens((string) $r->name_norm);
            if (empty($ct)) continue;
            $shared = array_intersect($qt, $ct);
            $subset = (count(array_diff($qt, $ct)) === 0) || (count(array_diff($ct, $qt)) === 0);
            if ($subset || count($shared) >= 2) {
                $possible[] = [
                    'name_raw' => $r->name_raw, 'name_type' => $r->name_type,
                    'sdn_uid'  => $r->sdn_uid,  'programs'  => $r->programs,
                ];
                if (count($possible) >= 10) break;
            }
        }
        if (!empty($possible)) {
            return [
                'status'  => 'possible',
                'detail'  => '姓名 "' . $norm . '" 与 OFAC SDN 名单近似(token 重叠/子集), 需人工核对是否同一人; 候选 ' . count($possible) . ' 条',
                'matched' => $possible,
            ];
        }

        return [
            'status'  => 'clear',
            'detail'  => '姓名 "' . $norm . '" 未命中 OFAC SDN 名单(精确 + token 近似均无)',
            'matched' => [],
        ];
    }

    /**
     * 对一组姓名(法人/受益人)做筛查, 取最严结论(hit > possible > clear).
     * @param array<int,string> $names
     * @return array{status:string, detail:string, matched:array}
     */
    public static function screen_names(array $names): array
    {
        $rank = ['clear' => 0, 'possible' => 1, 'hit' => 2];
        $worst = ['status' => 'clear', 'detail' => '', 'matched' => []];
        $details = [];
        foreach ($names as $nm) {
            $nm = trim((string) $nm);
            if ($nm === '') continue;
            $r = self::screen_name($nm);
            $details[] = '[' . $nm . '] ' . $r['detail'];
            if ($rank[$r['status']] > $rank[$worst['status']]) {
                $worst = $r;
            }
        }
        $worst['detail'] = $details ? implode(' | ', $details) : '无可筛姓名';
        return $worst;
    }

    /** 把筛查结论写回 vendor_kyc_profiles.screen_*. */
    public static function apply_to_profile(VendorKycProfile $profile, array $result): void
    {
        $st = $result['status'] ?? 'not_run';
        $profile->screen_status = in_array($st, ['clear', 'possible', 'hit'], true) ? $st : 'not_run';
        $profile->screen_detail = mb_substr((string) ($result['detail'] ?? ''), 0, 2000);
        $profile->screened_at   = now();
        $profile->save();
    }

    /**
     * possible/hit → 写一条风控记录(复用风控① 审核队列/日志, rule 含 'sanction' 故在日志「制裁命中」筛选里也可见).
     *   possible: action=review, status=pending → 进 /admin/nezha-risk/queue.
     *   hit:      action=reject, status=auto.
     * 去重: 同 restaurant_id 已有未结同类记录则复用, 不重复刷队列.
     * @return int|null 记录 id (clear 返回 null)
     */
    public static function record_risk(?int $restaurantId, ?int $vendorUserId, array $result, string $context = 'kyc'): ?int
    {
        $st = $result['status'] ?? 'clear';
        if (!in_array($st, ['possible', 'hit'], true)) return null;

        $isHit = $st === 'hit';
        $rule  = $isHit ? 'sanction_kyc_name' : 'sanction_kyc_name_possible';

        if ($restaurantId) {
            $dupStatus = $isHit ? 'auto' : 'pending';
            $existing = NezhaRiskRecord::where('restaurant_id', $restaurantId)
                ->where('status', $dupStatus)
                ->where('hit_rules', 'like', '%' . $rule . '%')
                ->first();
            if ($existing) return $existing->id;
        }

        $rec = new NezhaRiskRecord();
        $rec->order_id        = null;
        $rec->user_id         = $vendorUserId;
        $rec->guest_id        = null;
        $rec->restaurant_id   = $restaurantId;
        $rec->payment_channel = 'other';
        $rec->order_amount    = 0;
        $rec->hit_rules       = [[
            'rule'   => $rule,
            'detail' => mb_substr((string) ($result['detail'] ?? ''), 0, 1000),
        ]];
        $rec->action          = $isHit ? 'reject' : 'review';
        $rec->status          = $isHit ? 'auto' : 'pending';
        $rec->snapshot        = [
            'context' => $context,
            'matched' => array_slice($result['matched'] ?? [], 0, 10),
        ];
        $rec->disposal_result = $isHit
            ? 'L1-6 制裁名单命中(法人/受益人姓名): 不予合作/应拒绝入驻, 平台不与受制裁主体交易。'
            : '制裁名单近似(姓名 token 重叠): 待人工核对是否同一人, 确认后在 KYC 页据实处置。';
        $rec->save();

        return $rec->id;
    }
}
