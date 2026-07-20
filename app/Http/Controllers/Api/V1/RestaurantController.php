<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Coupon;
use App\Models\Review;
use App\Models\Restaurant;
use App\Models\NezhaRestaurantContactEvent;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\CentralLogics\RestaurantLogic;
use App\CentralLogics\NezhaContacts;
use Illuminate\Support\Facades\Validator;

class RestaurantController extends Controller
{
    public function get_restaurants(Request $request, $filter_data="all")
    {
                Helpers::getZoneIds($request);

        $decoded_zone = json_decode($request->header('zoneId'), true);
        if (is_null($decoded_zone)) {
            $decoded_zone = [];
        }
        if (!is_array($decoded_zone)) {
            $decoded_zone = [$decoded_zone];
        }
        $additional_data=[
            'zone_id'=> $decoded_zone,
            'filter'=> $request->query('filter_data') ?? $filter_data,
            'limit' =>$request['limit'] ?? 25,
            'offset' =>$request['offset'] ?? 1,
            'type' =>$request->query('type', 'all') ?? 'all',
            'name' =>$request->query('name') ?? null,
            'longitude' =>$request->header('longitude') ?? 0,
            'latitude' => $request->header('latitude') ?? 0,
            'cuisine' => $request->query('cuisine', 'all') ?? 'all',
            'veg' =>$request->veg ?? null,
            'non_veg' =>$request->non_veg ?? null,
            'discount' =>$request->discount ?? null,
            'top_rated' =>$request->top_rated  ?? null,
            'delivery' =>$request->delivery ?? null,
            'takeaway' =>$request->takeaway ?? null,
            'avg_rating' =>$request->avg_rating ?? null,
            'sort_by' => $request->query('sort_by') ?? null,
            'filter_by' => $request->query('filter_by', []) ?? [],
            'cuisine_id' => $request->query('cuisine_id') ? (is_array($request->query('cuisine_id')) ? $request->query('cuisine_id') : json_decode($request->query('cuisine_id'), true)) : (is_array($request->query('cuisine')) ? $request->query('cuisine') : (json_decode($request->query('cuisine'), true) ?? [])),
            'order_type' => $request->query('order_type'),
            'request' => $request,
        ];


        $restaurants = RestaurantLogic::get_restaurants(additional_data: $additional_data );



        $restaurants['restaurants'] = Helpers::restaurant_data_formatting(data:$restaurants['restaurants'],multi_data: true);

        return response()->json($restaurants, 200);
    }

    public function get_latest_restaurants(Request $request, $filter_data="all")
    {
               Helpers::getZoneIds($request);
               $additional_data=[
            'sort_by' => $request->query('sort_by') ?? 'default',
            'filter_by' => $request->query('filter_by', []) ?? [],
            'cuisine_id' => $request->query('cuisine_id') ? (is_array($request->query('cuisine_id')) ? $request->query('cuisine_id') : json_decode($request->query('cuisine_id'), true)) : (is_array($request->query('cuisine')) ? $request->query('cuisine') : (json_decode($request->query('cuisine'), true) ?? [])),
            'order_type' => $request->query('order_type'),
            'request' => $request,
        ];

        $type = $request->query('type', 'all');
        $longitude= $request->header('longitude');
        $latitude= $request->header('latitude');
        $zone_id= json_decode($request->header('zoneId'), true);
        $restaurants = RestaurantLogic::get_latest_restaurants(zone_id:$zone_id, additional_data:$additional_data,limit:$request['limit'], offset:$request['offset'], type:$type ,longitude:$longitude,latitude:$latitude,veg:$request->veg ,non_veg:$request->non_veg, discount:$request->discount,top_rated: $request->top_rated);
        $restaurants['restaurants'] = Helpers::restaurant_data_formatting(data:$restaurants['restaurants'],multi_data: true );

        return response()->json($restaurants['restaurants'], 200);
    }

