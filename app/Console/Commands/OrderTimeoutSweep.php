<?php

namespace App\Console\Commands;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaOrderTimeout;
use App\CentralLogics\OrderLogic;
use App\Mail\NezhaOrderTimeoutMail;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * 哪吒 B方案 — 订单超时兜底任务（每分钟）。
 *
 * 杜绝订单"无限停留在待接单/备餐中"。规则全文见 docs/ORDER_TIMEOUT_RULES.md。
 * 三阶段 + 自动动作，全部经 nezha_order_timeout_events 幂等账本保证每单每动作恰好一次：
 *   A 凭证审核(pending+offline):  无凭证图 N1 分钟自动取消; 有凭证图 N2 邮件商家、N3 自动取消+待退款留痕+邮件商家退款
 *   B 付款确认后待接单(confirmed): N2 邮件商家、N3 自动取消+待退款留痕+邮件商家退款
 *   C 备餐(processing):           超 ETA+N4 或 ETA 未知 -> 升级客服(邮件商家+客服), 不自动取消
 *
 * 合规(L1): 平台不碰钱。自动取消绝不触发平台退款, 已付款单走 OrderLogic::record_direct_pay_refund_pending
 *           生成 pending_merchant_refund 留痕 + 通知商家原路退。顾客文案显示真实责任人=商家。
 */
class OrderTimeoutSweep extends Command
{
    protected $signature = 'nezha:order-timeout-sweep {--dry-run : 只报告将执行哪些动作, 不实际改动}';

    protected $description = '哪吒: 扫描超时订单并执行幂等自动动作(提醒/自动取消/待退款留痕/升级客服)';

    private bool $dry = false;

    /** 哪吒 批次1(TG双管·L3): 业主 TG 升级 dormant 开关(nezha_timeout_escalate_status, 默认 0)。 */
    private bool $ownerEscalate = false;

    /** 哪吒 P2-8: 业主升级独立阈值分钟(nezha_timeout_escalate_owner_min, 默认 5·L2 可调)。
     *  不复用 remind/email_merchant, 免把作业台提醒节奏与业主升级焊死(§0.5④)。仅 dormant 开关开时生效。 */
    private int $ownerEscalateMin = 5;

