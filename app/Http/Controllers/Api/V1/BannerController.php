<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Campaign;
use App\CentralLogics\BannerLogic;
use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BannerController extends Controller
{
    public function get_banners(Request $request)
    {
        Helpers::getZoneIds($request);
        $longitude = $request->header('longitude')??0;
        $latitude = $request->header('latitude')??0;
        $zone_id = json_decode($request->header('zoneId'), true);

        $bannersCacheKey = 'banners_' . md5(json_encode($zone_id));
        // Coarsen coords to ~1km grid for the cache key. Exact GPS made every request a unique
        // key -> cache always missed -> per-request recompute of campaign formatting (~8 queries
        // per restaurant) -> N+1 (banners was the top N+1-guard hit, 155x). Distance stays within ~1km.
        $lonBucket = round((float) $longitude, 2);
        $latBucket = round((float) $latitude, 2);
        $campaignsCacheKey = 'campaigns_' . md5(json_encode([$zone_id, $lonBucket, $latBucket]));

        // 只有"非空结果"才长缓存20分钟; 算出来是空就只缓存1分钟。
        // 否则某次"瞬时空"(例: 午夜活动按 end_time 短暂跌出 running())会被锁存整整20分钟,
        // 把1秒级的数据抖动放大成20分钟的"接口返回空" —— 曾致站外监控误报 DOWN。
        $rememberNonEmpty = function ($key, callable $compute) {
            $cached = Cache::get($key);
            $cachedEmpty = is_null($cached)
                ? true
                : (is_object($cached) && method_exists($cached, 'isEmpty') ? $cached->isEmpty() : empty($cached));
            if (!$cachedEmpty) {
                return $cached;
            }
            $fresh = $compute();
            $freshEmpty = (is_object($fresh) && method_exists($fresh, 'isEmpty')) ? $fresh->isEmpty() : empty($fresh);
            Cache::put($key, $fresh, $freshEmpty ? now()->addMinute() : now()->addMinutes(20));
            return $fresh;
        };

        $banners = $rememberNonEmpty($bannersCacheKey, function () use ($zone_id) {
            return BannerLogic::get_banners($zone_id);
        });

        $campaigns = $rememberNonEmpty($campaignsCacheKey, function () use ($zone_id, $longitude, $latitude) {
            $rawCampaigns = Campaign::whereHas('restaurants', function ($query) use ($zone_id) {
                $query->whereIn('zone_id', $zone_id)->Active()->where('campaign_status', 'confirmed');
            })->with('restaurants', function ($query) use ($zone_id, $longitude, $latitude) {
                return $query->WithOpen($longitude, $latitude)
                    ->whereIn('zone_id', $zone_id)
                    ->where('campaign_status', 'confirmed')
                    ->where('status', 1);
            })
                ->running()
                ->active()
                ->get();

            // Cache the FORMATTED result, not the raw collection. restaurant_data_formatting runs
            // ~8 queries per restaurant; doing it outside the cache meant it re-ran on every request
            // (the real banners N+1, was top N+1-guard hit 155x). Now it runs only on a cache miss
            // (~once per 1km cell per 20min). Distance/delivery accuracy stays within the ~1km bucket.
            try {
                return Helpers::basic_campaign_data_formatting($rawCampaigns, true);
            } catch (\Exception $e) {
                info($e->getMessage());
                return [];
            }
        });

        return response()->json([
            'campaigns' => $campaigns,
            'banners' => $banners
        ], 200);
    }
}
