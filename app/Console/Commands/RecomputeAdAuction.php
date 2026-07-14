<?php

namespace App\Console\Commands;

use App\Models\Advertisement;
use App\Models\BusinessSetting;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒商家广告「实时竞价」v1 — T2 近实时物化竞价.
 *
 * 每 nezha_ad_recompute_min 分钟跑一次(bootstrap/app.php withSchedule, 不是 Kernel).
 * 目的: 把「当前各广告位赢家 + 排名 + 综合分加成」物化进 advertisements.mat_rank / mat_boost / mat_at,
 *       让顾客端排序/广告位读物化值(O(1)), 不在每个请求里实时跑竞价 → 保住首页/列表缓存、不抬 P99.
 *
 * 排序: eCPM = bid_amount × quality_score(只用难刷信号), 首价语义(谁出价高谁靠前; 实扣在 click 端点现算).
 * 质量分(quality_score, 映射到 [0.5,1.5], 难刷):
 *   - 完单率   conv  = delivered / (delivered + canceled)        近 N 天, 商家刷不出(要真实顾客完成)
 *   - 好评率   good  = 1 - 差评率(rating<=2 / 总 approved 评价)  近 N 天, 难刷(要真实评价)
 *   - 出餐速度 speed = 映射(confirmed→processing 实际备餐时长)   近 N 天, 难刷(真实时间戳)
 *   raw = 0.4*conv + 0.4*good + 0.2*speed ∈ [0,1]; quality = 0.5 + raw ∈ [0.5,1.5]; 无数据中性=1.0.
 *   ❌ 不用裸点击率(可刷)、不用自点击。
 *
 * 合格广告(进入排名): pricing_model='cpc' AND status='approved' AND 在投放期内 AND bid>=floor
 *   AND 商家 ad_balance>0(余额 0 不上架, INV-1 隔离) AND 未耗尽日预算(spent_today<daily_budget).
 * max_share_per_store: 同 slot 同店最多占 N 位(防垄断, 硬约束; 超额者 mat_rank=NULL 不上架).
 * mat_boost(综合分加成, 给 RestaurantLogic 列表排序): boost_cap × 排名衰减.
 *   boost_cap = nezha_ad_boost_weight(复用现有量级 0.5). rank1=cap, rank2=cap/2, rank3+=cap/4.
 *   「前 N 自然位保留」第一期由 boost_cap 软实现: 加成上限不足以碾压综合分领先 >cap 的自然好店
 *   (列表综合分含 per-顾客距离, 无法全局物化硬保留; 硬保留留第二期纯广告位). 死亡测试验「非广告店不被误伤」.
 *
 * 开关 nezha_ad_auction_status: 0(默认关) → 清空所有 cpc 广告 mat_*(无残留物化) 并返回, 排序退化到现行 CPT.
 *
 * 用法: php artisan nezha:recompute-ad-auction [--dry-run]
 */
class RecomputeAdAuction extends Command
{
    protected $signature = 'nezha:recompute-ad-auction {--dry-run : 只预览不写库}';
    protected $description = '广告竞价: 近实时物化各广告位赢家/排名/综合分加成(eCPM=出价×质量分,首价)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $auctionOn = (int) (BusinessSetting::where('key', 'nezha_ad_auction_status')->first()?->value ?? 0);
        if ($auctionOn !== 1) {
            // 关: 清空所有 cpc 广告的物化字段, 保证关时无残留物化(排序退化到现行 CPT EXISTS 路径)
            $cleared = 0;
            if (!$dry) {
                $cleared = Advertisement::where('pricing_model', 'cpc')
                    ->where(function ($q) {
                        $q->whereNotNull('mat_rank')->orWhere('mat_boost', '>', 0);
                    })
                    ->update(['mat_rank' => null, 'mat_boost' => 0, 'mat_at' => now()]);
            }
            $this->info('竞价总开关未开 (nezha_ad_auction_status != 1), 已清空物化'.($dry ? '(dry)' : '').": {$cleared} 条。");
            return self::SUCCESS;
        }

        $floor     = (float) (BusinessSetting::where('key', 'nezha_ad_floor_price')->first()?->value ?? 0);
        $boostCap  = (float) (BusinessSetting::where('key', 'nezha_ad_boost_weight')->first()?->value ?? 0.5);
        $boostCap  = max(0, min($boostCap, 1.0));
        $maxShare  = (int) (BusinessSetting::where('key', 'nezha_ad_max_share_per_store')->first()?->value ?? 3);
        $maxShare  = max(1, $maxShare);

        $today = Carbon::now('Asia/Yerevan')->toDateString();

