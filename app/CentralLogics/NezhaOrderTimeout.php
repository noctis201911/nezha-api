<?php

namespace App\CentralLogics;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒 B方案 — 订单超时规则集中计算（单一真相源）。
 *
 * 杜绝订单"无限停留在待接单/备餐中"。本类被两处共用：
 *   ① 订单详情 API track_order -> describe() 下发顾客可见超时状态（展示层，无副作用）
 *   ② 每分钟兜底任务 nezha:order-timeout-sweep -> 据 phase()/阈值 执行自动动作（动作层）
 * 前端不得再写散落计时器，只渲染 describe() 下发的 nezha_timeout 对象。
 *
 * 规则全文见 docs/ORDER_TIMEOUT_RULES.md。触及 L1-1/L1-2，改动需用户批准。
 */
class NezhaOrderTimeout
{
    const PHASE_PROOF  = 'proof_review';   // A 凭证审核: pending + offline_payment
    const PHASE_ACCEPT = 'await_accept';   // B 付款确认后待接单: confirmed
    const PHASE_PREP   = 'preparing';      // C 备餐: processing

    /** 阈值（business_settings，可后台调；测试可注入）。单位=分钟。 */
    public static function settings(): array
    {
        $rows = DB::table('business_settings')->whereIn('key', [
            'nezha_timeout_status',
            'nezha_timeout_remind_min',
            'nezha_timeout_email_merchant_min',
            'nezha_timeout_unpaid_cancel_min',
            'nezha_timeout_cancel_min',
            'nezha_timeout_prep_orange_min',
            'nezha_timeout_prep_red_min',
        ])->pluck('value', 'key');

        return [
            'status'         => isset($rows['nezha_timeout_status']) ? (int) $rows['nezha_timeout_status'] : 1,
            'remind'         => (int) ($rows['nezha_timeout_remind_min'] ?? 5),
            'email_merchant' => (int) ($rows['nezha_timeout_email_merchant_min'] ?? 10),
            'unpaid_cancel'  => (int) ($rows['nezha_timeout_unpaid_cancel_min'] ?? 10),
            'cancel'         => (int) ($rows['nezha_timeout_cancel_min'] ?? 20),
            'prep_orange'    => (int) ($rows['nezha_timeout_prep_orange_min'] ?? 5),
            'prep_red'       => (int) ($rows['nezha_timeout_prep_red_min'] ?? 15),
        ];
    }

    /** 订单当前所属超时阶段，不在范围返回 null。 */
    public static function phase(Order $order): ?string
    {
        $s = $order->order_status;
        if ($s === 'pending' && $order->payment_method === 'offline_payment') {
            return self::PHASE_PROOF;
        }
        if ($s === 'confirmed') {
            return self::PHASE_ACCEPT;
        }
        if ($s === 'processing') {
            return self::PHASE_PREP;
        }
        return null;
    }

    /** 阶段 A/B 的时钟起点。 */
    public static function clockStart(Order $order, string $phase): ?Carbon
    {
        $raw = match ($phase) {
            self::PHASE_PROOF  => optional($order->offline_payments)->created_at ?? $order->pending ?? $order->created_at,
            self::PHASE_ACCEPT => $order->confirmed ?? $order->created_at,
            self::PHASE_PREP   => $order->processing ?? $order->created_at,
            default            => null,
        };
        return $raw ? Carbon::parse($raw) : null;
    }