    public function handle()
    {
        $this->dry = (bool) $this->option('dry-run');
        $cfg = NezhaOrderTimeout::settings();

        if (!$cfg['status']) {
            $this->info('订单超时兜底总开关关闭(nezha_timeout_status=0), 跳过。');
            return self::SUCCESS;
        }

        // 哪吒 批次1: 无人接单时向业主 TG 升级的 dormant 开关(默认关=升级跳静默, 既有商家 TG 催单/邮件不受影响)。
        $this->ownerEscalate = (int) Helpers::get_business_settings('nezha_timeout_escalate_status') === 1;
        // 哪吒 P2-8: 业主升级独立阈值(默认 5·20min 取消前留 ~15min 挽回窗)。仅在上面 dormant 开关开时才被用到。
        $ownerMin = (int) Helpers::get_business_settings('nezha_timeout_escalate_owner_min');
        $this->ownerEscalateMin = $ownerMin > 0 ? $ownerMin : 5;

        $now = Carbon::now();
        $acted = 0;

        // ---- 阶段 A: 凭证审核(pending + offline_payment) ----
        $proofOrders = Order::with(['offline_payments', 'details', 'restaurant.vendor', 'customer'])
            ->where('order_status', 'pending')
            ->where('payment_method', 'offline_payment')
            ->get();
        foreach ($proofOrders as $order) {
            $start = NezhaOrderTimeout::clockStart($order, NezhaOrderTimeout::PHASE_PROOF);
            if (!$start) { continue; }
            $age = (int) floor($start->diffInSeconds($now) / 60);
            $hasProof = NezhaOrderTimeout::hasPaymentProof($order);

            if (!$hasProof) {
                if ($age >= $cfg['unpaid_cancel']) {
                    $acted += $this->fireOnce($order->id, 'cancel_unpaid', function () use ($order) {
                        $this->cancelOrder($order, false, '顾客超时未完成付款，系统自动取消');
                    }, "未付款超时 {$age}min 自动取消") ? 1 : 0;
                }
                continue;
            }

            // 有凭证图：可能已付待商家核对
            // 预约单(scheduled=1)P0: 已付款预约单绝不自动取消(与 D/E「钱已付不自动取消」一致)——不 email/不升级/不取消, 直接跳。
            // 未付款预约单(上面 !$hasProof 分支)窗口临近仍会被清扫(clockStart 窗口锚定, 承诺窗口前不启表)。业主 2026-07-11 已批。
            if (NezhaOrderTimeout::isScheduled($order)) { continue; }
            $this->escalateOwnerUnbound($order, $age); // 哪吒 P2-9: 商家未绑 TG(收不到催单)→ 不等阈值, 首次 sweep 即升级业主(dormant·共享幂等)
            if ($age >= $cfg['email_merchant']) {
                $acted += $this->fireOnce($order->id, 'email_merchant', function () use ($order, $age) {
                    $this->remindMerchant($order, $age, true);
                }, "有凭证超时 {$age}min 邮件商家") ? 1 : 0;
            }
            if ($age >= $this->ownerEscalateMin) {
                $this->escalateOwner($order, $age); // 哪吒 P2-8: 业主 TG 升级挪到独立阈值 T+5(原并列 email_merchant 10min·dormant·独立幂等)
            }
            if ($age >= $cfg['cancel']) {
                $acted += $this->fireOnce($order->id, 'cancel_paid_refund', function () use ($order) {
                    $this->cancelOrder($order, true, '商家超时未处理，系统自动取消，已付款项需商家原路退回');
                }, "有凭证超时 {$age}min 自动取消+退款留痕") ? 1 : 0;
            }
        }

        // ---- 阶段 B: 付款确认后待接单(confirmed) ----
        $acceptOrders = Order::with(['offline_payments', 'details', 'restaurant.vendor', 'customer'])
            ->where('order_status', 'confirmed')
            ->get();
        foreach ($acceptOrders as $order) {
            $start = NezhaOrderTimeout::clockStart($order, NezhaOrderTimeout::PHASE_ACCEPT);
            if (!$start) { continue; }
            $age = (int) floor($start->diffInSeconds($now) / 60);

            // 预约单(scheduled=1)P0: 已付款/已确认预约单绝不自动取消——窗口临近后由到窗口提醒机制(非本超时钟)驱动商家履约。业主 2026-07-11 已批。
            if (NezhaOrderTimeout::isScheduled($order)) { continue; }
            $this->escalateOwnerUnbound($order, $age); // 哪吒 P2-9: 商家未绑 TG → 不等阈值, 首次 sweep 即升级业主(dormant·共享幂等)
            if ($age >= $cfg['email_merchant']) {
                $acted += $this->fireOnce($order->id, 'email_merchant', function () use ($order, $age) {
                    $this->remindMerchant($order, $age, true);
                }, "待接单超时 {$age}min 邮件商家") ? 1 : 0;
            }
            if ($age >= $this->ownerEscalateMin) {
                $this->escalateOwner($order, $age); // 哪吒 P2-8: 业主 TG 升级挪到独立阈值 T+5(dormant·独立幂等)
            }
            if ($age >= $cfg['cancel']) {
                $acted += $this->fireOnce($order->id, 'cancel_paid_refund', function () use ($order) {
                    $this->cancelOrder($order, true, '商家超时未接单，系统自动取消，已付款项需商家原路退回');
                }, "待接单超时 {$age}min 自动取消+退款留痕") ? 1 : 0;
            }
        }

        // ---- 阶段 C: 备餐(processing) ----
        $prepOrders = Order::with(['restaurant.vendor', 'customer'])
            ->where('order_status', 'processing')
            ->get();
        foreach ($prepOrders as $order) {
            $start = NezhaOrderTimeout::clockStart($order, NezhaOrderTimeout::PHASE_PREP);
            if (!$start) { continue; }
            $age = (int) floor($start->diffInSeconds($now) / 60);
            // 预约单(scheduled=1)P0: 备餐中的预约单不计「备餐超时」升级(其时限由配送窗口而非即时钟界定)。业主 2026-07-11 已批。
            if (NezhaOrderTimeout::isScheduled($order)) { continue; }
            $etaMin = is_numeric($order->processing_time) && (int) $order->processing_time > 0
                ? (int) $order->processing_time : null;
            // 关键修复(2026-06-21): 无 ETA 不再在第 0 分钟立刻升级客服(避免每张未填出餐时间的单都骚扰客服,
            // 也与 describe() 客户态对齐——无 ETA 早期是正常 info「商家备餐中」)。
            // 改用绝对已等待 age 作基准：仅 age(或超出 ETA 的分钟数) >= prep_red 才真的通知商家+客服。
            $overBy = $etaMin === null ? $age : ($age - $etaMin);

            if ($overBy >= $cfg['prep_red']) {
                $overTxt = $overBy;
                $acted += $this->fireOnce($order->id, 'prep_escalate', function () use ($order, $overTxt) {
                    $this->escalatePrep($order, (int) $overTxt);
                }, "备餐超时 over={$overTxt}min 升级客服") ? 1 : 0;
            }
        }

        $msg = ($this->dry ? '[DRY-RUN] ' : '') . "订单超时兜底完成: 本轮触发动作 {$acted} 个。";
        $this->info($msg);
        Log::info('NEZHA_TIMEOUT_SWEEP: ' . $msg);
        return self::SUCCESS;
    }

