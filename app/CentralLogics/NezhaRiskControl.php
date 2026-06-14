<?php

namespace App\CentralLogics;

use App\Models\BusinessSetting;
use App\Models\Order;
use App\Models\NezhaRiskRecord;
use App\Models\OfflinePaymentMethod;
use Carbon\Carbon;

/**
 * 哪吒外卖 风控① 交易红旗风控引擎 (法币 + USDT 共用; USDT 单笔/单日用独立阈值).
 *
 * 所有阈值来自 business_settings (后台「风控设置」页可调, 不硬编码).
 * evaluate() 只做判定不写库; 命中后由调用方 record() 落库+进审核队列.
 *
 * 处置档位:
 *   reject  单笔超上限 → 直接拒单 (status=auto)
 *   review  单日累计/频次/金额特征 → 转人工审核 (status=pending), 审核放行前不给收款码
 *   pass    放行
 */
class NezhaRiskControl
{
    /** 读单个配置(带默认值) */
    protected static function cfg(string $key, $default = null)
    {
        $v = BusinessSetting::where('key', $key)->first()?->value;
        return $v === null ? $default : $v;
    }

    /** 风控总开关 */
    public static function enabled(): bool
    {
        return (string) self::cfg('nezha_risk_control_status', '1') === '1';
    }

    /**
     * 从请求推断支付通道: rmb / usdt / other.
     * 优先用前端显式传的 payment_channel; 否则按 offline 收款方式名推断.
     */
    public static function detect_channel($request): string
    {
        $c = strtolower((string) ($request->payment_channel ?? ''));
        if (in_array($c, ['rmb', 'usdt'], true)) {
            return $c;
        }
        $name = (string) ($request->payment_method_name ?? $request->offline_method_name ?? '');
        if ($request->offline_payment_method_id) {
            $name = OfflinePaymentMethod::find($request->offline_payment_method_id)?->method_name ?? $name;
        }
        $upper = strtoupper($name);
        if (str_contains($upper, 'USDT') || str_contains($upper, 'TRC') || str_contains($upper, 'USD')) {
            return 'usdt';
        }
        return $name !== '' ? 'rmb' : 'other';
    }

