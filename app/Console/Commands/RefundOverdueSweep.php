<?php

namespace App\Console\Commands;

use App\Mail\NezhaRefundOverdueMail;
use App\Models\BusinessSetting;
use App\Models\NezhaRefundRecord;
use App\Models\NezhaRiskRecord;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * 哪吒 B方案 — 商家「逾期未退款」兜底扫描(每天)。
 *
 * 对 nezha_refund_records 中 status=pending_merchant_refund 且生成(created_at)后超过阈值天数
 * 仍未 merchant_refunded 的记录, 施加【非资金性】约束。全部经 nezha_refund_overdue_events
 * 幂等账本保证每留痕每动作恰好一次(防重复催办/重复扣分):
 *   T1(逾期 remind_days 天): risk_record 写商家风控档案 + remind_merchant 催办邮件 + escalate_t1 告警运营
 *   T2(逾期 suspend_days 天): escalate_t2 升级告警运营「建议停接单」
 *                            (🔴 实际停接单仍由运营在后台手动一键执行, 留人工复核口子防误伤)
 *
 * 🔴 L1 红线: 零资金操作。不碰保证金、不代退、不向顾客打钱。实际退款永远靠商家原路退。
 * 总开关 business_settings.nezha_refund_overdue_status(默认 0 关)。
 */
class RefundOverdueSweep extends Command
{
    protected $signature = 'nezha:refund-overdue-sweep {--dry-run : 只报告将执行哪些动作, 不实际改动}';

    protected $description = '哪吒: 扫描商家逾期未退款的留痕, 施加非资金约束(记风控/催办商家/告警运营; 停接单由运营手动)';

    private bool $dry = false;

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');

        $status = (int) (BusinessSetting::where('key', 'nezha_refund_overdue_status')->value('value') ?? 0);
        if ($status !== 1) {
            $this->info('逾期未退款兜底总开关关闭(nezha_refund_overdue_status=0), 跳过。');
            return self::SUCCESS;
        }

        // 哪吒[退款专项2026-06-22 小时级]: 催办/停接单阈值改以「小时」为主单位(外卖即时消费,退款要快)。
        // 新键 *_hours 优先; 缺失回退旧「天」键×24兼容(见 NezhaRefundOverdue::thresholdHours)。
        $remindHours  = \App\CentralLogics\NezhaRefundOverdue::thresholdHours('nezha_refund_overdue_remind_hours', 'nezha_refund_overdue_remind_days', 12);
        $suspendHours = \App\CentralLogics\NezhaRefundOverdue::thresholdHours('nezha_refund_overdue_suspend_hours', 'nezha_refund_overdue_suspend_days', 72);
        if ($remindHours < 1) { $remindHours = 1; }
        if ($suspendHours < $remindHours) { $suspendHours = $remindHours; }

        $now    = Carbon::now();
        $cutoff = $now->copy()->subHours($remindHours);

        $records = NezhaRefundRecord::with(['restaurant.vendor', 'order'])
            ->where('status', 'pending_merchant_refund')
            ->whereNull('merchant_refunded_at')
            ->where('created_at', '<=', $cutoff)
            ->get();

        $acted = 0;
        foreach ($records as $rec) {
            $overdueHours = (int) floor($rec->created_at->diffInSeconds($now) / 3600);
            $overdueLabel = \App\CentralLogics\NezhaRefundOverdue::humanizeHours($overdueHours);

            // T1: 风控记录 + 催办商家 + 告警运营
            $acted += $this->fireOnce($rec->id, $rec->restaurant_id, 'risk_record', function () use ($rec, $overdueLabel, $overdueHours) {
                $this->writeRiskRecord($rec, $overdueLabel, $overdueHours);
            }, "逾期{$overdueLabel} 记风控 refund_overdue") ? 1 : 0;

            $acted += $this->fireOnce($rec->id, $rec->restaurant_id, 'remind_merchant', function () use ($rec, $overdueLabel) {
                $this->remindMerchant($rec, $overdueLabel);
            }, "逾期{$overdueLabel} 催办商家") ? 1 : 0;

            $acted += $this->fireOnce($rec->id, $rec->restaurant_id, 'escalate_t1', function () use ($rec, $overdueLabel) {
                $this->escalate($rec, $overdueLabel, false);
            }, "逾期{$overdueLabel} 告警运营") ? 1 : 0;

            // T2: 升级告警「建议停接单」(实际停接单仍由运营在后台手动)
            if ($overdueHours >= $suspendHours) {
                $acted += $this->fireOnce($rec->id, $rec->restaurant_id, 'escalate_t2', function () use ($rec, $overdueLabel) {
                    $this->escalate($rec, $overdueLabel, true);
                }, "逾期{$overdueLabel} 升级告警建议停接单") ? 1 : 0;
            }
        }

