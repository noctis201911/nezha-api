<?php

namespace App\CentralLogics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 轻量使用埋点 (方案C)。
 *
 * 全部静态方法, 每个内部 try/catch 兜底——埋点失败绝不影响搜索/购物车主流程(对用户零打扰、零风险)。
 * 只记匿名/聚合信号:
 *  - searchMiss: 「搜了但没结果」的词, 聚合计数, 不存 user_id(天然匿名), 入库前脱敏。
 *  - cartAdd:    加购事件, 供"加购未下单"分析; user_id 为 PII, 由 nezha:purge-analytics 30天清。
 * 这些信号会被「反馈日报(方案A)」自动纳入。
 */
class NezhaUsageLog
{
    public static function searchMiss($keyword, string $type, $zoneId = null): void
    {
        try {
            if (!Schema::hasTable('nezha_search_misses')) {
                return;
            }
            $kw = trim((string) $keyword);
            if ($kw === '' || $kw === 'null' || mb_strlen($kw) > 60) {
                return; // 空/无效/过长跳过
            }
            $kw = self::redact($kw);
            $zid = is_array($zoneId) ? (int) ($zoneId[0] ?? 0) : (int) $zoneId;
            $row = DB::table('nezha_search_misses')
                ->where('keyword', $kw)->where('search_type', $type)->where('zone_id', $zid)->first();
            if ($row) {
                DB::table('nezha_search_misses')->where('id', $row->id)->update([
                    'hit_count' => DB::raw('hit_count + 1'),
                    'last_seen_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('nezha_search_misses')->insert([
                    'keyword' => $kw, 'search_type' => $type, 'zone_id' => $zid,
                    'hit_count' => 1, 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
        }
    }

    public static function cartAdd($itemId, $restaurantId, $userId, $isGuest): void
    {
        try {
            if (!Schema::hasTable('nezha_cart_events')) {
                return;
            }
            DB::table('nezha_cart_events')->insert([
                'item_id' => $itemId ? (int) $itemId : null,
                'restaurant_id' => $restaurantId ? (int) $restaurantId : null,
                'user_id' => $userId ? (int) $userId : null,
                'is_guest' => $isGuest ? 1 : 0,
                'converted' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    private static function redact(string $s): string
    {
        $s = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[邮箱]', $s);
        $s = preg_replace('/\+?\d[\d().\-]{6,}\d/u', '[电话]', $s);
        return $s;
    }
}