    /**
     * 顾客是否已上传付款凭证图。
     * = offline_payment.payment_info 中, method_fields 标 input_type=file 的字段有非空文件值。
     */
    public static function hasProofImage(Order $order): bool
    {
        $op = $order->offline_payments;
        if (!$op) {
            return false;
        }
        $info   = is_array($op->payment_info) ? $op->payment_info : json_decode((string) $op->payment_info, true);
        $fields = is_array($op->method_fields) ? $op->method_fields : json_decode((string) $op->method_fields, true);
        if (!is_array($info)) {
            return false;
        }
        // 找 file 型字段名；若 method_fields 不可用则退而求其次：任一值看起来像文件路径
        $fileNames = [];
        if (is_array($fields)) {
            foreach ($fields as $f) {
                if (($f['input_type'] ?? null) === 'file' && !empty($f['input_field_name'])) {
                    $fileNames[] = $f['input_field_name'];
                }
            }
        }
        if ($fileNames) {
            foreach ($fileNames as $name) {
                $val = $info[$name] ?? '';
                if (is_string($val) && $val !== '' && str_contains($val, '/')) {
                    return true; // 形如 offline_payment/xxx.webp
                }
            }
            return false;
        }
        // 兜底：任一值含图片扩展名
        foreach ($info as $v) {
            if (is_string($v) && preg_match('/\.(webp|png|jpe?g|gif|pdf)$/i', $v)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 展示层：返回 nezha_timeout 对象供订单详情 API 下发。不在超时范围返回 null。
     * 字段（需求5）：phase / severity / title / next_step / deadline_at / refund_method / refund_eta。
     */
    public static function describe(Order $order): ?array
    {
        $phase = self::phase($order);
        if (!$phase) {
            return null;
        }
        $cfg = self::settings();
        $now = Carbon::now();

        $refundMethod = '联系商家原路退回（平台不经手此款）';
        $refundEta    = '以商家退款时间为准（平台不经手，无法预估到账）';

        if ($phase === self::PHASE_PREP) {
            $start  = self::clockStart($order, $phase);
            $etaMin = is_numeric($order->processing_time) ? (int) $order->processing_time : null;
            $elapsed = $start ? max(0, (int) floor($start->diffInSeconds($now) / 60)) : 0;

            if ($etaMin === null || $etaMin <= 0) {
                return [
                    'phase'         => $phase,
                    'severity'      => 'error',
                    'title'         => '备餐异常，已升级客服处理',
                    'next_step'     => '商家未填写预计出餐时间，系统已通知商家与客服跟进。如长时间无进展，请联系客服。',
                    'deadline_at'   => null,
                    'refund_method' => $refundMethod,
                    'refund_eta'    => $refundEta,
                    'elapsed_minutes' => $elapsed,
                ];
            }

            $overBy   = $elapsed - $etaMin;
            $deadline = $start ? $start->copy()->addMinutes($etaMin)->toDateTimeString() : null;
            if ($overBy < $cfg['prep_orange']) {
                return [
                    'phase'         => $phase,
                    'severity'      => 'info',
                    'title'         => "备餐中，预计 {$etaMin} 分钟出餐",
                    'next_step'     => '商家正在备餐，出餐后将更新取餐号。',
                    'deadline_at'   => $deadline,
                    'refund_method' => $refundMethod,
                    'refund_eta'    => $refundEta,
                    'elapsed_minutes' => $elapsed,
                ];
            }
            if ($overBy < $cfg['prep_red']) {
                return [
                    'phase'         => $phase,
                    'severity'      => 'warning',
                    'title'         => '出餐稍有延迟',
                    'next_step'     => "已超出预计出餐时间约 {$overBy} 分钟，商家仍在备餐。如较急可联系商家。",
                    'deadline_at'   => $deadline,
                    'refund_method' => $refundMethod,
                    'refund_eta'    => $refundEta,
                    'elapsed_minutes' => $elapsed,
                ];
            }
            return [
                'phase'         => $phase,
                'severity'      => 'error',
                'title'         => '备餐异常，已升级客服处理',
                'next_step'     => "已超出预计出餐时间约 {$overBy} 分钟，系统已通知商家与客服跟进。如需取消/退款，请联系客服，平台将通知商家原路退款。",
                'deadline_at'   => $deadline,
                'refund_method' => $refundMethod,
                'refund_eta'    => $refundEta,
                'elapsed_minutes' => $elapsed,
            ];
        }

        // 阶段 A / B：等待商家确认收款/接单
        $start   = self::clockStart($order, $phase);
        $elapsed = $start ? max(0, (int) floor($start->diffInSeconds($now) / 60)) : 0;
        $hasProof = $phase === self::PHASE_PROOF ? self::hasProofImage($order) : true; // B 阶段钱已确认

        // 终态截止与退款表述
        if ($phase === self::PHASE_PROOF && !$hasProof) {
            // 未付款（未上传凭证）：超时自动取消，无退款
            $deadline = $start ? $start->copy()->addMinutes($cfg['unpaid_cancel'])->toDateTimeString() : null;
            $refundMethod = '未完成付款，无需退款';
            $refundEta    = null;
            $autoNote = "若 {$cfg['unpaid_cancel']} 分钟内仍未完成付款，系统将自动取消本单。";
        } else {
            // 已付款/钱已确认：超时自动取消 + 通知商家原路退款
            $deadline = $start ? $start->copy()->addMinutes($cfg['cancel'])->toDateTimeString() : null;
            $autoNote = "系统将在第 {$cfg['email_merchant']} 分钟提醒商家、第 {$cfg['cancel']} 分钟自动取消并通知商家联系你原路退款。";
        }

        if ($elapsed < $cfg['remind']) {
            return [
                'phase'         => $phase,
                'severity'      => 'info',
                'title'         => '已下单，等待商家确认收款与接单',
                'next_step'     => '通常 3–5 分钟内商家会确认。' . $autoNote,
                'deadline_at'   => $deadline,
                'refund_method' => $refundMethod,
                'refund_eta'    => $refundEta,
                'elapsed_minutes' => $elapsed,
            ];
        }

        return [
            'phase'         => $phase,
            'severity'      => 'warning',
            'title'         => '商家暂未确认，已等待 ' . self::humanDuration($elapsed),
            'next_step'     => '已超过通常的 3–5 分钟确认时间。' . $autoNote . ' 你也可现在联系商家或客服确认。',
            'deadline_at'   => $deadline,
            'refund_method' => $refundMethod,
            'refund_eta'    => $refundEta,
            'elapsed_minutes' => $elapsed,
        ];
    }

    public static function humanDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' 分钟';
        }
        $h = intdiv($minutes, 60);
        if ($h < 24) {
            return $h . ' 小时' . ($minutes % 60) . ' 分钟';
        }
        return intdiv($h, 24) . ' 天' . ($h % 24) . ' 小时';
    }
}
