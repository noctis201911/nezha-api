<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\ItemCampaign;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use Illuminate\Support\Facades\Validator;

class CampaignController extends Controller
{
    public function get_basic_campaigns(Request $request){
        Helpers::getZoneIds($request);
        $longitude= $request->header('longitude');
        $latitude= $request->header('latitude');
        $zone_id= json_decode($request->header('zoneId'), true);
        try {
            $campaigns = Campaign::whereHas('restaurants', function($query)use($zone_id){
                $query->whereIn('zone_id', $zone_id);
            })
            ->with('restaurants',function($query)use($zone_id,$longitude,$latitude){
                return $query->WithOpen($longitude,$latitude)->whereIn('zone_id', $zone_id)->wherePivot('campaign_status', 'confirmed')->active();
            })
            ->running()->active()->get();
            $campaigns=Helpers::basic_campaign_data_formatting($campaigns, true);
            return response()->json($campaigns, 200);
        } catch (\Exception $e) {
            // 哪吒[安全 2026-07-23]: 原始异常串不回客户端。本端点无鉴权中间件, 游客可直接打;
            // 落 ErrorException 泄内部属性名, 落 QueryException 连 Host/Port/Database 与内联 bindings 一并外泄。
            \Illuminate\Support\Facades\Log::warning('nz_basic_campaigns_failed', [
                'ex' => get_class($e),
                'code' => $e->getCode(),
            ]);
            return response()->json(['出现错误，请重试'], 200);
        }
    }
    public function basic_campaign_details(Request $request){
        Helpers::getZoneIds($request);
        $zone_id= json_decode($request->header('zoneId'), true);

        $validator = Validator::make($request->all(), [
            'basic_campaign_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        try {
            $longitude= $request->header('longitude');
            $latitude= $request->header('latitude');
            $campaign = Campaign::with(['restaurants'=>function($q)use($zone_id){
                $q->whereIn('zone_id', $zone_id);
            }])
            ->with('restaurants',function($query)use($zone_id,$longitude,$latitude){
                return $query->WithOpen($longitude,$latitude)->withcount('foods')->with(['discount'=>function($q){
                    return $q->validate();
                }])->whereIn('zone_id', $zone_id)->wherePivot('campaign_status', 'confirmed')->active();
            })
            ->running()->active()->where(fn($q) => $q->where('id', $request->basic_campaign_id)->orWhere('slug', $request->basic_campaign_id))
            ->first();

            $campaign=Helpers::basic_campaign_data_formatting($campaign, false);

            $campaign['restaurants'] = Helpers::restaurant_data_formatting($campaign['restaurants'], true);

            return response()->json($campaign, 200);
        } catch (\Exception $e) {
            // 哪吒[安全 2026-07-23]: 同 ProductController 判例, 原始异常串不回客户端。
            // 本端点 /api/v1/campaigns/basic-campaign-details 无鉴权中间件, 游客可直接打。
            // 线上实证: ?basic_campaign_id=zzz 查不到 -> 格式化函数读 null 属性 ->
            // ErrorException 落入本 catch 原样外泄内部属性名。
            \Illuminate\Support\Facades\Log::warning('nz_basic_campaign_details_failed', [
                'ex' => get_class($e),
                'code' => $e->getCode(),
            ]);
            return response()->json(['出现错误，请重试'], 500);
        }
    }
    public function get_item_campaigns(Request $request){
        Helpers::getZoneIds($request);
        $campaign_food_default_status = \App\Models\BusinessSetting::where('key', 'campaign_food_default_status')->first();
        $campaign_food_default_status = $campaign_food_default_status ? $campaign_food_default_status->value : 1;
        $campaign_food_sort_by_general = \App\Models\PriorityList::where('name', 'campaign_food_sort_by_general')->where('type','general')->first();
        $campaign_food_sort_by_general = $campaign_food_sort_by_general ? $campaign_food_sort_by_general->value : '';
        $campaign_food_sort_by_unavailable = \App\Models\PriorityList::where('name', 'campaign_food_sort_by_unavailable')->where('type','unavailable')->first();
        $campaign_food_sort_by_unavailable = $campaign_food_sort_by_unavailable ? $campaign_food_sort_by_unavailable->value : '';
        $campaign_food_sort_by_temp_closed = \App\Models\PriorityList::where('name', 'campaign_food_sort_by_temp_closed')->where('type','temp_closed')->first();
        $campaign_food_sort_by_temp_closed = $campaign_food_sort_by_temp_closed ? $campaign_food_sort_by_temp_closed->value : '';
        $zone_id= json_decode($request->header('zoneId'), true);
        $longitude= $request->header('longitude');
        $latitude= $request->header('latitude');
        try {
            $query = ItemCampaign::with('restaurant')
            ->Active()->whereHas('restaurant', function($q) use ($zone_id) {
                $q->whereIn('zone_id', $zone_id)->Weekday()->active();
            })
                ->select(['item_campaigns.*'])
                // ->leftJoin('restaurants', 'item_campaigns.restaurant_id', '=', 'restaurants.id')
                ->selectSub(function ($subQuery) {
                    $subQuery->selectRaw('active as temp_available')
                        ->from('restaurants')
                        ->whereColumn('restaurants.id', 'item_campaigns.restaurant_id');
                }, 'temp_available')
                ->selectSub(function ($subQuery) {
                    $subQuery->selectRaw('IF(((select count(*) from `restaurant_schedule` where `restaurants`.`id` = `restaurant_schedule`.`restaurant_id` and `restaurant_schedule`.`day` = ? and `restaurant_schedule`.`opening_time` < ? and `restaurant_schedule`.`closing_time` > ?) > 0), true, false) as open', [now()->dayOfWeek, now()->format('H:i:s'), now()->format('H:i:s')])
                        ->from('restaurants')
                        ->whereColumn('restaurants.id', 'item_campaigns.restaurant_id');
                }, 'open');
            if($campaign_food_default_status == '1'){
                $query = $query->running();
            }else{
                if($campaign_food_sort_by_unavailable == 'remove'){
                    $query = $query->running()->having('open', '>', 0);
                }elseif($campaign_food_sort_by_unavailable == 'last'){
                    $query = $query->orderByRaw("CASE WHEN start_date <= CURDATE() AND end_date >= CURDATE() AND start_time <= CURTIME() AND end_time >= CURTIME() THEN 0 ELSE 1 END")
                    ->orderByDesc('open');
                }

                if($campaign_food_sort_by_temp_closed == 'remove'){
                    $query = $query->having('temp_available', '>', 0);
                }elseif($campaign_food_sort_by_temp_closed == 'last'){
                    $query = $query->orderByDesc('temp_available');
                }

                if ($campaign_food_sort_by_general == 'nearest_first') {
                    $query = $query->selectSub(function ($subQuery) use ($longitude, $latitude) {
                        $subQuery->selectRaw('ST_Distance_Sphere(point(longitude, latitude), point(?, ?)) as distance', [$longitude, $latitude])
                            ->from('restaurants')
                            ->whereColumn('restaurants.id', 'item_campaigns.restaurant_id');
                    }, 'distance')
                        ->orderBy('distance');
                } elseif ($campaign_food_sort_by_general == 'order_count') {
                    $query = $query->withCount('orderdetails')->orderByDesc('orderdetails_count');
                } elseif ($campaign_food_sort_by_general == 'a_to_z') {
                    $query = $query->orderBy('title');
                } elseif ($campaign_food_sort_by_general == 'z_to_a') {
                    $query = $query->orderByDesc('title');
                } elseif ($campaign_food_sort_by_general == 'nearest_end_first') {
                    $query = $query->orderBy('end_date');
                } elseif ($campaign_food_sort_by_general == 'latest_created') {
                    $query = $query->latest();
                }
            }

            $campaigns =  $query->get();
            $campaigns= Helpers::product_data_formatting($campaigns, true, false, app()->getLocale());
            return response()->json($campaigns, 200);
        } catch (\Exception $e) {
            // 哪吒[安全 2026-07-23]: 原始异常串不回客户端。本端点无鉴权中间件, 游客可直接打。
            // 线上实证: 该端点当前恒抛 "Call to undefined relationship [tags] on model
            // [App\Models\ItemCampaign]" 并被本 catch 吞成 HTTP 200 -> 泄内部模型类路径。
            // 保持 HTTP 200 与数组结构不变: 前端首页「天天特价」按数组消费并 filter 掉非法项,
            // 改状态码会触发 react-query onError 路径, 属行为变更, 不在本次安全修复范围内。
            // 该端点自身的功能故障(loadMissing 对 ItemCampaign 取不存在的 tags 关系)由另一窗口修。
            \Illuminate\Support\Facades\Log::warning('nz_item_campaigns_failed', [
                'ex' => get_class($e),
                'code' => $e->getCode(),
            ]);
            return response()->json(['出现错误，请重试'], 200);
        }
    }
}