        // 合格广告(含 restaurant 关系拿 vendor_id 查 ad_balance)
        $ads = Advertisement::query()
            ->where('pricing_model', 'cpc')
            ->where('status', 'approved')
            ->whereNotNull('bid_amount')
            ->where('bid_amount', '>=', $floor)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->with('restaurant:id,vendor_id')
            ->get();

        if ($ads->isEmpty()) {
            // 无合格广告: 清空所有 cpc 物化(防上一轮残留)
            if (!$dry) {
                Advertisement::where('pricing_model', 'cpc')
                    ->where(function ($q) {
                        $q->whereNotNull('mat_rank')->orWhere('mat_boost', '>', 0);
                    })
                    ->update(['mat_rank' => null, 'mat_boost' => 0, 'mat_at' => now()]);
            }
            $this->info('无合格 cpc 广告, 已清空物化。');
            return self::SUCCESS;
        }

        // 质量分: 一次聚合所有涉及餐馆的难刷信号
        $restaurantIds = $ads->pluck('restaurant_id')->filter()->unique()->values()->all();
        $quality = $this->computeQualityScores($restaurantIds);

        // ad_balance(按 vendor) 一次取出
        $vendorIds = $ads->pluck('restaurant.vendor_id')->filter()->unique()->values()->all();
        $adBalances = DB::table('restaurant_wallets')
            ->whereIn('vendor_id', $vendorIds)
            ->pluck('ad_balance', 'vendor_id')
            ->map(fn ($v) => (float) $v)
            ->toArray();

        // 计算 eCPM + 过滤(余额>0 / 未耗尽预算) + 按 slot 分组
        $bySlot = [];   // slot => [ ['ad'=>Ad, 'ecpm'=>float, 'quality'=>float], ... ]
        $rejected = []; // 不合格(余额0/预算耗尽)的广告 id → 需清物化
        foreach ($ads as $ad) {
            $vendorId = $ad->restaurant?->vendor_id;
            $balance  = $vendorId ? ($adBalances[$vendorId] ?? 0) : 0;
            $budget   = $ad->daily_budget !== null ? (float) $ad->daily_budget : null;
            $spent    = (float) $ad->spent_today;

            // INV-1: 余额 0 不上架; 日预算已耗尽不上架(原子兜底仍在 click 端点)
            if ($balance <= 0 || ($budget !== null && $spent >= $budget)) {
                $rejected[] = $ad->id;
                continue;
            }

            $q = $quality[$ad->restaurant_id] ?? 1.0;
            $ecpm = (float) $ad->bid_amount * $q;
            $slot = $ad->slot ?: $this->inferSlot($ad);
            $bySlot[$slot][] = ['ad' => $ad, 'ecpm' => $ecpm, 'quality' => $q];
        }

        // 每 slot 排序 + max_share 过滤 + 物化
        $updates = [];   // ad_id => [mat_rank, mat_boost, quality_score]
        $clears  = $rejected;
        foreach ($bySlot as $slot => $entries) {
            // eCPM 降序; tie-break: id 升序(确定性, 防每轮抖动)
            usort($entries, function ($a, $b) {
                if (abs($a['ecpm'] - $b['ecpm']) > 0.0001) {
                    return $b['ecpm'] <=> $a['ecpm'];
                }
                return $a['ad']->id <=> $b['ad']->id;
            });

            $perStore = [];
            $rank = 0;
            foreach ($entries as $e) {
                $ad = $e['ad'];
                $rid = $ad->restaurant_id;
                $perStore[$rid] = ($perStore[$rid] ?? 0) + 1;
                if ($perStore[$rid] > $maxShare) {
                    // 同店超额: 不上架
                    $clears[] = $ad->id;
                    continue;
                }
                $rank++;
                $boost = $this->boostForRank($rank, $boostCap);
                $updates[$ad->id] = [
                    'mat_rank'      => $rank,
                    'mat_boost'     => $boost,
                    'quality_score' => round($e['quality'], 3),
                ];
            }
        }

        if ($dry) {
            $this->info('[dry-run] 物化预览: 上架 '.count($updates).' 条, 下架/清除 '.count($clears).' 条。');
            foreach ($updates as $id => $u) {
                $this->line("  广告#{$id} → slot 内 rank={$u['mat_rank']} boost={$u['mat_boost']} quality={$u['quality_score']}");
            }
            return self::SUCCESS;
        }