    /**
     * 评估一笔下单意图.
     * @param array $ctx user_id, guest_id, restaurant_id, order_amount, payment_channel
     * @return array ['action'=>'pass'|'reject'|'review', 'hit_rules'=>[...], 'message'=>'...']
     */
    public static function evaluate(array $ctx): array
    {
        if (!self::enabled()) {
            return ['action' => 'pass', 'hit_rules' => [], 'message' => ''];
        }

        $channel = $ctx['payment_channel'] ?? 'other';
        $amount  = (float) ($ctx['order_amount'] ?? 0);
        $isUsdt  = $channel === 'usdt';

        // USDT 与法币分开的阈值
        $singleLimit = (float) ($isUsdt
            ? self::cfg('nezha_risk_usdt_single_limit', 200)
            : self::cfg('nezha_risk_single_order_limit', 100));
        $dailyLimit = (float) ($isUsdt
            ? self::cfg('nezha_risk_usdt_daily_limit', 500)
            : self::cfg('nezha_risk_daily_cumulative_limit', 300));

        $hits = [];

        // ── 规则1: 单笔上限 → 拒单 (优先级最高, 命中即返回; 不被人工放行豁免) ──
        if ($singleLimit > 0 && $amount > $singleLimit) {
            $hits[] = ['rule' => 'single_order_limit', 'detail' => "单笔 \${$amount} 超过上限 \${$singleLimit}"];
            return ['action' => 'reject', 'hit_rules' => $hits, 'message' => '订单金额超过单笔上限，请联系客服'];
        }

        $userId = $ctx['user_id'] ?? null;

        // ── 人工放行宽限: 客服在后台「放行」后, 该顾客在宽限期内重新下单直接通过 (仅豁免 review, 不豁免上面的单笔拒单) ──
        if ($userId) {
            $grace = (int) self::cfg('nezha_risk_approval_grace_minutes', 60);
            if ($grace > 0) {
                $approved = NezhaRiskRecord::where('user_id', $userId)
                    ->where('status', 'approved')
                    ->where('reviewed_at', '>=', Carbon::now()->subMinutes($grace))
                    ->exists();
                if ($approved) {
                    return ['action' => 'pass', 'hit_rules' => [['rule' => 'manual_approved', 'detail' => '客服已放行(宽限期内)']], 'message' => ''];
                }
            }
        }

        $action = 'pass';

        // 累计 / 频次: 只对登录用户统计 (游客无稳定身份, 仅走单笔+金额特征)
        if ($userId) {
            // ── 规则2: 单日累计(含本单) → 转审核 ──
            $todaySum = (float) Order::where('user_id', $userId)
                ->where('created_at', '>=', Carbon::today())
                ->whereNotIn('order_status', ['failed', 'canceled'])
                ->sum('order_amount');
            if ($dailyLimit > 0 && ($todaySum + $amount) > $dailyLimit) {
                $hits[] = ['rule' => 'daily_cumulative', 'detail' => '单日累计 $' . round($todaySum + $amount, 2) . " 超过 \${$dailyLimit}"];
                $action = 'review';
            }

            // ── 规则3: 频次 (24h / 10min) → 转审核 ──
            $cnt24h = Order::where('user_id', $userId)->where('created_at', '>=', Carbon::now()->subDay())->count();
            $cnt10m = Order::where('user_id', $userId)->where('created_at', '>=', Carbon::now()->subMinutes(10))->count();
            $freq24 = (int) self::cfg('nezha_risk_freq_24h_count', 5);
            $freq10 = (int) self::cfg('nezha_risk_freq_10min_count', 2);
            if (($freq24 > 0 && $cnt24h > $freq24) || ($freq10 > 0 && $cnt10m > $freq10)) {
                $hits[] = ['rule' => 'frequency', 'detail' => "24h={$cnt24h}单 / 10min={$cnt10m}单"];
                $action = 'review';
            }
        }

        // ── 规则4: 金额特征 (大额) → 转审核 ──
        // 注: "整百/整千"子规则已于 2026-06-14 删除 —— 德拉姆计价下正常菜价多为整百(1500/2000), 会大量误报(用户决定移除)。
        $largeThreshold = (float) self::cfg('nezha_risk_large_amount_threshold', 80);
        if ($largeThreshold > 0 && $amount >= $largeThreshold) {
            $hits[] = ['rule' => 'large_amount', 'detail' => "大额 \${$amount} ≥ \${$largeThreshold}"];
            $action = 'review';
        }

        if ($action === 'review') {
            return ['action' => 'review', 'hit_rules' => $hits, 'message' => '订单需人工审核，请等待客服联系'];
        }
        return ['action' => 'pass', 'hit_rules' => $hits, 'message' => ''];
    }

    /**
     * 命中后落库一条记录 (审计日志 + 进审核队列).
     * @return int 记录 id
     */
    public static function record(array $ctx, array $result, $orderId = null): int
    {
        $rec = new NezhaRiskRecord();
        $rec->order_id        = $orderId;
        $rec->user_id         = $ctx['user_id'] ?? null;
        $rec->guest_id        = $ctx['guest_id'] ?? null;
        $rec->restaurant_id   = $ctx['restaurant_id'] ?? null;
        $rec->payment_channel = $ctx['payment_channel'] ?? 'other';
        $rec->order_amount    = (float) ($ctx['order_amount'] ?? 0);
        $rec->hit_rules       = $result['hit_rules'] ?? [];
        $rec->action          = $result['action'];
        $rec->status          = $result['action'] === 'reject' ? 'auto' : 'pending';
        $rec->snapshot        = $ctx['snapshot'] ?? null;
        $rec->ip_address      = $ctx['ip_address'] ?? null;
        $rec->save();

        return $rec->id;
    }
}
