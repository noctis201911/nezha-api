<?php

namespace App\Console\Commands;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaAutoOffline;
use App\Models\Restaurant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * 哪吒 B方案 — 商家「长期不确认订单 → 自动暂停接单」兜底扫描(每分钟)。
 *
 * 触发(业主 2026-07-11 拍板): 滚动窗口 H 小时内, 某商家因【商家责任】超时被系统自动取消
 *   (nezha_order_timeout_events.action=cancel_paid_refund) 达 N 单, 且窗口内【一单都没成功处理】
 *   (行为在场判定: 无 accepted/processing/handover/picked_up/delivered 时间戳落在窗口内 = 商家不在场)
 *   → 自动下线(停止接收新单) + 通知商家(作业台红条/邮件/TG) + 升级运营/业主。
 *   若窗口内有成功处理过单(在场但偶发慢) → 不下线, 仅升级一次「濒临下线」预警(每店每窗口节流)。
 *
 * 恢复: 🔴 无冷却自动恢复。只能商家自助一键 / 运营后台显式恢复(见 NezhaAutoOffline::recover)。sweep 绝不自动解挂。
 *
 * 🔴 L1: 零资金。不取消存量单(单笔超时取消归 OrderTimeoutSweep)、不碰钱。只置/读「与钱无关」接单挂起标记。
 * 总闸 nezha_autooffline_status(默认 0 关)。strike 只数 cancel_paid_refund(排除 cancel_unpaid=顾客没付)、排除预约单(scheduled=1)。
 */
class NezhaMerchantAutoOfflineSweep extends Command
{
    protected $signature = 'nezha:merchant-autooffline-sweep {--dry-run : 只报告将执行哪些动作, 不实际改动}';

    protected $description = '哪吒: 扫描长期不确认订单的商家, 达阈值且不在场则自动停接单(商家自助/运营恢复, 无自动恢复)';

    private bool $dry = false;

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');

        if (!NezhaAutoOffline::enabled()) {
            $this->info('自动下线总闸关闭(nezha_autooffline_status=0), 跳过。');
            return self::SUCCESS;
        }

        $n      = NezhaAutoOffline::strikeCount();
        $hours  = NezhaAutoOffline::windowHours();
        $now    = Carbon::now();
        $cutoff = $now->copy()->subHours($hours)->toDateTimeString();

        // 商家责任超时取消的「strike」= nezha_order_timeout_events.action=cancel_paid_refund(排除 cancel_unpaid=顾客没付)。
        // 账本无 restaurant_id → JOIN orders 取店; 排除预约单(scheduled=1)。按店计 DISTINCT 订单数 >= N。(查询体见 NezhaAutoOffline)
        $rows = NezhaAutoOffline::strikingRestaurants($cutoff, $n);