    /**
     * 幂等闸: 先抢占 (order_id, action) 唯一行, 抢到才执行 $cb。
     * 唯一冲突=已处理过, 跳过。$cb 抛错则回滚(连同抢占行), 下轮重试。
     */
    private function fireOnce(int $orderId, string $action, callable $cb, string $detail = ''): bool
    {
        if ($this->dry) {
            // dry-run: 已有记录视为已处理, 否则报告将执行(不写库)
            $exists = DB::table('nezha_order_timeout_events')->where(['order_id' => $orderId, 'action' => $action])->exists();
            if ($exists) { return false; }
            $this->line("  [DRY] order#{$orderId} action={$action} :: {$detail}");
            return true;
        }
        try {
            DB::beginTransaction();
            DB::table('nezha_order_timeout_events')->insert([
                'order_id'   => $orderId,
                'action'     => $action,
                'fired_at'   => now(),
                'detail'     => $detail,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $cb();
            DB::commit();
            $this->line("  order#{$orderId} action={$action} :: {$detail}");
            return true;
        } catch (QueryException $e) {
            DB::rollBack();
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 || stripos($e->getMessage(), 'duplicate') !== false) {
                return false; // 幂等: 已处理过
            }
            Log::error("NEZHA_TIMEOUT_SWEEP {$action} order#{$orderId} QueryException: " . $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("NEZHA_TIMEOUT_SWEEP {$action} order#{$orderId} failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 自动取消订单。$paid=true 走待退款留痕(已付款); false 仅取消(未付款无退款)。
     * 取消的 DB 改动在外层事务内; 邮件/推送best-effort, 失败不回滚取消。
     */
    private function cancelOrder(Order $order, bool $paid, string $reason): void
    {
        // 事务内再确认状态未被商家手动改走(防竞态)
        $fresh = Order::where('id', $order->id)->lockForUpdate()->first();
        if (!$fresh || !in_array($fresh->order_status, ['pending', 'confirmed'], true)) {
            throw new \RuntimeException('order#' . $order->id . ' 状态已变(' . ($fresh->order_status ?? 'gone') . '), 放弃自动取消');
        }

        $fresh->order_status        = 'canceled';
        $fresh->canceled            = now();
        $fresh->cancellation_reason = $reason;
        $fresh->cancellation_note   = '系统超时自动处理';
        $fresh->canceled_by         = 'system_timeout';
        $fresh->save();

        Helpers::decreaseSellCount(order_details: $order->details);
        Helpers::increment_order_count($order->restaurant);

        if ($paid && $order->payment_method === 'offline_payment') {
            // 哪吒 H4(L1-6): 生成「待商家退款」前复跑制裁筛查 —— 命中制裁名单的单【不生成退款指示】
            // (否则等于促成商家把钱原路退回受制裁地址, 与 L1-6「制裁命中即拒/不与受制裁主体交易」抵触),
            // 改为只取消 + 留痕 + 升级人工裁决退款方向。仅 reject(确凿命中)拦; pass/inconclusive/筛查异常
            // 一律照常生成退款留痕(不误伤正常单, 与原行为一致)。
            $nezhaSanctionReject = false;
            if (\App\CentralLogics\NezhaSanctionScreen::enabled()) {
                try {
                    $nezhaScreen = \App\CentralLogics\NezhaSanctionScreen::screen_order($order);
                    if (($nezhaScreen['action'] ?? 'pass') === 'reject') {
                        $nezhaSanctionReject = true;
                        try { \App\CentralLogics\NezhaSanctionScreen::record_reject($fresh, $nezhaScreen); } catch (\Throwable $e) {}
                        Log::warning('NEZHA_H4 sanctioned order auto-canceled WITHOUT refund instruction (escalated). order#' . $fresh->id);
                    }
                } catch (\Throwable $e) {
                    Log::warning('NEZHA_H4 sanction re-screen failed order#' . $fresh->id . ': ' . $e->getMessage());
                }
            }
            \App\Models\OfflinePayments::where('order_id', $order->id)
                ->whereIn('status', ['pending', 'verified', 'denied'])
                ->update(['status' => 'canceled']);
            if ($nezhaSanctionReject) {
                $this->escalateToSupport($fresh, "订单 #{$fresh->id} 超时自动取消：付款来源命中制裁名单(L1-6)，已【不生成退款指示】。请人工裁决退款方向，勿直接原路退回受制裁地址。");
            } else {
                OrderLogic::record_direct_pay_refund_pending($fresh, 'system', $order->user_id, $reason, true);
            }
        } elseif ($order->payment_method === 'offline_payment') {
            \App\Models\OfflinePayments::where('order_id', $order->id)
                ->where('status', 'pending')
                ->update(['status' => 'canceled']);
        }

        // best-effort 通知(不影响取消提交)
        try {
            $this->notifyCustomerCanceled($fresh, $paid);
        } catch (\Throwable $e) {
            Log::warning('NEZHA_TIMEOUT notifyCustomer order#' . $order->id . ': ' . $e->getMessage());
        }
        try {
            Helpers::send_order_notification($fresh);
        } catch (\Throwable $e) {
            info('NEZHA_TIMEOUT send_order_notification: ' . $e->getMessage());
        }
        if ($paid) {
            try {
                $this->mailMerchant($order, 'cancel_refund', $this->waited($order), true);
            } catch (\Throwable $e) {
                Log::warning('NEZHA_TIMEOUT mailMerchant cancel order#' . $order->id . ': ' . $e->getMessage());
            }
            $this->telegramMerchant($order, 'cancel_refund', $this->waited($order));
        }
    }

    private function remindMerchant(Order $order, int $age, bool $paid): void
    {
        // 邮件商家(失败抛出 -> 回滚重试)
        $this->mailMerchant($order, 'remind', $age, $paid);
        // 升级客服: 通知平台客服邮箱(best-effort)
        $this->escalateToSupport($order, "订单 #{$order->id} 待接单已超 {$age} 分钟，商家未处理。");
        $this->telegramMerchant($order, 'remind', $age);
    }

    private function escalatePrep(Order $order, int $overBy): void
    {
        $this->mailMerchant($order, 'prep_overtime', $overBy, true);
        $this->escalateToSupport($order, "订单 #{$order->id} 备餐超时约 {$overBy} 分钟(或无预计出餐时间)，已升级。");
        $this->telegramMerchant($order, 'prep_overtime', $overBy);
    }

    /**
     * 哪吒: 实时提醒商家本人(Telegram)。复用 Helpers::sendTelegramToRestaurant(每家在线店硬闸已绑);
     * best-effort: 任何失败只记日志不抛, 不阻断超时 sweep 主流程, 不影响邮件那条腿。
     * 软提醒沿用商家「仅系统(面板)」开关(timeout_notify_email=0)可关; 但敏感的 cancel_refund 恒发
     * (与邮件一致, L1 退款义务必须送达商家)。纯通知, 不碰钱。
     */
    private function telegramMerchant(Order $order, string $type, int $minutes): void
    {
        try {
            $restaurant = $order->restaurant;
            if (!$restaurant || !($restaurant->telegram_chat_id ?? null)) {
                \App\CentralLogics\NezhaNotifyLog::record('telegram', 'merchant', $type, 'no_recipient', $order->id, $order->restaurant_id, 'no telegram_chat_id');
                return;
            }
            $sensitive = $type === 'cancel_refund';
            if (!$sensitive && (int) ($restaurant->timeout_notify_telegram ?? 1) === 0) {
                \App\CentralLogics\NezhaNotifyLog::record('telegram', 'merchant', $type, 'skipped', $order->id, $order->restaurant_id, 'timeout_notify_telegram=0');
                return;
            }
            $id = $order->id;
            switch ($type) {
                case 'cancel_refund':
                    $text = "⚠️ 哪吒通知｜订单 #{$id} 因超时已被系统自动取消。顾客此前直付给你的款项，请按原路尽快退回。";
                    break;
                case 'prep_overtime':
                    $text = "🍳 哪吒提醒｜订单 #{$id} 备餐已超时约 {$minutes} 分钟，请尽快出餐，避免顾客久等。";
                    break;
                default: // remind
                    $text = "🔔 哪吒提醒｜订单 #{$id} 已等待约 {$minutes} 分钟仍未处理，请尽快登录商家后台接单/核对。";
            }
            $nzSent = Helpers::sendTelegramToRestaurant($restaurant, $text);
            \App\CentralLogics\NezhaNotifyLog::record('telegram', 'merchant', $type, $nzSent ? 'ok' : 'failed', $order->id, $order->restaurant_id);
        } catch (\Throwable $e) {
            \App\CentralLogics\NezhaNotifyLog::record('telegram', 'merchant', $type, 'failed', $order->id, $order->restaurant_id, 'exception');
            Log::info('NEZHA_TIMEOUT telegramMerchant order#' . $order->id . ': ' . $e->getMessage());
        }
    }

    /**
     * 哪吒 批次1(TG双管·L3 兜底腿): 无人接单时向【业主】Telegram 升级(nezha_risk_admin_chat_id)。
     *
     * 与 email_merchant(10min)同级、并联在既有商家催单腿旁——达成 §5 双扇出:
     *   ① 商家 TG 催单 = 既有 telegramMerchant('remind') / 邮件(remindMerchant), 本方法【不触碰】；
     *   ② 业主 TG 升级 = 本方法(新增), 由 dormant 开关 nezha_timeout_escalate_status 单独门控。
     * 故开关=0 回滚只让"业主升级跳"静默, 既有商家 TG/邮件行为零回归。
     *
     * 幂等: 每单一次, 走独立 Cache key(参考 sendTelegramOrderAlert 的 tg_alert_ 模式), 不占用 email_merchant 事件账本。
     * 文案: 店名 / 单号 / 已挂分钟 / 【商家】电话(经营联系方式, 发业主合规)——🔴 禁带顾客任何 PII(§7-1)。
     * 🔴 只发通知, 绝不碰 NezhaOrderTimeout 取消/退款/状态动作(§7-2 触 L1-1/L1-2)。best-effort: 任何失败只记日志不抛。
     */
    private function escalateOwner(Order $order, int $age): void
    {
        if (!$this->ownerEscalate) {
            return; // 开关 dormant: 业主升级跳静默(既有商家 TG 催单/邮件不受影响)
        }
        if ($this->dry) {
            $this->line("  [DRY] order#{$order->id} owner_escalate :: 业主 TG 升级(已挂 {$age}min)");
            return;
        }
        $restaurant = $order->restaurant;
        $shopName  = $restaurant?->name ?: ('餐厅 #' . ($order->restaurant_id ?? '?'));
        $shopPhone = $restaurant?->phone ?: ($restaurant?->vendor?->phone ?: '未登记');
        $text = "🚨 哪吒升级｜「{$shopName}」订单 #{$order->id} 已挂约 {$age} 分钟无人接单/处理，请联系商家催单。\n商家电话：{$shopPhone}";
        $this->ownerEscalateDispatch($order, $text, 'stuck');
    }

    /**
     * 哪吒 P2-9: 商家【未绑 TG】的新单 → 不等 T+5 阈值, 首次 sweep 即升级业主(该店收不到催单, 再等无意义)。
     * 与常规 escalateOwner 共享 nezha_owner_escalate_<id> 幂等键(二选一先到先得·不重复打扰); 仅未绑店触发, 已绑走常规。
     * 🔴 纯通知(店名/单号/商家电话·禁顾客 PII), 不碰任何 L1 取消/退款/状态动作。dormant 开关 nezha_timeout_escalate_status 门控。
     * 注: 「商家已绑但 P0 送达失败」的早升级需持久投递记录(P4 outbox)才能可靠判定, 现由常规 T+5 escalateOwner 兜底, 不在本轮。
     */
    private function escalateOwnerUnbound(Order $order, int $age): void
    {
        if (!$this->ownerEscalate) {
            return;
        }
        $restaurant = $order->restaurant;
        if (!$restaurant || ($restaurant->telegram_chat_id ?? null)) {
            return; // 已绑 TG(能收催单) → 走常规 T+5 escalateOwner, 不早升级
        }
        if ($this->dry) {
            $this->line("  [DRY] order#{$order->id} owner_escalate_unbound :: 商家未绑 TG 早升级(挂 {$age}min)");
            return;
        }
        $shopName  = $restaurant?->name ?: ('餐厅 #' . ($order->restaurant_id ?? '?'));
        $shopPhone = $restaurant?->phone ?: ($restaurant?->vendor?->phone ?: '未登记');
        $text = "🚨 哪吒升级｜「{$shopName}」订单 #{$order->id} 已到店，但该店未绑 TG 提醒（收不到新单推送），请尽快电话联系商家接单。\n商家电话：{$shopPhone}";
        $this->ownerEscalateDispatch($order, $text, 'unbound');
    }

    /**
     * 哪吒 P0/P2 共享: 向业主 TG 发升级。每单一次幂等(nezha_owner_escalate_<id>·TTL 1 天覆盖取消窗口内重复 sweep);
     * 送达真值由 P0 加固的 sendTelegramToAdmin 提供——硬失败清幂等标记, 靠下一分钟 sweep 重升(20min 取消前尚有重试窗)。
     * 业主 chat 未绑=明确记一行不静默(§5-№4)。best-effort: 任何异常只记日志不抛, 不阻断 sweep 主流程。
     */
    private function ownerEscalateDispatch(Order $order, string $text, string $tag): void
    {
        // 每单一次(独立于 email_merchant 幂等账本)
        if (!Cache::add('nezha_owner_escalate_' . $order->id, 1, now()->addDay())) {
            return;
        }
        try {
            $chatId = Helpers::get_business_settings('nezha_risk_admin_chat_id', false);
            if (!$chatId) {
                \App\CentralLogics\NezhaNotifyLog::record('telegram', 'owner', 'owner_escalate', 'no_recipient', $order->id, $order->restaurant_id, 'admin chat unset · ' . $tag);
                // 🔴 升级路径不许静默(§5-№4): 业主 chat 未绑时明确记一行
                Log::warning('NEZHA_TIMEOUT_OWNER_ESCALATE[' . $tag . '] 业主 TG 未绑(nezha_risk_admin_chat_id 空), 订单 #' . $order->id . ' 升级未送达, 请补配业主 chat_id');
                return;
            }
            // P0-2 幂等后置(§0.5③): sendTelegramToAdmin 返送达真值(异步=入队成功 true·送达+重试归 Job tries=3; 同步=真实真值)。
            if (Helpers::sendTelegramToAdmin($text)) {
                \App\CentralLogics\NezhaNotifyLog::record('telegram', 'owner', 'owner_escalate', 'ok', $order->id, $order->restaurant_id, $tag);
                Log::info('NEZHA_TIMEOUT_OWNER_ESCALATE[' . $tag . '] 已升级业主 TG · order#' . $order->id);
            } else {
                \App\CentralLogics\NezhaNotifyLog::record('telegram', 'owner', 'owner_escalate', 'failed', $order->id, $order->restaurant_id, $tag);
                Cache::forget('nezha_owner_escalate_' . $order->id);
                Log::warning('NEZHA_TIMEOUT_OWNER_ESCALATE[' . $tag . '] 发送失败, 已清幂等标记待下轮 sweep 重试 · order#' . $order->id);
            }
        } catch (\Throwable $e) {
            Log::warning('NEZHA_TIMEOUT ownerEscalateDispatch[' . $tag . '] order#' . $order->id . ': ' . $e->getMessage());
        }
    }

    private function mailMerchant(Order $order, string $type, int $minutes, bool $paid): void
    {
        $email = $order->restaurant?->nezha_notify_email ?: ($order->restaurant?->email ?? $order->restaurant?->vendor?->email);
        $name  = $order->restaurant?->name ?? '商家';
        if (!$email) {
            \App\CentralLogics\NezhaNotifyLog::record('email', 'merchant', $type, 'no_recipient', $order->id, $order->restaurant_id, 'no email');
            Log::warning('NEZHA_TIMEOUT 商家无邮箱, 跳过邮件 order#' . $order->id);
            return;
        }
        // 哪吒: 超时提醒渠道开关——商家可在后台「餐厅设置」选「仅系统(面板)」关掉软提醒邮件;
        // 但敏感邮件(自动取消+需原路退款 cancel_refund)恒发, 无视开关(L1 退款义务必须落到商家)。
        $sensitive = in_array($type, ['cancel_refund'], true);
        if (! $sensitive && (int) ($order->restaurant?->timeout_notify_email ?? 1) === 0) {
            \App\CentralLogics\NezhaNotifyLog::record('email', 'merchant', $type, 'skipped', $order->id, $order->restaurant_id, 'timeout_notify_email=0');
            Log::info('NEZHA_TIMEOUT 商家选「仅系统」, 跳过软提醒邮件 type=' . $type . ' order#' . $order->id);
            return;
        }
        Mail::to($email)->send(new NezhaOrderTimeoutMail($type, (int) $order->id, $name, $minutes, $paid));
        \App\CentralLogics\NezhaNotifyLog::record('email', 'merchant', $type, 'ok', $order->id, $order->restaurant_id);
    }

    private function escalateToSupport(Order $order, string $body): void
    {
        try {
            Log::warning('NEZHA_TIMEOUT_ESCALATE order#' . $order->id . ': ' . $body);
            // 升级客服: 给平台客服信箱发简报(best-effort)
            Mail::raw($body . "\n\n请登录后台跟进订单 #" . $order->id . "。", function ($m) use ($order) {
                $m->to('support@nezha.am')->subject('哪吒 · 订单超时升级 #' . $order->id);
            });
        } catch (\Throwable $e) {
            Log::warning('NEZHA_TIMEOUT escalateToSupport order#' . $order->id . ': ' . $e->getMessage());
        }
    }

    private function notifyCustomerCanceled(Order $order, bool $paid): void
    {
        if ($order->is_guest) { return; }
        $zh = stripos(($order->customer?->current_language_key ?: 'zh'), 'zh') === 0;
        $title = $zh ? '订单已取消' : 'Order canceled';
        if ($paid) {
            $msg = $zh
                ? '商家接单超时，订单 #' . $order->id . ' 已自动取消。你此前直付商家的款项，平台将通知商家联系你按原路退回。'
                : 'The restaurant did not respond in time, so order #' . $order->id . ' was canceled. For the amount paid directly to the restaurant, the platform will ask the restaurant to refund you via the original method (the platform does not handle this money).';
        } else {
            $msg = $zh
                ? '订单 #' . $order->id . ' 因超时未完成付款已自动取消。'
                : 'Order #' . $order->id . ' was canceled because payment was not completed in time.';
        }
        $data = Helpers::makeDataForPushNotification(title: $title, message: $msg, orderId: $order->id, type: 'order_status', orderStatus: 'canceled');
        // 哪吒: 顾客「订单进度」推送偏好闸(接单超时自动取消)
        if ($order->customer && Helpers::customerWantsPush($order->customer, $paid ? 'refund' : 'order_progress')) {
            Helpers::send_push_notif_to_customer($order->customer, $data);
        }
        Helpers::insertDataOnNotificationTable($data, 'user', $order->user_id);
        Helpers::markCancelNotified($order->id);
    }

    private function waited(Order $order): int
    {
        $phase = NezhaOrderTimeout::phase($order) ?? NezhaOrderTimeout::PHASE_PROOF;
        $start = NezhaOrderTimeout::clockStart($order, $phase);
        return $start ? (int) floor($start->diffInSeconds(Carbon::now()) / 60) : 0;
    }
}