    public function get_popular_restaurants(Request $request)
    {
        Helpers::getZoneIds($request);
        $longitude= $request->header('longitude');
        $latitude= $request->header('latitude');
        $type = $request->query('type', 'all');
        $zone_id= json_decode($request->header('zoneId'), true);
        $additional_data=[
            'sort_by' => $request->query('sort_by') ?? 'default',
            'filter_by' => $request->query('filter_by', []) ?? [],
            'cuisine_id' => $request->query('cuisine_id') ? (is_array($request->query('cuisine_id')) ? $request->query('cuisine_id') : json_decode($request->query('cuisine_id'), true)) : (is_array($request->query('cuisine')) ? $request->query('cuisine') : (json_decode($request->query('cuisine'), true) ?? [])),
            'order_type' => $request->query('order_type'),
            'request' => $request,
        ];
        $restaurants = RestaurantLogic::get_popular_restaurants(zone_id:$zone_id,limit: $request['limit'], offset:$request['offset'],type: $type,longitude:$longitude,latitude:$latitude,veg:$request->veg ,non_veg:$request->non_veg, discount:$request->discount,top_rated: $request->top_rated, additional_data:$additional_data);
        $restaurants['restaurants'] = Helpers::restaurant_data_formatting(data:$restaurants['restaurants'], multi_data:true);
        return response()->json($restaurants['restaurants'], 200);
    }


    public function recently_viewed_restaurants(Request $request)
    {
        Helpers::getZoneIds($request);
        $longitude= $request->header('longitude');
        $latitude= $request->header('latitude');
        $type = $request->query('type', 'all');
        $zone_id= json_decode($request->header('zoneId'), true);
        $restaurants = RestaurantLogic::recently_viewed_restaurants_data(request:$request,zone_id:$zone_id, limit:$request['limit'], offset:$request['offset'],type: $type,longitude:$longitude,latitude:$latitude);
        $restaurants['restaurants'] = Helpers::restaurant_data_formatting(data:$restaurants['restaurants'], multi_data:true);

        return response()->json($restaurants['restaurants'], 200);
    }

    public function get_details(Request $request ,$id)
    {
        $restaurant = RestaurantLogic::get_restaurant_details($id);
        if($restaurant)
        {
            $category_ids = DB::table('food')
            ->join('categories', 'food.category_id', '=', 'categories.id')
            ->selectRaw('IF((categories.position = "0"), categories.id, categories.parent_id) as categories')
            ->where('food.restaurant_id', $restaurant->id)
            ->where('categories.status',1)
            ->groupBy('categories')
            ->get();
            $nzCatOrder = json_decode($restaurant->nezha_category_order ?? '[]', true) ?: []; // 哪吒[分类排序] 读原始模型(须在 formatting 前)
            $restaurant = Helpers::restaurant_data_formatting(data: $restaurant);
            $restaurant['category_ids'] = array_map('intval', $category_ids->pluck('categories')->toArray());
            $restaurant['nezha_category_order'] = array_values(array_map('intval', $nzCatOrder)); // 商家自定义分类先后; 空=前端用默认序(零影响)

            // 哪吒[外卖TG化 Phase1·挂牌态] 顾客侧联系方式规范化: 裸 JSON [{method,value,label}]
            //   → [{method,value,label,href,copy}], 与本地生活商家页共用同一 normalizer(deep-link 规则单点)。
            // 🔴 只在「单店详情」这一条顾客路径上转换 —— admin/vendor/各列表仍读裸 JSON, 语义不变。
            // ⚠️ 影响面别说小了: 本行对【所有】店的 details 响应生效。现网 19 家店该列全为 NULL,
            //    故 payload 由 nezha_contacts:null 统一变成 [] —— 这是一次全量【形状变更】, 不是"只影响挂牌店"。
            //    行为影响为零: 已 grep 确认前端 release 无任何消费者(web-deploy/current/src 零命中);
            //    非 web 客户端未核, 将来若有 App 读该字段需回看这里。
            $restaurant['nezha_contacts'] = NezhaContacts::normalize($restaurant['nezha_contacts'] ?? null);

            // 哪吒[2026-07-02 修看过的餐厅]: details/{id} 路由无 apiGuestCheck 中间件, $request->user 恒 null 致浏览从不入库(visit_count 全0);
            // 改用 auth('api')->user() 按需解析 Bearer token(游客无 token→null, 不触发中间件401, 浏览照常).
            $auth_user = auth('api')->user();
            if($auth_user !== null){
                $customer_id = $auth_user->id;
                Helpers::visitor_log(model:'restaurant',user_id:$customer_id,visitor_log_id:$restaurant->id,order_count:false);
            }
        }

        return response()->json($restaurant, 200);
    }