        $acted = 0;
        foreach ($rows as $row) {
            $rid     = (int) $row->restaurant_id;
            $strikes = (int) $row->strikes;

            $restaurant = Restaurant::find($rid);
            if (!$restaurant) {
                continue;
            }
            // 已被自动下线 → 幂等跳过(状态而非重复动作)。
            if (NezhaAutoOffline::is_offline($restaurant)) {
                continue;
            }

            // 行为在场判定: 窗口内有没有成功推进过任何一单(商家在忙但偶发慢, 不误伤)。
            // 超时被取消的单只会盖 canceled(不会盖 accepted/processing/... ), 故不会被误算成「已处理」。
            $handled = NezhaAutoOffline::handledInWindow($rid, $cutoff);

            if ($handled) {
                // 在场但偶发慢 → 不下线, 仅升级一次「濒临下线」预警(每店每窗口节流, 避免每分钟刷屏)。
                $this->warnNearOffline($restaurant, $strikes, $hours);
                continue;
            }

            // 不在场 + 达阈值 → 自动下线。
            $reason = "近{$hours}小时{$strikes}单超时未处理被自动取消, 且期间无成功接单(疑失联)";
            if ($this->dry) {
                $this->line("  [DRY] restaurant#{$rid} auto_offline :: {$reason}");
                $acted++;
                continue;
            }
            try {
                NezhaAutoOffline::offline($rid, $reason);
                DB::table('nezha_auto_offline_events')->insert([
                    'restaurant_id' => $rid,
                    'action'        => 'auto_offline',
                    'detail'        => $reason,
                    'fired_at'      => now(),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
                $this->notifyMerchant($restaurant, $strikes, $hours);
                $this->escalate($restaurant, $strikes, $hours);
                $this->line("  restaurant#{$rid} auto_offline :: {$reason}");
                $acted++;
            } catch (\Throwable $ex) {
                Log::error('NEZHA_AUTOOFFLINE offline failed restaurant#' . $rid . ': ' . $ex->getMessage());
            }
        }

        $msg = ($this->dry ? '[DRY-RUN] ' : '') . "自动下线兜底完成: 命中候选 {$rows->count()} 店, 本轮下线 {$acted} 店。";
        $this->info($msg);
        Log::info('NEZHA_AUTOOFFLINE_SWEEP: ' . $msg);
        return self::SUCCESS;
    }

    /** 通知商家本人: 邮件(有则发) + TG(绑了才有)。作业台红条由前端读标记渲染(最可靠·在场即见)。best-effort。 */
    private function notifyMerchant(Restaurant $restaurant, int $strikes, int $hours): void
    {
        $name = $restaurant->name ?? '商家';
        $text = "⛔ 哪吒通知｜你的店「{$name}」近{$hours}小时有{$strikes}单因超时未处理被系统取消, 且期间没有成功接单, 已【暂停接单】以免继续影响顾客。\n"
              . "你在岗后, 请登录作业台点「恢复接单」即可继续营业。";
        try {
            $email = $restaurant->nezha_notify_email ?: ($restaurant->email ?? $restaurant->vendor?->email);
            if ($email) {
                Mail::raw($text, function ($m) use ($email) {
                    $m->to($email)->subject('哪吒 · 你的店已被暂停接单(可一键恢复)');
                });
            }
        } catch (\Throwable $e) {
            Log::info('NEZHA_AUTOOFFLINE notifyMerchant mail failed: ' . $e->getMessage());
        }
        try {
            if ($restaurant->telegram_chat_id ?? null) {
                Helpers::sendTelegramToRestaurant($restaurant, $text);
            }
        } catch (\Throwable $e) {
            Log::info('NEZHA_AUTOOFFLINE notifyMerchant tg failed: ' . $e->getMessage());
        }
    }

    /** 升级运营/业主: 管理员 TG(已绑=可靠) + 客服邮箱留痕。best-effort。禁顾客 PII, 只发商家经营联系方式。 */
    private function escalate(Restaurant $restaurant, int $strikes, int $hours): void
    {
        $name  = $restaurant->name ?? ('餐厅#' . $restaurant->id);
        $phone = $restaurant->phone ?: ($restaurant->vendor?->phone ?: '未登记');
        $text  = "🚨 哪吒升级｜商家「{$name}」(#{$restaurant->id}) 近{$hours}小时{$strikes}单超时未处理且无成功接单, 已【自动停接单】。\n"
               . "商家电话: {$phone}。商家可自助恢复, 运营也可在「风控中心 → 自动下线商家」恢复。";
        try {
            Helpers::sendTelegramToAdmin($text);
        } catch (\Throwable $e) {
            Log::info('NEZHA_AUTOOFFLINE escalate tg failed: ' . $e->getMessage());
        }
        try {
            Mail::raw($text, function ($m) use ($restaurant) {
                $m->to('support@nezha.am')->subject('哪吒 · 商家自动停接单 #' . $restaurant->id);
            });
        } catch (\Throwable $e) {
            Log::info('NEZHA_AUTOOFFLINE escalate mail failed: ' . $e->getMessage());
        }
    }

    /** 在场但达阈值 → 每店每窗口节流升级一次「濒临下线」预警给运营/业主(不下线)。best-effort。 */
    private function warnNearOffline(Restaurant $restaurant, int $strikes, int $hours): void
    {
        if ($this->dry) {
            $this->line("  [DRY] restaurant#{$restaurant->id} warn_near_offline :: 在场但 {$strikes} 单超时(不下线)");
            return;
        }
        // 节流: 每店每 (窗口) 小时最多一条, 避免每分钟刷屏。
        if (!Cache::add('nezha_autooffline_warn_' . $restaurant->id, 1, now()->addHours(max(1, $hours)))) {
            return;
        }
        $name = $restaurant->name ?? ('餐厅#' . $restaurant->id);
        $text = "⚠️ 哪吒预警｜商家「{$name}」(#{$restaurant->id}) 近{$hours}小时已有{$strikes}单超时被取消(但仍在处理其它单, 暂不自动下线)。建议关注是否人手不足。";
        try {
            Helpers::sendTelegramToAdmin($text);
        } catch (\Throwable $e) {
            Log::info('NEZHA_AUTOOFFLINE warn tg failed: ' . $e->getMessage());
        }
        try {
            DB::table('nezha_auto_offline_events')->insert([
                'restaurant_id' => $restaurant->id,
                'action'        => 'escalate_warn',
                'detail'        => "在场但近{$hours}小时{$strikes}单超时(不下线)",
                'fired_at'      => now(),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            Log::info('NEZHA_AUTOOFFLINE warn event log failed: ' . $e->getMessage());
        }
    }
}
