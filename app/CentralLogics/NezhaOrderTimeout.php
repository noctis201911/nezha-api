<?php

namespace App\CentralLogics;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒 B方案 — 订单超时规则集中计算（单一真相源）。
 *
 * 杜绝订单"无限停留在待接单/备餐中/出餐后未配送/配送中未送达"。本类被两处共用：
 *   ① 订单详情 API track_order -> describe() 下发顾客可见超时状态（展示层，无副作用）
 *   ② 每分钟兜底任务 nezha:order-timeout-sweep -> 据 phase()/阈值 执行自动动作（动作层）
 * 前端不得再写散落计时器，只渲染 describe() 下发的 nezha_timeout 对象。
 *
 * 阶段 A/B/C（凭证审核/待接单/备餐）由动作层 + 展示层共同处理；
 * 阶段 D/E（已出餐待配送/配送中）**仅展示层**——饭已出/在配送、钱已付，
 * 自动取消风险高，按 L1 绝不自动取消，只对长时间无进展给诚实升级提示，不造 ETA/不伪造骑手。
 *
 * 规则全文见 docs/ORDER_TIMEOUT_RULES.md。触及 L1-1/L1-2，改动需用户批准。
 */
class NezhaOrderTimeout
{
    const PHASE_PROOF    = 'proof_review';   // A 凭证审核: pending + offline_payment
    const PHASE_ACCEPT   = 'await_accept';   // B 付款确认后待接单: confirmed
    const PHASE_PREP     = 'preparing';      // C 备餐: processing
    const PHASE_HANDOVER = 'handover';       // D 已出餐待配送(仅 delivery): handover
    const PHASE_PICKED   = 'picked_up';      // E 配送中: picked_up

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
            'nezha_timeout_handover_min',
            'nezha_timeout_picked_min',
        ])->pluck('value', 'key');

        return [
            'status'         => isset($rows['nezha_timeout_status']) ? (int) $rows['nezha_timeout_status'] : 1,
            'remind'         => (int) ($rows['nezha_timeout_remind_min'] ?? 5),
            'email_merchant' => (int) ($rows['nezha_timeout_email_merchant_min'] ?? 10),
            'unpaid_cancel'  => (int) ($rows['nezha_timeout_unpaid_cancel_min'] ?? 10),
            'cancel'         => (int) ($rows['nezha_timeout_cancel_min'] ?? 20),
            'prep_orange'    => (int) ($rows['nezha_timeout_prep_orange_min'] ?? 5),
            'prep_red'       => (int) ($rows['nezha_timeout_prep_red_min'] ?? 15),
            'handover'       => (int) ($rows['nezha_timeout_handover_min'] ?? 45),
            'picked'         => (int) ($rows['nezha_timeout_picked_min'] ?? 90),
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
        // D/E 仅 delivery 单计配送超时；take_away 的 handover=可取餐、非配送延迟，不计超时。
        if ($s === 'handover' && $order->order_type === 'delivery') {
            return self::PHASE_HANDOVER;
        }
        if ($s === 'picked_up' && $order->order_type === 'delivery') {
            return self::PHASE_PICKED;
        }
        return null;
    }

    /** 阶段时钟起点。D/E 只认状态切换真实时间列，无可靠记录返回 null（绝不退而用 created_at/updated_at 臆造）。 */
    public static function clockStart(Order $order, string $phase): ?Carbon
    {
        $raw = match ($phase) {
            self::PHASE_PROOF    => optional($order->offline_payments)->created_at ?? $order->pending ?? $order->created_at,
            self::PHASE_ACCEPT   => $order->confirmed ?? $order->created_at,
            self::PHASE_PREP     => $order->processing ?? $order->confirmed ?? $order->updated_at ?? $order->created_at,
            self::PHASE_HANDOVER => $order->handover,   // 仅真实出餐时间，无则 null
            self::PHASE_PICKED   => $order->picked_up,  // 仅真实配送时间，无则 null
            default              => null,
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
     * 顾客是否已提交有效交易哈希(链下 USDT 等文本凭证)。
     * = method_fields 中 input_type=text 且字段名含「哈希/Hash」的字段, 其 payment_info 值经
     *   64位十六进制(0x 可选)正则校验通过。仅认哈希类文本(防把备注等可选文本误判为凭证);
     *   格式不合(空/乱填)不算有效, 仍走未付款路径 —— 避免给未真付款单凭空造退款义务。
     */
    public static function hasValidHashText(Order $order): bool
    {
        $op = $order->offline_payments;
        if (!$op) {
            return false;
        }
        $info   = is_array($op->payment_info) ? $op->payment_info : json_decode((string) $op->payment_info, true);
        $fields = is_array($op->method_fields) ? $op->method_fields : json_decode((string) $op->method_fields, true);
        if (!is_array($info) || !is_array($fields)) {
            return false;
        }
        foreach ($fields as $f) {
            if (($f['input_type'] ?? null) !== 'text' || empty($f['input_field_name'])) {
                continue;
            }
            $name = $f['input_field_name'];
            if (stripos($name, '哈希') === false && stripos($name, 'hash') === false) {
                continue; // 只认哈希类文本字段
            }
            $val = $info[$name] ?? '';
            if (is_string($val) && preg_match('/^(0x)?[0-9a-fA-F]{64}$/', trim($val))) {
                return true;
            }
        }
        return false;
    }

    /**
     * 顾客是否已提交「有效付款凭证」= 截图文件 或 有效交易哈希文本。
     * USDT(链下)主推哈希、支付宝走截图; 二者任一有效即视为已提交凭证(对齐 PaymentDrawer 承诺)。
     * 替代单一 hasProofImage 作为「已付待核」判定, 使哈希单与图片单走同一 20min + 退款留痕路径。
     */
    public static function hasPaymentProof(Order $order): bool
    {
        return self::hasProofImage($order) || self::hasValidHashText($order);
    }

    /**
     * 展示层：返回 nezha_timeout 对象供订单详情 API 下发。不在超时范围返回 null。不在超时范围返回 null。
     * 字段（需求5）：phase / severity / title / next_step / contact_hint / deadline_at / refund_method / refund_eta。
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

        // 阶段 D/E：配送相关（仅展示，不自动取消）
        if ($phase === self::PHASE_HANDOVER || $phase === self::PHASE_PICKED) {
            return self::describeDelivery($order, $phase, $cfg, $now, $refundMethod, $refundEta);
        }

        if ($phase === self::PHASE_PREP) {
            $start  = self::clockStart($order, $phase);
            $etaMin = is_numeric($order->processing_time) ? (int) $order->processing_time : null;
            if ($etaMin !== null && $etaMin <= 0) {
                $etaMin = null; // 0/负数视同"未填"，不当 ETA
            }
            $elapsed = $start ? max(0, (int) floor($start->diffInSeconds($now) / 60)) : 0;

            // 升级基准：有 ETA 用「超出 ETA 的分钟数」；无 ETA 用「绝对已等待分钟数」。
            // 关键修复(2026-06-21): 无 ETA 不再立刻 error —— 早期是正常的 info「商家备餐中」，
            // 只有久未出餐(超 prep_orange/prep_red)才升级。与 OrderTimeoutSweep 客服升级阈值对齐：
            // 仅 $overBy >= prep_red(含无 ETA 时绝对 elapsed>=prep_red) 才真的通知商家+客服，
            // 故只有 error 级才写「已升级客服」(满足"不假称已升级"铁律)。
            $overBy   = $etaMin === null ? $elapsed : ($elapsed - $etaMin);
            $deadline = ($etaMin !== null && $start) ? $start->copy()->addMinutes($etaMin)->toDateTimeString() : null;

            // 正常备餐（含无 ETA 早期）：info，绝不报警
            if ($overBy < $cfg['prep_orange']) {
                return [
                    'phase'         => $phase,
                    'severity'      => 'info',
                    'title'         => $etaMin !== null ? "备餐中，预计 {$etaMin} 分钟出餐" : '商家备餐中',
                    'next_step'     => $etaMin !== null
                        ? '商家正在备餐，出餐后将更新配送安排。'
                        : '商家正在制作，出餐后会更新配送安排。',
                    'deadline_at'   => $deadline,
                    'refund_method' => $refundMethod,
                    'refund_eta'    => $refundEta,
                    'elapsed_minutes' => $elapsed,
                ];
            }
            // 轻微延迟：warning，措辞克制，不写「已升级客服」（此时 sweep 尚未升级）
            if ($overBy < $cfg['prep_red']) {
                return [
                    'phase'         => $phase,
                    'severity'      => 'warning',
                    'title'         => '备餐时间较长',
                    'next_step'     => $etaMin !== null
                        ? "已超出预计出餐时间约 {$overBy} 分钟，商家仍在备餐。如较急可联系商家或客服。"
                        : "备餐已等待约 {$elapsed} 分钟，商家仍在制作。如较急可联系商家或客服。",
                    'deadline_at'   => $deadline,
                    'refund_method' => $refundMethod,
                    'refund_eta'    => $refundEta,
                    'elapsed_minutes' => $elapsed,
                ];
            }
            // 严重超时：error。此档 OrderTimeoutSweep 已 escalatePrep 通知商家+客服，故「已升级」属实。
            return [
                'phase'         => $phase,
                'severity'      => 'error',
                'title'         => '备餐超时，已升级处理',
                'next_step'     => $etaMin !== null
                    ? "已超出预计出餐时间约 {$overBy} 分钟，系统已通知商家与客服跟进。如需取消/退款，请联系客服，平台将通知商家原路退款。"
                    : "备餐已等待约 {$elapsed} 分钟仍未出餐，系统已通知商家与客服跟进。如需取消/退款，请联系客服，平台将通知商家原路退款。",
                'deadline_at'   => $deadline,
                'refund_method' => $refundMethod,
                'refund_eta'    => $refundEta,
                'elapsed_minutes' => $elapsed,
            ];
        }

        // 阶段 A / B：等待商家确认收款/接单
        $start   = self::clockStart($order, $phase);
        $elapsed = $start ? max(0, (int) floor($start->diffInSeconds($now) / 60)) : 0;
        $hasProof = $phase === self::PHASE_PROOF ? self::hasPaymentProof($order) : true; // B 阶段钱已确认

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

        // 阶段 B（confirmed）：钱已确认、商家已接单，等待开始备餐。
        // 关键修复(2026-06-21): 不再沿用 A 阶段的「等待确认收款与接单」——收款与接单都已发生。
        if ($phase === self::PHASE_ACCEPT) {
            if ($elapsed < $cfg['remind']) {
                return [
                    'phase'         => $phase,
                    'severity'      => 'info',
                    'title'         => '商家已接单，正在安排备餐',
                    'next_step'     => '商家已确认收款并接单，正在安排备餐，出餐后会更新配送安排。' . $autoNote,
                    'deadline_at'   => $deadline,
                    'refund_method' => $refundMethod,
                    'refund_eta'    => $refundEta,
                    'elapsed_minutes' => $elapsed,
                ];
            }
            return [
                'phase'         => $phase,
                'severity'      => 'warning',
                'title'         => '商家已接单，但迟迟未开始备餐',
                'next_step'     => '商家已接单约 ' . self::humanDuration($elapsed) . '，但还未开始备餐。' . $autoNote . ' 你也可现在联系商家或客服确认。',
                'deadline_at'   => $deadline,
                'refund_method' => $refundMethod,
                'refund_eta'    => $refundEta,
                'elapsed_minutes' => $elapsed,
            ];
        }

        // 阶段 A（pending offline）：等待商家确认收款（前端对 pending 另有绿色清爽态处理）
        if ($elapsed < $cfg['remind']) {
            return [
                'phase'         => $phase,
                'severity'      => 'info',
                'title'         => '等待商家确认收款',
                'next_step'     => '通常 3–5 分钟内商家会确认收款并接单。' . $autoNote,
                'deadline_at'   => $deadline,
                'refund_method' => $refundMethod,
                'refund_eta'    => $refundEta,
                'elapsed_minutes' => $elapsed,
            ];
        }

        return [
            'phase'         => $phase,
            'severity'      => 'warning',
            'title'         => '商家暂未确认收款，已等待 ' . self::humanDuration($elapsed),
            'next_step'     => '已超过通常的 3–5 分钟确认时间。' . $autoNote . ' 你也可现在联系商家或客服确认。',
            'deadline_at'   => $deadline,
            'refund_method' => $refundMethod,
            'refund_eta'    => $refundEta,
            'elapsed_minutes' => $elapsed,
        ];
    }

    /**
     * 阶段 D/E 展示层：已出餐待配送 / 配送中。
     * 仅展示，绝不自动取消（饭已出/在配送、钱已付，L1 风险高）。
     * - 无可靠后台时间记录 -> 返回 no_time_record 诚实对象，绝不据无关时间臆造超时（需求3）。
     * - 未超阈值 -> 返回 null，前端用正常默认文案（需求6）。
     * - 超阈值 -> warning 诚实升级提示：不声称第三方配送在重试、不伪造骑手、不给虚假 ETA（需求5）。
     */
    private static function describeDelivery(Order $order, string $phase, array $cfg, Carbon $now, string $refundMethod, string $refundEta): ?array
    {
        $start       = self::clockStart($order, $phase);
        $contactHint = '可点「催单」或联系商家确认配送安排；长时间无进展请联系平台客服处理。';

        if (!$start) {
            // 无后台时间记录：诚实告知，不触发假超时
            return [
                'phase'           => $phase,
                'severity'        => 'info',
                'no_time_record'  => true,
                'title'           => $phase === self::PHASE_HANDOVER ? '餐已出好，等待配送' : '配送中',
                'next_step'       => '系统暂无该状态的后台时间记录，无法判断配送是否超时；配送进度以商家通知为准。',
                'contact_hint'    => $contactHint,
                'deadline_at'     => null,
                'refund_method'   => $refundMethod,
                'refund_eta'      => $refundEta,
                'elapsed_minutes' => null,
            ];
        }

        $elapsed   = max(0, (int) floor($start->diffInSeconds($now) / 60));
        $threshold = $phase === self::PHASE_HANDOVER ? $cfg['handover'] : $cfg['picked'];

        // 未超阈值：不下发超时对象，前端走正常默认文案
        if ($elapsed < $threshold) {
            return null;
        }

        if ($phase === self::PHASE_HANDOVER) {
            return [
                'phase'           => $phase,
                'severity'        => 'warning',
                'title'           => '已出餐较久，仍未开始配送',
                'next_step'       => '餐已出餐约 ' . self::humanDuration($elapsed) . '，配送尚未开始。平台未接入第三方配送实时进度，无法预估到达时间；如较急可联系商家确认配送安排。',
                'contact_hint'    => $contactHint,
                'deadline_at'     => null,
                'refund_method'   => $refundMethod,
                'refund_eta'      => $refundEta,
                'elapsed_minutes' => $elapsed,
            ];
        }

        return [
            'phase'           => $phase,
            'severity'        => 'warning',
            'title'           => '配送时间偏长，仍未送达',
            'next_step'       => '订单显示配送中已 ' . self::humanDuration($elapsed) . '，超过常规送达时间。平台无第三方配送实时位置与预计送达时间；如长时间未收到，请联系商家或客服核实。',
            'contact_hint'    => $contactHint,
            'deadline_at'     => null,
            'refund_method'   => $refundMethod,
            'refund_eta'      => $refundEta,
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
