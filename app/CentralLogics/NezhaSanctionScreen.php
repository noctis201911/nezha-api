<?php

namespace App\CentralLogics;

use App\Models\BusinessSetting;
use App\Models\NezhaSanctionAddress;
use App\Models\NezhaRiskRecord;

/**
 * 哪吒外卖 制裁筛查机制② 引擎 (L1-6: 付款来源地址命中 OFAC SDN/黑名单 → 拒收并记录).
 *
 * 职责:
 *   - enabled()        : 读独立总开关 nezha_sanction_screen_status (默认开).
 *   - normalize()      : 地址规范化 —— EVM 统一小写(吸收 EIP-55 checksum 大小写差异);
 *                        Tron base58 大小写敏感, 原样保留(不可 lowercase, 否则永不命中).
 *   - screen_address() : 单地址比对制裁表, 命中返回匹配记录, 否则 null.
 *   - screen_order()   : 对一笔订单的 USDT 付款 —— 复用 NezhaRefundControl 的链上设施反查
 *                        付款 tx 的 from 地址 → 比对 → 给出处置.
 *
 * 处置语义:
 *   reject        命中制裁名单 → 拒收(不放行出餐) + 写 NezhaRiskRecord(rule=sanction).
 *   pass          非 USDT, 或反查出 from 且未命中 → 放行.
 *   inconclusive  USDT 但无法取得 from 地址(无 tx hash / 链上 API 不可达) → 不硬拦(避免误伤合法顾客),
 *                 写一条 review 审计记录待人工复核, 让流程继续. (命中才拒, 查不出不等于命中.)
 *
 * 复用: 通道识别/原始 tx 提取/链识别/from 地址反查全部走 NezhaRefundControl 现有方法,
 *      与退款用同一套 RPC(bsc-dataseed)/TronGrid 端点与 key, 不重复造链上设施.
 */
class NezhaSanctionScreen
{
    protected static function cfg(string $key, $default = null)
    {
        $v = BusinessSetting::where('key', $key)->first()?->value;
        return ($v === null || $v === '') ? $default : $v;
    }

    public static function enabled(): bool
    {
        return (string) self::cfg('nezha_sanction_screen_status', '1') === '1';
    }

    /**
     * 反查不出来源地址(无 tx / 链上 API 不可达)时的处置策略:
     *   'hold'  (默认, fail-closed) — 不放行出餐, 中止确认转人工复核(更符合制裁筛查通用准则)。
     *   'allow' (fail-open)        — 自动放行出餐, 仅留一条待人工复核记录。
     * 后台「风控设置→制裁名单筛查」可调。
     */
    public static function inconclusive_action(): string
    {
        return (string) self::cfg('nezha_sanction_inconclusive_action', 'hold') === 'allow' ? 'allow' : 'hold';
    }