        // 写库: 上架(mat_rank/boost/quality) + 下架清除(mat_rank=null/boost=0, quality 仍刷新)
        $now = now();
        DB::transaction(function () use ($updates, $clears, $quality, $now) {
            foreach ($updates as $id => $u) {
                Advertisement::where('id', $id)->update([
                    'mat_rank'      => $u['mat_rank'],
                    'mat_boost'     => $u['mat_boost'],
                    'quality_score' => $u['quality_score'],
                    'mat_at'        => $now,
                ]);
            }
            if (!empty($clears)) {
                // 下架: 清排名/加成; quality_score 各自刷新(下面用各餐馆质量分)
                foreach (array_unique($clears) as $id) {
                    $ad = Advertisement::find($id);
                    if (!$ad) continue;
                    $ad->mat_rank = null;
                    $ad->mat_boost = 0;
                    $ad->quality_score = round($quality[$ad->restaurant_id] ?? 1.0, 3);
                    $ad->mat_at = $now;
                    $ad->save();
                }
            }
        });

        $this->info('物化完成: 上架 '.count($updates).' 条, 下架/清除 '.count(array_unique($clears)).' 条。');
        return self::SUCCESS;
    }

    /**
     * 难刷质量分: 完单率 / 好评率 / 出餐速度 合成, 映射到 [0.5,1.5], 无数据中性 1.0.
     * 近 N 天窗口防过期数据; 全部用真实顾客侧信号, 商家无法自刷.
     *
     * @param  array<int>  $restaurantIds
     * @return array<int,float>  restaurant_id => quality_score
     */
    public function computeQualityScores(array $restaurantIds, int $windowDays = 30): array
    {
        if (empty($restaurantIds)) return [];
        $since = Carbon::now('Asia/Yerevan')->subDays($windowDays)->toDateTimeString();
        $out = [];

        // 完单率 + 出餐速度(orders)
        $averagePrepExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "AVG(CASE WHEN confirmed IS NOT NULL AND processing IS NOT NULL AND processing >= confirmed THEN (julianday(processing) - julianday(confirmed)) * 1440 END) as avg_prep_min"
            : "AVG(CASE WHEN confirmed IS NOT NULL AND processing IS NOT NULL AND processing >= confirmed THEN TIMESTAMPDIFF(MINUTE, confirmed, processing) END) as avg_prep_min";

        $orderStats = DB::table('orders')
            ->select('restaurant_id')
            ->selectRaw("SUM(order_status='delivered') as delivered")
            ->selectRaw("SUM(order_status='canceled') as canceled")
            ->selectRaw($averagePrepExpression)
            ->whereIn('restaurant_id', $restaurantIds)
            ->where('created_at', '>=', $since)
            ->groupBy('restaurant_id')
            ->get()
            ->keyBy('restaurant_id');

        // 好评率(reviews; status=1 approved)
        $reviewStats = DB::table('reviews')
            ->select('restaurant_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(rating <= 2) as bad')
            ->whereIn('restaurant_id', $restaurantIds)
            ->where('status', 1)
            ->where('created_at', '>=', $since)
            ->groupBy('restaurant_id')
            ->get()
            ->keyBy('restaurant_id');

        foreach ($restaurantIds as $rid) {
            $os = $orderStats[$rid] ?? null;
            $rs = $reviewStats[$rid] ?? null;

            // 完单率: 无单 → 中性 0.5
            $delivered = $os ? (int) $os->delivered : 0;
            $canceled  = $os ? (int) $os->canceled : 0;
            $denom = $delivered + $canceled;
            $conv = $denom > 0 ? $delivered / $denom : 0.5;

            // 好评率: 无评价 → 中性 0.7(略偏正, 新店不被过度惩罚)
            $total = $rs ? (int) $rs->total : 0;
            $bad   = $rs ? (int) $rs->bad : 0;
            $good = $total > 0 ? max(0, 1 - $bad / $total) : 0.7;

            // 出餐速度: <=15min 满分, >=60min 0 分; 无数据 → 中性 0.5
            $prep = ($os && $os->avg_prep_min !== null) ? (float) $os->avg_prep_min : null;
            if ($prep === null) {
                $speed = 0.5;
            } else {
                $speed = max(0, min(1, (60 - $prep) / 45)); // 15→1.0, 60→0
            }

            $raw = 0.4 * $conv + 0.4 * $good + 0.2 * $speed;
            $raw = max(0, min(1, $raw));
            $out[$rid] = round(0.5 + $raw, 3); // [0.5, 1.5]
        }

        return $out;
    }

    /** 排名 → 综合分加成(boost). rank1=cap, rank2=cap/2, rank3+=cap/4. 软保留自然位. */
    public function boostForRank(int $rank, float $boostCap): float
    {
        if ($rank <= 1) return round($boostCap, 3);
        if ($rank == 2) return round($boostCap / 2, 3);
        return round($boostCap / 4, 3);
    }

    /** slot 为空时按 add_type 推断(向后兼容老广告). */
    protected function inferSlot(Advertisement $ad): string
    {
        return $ad->add_type === 'restaurant_promotion' ? 'home_carousel' : 'list_top';
    }
}