    /**
     * 哪吒[外卖TG化 Phase1·挂牌态] 联系意图埋点。
     * POST /api/v1/restaurants/{id}/contact-intent  (公开·无 auth·throttle:nezha_res_contact)
     *
     * 度量顾客在挂牌店页点了哪个联系渠道、点前想问什么，仅落聚合事实到 nezha_restaurant_contact_events。
     * 🔴 零主体标识：全程不读 $request->user()、不写 user_id/IP/UA/设备/platform。
     * 🔴 永不阻塞：无论何种输入一律 204（脏值降级/静默丢弃），唯一非 204 = 429（throttle 中间件）。
     *   - channel 不在白名单 → 204 零写入
     *   - restaurant 不存在 → 204 零写入（不暴露存在性差异）
     *   - question 不在白名单 或 渠道为 wechat/phone → 落行但 question=NULL（不拒）
     *   - insert 异常 → try/catch 吞，仍 204
     * 🔴 刻意不复用本地生活的 /local-life/merchants/{id}/contact-intent：主键空间不同，
     *    混写会让本地生活运营看板出现不存在的商家（业主 0720 要求两边统计隔离）。
     * L1-1 纯信息墙：埋点只是信息层，不含任何交易/下单/收款。
     */
    public function contact_intent(Request $request, $id)
    {
        $noContent = response()->noContent(); // 204

        // channel 白名单与 NezhaContacts::METHODS 同源（前端 consumer、normalizer、埋点三处必须同值）
        $channel = strtolower(trim((string) $request->input('channel', '')));
        if (!in_array($channel, NezhaContacts::METHODS, true)) {
            return $noContent;
        }

        if (!Restaurant::whereKey($id)->exists()) {
            return $noContent;
        }

        // question 白名单（与前端快捷提问芯片 key 同步）；wechat/phone 恒 NULL；乱值降级 NULL（照常落行，不拒）
        $question = strtolower(trim((string) $request->input('question', '')));
        $allowedQ = ['hours', 'order', 'delivery', 'recommend'];
        if (in_array($channel, ['wechat', 'phone'], true) || !in_array($question, $allowedQ, true)) {
            $question = null;
        }

        try {
            NezhaRestaurantContactEvent::create([
                'restaurant_id' => (int) $id,
                'channel'       => $channel,
                'question'      => $question,
            ]);
        } catch (\Throwable $e) {
            // 静默吞：埋点永不阻塞、永不 5xx
        }

        return $noContent;
    }

    public function get_searched_restaurants(Request $request)
    {
        Helpers::getZoneIds($request);
        $additional_data=[
            'sort_by' => $request->query('sort_by') ?? 'default',
            'filter_by' => $request->query('filter_by', []) ?? [],
            'cuisine_id' => $request->query('cuisine_id') ? (is_array($request->query('cuisine_id')) ? $request->query('cuisine_id') : json_decode($request->query('cuisine_id'), true)) : (is_array($request->query('cuisine')) ? $request->query('cuisine') : (json_decode($request->query('cuisine'), true) ?? [])),
            'order_type' => $request->query('order_type'),
            'request' => $request,
        ];

        $type = $request->query('type', 'all');
        $longitude= $request->header('longitude');
        $latitude= $request->header('latitude');
        $zone_id= json_decode($request->header('zoneId'), true);
        $cuisine = $request->query('cuisine', []) ?? [];
        $restaurants = RestaurantLogic::search_restaurants(name:$request['name'], zone_id:$zone_id, category_id:$request->category_id,limit:$request['limit'], offset:$request['offset'],type: $type,longitude:$longitude,latitude:$latitude ,popular: $request->popular ,new: $request->new ,rating: $request->rating,
        rating_3_plus:$request->rating_3_plus,rating_4_plus:$request->rating_4_plus ,rating_5:$request->rating_5 ,
        discounted: $request->discounted ,sort_by: $request->sort_by , dine_in:  $request->dine_in , open:  $request->open,cuisine: $cuisine , additional_data:$additional_data);
        $restaurants['restaurants'] = Helpers::restaurant_data_formatting( data: $restaurants['restaurants'],multi_data: true);
        if (!empty($request['name'])) { \App\CentralLogics\NezhaUsageLog::searchTerm($request['name'], 'restaurant', $zone_id, (int) ($restaurants['total_size'] ?? 0) === 0); } // 方案C 全量搜索埋点
        if ((int) ($restaurants['total_size'] ?? 0) === 0) { \App\CentralLogics\NezhaUsageLog::searchMiss($request['name'] ?? null, 'restaurant', $zone_id); } // 方案C 埋点(失败不影响)
        return response()->json($restaurants, 200);
    }

