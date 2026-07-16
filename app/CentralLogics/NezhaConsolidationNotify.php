<?php

namespace App\CentralLogics;

use App\Models\Restaurant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒 平台集运 · 期次通知(包3 · B通知)。
 * 开期/取消经【既有】商家 TG 管道(Helpers::sendTelegramToRestaurant·未绑 TG=no-op·异步/兜底同步)广播,
 * 不新建任何推送渠道/红点/未读机制(沿用同管道同模式)。开期通知幂等靠 rounds.notified_at(重复开放不重发)。
 * 平台不碰钱, 与 L1 红线无关。
 */
class NezhaConsolidationNotify
{
    /**
     * 开期通知: draft→open 时向全体活跃商家发一次。
     * 幂等: rounds.notified_at 已设则跳过(重复开放/重放不重发)。返回尝试发送的商家数(未绑 TG 者管道内 no-op)。
     */
    public static function roundOpened($roundId): int
    {
        try {
            $round = DB::table('nezha_consolidation_rounds')->where('id', $roundId)->first();
            if (!$round || $round->status !== 'open' || !empty($round->notified_at)) {
                return 0;
            }
            $text = self::openedText($round);
            $sent = self::broadcastActive($text);
            DB::table('nezha_consolidation_rounds')->where('id', $roundId)->update(['notified_at' => Carbon::now()]);
            return $sent;
        } catch (\Throwable $e) {
            Log::warning('nezha consolidation roundOpened notify failed #' . $roundId . ': ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 取消通知: 已开放期次被取消时, 向【已报名(enrolled)】商家发取消通知(仅触达受影响者, 不广播全体)。
     */
    public static function roundCanceled($roundId): int
    {
        try {
            $round = DB::table('nezha_consolidation_rounds')->where('id', $roundId)->first();
            if (!$round) {
                return 0;
            }
            $rids = DB::table('nezha_consolidation_enrollments')
                ->where('round_id', $roundId)->where('status', 'enrolled')
                ->pluck('restaurant_id')->filter()->unique()->values()->all();
            if (!$rids) {
                return 0;
            }
            $text = self::canceledText($round);
            $sent = 0;
            foreach (Restaurant::whereIn('id', $rids)->get() as $r) {
                Helpers::sendTelegramToRestaurant($r, $text);
                $sent++;
            }
            return $sent;
        } catch (\Throwable $e) {
            Log::warning('nezha consolidation roundCanceled notify failed #' . $roundId . ': ' . $e->getMessage());
            return 0;
        }
    }

    /** 向全体活跃商家(status=1)广播(分块避免大集合内存)。 */
    private static function broadcastActive(string $text): int
    {
        $sent = 0;
        Restaurant::where('status', 1)->select('id', 'telegram_chat_id')->chunk(200, function ($chunk) use ($text, &$sent) {
            foreach ($chunk as $r) {
                Helpers::sendTelegramToRestaurant($r, $text);
                $sent++;
            }
        });
        return $sent;
    }

    /** 开期文案(逐字·{round_no}/{cutoff 月日}变量替换)。 */
    private static function openedText($round): string
    {
        $cutoff = !empty($round->cutoff_at) ? Carbon::parse($round->cutoff_at)->format('n月j日') : '另行通知';
        return '【集运】第 ' . $round->round_no . ' 期拼柜已开放报名：' . $cutoff . ' 截止收货，路线与报价请在商家后台「平台集运」查看。';
    }

    /** 取消文案(逐字)。 */
    private static function canceledText($round): string
    {
        return '【集运】第 ' . $round->round_no . ' 期拼柜已取消，给您带来不便请谅解。';
    }
}
