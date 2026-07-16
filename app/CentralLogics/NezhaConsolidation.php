<?php

namespace App\CentralLogics;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 平台集运申报 — 触达补强(阶段 A)共享逻辑收口。
 * 把"数据保鲜"90 天阈值 + 商家 dashboard 提示卡显隐 + 关闭落库判定收敛到一处,
 * 供 vendor dashboard / vendor 问卷页 / admin 汇总页三处复用(避免阈值散落漂移)。
 * 纯读判定 + 提示卡关闭状态落库, 平台不碰钱, 与 L1 红线无关。
 */
class NezhaConsolidation
{
    /** 需求数据保鲜阈值(天): 超过即视为陈旧。问卷"近 3 个月"是滚动语义, 旧数据会失真, 故到期提示确认或更新。 */
    public const STALE_DAYS = 90;

    /** 记录是否已陈旧(updated_at 距今超过 STALE_DAYS 天)。空/不可解析一律视为不陈旧(交给"未提交"分支)。 */
    public static function isStale($updatedAt): bool
    {
        if (empty($updatedAt)) {
            return false;
        }
        try {
            return Carbon::parse($updatedAt)->lt(Carbon::now()->subDays(self::STALE_DAYS));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 商家 dashboard 提示卡是否应显示。
     * 显示条件: 该 vendor 无 survey 记录, 或记录 updated_at 超 STALE_DAYS 天; 且未在"当前这一轮陈旧"内关闭过。
     * 关闭语义: 关闭后不再弹; 若记录后续再次变陈旧(跨新的一轮 90 天)可再出现一次。
     */
    public static function shouldShowDashboardPromo($vendorId): bool
    {
        if (!$vendorId) {
            return false;
        }
        $survey = DB::table('nezha_consolidation_surveys')->where('vendor_id', $vendorId)
            ->select('updated_at')->first();

        $needsPrompt = !$survey || self::isStale($survey->updated_at);
        if (!$needsPrompt) {
            return false;
        }

        $dismissedAt = self::promoDismissedAt($vendorId);
        if ($dismissedAt === null) {
            return true; // 从未关闭 → 显示
        }

        // 已关闭过: 无 survey 记录时, 关闭即永久(商家主动选择"暂不需要", 不再打扰);
        // 有 survey 记录时, 仅当"本轮陈旧起点(updated_at + 90 天)"晚于上次关闭时刻才再显示一次(新一轮)。
        if (!$survey) {
            return false;
        }
        try {
            $staleSince = Carbon::parse($survey->updated_at)->addDays(self::STALE_DAYS);
            return Carbon::parse($dismissedAt)->lt($staleSince);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 读该 vendor 的提示卡关闭时刻(null = 从未关闭)。表未建时安全返回 null(dormant/部署窗口容错)。 */
    public static function promoDismissedAt($vendorId)
    {
        if (!$vendorId || !Schema::hasTable('nezha_consolidation_promos')) {
            return null;
        }
        $row = DB::table('nezha_consolidation_promos')->where('vendor_id', $vendorId)
            ->select('dismissed_at')->first();
        return $row->dismissed_at ?? null;
    }

    /** 记录该 vendor 关闭了提示卡(upsert dismissed_at = now)。表未建时静默跳过。 */
    public static function dismissPromo($vendorId): void
    {
        if (!$vendorId || !Schema::hasTable('nezha_consolidation_promos')) {
            return;
        }
        $now = Carbon::now();
        $exists = DB::table('nezha_consolidation_promos')->where('vendor_id', $vendorId)->exists();
        if ($exists) {
            DB::table('nezha_consolidation_promos')->where('vendor_id', $vendorId)
                ->update(['dismissed_at' => $now, 'updated_at' => $now]);
        } else {
            DB::table('nezha_consolidation_promos')->insert([
                'vendor_id'    => $vendorId,
                'dismissed_at' => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }
}