    public function reviews(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'restaurant_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $id = $request['restaurant_id'];

        // 筛选类型: all/latest(默认按最新) | good(4-5星好评) | bad(1-2星差评) | has_image(有图)
        $type = $request->input('type', 'all');

        $query = Review::with(['customer', 'food.translations'])
        ->whereHas('food', function($query)use($id){
            return $query->where('restaurant_id', $id);
        })
        ->active();

        if ($type === 'good') {
            $query->where('rating', '>=', 4);
        } elseif ($type === 'bad') {
            $query->where('rating', '<=', 2);
        } elseif ($type === 'has_image') {
            $query->whereNotNull('attachment')
                  ->where('attachment', '!=', '')
                  ->where('attachment', '!=', '[]');
        }

        $query->latest();

        // 分页: 传了 limit 才分页, 不传保持全量(向后兼容旧前端)
        if ($request->filled('limit')) {
            $limit = max(1, (int) $request->input('limit'));
            $offset = max(0, (int) $request->input('offset', 0));
            $query->skip($offset)->take($limit);
        }

        $reviews = $query->get();

        $storage = [];
        foreach ($reviews as $item) {
            $attachments = json_decode($item->attachment, true) ?? [];
            $item->attachment = $attachments;
            // 评价图完整 URL(与 food_image_full_url 同机制, 存于 public 盘 review 目录)
            $attachment_full_url = [];
            foreach ($attachments as $att) {
                if (!empty($att)) {
                    $attachment_full_url[] = Helpers::get_full_url('review', basename($att), 'public');
                }
            }
            $item->attachment_full_url = $attachment_full_url;
            $item->food_name = null;
            $item->food_image = null;
            $item->customer_name = null;
            if($item->food)
            {
                $item->food_id = $item->food->id;
                $item->food_name = $item->food->name;
                $item->food_image = $item->food->image;
                $item->food_image_full_url = $item->food->image_full_url;
                if(count($item->food->translations)>0)
                {
                    $translate = array_column($item->food->translations->toArray(), 'value', 'key');
                    $item->food_name = $translate['name'];
                }
            }
            if($item->customer)
            {
                $item->customer_name = $item->customer->f_name.' '.$item->customer->l_name;
            }

            unset($item->food);
            unset($item->customer);
            array_push($storage, $item);
        }

        return response()->json($storage, 200);
    }

