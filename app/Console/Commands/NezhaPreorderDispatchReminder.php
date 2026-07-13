<?php

namespace App\Console\Commands;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaPreorder;
use App\Models\Order;
use App\Models\Restaurant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒 预约配送 v2 单点 · 阶段③ ④叫车推送(每分钟·全 dormant)。
 *
 * 到每单「建议叫车时间」(= 预计送达点 − dispatch_lead)给商家推「该叫车了：N 单待发」摘要一条。
 * 防轰炸三件套(07 §一.3 硬规格):
 *   摘要一条 = 把该商家所有「该叫车」单合并成一条(不逐单轰炸)。
 *   在场抑制 = 商家正开着作业台(6s 轮询 heartbeat=isViewingWorkbench)→ 不推, 只靠作业台在场横幅(与推送同套数)。
 *   冷却     = 一个叫车周期最多一条, 不随单量涨; 只有出现「新的到点单」(due 集合有新 id)才可能再推。
 *
 * 🔴 L2/L3 展示层: 只读订单 + 发 TG, 绝不写订单状态 / 不碰钱 / 不碰 L1。
 * 三门(业主 0712): ①命令级双闸 总闸 nezha_preorder_status + 平台 killswitch nezha_preorder_dispatch_remind_push(任一关 → 整命令 no-op·dormant 零查询零发送) ②店级 restaurants.nezha_preorder_dispatch_remind(每商家自选·关则本店跳过)。
 * 通道: 复用商家 TG(Helpers::sendTelegramToRestaurant·绑定 telegram_chat_id 才有·尊重每店 timeout_notify_telegram)。
 */
class NezhaPreorderDispatchReminder extends Command
{
    protected $signature = 'nezha:preorder-dispatch-remind {--dry-run : 只报告将推送哪些, 不实际发送}';

    protected $description = '哪吒: 预约单到「建议叫车时间」给商家推摘要提醒(摘要一条+在场抑制+冷却·全 dormant)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // 命令级双闸: 总闸 + 平台 killswitch。任一关 → no-op(dormant)。店级每商家开关在下面循环内逐店过滤。
        if (!NezhaPreorder::enabled() || !NezhaPreorder::dispatchRemindPush()) {
            $this->info('预约总闸或叫车提醒开关关闭, 跳过。');
            return self::SUCCESS;
        }

        $now  = Carbon::now();
        $lead = NezhaPreorder::dispatchLeadMin();
        // 「该叫车」= scheduled 未派出单(confirmed/accepted/processing/handover) 且 建议叫车时间已到:
        //   now ≥ schedule_at − lead  ⇔  schedule_at ≤ now + lead。下界 now−1天 防历史脏单(过期未派出)拉进来。
        $orders = Order::where('scheduled', 1)
            ->whereNotNull('schedule_at')
            ->whereIn('order_status', ['confirmed', 'accepted', 'processing', 'handover'])
            ->where('schedule_at', '<=', $now->copy()->addMinutes($lead)->toDateTimeString())
            ->where('schedule_at', '>=', $now->copy()->subDay()->toDateTimeString())
            ->orderBy('schedule_at', 'asc')
            ->get(['id', 'restaurant_id', 'schedule_at']);

        $byRid = [];
        foreach ($orders as $o) {
            $byRid[(int) $o->restaurant_id][] = $o;
        }

        $pushed = 0;
        foreach ($byRid as $rid => $dueOrders) {
            // 在场抑制: 商家正开着作业台 → 不推(作业台在场横幅已覆盖·同套数)。
            if (NezhaPreorder::isViewingWorkbench($rid)) {
                continue;
            }

            $restaurant = Restaurant::find($rid);
            if (!$restaurant) {
                continue;
            }
            // 本店叫车提醒开关(每商家自选·业主 0712)。关 = 本店不推(作业台在场横幅仍覆盖)。缺列→ ?? 1 回落开。
            if (!(int) ($restaurant->nezha_preorder_dispatch_remind ?? 1)) {
                continue;
            }
            // 尊重每店 TG 通道开关(商家把 TG 关了就别推)+ 须已绑 chat(未绑 = 无投递地址)。
            if (!(int) ($restaurant->timeout_notify_telegram ?? 1) || !($restaurant->telegram_chat_id ?? null)) {
                continue;
            }

            // 冷却/去重: 只有出现「新的到点单」才推。prev = 上轮已通知的 due id 集合(12h TTL 自过期)。
            $dueIds = array_map(fn ($o) => (int) $o->id, $dueOrders);
            sort($dueIds);
            $prevKey = 'nezha_po_remind_' . $rid;
            $prev    = Cache::get($prevKey, []);
            $newIds  = array_values(array_diff($dueIds, is_array($prev) ? $prev : []));
            if (empty($newIds)) {
                continue;   // 无新到点单 → 不重复推(冷却: 一个叫车周期最多一条)
            }

            // 摘要一条: 合并该商家所有「该叫车」单。最早送达点 + 其建议叫车时间(= 点 − lead)——与 05b 在场横幅同套数。
            $count           = count($dueOrders);
            $earliest        = Carbon::parse((string) $dueOrders[0]->schedule_at);   // 已按 schedule_at 升序
            $earliestPoint   = $earliest->format('H:i');
            $earliestSuggest = $earliest->copy()->subMinutes($lead)->format('H:i');
            $text = "该叫车了：{$count} 单待发\n最早 {$earliestPoint} 送达（建议 {$earliestSuggest} 叫车）· 去作业台处理";

            if ($dry) {
                $this->line("  [DRY] restaurant#{$rid} :: 该叫车了 {$count} 单 · 最早 {$earliestPoint}(建议 {$earliestSuggest}) new=" . implode(',', $newIds));
                $pushed++;
                continue;
            }
            try {
                Helpers::sendTelegramToRestaurant($restaurant, $text);
                Cache::put($prevKey, $dueIds, $now->copy()->addHours(12));   // 记住本轮已推集合(冷却)
                $this->line("  restaurant#{$rid} :: 该叫车了 {$count} 单");
                $pushed++;
            } catch (\Throwable $e) {
                Log::info('NEZHA_PREORDER_REMIND push failed restaurant#' . $rid . ': ' . $e->getMessage());
            }
        }

        $msg = ($dry ? '[DRY-RUN] ' : '') . '预约叫车提醒完成: 命中 ' . count($byRid) . ' 店, 推送 ' . $pushed . ' 店。';
        $this->info($msg);
        Log::info('NEZHA_PREORDER_REMIND_SWEEP: ' . $msg);
        return self::SUCCESS;
    }
}
