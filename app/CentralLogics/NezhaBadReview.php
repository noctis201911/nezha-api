<?php

namespace App\CentralLogics;

use App\Models\Review;
use Carbon\Carbon;

/**
 * 哪吒[差评预警] 单一真相源。
 * 「最近 N 天 rating<=N 且未回复」的差评数, 供三处同源消费, 杜绝"看板卡数 vs 侧栏角标数 vs 深链列表数"三套口径打架:
 *   ① 商家首屏「差评预警」卡  (Vendor\DashboardController::nezha_today_summary)
 *   ② 侧栏「评价」菜单红色角标 (layouts.vendor.partials._sidebar)
 *   ③ 卡/角标点击后的评价页深链 (ReviewController@index 用 rating_max + reply_status=no_reply + 同一日期窗过滤)
 * 作用域刻意与 ReviewController@index 完全一致(whereHas food.restaurant_id + reply 空 + rating<=MAX + 同一日期窗),
 * 保证「角标/卡上的数字」== 「点进去评价列表条数」(反 drift 铁律)。纯 L3 只读聚合。
 */
class NezhaBadReview
{
    /** 预警窗口(含今天共 N 个自然日) */
    const WINDOW_DAYS = 7;
    /** 低分上限: rating <= MAX_RATING 视为差评 */
    const MAX_RATING = 3;

    /** 返回 [from(Y-m-d), to(Y-m-d)] —— 与深链 start_date/end_date 及 ReviewController 日期窗对齐 */
    public static function window(): array
    {
        $to   = Carbon::today();
        $from = Carbon::today()->subDays(self::WINDOW_DAYS - 1);
        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    /**
     * 最近 N 天 rating<=MAX 且未回复的差评数。单条 COUNT(*)+EXISTS 子查询, 无 N+1。
     * $rid 为空(未定位到餐厅)时返回 0, 不报错。
     */
    public static function count($rid): int
    {
        if (!$rid) {
            return 0;
        }
        [$from, $to] = self::window();
        return Review::whereHas('food', function ($q) use ($rid) {
                $q->where('restaurant_id', $rid);
            })
            ->where('rating', '<=', self::MAX_RATING)
            ->where(function ($q) {
                $q->whereNull('reply')->orWhere('reply', '=', '');
            })
            ->whereBetween('created_at', [
                Carbon::createFromFormat('Y-m-d', $from)->startOfDay(),
                Carbon::createFromFormat('Y-m-d', $to)->endOfDay(),
            ])
            ->count();
    }

    /** 首屏卡用: 计数 + 窗口天数 + 起止日期(供深链拼 start_date/end_date) */
    public static function summary($rid): array
    {
        [$from, $to] = self::window();
        return [
            'bad_review_count' => self::count($rid),
            'bad_review_days'  => self::WINDOW_DAYS,
            'bad_review_from'  => $from,
            'bad_review_to'    => $to,
        ];
    }

    /**
     * 平台版(超管驾驶舱 M2-D4 用): 全平台最近 N 天 rating<=MAX 未回复差评总数 + 涉及商家数。
     * 口径与 count()/summary() 逐字一致(rating<=MAX + reply 空 + 同一日期窗), 仅去掉 restaurant_id 过滤
     * = 单操作员平台的"平台版"(与 NezhaAdminCounts 之于 NezhaOrderCounts 同理), 非另造口径。纯只读。
     */
    public static function platformSummary(): array
    {
        [$from, $to] = self::window();
        try {
            $base = Review::whereHas('food')
                ->where('rating', '<=', self::MAX_RATING)
                ->where(function ($q) {
                    $q->whereNull('reply')->orWhere('reply', '=', '');
                })
                ->whereBetween('created_at', [
                    Carbon::createFromFormat('Y-m-d', $from)->startOfDay(),
                    Carbon::createFromFormat('Y-m-d', $to)->endOfDay(),
                ]);
            $count = (clone $base)->count();
            $restaurants = (clone $base)->with('food:id,restaurant_id')->get()
                ->pluck('food.restaurant_id')->filter()->unique()->count();
            return ['count' => (int) $count, 'restaurants' => (int) $restaurants, 'days' => self::WINDOW_DAYS, 'from' => $from, 'to' => $to];
        } catch (\Throwable $e) {
            return ['count' => 0, 'restaurants' => 0, 'days' => self::WINDOW_DAYS, 'from' => $from, 'to' => $to];
        }
    }

    /**
     * 平台版: 未回复差评最多的前 N 家商家 [['id','name','count'], ...](驾驶舱「商家健康」卡行用)。
     * 同 platformSummary 口径; 按未回复差评条数降序。空/异常返回 []。纯只读。
     */
    public static function platformShops(int $limit = 5): array
    {
        [$from, $to] = self::window();
        try {
            $reviews = Review::whereHas('food')
                ->where('rating', '<=', self::MAX_RATING)
                ->where(function ($q) {
                    $q->whereNull('reply')->orWhere('reply', '=', '');
                })
                ->whereBetween('created_at', [
                    Carbon::createFromFormat('Y-m-d', $from)->startOfDay(),
                    Carbon::createFromFormat('Y-m-d', $to)->endOfDay(),
                ])
                ->with('food:id,restaurant_id')->get();
            $byRid = [];
            foreach ($reviews as $rv) {
                $rid = optional($rv->food)->restaurant_id;
                if (! $rid) {
                    continue;
                }
                $byRid[$rid] = ($byRid[$rid] ?? 0) + 1;
            }
            arsort($byRid);
            $top = array_slice($byRid, 0, $limit, true);
            if (! $top) {
                return [];
            }
            $names = \App\Models\Restaurant::whereIn('id', array_keys($top))->pluck('name', 'id');
            $out = [];
            foreach ($top as $rid => $cnt) {
                $out[] = ['id' => (int) $rid, 'name' => $names[$rid] ?? ('商家 #' . $rid), 'count' => (int) $cnt];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