    // 顾客举报评价(对齐本地生活 UGC 举报, auth:api 禁匿名)
    public function report_review(Request $request, $id)
    {
        $allowed_reasons = ['spam', 'offensive', 'fake', 'privacy', 'other'];
        $validator = Validator::make($request->all(), [
            'reason' => 'required|in:' . implode(',', $allowed_reasons),
            'detail' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $review = \App\Models\Review::find($id);
        if (!$review) {
            return response()->json(['errors' => [['code' => 'review', 'message' => translate('messages.not_found')]]], 404);
        }

        $user_id = $request?->user()?->id;
        // 「其他」必须填说明
        if ($request->reason === 'other' && trim((string) $request->detail) === '') {
            return response()->json(['errors' => [['code' => 'detail', 'message' => translate('messages.please_provide_detail')]]], 422);
        }
        // 去重: 同用户对同评价同理由不重复记
        $exists = DB::table('nezha_review_reports')
            ->where('review_id', $id)->where('user_id', $user_id)->where('reason', $request->reason)
            ->exists();
        if (!$exists) {
            DB::table('nezha_review_reports')->insert([
                'review_id' => $id,
                'user_id' => $user_id,
                'reason' => $request->reason,
                'detail' => $request->detail,
                'status' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        // already_reported: 端点按(review,user,reason)幂等去重, 重复举报仍返 200(非 4xx)。前端据此标区分「举报已提交」vs「您已举报过这条评价」。additive·旧调用方忽略此键无影响。
        return response()->json(['message' => translate('messages.report_submitted_successfully'), 'already_reported' => $exists], 200);
    }

    public function get_coupons(Request $request){

        $validator = Validator::make($request->all(), [
            'restaurant_id' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        // 哪吒[券包 Slice3]: restaurant_id 来自 query 是字符串, data 列存整数 → JSON_CONTAINS 类型敏感("6"≠6)致 admin restaurant_wise 券在餐厅页永不显示。强制转 int 修复。
        $restaurant_id=(int) $request->restaurant_id;
        $customer_id=$request->customer_id ?? null;

        $coupons = Coupon::Where(function ($q) use ($restaurant_id,$customer_id) {
            $q->Where('coupon_type', 'restaurant_wise')->whereJsonContains('data', [$restaurant_id])
                ->where(function ($q1) use ($customer_id) {
                    $q1->whereJsonContains('customer_id', [$customer_id])->orWhereJsonContains('customer_id', ['all']);
                });
        })->orWhereHas('restaurant',function($q) use ($restaurant_id){
            $q->where('id',$restaurant_id);
        })
        ->active()->whereDate('expire_date', '>=', date('Y-m-d'))->whereDate('start_date', '<=', date('Y-m-d'))
        ->get();
        return response()->json($coupons, 200);
    }


    public function get_recommended_restaurants(Request $request){
        Helpers::getZoneIds($request);

        $longitude= $request->header('longitude') ?? 0;
        $latitude= $request->header('latitude') ?? 0;
        // $user_id = $request->user ? $request->user->id : $request['guest_id'];
        $zone_id= json_decode($request->header('zoneId'), true);
        $data = Restaurant::withOpen($longitude,$latitude)
        ->withCustomerAvailability()
        ->withcount('foods')
        ->with(['foods_for_reorder'])
        ->Active()
        ->whereIn('zone_id', $zone_id)
        ->orderByCustomerAvailability()
        ->orderBy('distance', 'asc')
        ->inRandomOrder()->limit(20)
        ->get()
		->map(function ($data) {
			$data->foods = $data->foods_for_reorder->take(5);
            unset($data->foods_for_reorder);
			return $data;
		});

        return response()->json(Helpers::restaurant_data_formatting($data, true), 200);
    }


    public function get_visited_restaurants(Request $request){
        Helpers::getZoneIds($request);

        $longitude= $request->header('longitude') ?? 0;
        $latitude= $request->header('latitude') ?? 0;
        $user_id = $request->user ? $request->user->id : $request['guest_id'];
        // dd($user_id);
        $zone_id= json_decode($request->header('zoneId'), true);
        $data = Restaurant::withOpen($longitude,$latitude)
        ->withCustomerAvailability()
        ->wherehas('users', function($q) use($user_id) {
            $q->where('user_id',$user_id);
        })
        ->with('users')
        ->withcount('foods')
        ->with(['foods_for_reorder'])
        ->Active()
        ->whereIn('zone_id', $zone_id)

        ->selectRaw('(SELECT `visit_count` FROM `visitor_logs` WHERE `restaurants`.`id` = `visitor_logs`.`visitor_log_id` AND `user_id` = ? ORDER BY `visit_count` DESC LIMIT 1) as visit_count', [$user_id])

        ->orderByCustomerAvailability()
        ->orderBy('visit_count', 'desc')

        ->limit(20)
        ->get()
		->map(function ($data) {
			$data->foods = $data->foods_for_reorder->take(5);
            unset($data->foods_for_reorder);
			return $data;
		});

        return response()->json(Helpers::restaurant_data_formatting($data, true), 200);
    }



    public function get_dine_in_restaurants(Request $request)
    {
        Helpers::getZoneIds($request);
        $longitude= $request->header('longitude')?? 0;
        $latitude= $request->header('latitude')?? 0;
        $type = $request->query('type', 'all');
        $zone_id= json_decode($request->header('zoneId'), true);

        $additional_data=[
            'zone_id'=>$zone_id,
            'limit'=> $request['limit'],
            'offset'=>$request['offset'],
            'type'=> $type,
            'longitude'=>$longitude,
            'latitude'=>$latitude,
            'veg'=>$request->veg ,
            'non_veg'=>$request->non_veg,
            'discount'=>$request->discount,
            'top_rated'=> $request->top_rated,
            'new'=>$request->new,
            'rating_5'=> $request->rating_5,
            'rating_4_plus'=> $request->rating_4_plus,
            'rating_3_plus'=>$request->rating_3_plus,
            'sort_by'=> $request->sort_by,
            'open' => $request->open,
            'cuisine' => $request->query('cuisine', []) ?? [],
            'request' => $request,
        ];


        $restaurants = RestaurantLogic::get_dine_in_restaurants(additional_data:$additional_data);
        $restaurants['restaurants'] = Helpers::restaurant_data_formatting(data:$restaurants['restaurants'], multi_data:true);
        return response()->json($restaurants, 200);
    }


}
