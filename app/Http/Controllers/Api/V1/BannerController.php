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
        $campaignsCacheKey = 'campaigns_' . md5(json_encode([$zone_id, $longitude, $latitude]));

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
            return Campaign::whereHas('restaurants', function ($query) use ($zone_id) {
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
        });

        try {
            return response()->json([
                'campaigns' => Helpers::basic_campaign_data_formatting($campaigns, true),
                'banners' => $banners
            ], 200);
        } catch (\Exception $e) {
            info($e->getMessage());
            return response()->json([], 200);
        }
    }
}