    /** 地址类型: evm(0x+40hex) / tron(T 开头 base58) / other. */
    public static function kind(string $address): string
    {
        $a = trim($address);
        if ($a === '') return 'other';
        if (preg_match('/^0x[0-9a-fA-F]{40}$/', $a)) return 'evm';
        if (preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $a)) return 'tron';
        return 'other';
    }

    /** 规范化: EVM 小写; Tron/其它原样(去空白). 与入库时同一规范, 保证比对一致. */
    public static function normalize(string $address, ?string $kind = null): string
    {
        $a = trim($address);
        $kind = $kind ?: self::kind($a);
        return $kind === 'evm' ? strtolower($a) : $a;
    }

    /**
     * 单地址比对制裁表. 命中返回数组(地址/来源/币种/sdn_uid), 否则 null.
     * @param string      $address
     * @param string|null $kind  evm/tron/other; 不传则按格式推断.
     */
    public static function screen_address(string $address, ?string $kind = null): ?array
    {
        $address = trim($address);
        if ($address === '') return null;
        $kind = $kind ?: self::kind($address);
        $norm = self::normalize($address, $kind);

        $hit = NezhaSanctionAddress::where('addr_kind', $kind)
            ->where('address', $norm)
            ->first();
        if (!$hit) return null;

        return [
            'address'       => $hit->address,
            'addr_kind'     => $hit->addr_kind,
            'source'        => $hit->source,
            'sdn_uid'       => $hit->sdn_uid,
            'currency_type' => $hit->currency_type,
        ];
    }

    /**
     * 对订单的 USDT 付款做制裁筛查.
     * @return array [
     *   'action'       => 'reject'|'pass'|'inconclusive',
     *   'channel'      => 'usdt'|'rmb'|'other',
     *   'chain'        => 'bsc'|'trc20'|null,
     *   'tx_hash'      => string|null,
     *   'from_address' => string|null,
     *   'matched'      => array|null,   // 命中的制裁记录
     *   'detail'       => string,       // 人类可读说明(审计/拒单文案)
     * ]
     */
    public static function screen_order($order): array
    {
        $base = [
            'action' => 'pass', 'channel' => 'other', 'chain' => null,
            'tx_hash' => null, 'from_address' => null, 'matched' => null, 'detail' => '',
        ];

        // 非 USDT 通道(法币/未知)不做链上筛查 —— 制裁地址筛查只针对链上付款来源.
        $channel = NezhaRefundControl::detect_channel($order);
        $base['channel'] = $channel;
        if ($channel !== 'usdt') {
            return $base;
        }

        // 取原始付款 tx hash + 链 → 反查 from 地址 (全部复用退款链上设施)
        $hash  = NezhaRefundControl::extract_original_tx_hash($order);
        $chain = NezhaRefundControl::detect_chain($hash);
        $base['tx_hash'] = $hash;
        $base['chain']   = $chain;

        if (!$hash || !$chain) {
            $base['action'] = 'inconclusive';
            $base['detail'] = 'USDT 付款未携带可识别的链上交易哈希, 无法反查来源地址做制裁筛查, 转人工复核。';
            return $base;
        }

        $from = NezhaRefundControl::reverse_lookup_from_address($hash, $chain);
        $base['from_address'] = $from;

        if (!$from) {
            $base['action'] = 'inconclusive';
            $base['detail'] = "链上反查付款来源地址失败(tx={$hash}, chain={$chain}; API 不可达或交易未确认), 转人工复核。";
            return $base;
        }

        $matched = self::screen_address($from);
        if ($matched) {
            $base['action']  = 'reject';
            $base['matched'] = $matched;
            $base['detail']  = sprintf(
                '付款来源地址命中制裁名单(%s): from=%s, chain=%s, tx=%s%s',
                $matched['source'] ?? 'OFAC_SDN', $from, $chain, $hash,
                isset($matched['sdn_uid']) && $matched['sdn_uid'] ? ', sdn_uid=' . $matched['sdn_uid'] : ''
            );
            return $base;
        }

        $base['action'] = 'pass';
        $base['detail'] = "付款来源地址 {$from}({$chain}) 未命中制裁名单, 放行。";
        return $base;
    }

    /**
     * 命中制裁 → 写一条自动拒单的风控记录(审计 + 后台「风控日志」可见).
     * 与下单风控① 共用 nezha_risk_records 表; rule=sanction, action=reject, status=auto.
     * @return int 记录 id
     */
    public static function record_reject($order, array $screen): int
    {
        $isGuest = (int) ($order->is_guest ?? 0) === 1;
        $m = $screen['matched'] ?? [];

        $rec = new NezhaRiskRecord();
        $rec->order_id        = $order->id;
        $rec->user_id         = $isGuest ? null : ($order->user_id ?? null);
        $rec->guest_id        = $isGuest ? (string) ($order->user_id ?? '') : null;
        $rec->restaurant_id   = $order->restaurant_id ?? null;
        $rec->payment_channel = 'usdt';
        $rec->order_amount    = (float) ($order->order_amount ?? 0);
        $rec->hit_rules       = [[
            'rule'   => 'sanction',
            'detail' => $screen['detail'] ?? '付款来源地址命中制裁名单',
        ]];
        $rec->action          = 'reject';
        $rec->status          = 'auto';
        $rec->snapshot        = [
            'from_address'  => $screen['from_address'] ?? null,
            'chain'         => $screen['chain'] ?? null,
            'tx_hash'       => $screen['tx_hash'] ?? null,
            'source'        => $m['source'] ?? null,
            'sdn_uid'       => $m['sdn_uid'] ?? null,
            'currency_type' => $m['currency_type'] ?? null,
        ];
        $rec->disposal_result = 'L1-6 制裁名单命中: 自动拒收, 不予放行出餐(平台不与受制裁主体交易)。';
        $rec->save();

        return $rec->id;
    }

    /**
     * 反查不出 from 地址(无 tx / API 不可达) → 写一条 review 审计记录待人工复核.
     * hold 策略下确认会被中止(订单留 pending 可重试), 商家/admin 可能多次点确认 →
     * 去重: 同订单已有 pending 的 sanction_inconclusive 记录则复用, 不重复刷队列.
     * @return int 记录 id
     */
    public static function record_inconclusive($order, array $screen): int
    {
        $existing = NezhaRiskRecord::where('order_id', $order->id)
            ->where('status', 'pending')
            ->where('hit_rules', 'like', '%sanction_inconclusive%')
            ->first();
        if ($existing) {
            return $existing->id;
        }

        $isGuest = (int) ($order->is_guest ?? 0) === 1;

        $rec = new NezhaRiskRecord();
        $rec->order_id        = $order->id;
        $rec->user_id         = $isGuest ? null : ($order->user_id ?? null);
        $rec->guest_id        = $isGuest ? (string) ($order->user_id ?? '') : null;
        $rec->restaurant_id   = $order->restaurant_id ?? null;
        $rec->payment_channel = 'usdt';
        $rec->order_amount    = (float) ($order->order_amount ?? 0);
        $rec->hit_rules       = [[
            'rule'   => 'sanction_inconclusive',
            'detail' => $screen['detail'] ?? 'USDT 来源地址无法反查, 制裁筛查未决',
        ]];
        $rec->action          = 'review';
        $rec->status          = 'pending';
        $rec->snapshot        = [
            'chain'   => $screen['chain'] ?? null,
            'tx_hash' => $screen['tx_hash'] ?? null,
        ];
        $rec->disposal_result = '制裁筛查未决(来源地址反查失败), 待人工核对来源地址。';
        $rec->save();

        return $rec->id;
    }
}
