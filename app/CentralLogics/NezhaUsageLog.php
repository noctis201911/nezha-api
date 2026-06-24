<?php

namespace App\CentralLogics;

use Illuminate\Support\Facades\Cache;
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
 *
 * redactPii() 是全项目"喂内部分析/第三方AI前抹PII"的单一口径(NezhaFeedbackDigest 也复用本方法, 防两处正则漂移)。
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
            $kw = self::redactPii($kw);
            $zid = is_array($zoneId) ? (int) ($zoneId[0] ?? 0) : (int) $zoneId;
            // 校验 zone 真实性(封死"zone_id 任意枚举"放大表行的攻击面); 非法 zone 归 0。短TTL缓存避免每次查库。
            if ($zid > 0 && Schema::hasTable('zones')) {
                $valid = Cache::remember('nz_zone_exists_' . $zid, 600, function () use ($zid) {
                    return DB::table('zones')->where('id', $zid)->exists();
                });
                if (!$valid) {
                    $zid = 0;
                }
            }
            // 原子 upsert(依赖 (keyword,search_type,zone_id) 唯一索引): 消除"先查后插"竞态 + 同词去重, 防刷爆。
            $now = now()->format('Y-m-d H:i:s');
            DB::statement(
                'INSERT INTO nezha_search_misses (keyword, search_type, zone_id, hit_count, last_seen_at, created_at, updated_at) '
                . 'VALUES (?, ?, ?, 1, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_seen_at = VALUES(last_seen_at), updated_at = VALUES(updated_at)',
                [$kw, $type, $zid, $now, $now, $now]
            );
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

    /**
     * 抹 PII(喂内部分析/第三方AI前). 覆盖: 邮箱 / TRON+EVM 钱包地址 / 电话 / 银行卡。
     * 电话与卡号先压缩"数字间的空格·分隔符"再统一抹 ≥7 位数字串, 以覆盖埃里温常见的分段写法
     * (如 +374 99 12 34 56)和带空格的卡号(如 4111 1111 1111 1111)。
     * 注: 姓名/地址这类自由文本无法用正则可靠识别, 属已知残留(调用方应避免把它们当"已完全匿名")。
     */
    public static function redactPii(string $s): string
    {
        // 先抹结构化标识(在数字压缩之前, 防被部分吃掉)
        $s = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[邮箱]', $s);
        $s = preg_replace('/\bT[1-9A-HJ-NP-Za-km-z]{33}\b/', '[钱包地址]', $s);
        $s = preg_replace('/\b0x[a-fA-F0-9]{40}\b/', '[钱包地址]', $s);
        // 压缩"数字之间"的空格/分隔符, 再抹连续 ≥7 位数字(覆盖带空格电话/卡号)。只动数字之间, 不误连普通文字。
        $s = preg_replace('/(?<=\d)[ \t.\-()]+(?=\d)/u', '', $s);
        $s = preg_replace('/\+?\d{7,}/u', '[电话]', $s);
        return $s;
    }
}