        $msg = ($this->dry ? '[DRY-RUN] ' : '') . "逾期未退款兜底完成: 命中留痕 {$records->count()} 条, 本轮触发动作 {$acted} 个。";
        $this->info($msg);
        Log::info('NEZHA_REFUND_OVERDUE_SWEEP: ' . $msg);
        return self::SUCCESS;
    }

    /**
     * 幂等闸(仿 OrderTimeoutSweep): 抢占 (refund_record_id, action) 唯一行, 抢到才执行 $cb。
     * 唯一冲突=已处理过, 跳过。$cb 抛错则回滚(连同抢占行), 下轮重试。
     */
    private function fireOnce(int $recordId, ?int $restaurantId, string $action, callable $cb, string $detail = ''): bool
    {
        if ($this->dry) {
            $exists = DB::table('nezha_refund_overdue_events')->where(['refund_record_id' => $recordId, 'action' => $action])->exists();
            if ($exists) { return false; }
            $this->line("  [DRY] refund#{$recordId} action={$action} :: {$detail}");
            return true;
        }
        try {
            DB::beginTransaction();
            DB::table('nezha_refund_overdue_events')->insert([
                'refund_record_id' => $recordId,
                'restaurant_id'    => $restaurantId,
                'action'           => $action,
                'fired_at'         => now(),
                'detail'           => $detail,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
            $cb();
            DB::commit();
            $this->line("  refund#{$recordId} action={$action} :: {$detail}");
            return true;
        } catch (QueryException $e) {
            DB::rollBack();
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 || stripos($e->getMessage(), 'duplicate') !== false) {
                return false; // 幂等: 已处理过
            }
            Log::error("NEZHA_REFUND_OVERDUE {$action} refund#{$recordId} QueryException: " . $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("NEZHA_REFUND_OVERDUE {$action} refund#{$recordId} failed: " . $e->getMessage());
            return false;
        }
    }

    /** 写一条商家风控记录(rule=refund_overdue, 进商家风控档案/人工审核队列)。零资金。 */
    private function writeRiskRecord(NezhaRefundRecord $rec, string $overdueLabel, int $overdueHours): void
    {
        NezhaRiskRecord::create([
            'order_id'        => $rec->order_id,
            'user_id'         => $rec->user_id,
            'restaurant_id'   => $rec->restaurant_id,
            'payment_channel' => $rec->payment_channel ?: 'other',
            'order_amount'    => (float) $rec->refund_amount,
            'hit_rules'       => [[
                'rule'   => 'refund_overdue',
                'detail' => "退款留痕#{$rec->id} 订单#{$rec->order_id} 逾期 {$overdueLabel} 未原路退款",
            ]],
            'action'          => 'review',
            'status'          => 'pending',
            'snapshot'        => [
                'refund_record_id' => $rec->id,
                'refund_amount'    => (float) $rec->refund_amount,
                'created_at'       => optional($rec->created_at)->toDateTimeString(),
                'overdue_hours'    => $overdueHours,
            ],
            'review_note'     => '系统: 商家逾期未退款(refund_overdue), 待人工跟进',
        ]);
    }

    /** 催办邮件商家(失败抛出 -> 回滚重试)。引导商家原路退 + 去后台「待退款」标记。 */
    private function remindMerchant(NezhaRefundRecord $rec, string $overdueLabel): void
    {
        $r     = $rec->restaurant;
        $email = $r?->email ?? $r?->vendor?->email;
        $name  = $r?->name ?? '商家';
        if (!$email) {
            Log::warning('NEZHA_REFUND_OVERDUE 商家无邮箱, 跳过催办 refund#' . $rec->id);
            return;
        }
        Mail::to($email)->send(new NezhaRefundOverdueMail($name, (int) $rec->order_id, (float) $rec->refund_amount, $overdueLabel));
    }

    /**
     * 告警运营(平台客服信箱)。发信失败吞掉(账本行已占, 不反复重发), 仅记日志。
     * $suggestSuspend=true 时升级为「建议停接单」(运营据此手动处置)。
     */
    private function escalate(NezhaRefundRecord $rec, string $overdueLabel, bool $suggestSuspend): void
    {
        $name = $rec->restaurant?->name ?? ('餐厅#' . $rec->restaurant_id);
        $head = $suggestSuspend
            ? "商家逾期未退款已达 {$overdueLabel}, 建议在后台「逾期未退款」一键停接单。"
            : "商家逾期未退款 {$overdueLabel}, 已记风控并催办商家。";
        $body = $head . "\n\n商家: {$name}\n退款留痕#{$rec->id} 订单#{$rec->order_id} 应退≈{$rec->refund_amount}"
            . "\n请登录后台「风控中心 → 逾期未退款」跟进(平台不代退, 仅约束/催办)。";
        try {
            Log::warning('NEZHA_REFUND_OVERDUE_ESCALATE refund#' . $rec->id . ': ' . $head);
            Mail::raw($body, function ($m) use ($rec) {
                $m->to('support@nezha.am')->subject('哪吒 · 商家逾期未退款 退款留痕#' . $rec->id);
            });
        } catch (\Throwable $e) {
            Log::warning('NEZHA_REFUND_OVERDUE escalate mail failed refund#' . $rec->id . ': ' . $e->getMessage());
        }
    }
}
