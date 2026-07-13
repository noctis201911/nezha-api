<?php

namespace App\CentralLogics;

use App\Models\Restaurant;
use App\Models\PriorityList;
use App\Models\BusinessSetting;
use App\Models\OrderTransaction;


class RestaurantLogic
{
    public static function get_restaurants(array $additional_data)
    {
        $all_restaurant_default_status = BusinessSetting::where('key', 'all_restaurant_default_status')->first();
        $all_restaurant_default_status = $all_restaurant_default_status ? $all_restaurant_default_status->value : 1;
        $all_restaurant_sort_by_general = PriorityList::where('name', 'all_restaurant_sort_by_general')->where('type','general')->first();
        $all_restaurant_sort_by_general = $all_restaurant_sort_by_general ? $all_restaurant_sort_by_general->value : '';
        $all_restaurant_sort_by_unavailable = PriorityList::where('name', 'all_restaurant_sort_by_unavailable')->where('type','unavailable')->first();
        $all_restaurant_sort_by_unavailable = $all_restaurant_sort_by_unavailable ? $all_restaurant_sort_by_unavailable->value : '';
        $all_restaurant_sort_by_temp_closed = PriorityList::where('name', 'all_restaurant_sort_by_temp_closed')->where('type','temp_closed')->first();
        $all_restaurant_sort_by_temp_closed = $all_restaurant_sort_by_temp_closed ? $all_restaurant_sort_by_temp_closed->value : '';
        $key = $additional_data['name'] ? explode(' ', $additional_data['name']):null;
        $cuisine = $additional_data['cuisine'] ?? [];
        $cuisine = is_array($cuisine) ? $cuisine : (json_decode($cuisine, true) ?: []);

        $query = Restaurant::
        withOpen($additional_data['longitude'],$additional_data['latitude'])
            ->withCustomerAvailability()
            ->orderByCustomerAvailability()
            ->with(['discount'=>function($q){
                return $q->validate();
            }])
            ->whereIn('zone_id', $additional_data['zone_id'])
            ->withcount('foods')
            ->withcount('reviews_comments')

            ->when($additional_data['filter'] =='delivery', function($q){
                return $q->delivery();
            })
            ->when($additional_data['filter'] =='take_away', function($q){
                return $q->takeaway();
            })
            ->when($additional_data['filter'] =='dine_in', function($q){
                return $q->whereHas('restaurant_config', function ($query) {
                    $query->where('dine_in',true);
                });
            })

            ->when($additional_data['avg_rating'] > 0 , function($query) use($additional_data) {
                $query->selectSub(function ($query) use ($additional_data){
                    $query->selectRaw('AVG(reviews.rating)')
                        ->from('reviews')
                        ->join('food', 'food.id', '=', 'reviews.food_id')
                        ->whereColumn('food.restaurant_id', 'restaurants.id')
                        ->groupBy('food.restaurant_id')
                        ->havingRaw('AVG(reviews.rating) >= ?', [$additional_data['avg_rating']]);
                }, 'avg_r')->having('avg_r', '>=', $additional_data['avg_rating']);
            })
            ->when(isset($additional_data['veg'])   && $additional_data['veg'] == 1  , function($query) {
                $query->where('veg',1);
            })
            ->when(isset($additional_data['non_veg']) && $additional_data['non_veg'] == 1   , function($query) {
                $query->where('non_veg',1);
            })

            ->when(isset($additional_data['delivery']) && $additional_data['delivery'] == 1   , function($query) {
                return $query->delivery();
            })
            ->when(isset($additional_data['takeaway']) && $additional_data['takeaway'] == 1   , function($query) {
                return $query->takeaway();
            })

            ->when(isset($additional_data['discount'])  && $additional_data['discount'] == 1  , function($query) {
                $query->whereHas('discount',function($query){
                    return $query->validate();
                });
            })
            ->when(isset($additional_data['top_rated']) && $additional_data['top_rated'] == 1 , function($query){
                $query->selectSub(function ($query) {
                    $query->selectRaw('AVG(reviews.rating)')
                        ->from('reviews')
                        ->join('food', 'food.id', '=', 'reviews.food_id')
                        ->whereColumn('food.restaurant_id', 'restaurants.id')
                        ->groupBy('food.restaurant_id')
                        ->havingRaw('AVG(reviews.rating) > ?', [3]);
                }, 'avg_r')->having('avg_r', '>=', 3);
            })
            ->Active()
            ->type($additional_data['type'])
            ->when(!empty($cuisine), function($query) use ($cuisine) {
                $query->cuisine($cuisine);
            })
            ->when($additional_data['filter'] =='latest', function($q){
                return $q->latest();
            })
            ->when($additional_data['filter'] =='popular', function($q){
                return self::addOrdersCountIfMissing($q)
                    ->orderBy('orders_count', 'desc');
            })
            ->when($additional_data['filter'] =='near_by_restaurants', function($q){
                return $q->orderBy('distance');
            })
            // 好评率排序(前端"好评率"筛选 filter_data=top_rated): 按已审核(status=1)评价的平均星级从高到低,
            // 与卡片显示的 avg_rating 同口径(同样只算 status=1); 无评价者 avg 为 NULL, MySQL DESC 下自动沉底。
            ->when($additional_data['filter'] =='top_rated', function($q){
                return $q->selectSub(function ($query) {
                    $query->selectRaw('AVG(reviews.rating)')
                        ->from('reviews')
                        ->join('food', 'food.id', '=', 'reviews.food_id')
                        ->where('reviews.status', 1)
                        ->whereColumn('food.restaurant_id', 'restaurants.id')
                        ->groupBy('food.restaurant_id');
                }, 'nz_rating_avg')->orderBy('nz_rating_avg', 'desc');
            })
            ->when(isset($key) , function($query)use($key){
                $query->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->Where('name', 'like', "%{$value}%");
                    }
                    $q->orWhereHas('translations',function($query) use($key){
                        foreach ($key as $value) {
                            $query->where('translationable_type', 'App\Models\Restaurant')->where('key','name')->where('value', 'like', "%{$value}%");
                        };
                    });
                });
            });
            if($all_restaurant_default_status == '1' && (!isset($additional_data['sort_by']) || $additional_data['sort_by'] == 'default')) {
                if ($additional_data['filter'] == 'all') {
                    // 综合排序(综合分): 营业中优先(open 已在前置 orderBy 强制沉底关店),再按综合分降序。
                    // 综合分 = 0.45×距离(近) + 0.30×评分(高) + 0.25×销量(多); 归一化用绝对阈值(距离8000m封顶/销量50单封顶/评分除以5)以保证分页排序稳定(不能用相对min-max,否则每页归一不同会乱序)。
                    // 权重/阈值如需调整改下方 orderByRaw 即可(L3实现细节)。无评价店评分计0分,仍靠距离+销量参与排序。
                    $query = self::addOrdersCountIfMissing($query)
                        ->selectSub(function ($q) {
                            $q->selectRaw('AVG(reviews.rating)')
                                ->from('reviews')
                                ->join('food', 'food.id', '=', 'reviews.food_id')
                                ->where('reviews.status', 1)
                                ->whereColumn('food.restaurant_id', 'restaurants.id')
                                ->groupBy('food.restaurant_id');
                        }, 'nz_comp_rating');

                    // [哪吒广告 T1/T3] 综合分 + 付费曝光加成。两套计费并存、由开关切换:
                    //   ① 竞价 CPC (nezha_ad_auction_status=1): 综合分 + 该餐馆在投 cpc 广告的 MAX(mat_boost)。
                    //      mat_boost 由 nezha:recompute-ad-auction 物化(出价×质量分排名→加成), 已在命令内钳过上限(防碾压自然好店)。
                    //      读物化=O(1)、可缓存(物化值每5分钟才变), 不在排序里实时跑竞价、不抬 P99。
                    //   ② 按天 CPT (auction 关时, 原路径不动): is_paid EXISTS 二值加权(nezha_ad_boost_weight)。
                    //   两开关皆关(默认)→ 无 is_paid=1、无 mat_rank → 加成恒 0 → 排序零行为变化。
                    //   只影响排序、不伪造数据/不改卡片(INV-5 付费位不打广告标)。
                    $nz_comp_base = '0.45 * GREATEST(0, 1 - distance / 8000) + 0.30 * COALESCE(nz_comp_rating, 0) / 5 + 0.25 * LEAST(1, orders_count / 50)';
                    $nz_auction_on = (int) (\App\Models\BusinessSetting::where('key', 'nezha_ad_auction_status')->first()?->value ?? 0);
                    $nz_today = date('Y-m-d');
                    if ($nz_auction_on === 1) {
                        // 竞价 CPC: 出价驱动 boost(读物化 mat_boost; mat_rank 非空=当前在投赢家)
                        $query = $query->orderByRaw("(" . $nz_comp_base . " + COALESCE((SELECT MAX(nz_ad.mat_boost) FROM advertisements nz_ad WHERE nz_ad.restaurant_id = restaurants.id AND nz_ad.pricing_model = 'cpc' AND nz_ad.mat_rank IS NOT NULL AND nz_ad.status = 'approved' AND DATE(nz_ad.start_date) <= ? AND DATE(nz_ad.end_date) >= ?), 0)) DESC", [$nz_today, $nz_today]);
                    } else {
                        // 按天 CPT(原路径, 不动): is_paid EXISTS 二值加权
                        $nz_ad_boost = (float) (\App\Models\BusinessSetting::where('key', 'nezha_ad_boost_weight')->first()?->value ?? 0);
                        $nz_ad_boost = max(0, min($nz_ad_boost, 1.0));
                        if ($nz_ad_boost > 0) {
                            $query = $query->orderByRaw("(" . $nz_comp_base . " + ? * EXISTS(SELECT 1 FROM advertisements nz_ad WHERE nz_ad.restaurant_id = restaurants.id AND nz_ad.is_paid = 1 AND nz_ad.status = 'approved' AND DATE(nz_ad.start_date) <= ? AND DATE(nz_ad.end_date) >= ?)) DESC", [$nz_ad_boost, $nz_today, $nz_today]);
                        } else {
                            $query = $query->orderByRaw("(" . $nz_comp_base . ") DESC");
                        }
                    }
                } else {
                    // 其它筛选(销量popular/好评率top_rated/距离near_by/delivery等)各有自己的主排序; 默认块这里只补订单量做稳定并列项, 不套综合分(否则销量等会被综合分打破并列,看起来与综合排序雷同)。
                    $query = self::addOrdersCountIfMissing($query)->orderBy('orders_count', 'desc');
                }
            } elseif(!isset($additional_data['sort_by']) || $additional_data['sort_by'] == 'default') {

                if($all_restaurant_sort_by_temp_closed == 'remove'){
                    $query = $query->where('active', '>', 0);
                }elseif($all_restaurant_sort_by_temp_closed == 'last'){
                    $query = $query->orderByDesc('active');
                }

                if($all_restaurant_sort_by_unavailable == 'remove'){
                    $query = NezhaPreorder::enabled()
                        ? $query->having('customer_availability_rank', '>', 1)
                        : $query->having('open', '>', 0);
                }

                if($all_restaurant_sort_by_general == 'latest_created') {
                    $query = $query->latest();
                }elseif($all_restaurant_sort_by_general == 'nearest_first') {
                    $query = $query->orderBy('distance');
                }elseif($all_restaurant_sort_by_general == 'rating') {
                    $query = $query->selectSub(function ($query) {
                                $query->selectRaw('AVG(reviews.rating)')
                                    ->from('reviews')
                                    ->join('food', 'food.id', '=', 'reviews.food_id')
                                    ->whereColumn('food.restaurant_id', 'restaurants.id')
                                    ->groupBy('food.restaurant_id');
                            }, 'avg_r')->orderBy('avg_r', 'desc');
                }elseif($all_restaurant_sort_by_general == 'review_count') {
                    $query = $query->withCount('reviews')->orderBy('reviews_count', 'desc');
                }elseif($all_restaurant_sort_by_general == 'order_count') {
                    $query = self::addOrdersCountIfMissing($query)->orderBy('orders_count', 'desc');
                } elseif ($all_restaurant_sort_by_general == 'a_to_z') {
                    $query = $query->orderBy('name');
                } elseif ($all_restaurant_sort_by_general == 'z_to_a') {
                    $query = $query->orderByDesc('name');
                }

            }

        $paginator = $query
        ->applyFilters($additional_data)
        ->applySorting($additional_data['sort_by'])
        ->applyRating($additional_data['request'] ?? null)
        ->applyPriceRange($additional_data['request'] ?? null)
        ->paginate($additional_data['limit'], ['*'], 'page', $additional_data['offset']);

        return [
            'filter_data'=> $additional_data['filter']  ?? null,
            'total_size' => $paginator->total(),
            'limit' => $additional_data['limit'],
            'offset' => $additional_data['offset'],
            'restaurants' => $paginator->items()
        ];
    }

    public static function get_latest_restaurants($zone_id, $additional_data, $limit = 10, $offset = 1, $type='all',$longitude=0,$latitude=0,
    $veg = null ,$non_veg = null ,$discount = null,$top_rated = null)
    {
        $new_restaurant_default_status = BusinessSetting::where('key', 'new_restaurant_default_status')->first();
        $new_restaurant_default_status = $new_restaurant_default_status ? $new_restaurant_default_status->value : 1;
        $new_restaurant_sort_by_general = PriorityList::where('name', 'new_restaurant_sort_by_general')->where('type','general')->first();
        $new_restaurant_sort_by_general = $new_restaurant_sort_by_general ? $new_restaurant_sort_by_general->value : '';
        $new_restaurant_sort_by_unavailable = PriorityList::where('name', 'new_restaurant_sort_by_unavailable')->where('type','unavailable')->first();
        $new_restaurant_sort_by_unavailable = $new_restaurant_sort_by_unavailable ? $new_restaurant_sort_by_unavailable->value : '';
        $new_restaurant_sort_by_temp_closed = PriorityList::where('name', 'new_restaurant_sort_by_temp_closed')->where('type','temp_closed')->first();
        $new_restaurant_sort_by_temp_closed = $new_restaurant_sort_by_temp_closed ? $new_restaurant_sort_by_temp_closed->value : '';

        $query = Restaurant::withOpen($longitude,$latitude)
        ->withCustomerAvailability()
        ->orderByCustomerAvailability()
        ->with(['discount'=>function($q){
            return $q->validate();
        }])->whereIn('zone_id', $zone_id)
        ->withcount('foods')
            ->withcount('reviews_comments')
        ->when(isset($veg)   && $veg == 1  , function($q) {
            $q->where('veg',1);
        })
        ->when(isset($non_veg) && $non_veg == 1   , function($q) {
            $q->where('non_veg',1);
        })
        ->when(isset($discount)  && $discount == 1  , function($q) {
            $q->whereHas('discount',function($query){
                return $query->validate();
            });
        })
        ->when(isset($top_rated) && $top_rated == 1 , function($query){
            $query->selectSub(function ($query) {
                $query->selectRaw('AVG(reviews.rating)')
                    ->from('reviews')
                    ->join('food', 'food.id', '=', 'reviews.food_id')
                    ->whereColumn('food.restaurant_id', 'restaurants.id')
                    ->groupBy('food.restaurant_id')
                    ->havingRaw('AVG(reviews.rating) > ?', [3]);
            }, 'avg_r')->having('avg_r', '>=', 3);
        })
        ->Active()
        ->type($type);

        if($new_restaurant_default_status == '1' && (!isset($additional_data['sort_by']) || $additional_data['sort_by'] == 'default')) {
            $query = $query->latest();
        }elseif(!isset($additional_data['sort_by']) || $additional_data['sort_by'] == 'default'){

            if($new_restaurant_sort_by_temp_closed == 'remove'){
                $query = $query->where('active', '>', 0);
            }elseif($new_restaurant_sort_by_temp_closed == 'last'){
                $query = $query->orderByDesc('active');
            }

            if($new_restaurant_sort_by_unavailable == 'remove'){
                $query = NezhaPreorder::enabled()
                    ? $query->having('customer_availability_rank', '>', 1)
                    : $query->having('open', '>', 0);
            }

            if($new_restaurant_sort_by_general == 'latest_created') {
                $query = $query->latest();
            }elseif($new_restaurant_sort_by_general == 'nearby_first') {
                $query = $query->orderBy('distance');
            }elseif($new_restaurant_sort_by_general == 'delivery_time') {
                $query = $query->whereRaw("delivery_time REGEXP '^[0-9]+-[0-9]+-min$'")
                    ->orderByRaw("SUBSTRING_INDEX(delivery_time, '-', 1)")
                    ->orderByRaw("SUBSTRING_INDEX(SUBSTRING_INDEX(delivery_time, '-', -1), '-', 1)");
            }

        }
        info($additional_data['sort_by']);

        $paginator = $query
        ->applyFilters($additional_data)
        ->applySorting($additional_data['sort_by'])
        ->applyRating($additional_data['request'] ?? null)
        ->applyPriceRange($additional_data['request'] ?? null)
        ->limit(20)
        ->get();

        return [
            'total_size' => $paginator->count(),
            'limit' => $limit,
            'offset' => $offset,
            'restaurants' => $paginator
        ];
    }

    public static function get_popular_restaurants($zone_id, $limit = 10, $offset = 1, $type='all',$longitude=0,$latitude=0 ,$veg = null ,$non_veg = null ,$discount = null,$top_rated = null, $additional_data = [])
    {
        $popular_restaurant_default_status = BusinessSetting::where('key', 'popular_restaurant_default_status')->first();
        $popular_restaurant_default_status = $popular_restaurant_default_status ? $popular_restaurant_default_status->value : 1;
        $popular_restaurant_sort_by_general = PriorityList::where('name', 'popular_restaurant_sort_by_general')->where('type','general')->first();
        $popular_restaurant_sort_by_general = $popular_restaurant_sort_by_general ? $popular_restaurant_sort_by_general->value : '';
        $popular_restaurant_sort_by_unavailable = PriorityList::where('name', 'popular_restaurant_sort_by_unavailable')->where('type','unavailable')->first();
        $popular_restaurant_sort_by_unavailable = $popular_restaurant_sort_by_unavailable ? $popular_restaurant_sort_by_unavailable->value : '';
        $popular_restaurant_sort_by_temp_closed = PriorityList::where('name', 'popular_restaurant_sort_by_temp_closed')->where('type','temp_closed')->first();
        $popular_restaurant_sort_by_temp_closed = $popular_restaurant_sort_by_temp_closed ? $popular_restaurant_sort_by_temp_closed->value : '';

        $query = Restaurant::withOpen($longitude,$latitude)
            ->withCustomerAvailability()
            ->orderByCustomerAvailability()
            ->with(['reviews','discount'=>function($q){
                return $q->validate();
            }])->whereIn('zone_id', $zone_id)
            ->withcount('foods')
            ->withcount('reviews_comments')
            ->withCount('reviews')
            ->withCount('orders')
            ->type($type)
            ->when(isset($veg) && $veg == 1  , function($q) {
                $q->where('veg',1);
            })
            ->when(isset($non_veg) && $non_veg == 1   , function($q) {
                $q->where('non_veg',1);
            })
            ->when(isset($discount)  && $discount == 1  , function($q) {
                $q->whereHas('discount',function($query){
                    return $query->validate();
                });
            })
            ->when(isset($top_rated) && $top_rated == 1 , function($query){
                $query->selectSub(function ($query) {
                    $query->selectRaw('AVG(reviews.rating)')
                        ->from('reviews')
                        ->join('food', 'food.id', '=', 'reviews.food_id')
                        ->whereColumn('food.restaurant_id', 'restaurants.id')
                        ->groupBy('food.restaurant_id');
                }, 'avg_r')->having('avg_r', '>=', 3);
            })
            ->search(keywords:request()?->search)
            ->Active();

        if($popular_restaurant_default_status == '1' && $type == 'all' && (!isset($additional_data['sort_by']) || $additional_data['sort_by'] == 'default')) {
            $query = $query->orderBy('orders_count', 'desc');
        }elseif(!isset($additional_data['sort_by']) || $additional_data['sort_by'] == 'default'){

            if($popular_restaurant_sort_by_temp_closed == 'remove'){
                $query = $query->where('active', '>', 0);
            }elseif($popular_restaurant_sort_by_temp_closed == 'last'){
                $query = $query->orderByDesc('active');
            }

            if($popular_restaurant_sort_by_unavailable == 'remove'){
                $query = NezhaPreorder::enabled()
                    ? $query->having('customer_availability_rank', '>', 1)
                    : $query->having('open', '>', 0);
            }

            if($popular_restaurant_sort_by_general == 'rating') {
                $query = $query->selectSub(function ($query) {
                    $query->selectRaw('AVG(reviews.rating)')
                        ->from('reviews')
                        ->join('food', 'food.id', '=', 'reviews.food_id')
                        ->whereColumn('food.restaurant_id', 'restaurants.id')
                        ->groupBy('food.restaurant_id');
                }, 'avg_r')->orderBy('avg_r', 'desc');
            }elseif($popular_restaurant_sort_by_general == 'review_count') {
                $query = $query->orderByDesc('reviews_count');
            }elseif($popular_restaurant_sort_by_general == 'order_count') {
                $query = $query->orderBy('orders_count', 'desc');
            }

        }

        $paginator = $query->applyFilters($additional_data)
            ->applySorting($additional_data['sort_by'] ?? null)
            ->applyRating($additional_data['request'] ?? null)
            ->applyPriceRange($additional_data['request'] ?? null)
            ->limit(50)->get();

        return [
            'total_size' => $paginator->count(),
            'limit' => $limit,
            'offset' => $offset,
            'restaurants' => $paginator
        ];
    }
    public static function get_dine_in_restaurants(array $additional_data)
    {
        $query = Restaurant::withOpen($additional_data['longitude']?? 0,$additional_data['latitude']?? 0)
            ->withCustomerAvailability()
            ->orderByCustomerAvailability()
            ->whereHas('restaurant_config', function ($query) {
                $query->where('dine_in',1);
            })
            ->with(['reviews','discount'=>function($q){
                return $q->validate();
            }])->whereIn('zone_id', $additional_data['zone_id'])
            ->multiCuisine($additional_data['cuisine'])
            ->withcount('foods')
            ->withcount('reviews_comments')
            ->withCount('reviews')
            ->withCount('orders')
            ->type($additional_data['type'] ?? 'all')
            ->Active();

        $paginator = $query->applyFilters($additional_data)
            ->applySorting($additional_data['sort_by'] ?? null)
            ->applyRating($additional_data['request'] ?? null)
            ->applyPriceRange($additional_data['request'] ?? null)
            ->paginate(($additional_data['limit'] ?? 10) , ['*'], 'page', ($additional_data['offset'] ?? 1) );

        return [
            'total_size' => $paginator->total(),
            'limit' => ($additional_data['limit'] ?? 10) ,
            'offset' => ($additional_data['offset'] ?? 1) ,
            'restaurants' => $paginator->items()
        ];
    }

    public static function get_restaurant_details($restaurant_id)
    {
        return Restaurant::with(['discount'=>function($q){
            return $q->validate();
        }, 'campaigns', 'schedules','restaurant_sub'])->active()
            ->withcount('reviews_comments')
        ->when(is_numeric($restaurant_id),function ($qurey) use($restaurant_id){
            $qurey-> where('id', $restaurant_id);
        })
        ->when(!is_numeric($restaurant_id),function ($qurey) use($restaurant_id){
            $qurey-> where('slug', $restaurant_id);
        })
        ->first();
    }

    public static function calculate_restaurant_rating($ratings)
    {
        $total_submit = $ratings[0]+$ratings[1]+$ratings[2]+$ratings[3]+$ratings[4];
        $rating = ($ratings[0]*5+$ratings[1]*4+$ratings[2]*3+$ratings[3]*2+$ratings[4])/($total_submit?$total_submit:1);
        return ['rating'=>$rating, 'total'=>$total_submit];
    }
    public static function calculate_positive_rating($ratings)
    {
        $total_submit = $ratings[0]+$ratings[1]+$ratings[2]+$ratings[3]+$ratings[4];
        $rating = (($ratings[0]+$ratings[1]) / ($total_submit?$total_submit:1)) *100;
        return ['rating'=>$rating, 'total'=>$total_submit];
    }

    public static function update_restaurant_rating($ratings, $product_rating)
    {
        $restaurant_ratings = [1=>0 , 2=>0, 3=>0, 4=>0, 5=>0];
        if($ratings)
        {
            $restaurant_ratings[1] = $ratings[4];
            $restaurant_ratings[2] = $ratings[3];
            $restaurant_ratings[3] = $ratings[2];
            $restaurant_ratings[4] = $ratings[1];
            $restaurant_ratings[5] = $ratings[0];
            $restaurant_ratings[$product_rating] = $ratings[5-$product_rating] + 1;
        }
        else
        {
            $restaurant_ratings[$product_rating] = 1;
        }
        return json_encode($restaurant_ratings);
    }

    public static function search_restaurants( $zone_id, $name ='null', $category_id= null,$limit = 10, $offset = 1, $type='all',$longitude=0,$latitude=0 ,$popular=0,$new=0 , $rating=0 , $rating_3_plus = 0,$rating_4_plus = 0 ,$rating_5 = 0 , $discounted =0, $sort_by =null , $dine_in =0 ,$open=0, $cuisine = [], $additional_data = [])
    {
        $search_bar_default_status = BusinessSetting::where('key', 'search_bar_default_status')->first()?->value ?? 1;
        $search_bar_sort_by_unavailable = PriorityList::where('name', 'search_bar_sort_by_unavailable')->where('type','unavailable')->first()?->value ?? '';
        $search_bar_sort_by_temp_closed = PriorityList::where('name', 'search_bar_sort_by_temp_closed')->where('type','temp_closed')->first()?->value ?? '';

        $key = isset($name) && $name != 'null'  ?  explode(' ', $name) : null;

        if(is_array($key)){
            $key = array_filter($key);
            $key = array_values($key);
        }

        $query = Restaurant::withOpen($longitude,$latitude)
        ->withCustomerAvailability()
        ->orderByCustomerAvailability()
        ->with(['discount'=>function($q){
            return $q->validate();
        }])->whereIn('zone_id', $zone_id)->weekday()
        ->multiCuisine($cuisine)
        ->withcount('foods')
            ->withcount('reviews_comments')
            ->when( isset($key)  , function ($query) use($key) {
                $query->where(function($q) use($key){
                    foreach ($key as $value) {
                        $q->Where('name', 'like', "%{$value}%");
                    }
                    // 哪吒[搜索精准化]: 餐厅搜索保留「店名 + 译名 + 在售菜名(找卖某道菜的店) + 类目」;
                    // 去掉 tags / foods.nutritions / foods.allergies 的 OR(结构化元数据非顾客搜索意图, 造成无关命中)。
                    $q->orWhereHas('cuisine',function($query) use($key){
                        foreach ($key as $value) {
                            $query->where('name', 'like', "%{$value}%");
                        };
                    });
                    $q->orWhereHas('foods',function($query) use($key){
                        foreach ($key as $value) {
                            $query->where('name', 'like', "%{$value}%");
                        };
                    });
                    $q->orWhereHas('translations',function($query) use($key){
                        foreach ($key as $value) {
                            $query->where('translationable_type', 'App\Models\Restaurant')->where('key','name')->where('value', 'like', "%{$value}%");
                        };
                    });
                });
            })

            ->when($new == 1, function($query){
                return $query->latest();
            })
            ->when($popular == 1, function($query){
                return self::addOrdersCountIfMissing($query)->orderBy('orders_count', 'desc');
            })
            ->when($dine_in == 1, function($query){
                return $query->whereHas('restaurant_config', function ($query) {
                    $query->where('dine_in',1);
                });
            })

            ->when($rating == 1 , function($query){
                $query->selectSub(function ($query) {
                    $query->selectRaw('AVG(reviews.rating)')
                        ->from('reviews')
                        ->join('food', 'food.id', '=', 'reviews.food_id')
                        ->whereColumn('food.restaurant_id', 'restaurants.id')
                        ->groupBy('food.restaurant_id')
                        ->havingRaw('AVG(reviews.rating) > ?', [3]);
                }, 'avg_r')->having('avg_r', '>=', 3);
            })

            ->when($rating_5 == 1 && !($rating_4_plus  == 1 || $rating_3_plus == 1) , function($query){
                $query->selectSub(function ($query) {
                    $query->selectRaw('AVG(reviews.rating)')
                        ->from('reviews')
                        ->join('food', 'food.id', '=', 'reviews.food_id')
                        ->whereColumn('food.restaurant_id', 'restaurants.id')
                        ->groupBy('food.restaurant_id')
                        ->havingRaw('AVG(reviews.rating) > ?', [4]);
                }, 'rating_5')->having('rating_5', '>=', 5);
            })
            ->when(($rating_4_plus == 1 && !($rating_5  == 1 || $rating_3_plus == 1 ) || ($rating_4_plus == 1 && $rating_5  == 1 && $rating_3_plus != 1) ), function($query){
                $query->selectSub(function ($query) {
                    $query->selectRaw('AVG(reviews.rating)')
                        ->from('reviews')
                        ->join('food', 'food.id', '=', 'reviews.food_id')
                        ->whereColumn('food.restaurant_id', 'restaurants.id')
                        ->groupBy('food.restaurant_id')
                        ->havingRaw('AVG(reviews.rating) > ?', [4]);
                }, 'rating_4_plus')->having('rating_4_plus', '>', 4);
            })
            ->when($rating_3_plus == 1 , function($query){
                $query->selectSub(function ($query) {
                    $query->selectRaw('AVG(reviews.rating)')
                        ->from('reviews')
                        ->join('food', 'food.id', '=', 'reviews.food_id')
                        ->whereColumn('food.restaurant_id', 'restaurants.id')
                        ->groupBy('food.restaurant_id')
                        ->havingRaw('AVG(reviews.rating) > ?', [3]);
                }, 'rating_3_plus')->having('rating_3_plus', '>', 3);
            })
            ->when($discounted == 1  , function($q) {
                $q->whereHas('discount',function($query){
                    return $query->validate();
                });
            })

        ->when($category_id, function($query)use($category_id){
            $query->whereHas('foods.category', function($q)use($category_id){
                return $q->whereId($category_id)->orWhere('parent_id', $category_id);
            });
        })
        ->when(isset($sort_by) && $sort_by == 'asc' , function($query){
            return $query->orderBy('name' , 'asc');
        })
            ->when(isset($sort_by) && $sort_by == 'desc' , function($query){
            return $query->orderBy('name' , 'desc');
        })
            ->when(isset($sort_by) && $sort_by == 'distance' , function($query){
            return $query->orderBy('distance');
        })
            ->when(isset($sort_by) && $sort_by == 'rating' , function($query){
            return $query->selectSub(function ($query) {
                $query->selectRaw('AVG(reviews.rating)')
                    ->from('reviews')
                    ->join('food', 'food.id', '=', 'reviews.food_id')
                    ->whereColumn('food.restaurant_id', 'restaurants.id')
                    ->groupBy('food.restaurant_id');
            }, 'avg_rate')->orderbyDesc('avg_rate');
        })
            ->when(isset($sort_by) && $sort_by == 'high' ||  $sort_by == 'low' , function($query){
            return $query->latest();
        })

        ->active()->type($type);


        if($open == 1){
            $query = $query->having('open', '>', 0);
        }


        if($search_bar_default_status == '1') {
            $query = $query->orderByRaw("FIELD(name, ?) DESC", [$name])
                            ->orderBy('distance');
        }

        if($search_bar_default_status == '0') {
            if($search_bar_sort_by_temp_closed == 'remove'){
                $query = $query->where('active', '>', 0);
            }elseif($search_bar_sort_by_temp_closed == 'last'){
                $query = $query->orderByDesc('active');
            }

            if($search_bar_sort_by_unavailable == 'remove'){
                $query = NezhaPreorder::enabled()
                    ? $query->having('customer_availability_rank', '>', 1)
                    : $query->having('open', '>', 0);
            }
        }

        $paginator = $query
        ->applyFilters($additional_data)
        ->applySorting($additional_data['sort_by'])
        ->applyRating($additional_data['request'] ?? null)
        ->applyPriceRange($additional_data['request'] ?? null)
        ->paginate($limit, ['*'], 'page', $offset);


        return [
            'total_size' => $paginator->total(),
            'limit' => $limit,
            'offset' => $offset,
            'restaurants' => $paginator->items()
        ];
    }

    public static function get_overall_rating($reviews)
    {
        $totalRating = count($reviews);
        $rating = 0;
        foreach ($reviews as $key => $review) {
            $rating += $review->rating;
        }
        if ($totalRating == 0) {
            $overallRating = 0;
        } else {
            $overallRating = number_format($rating / $totalRating, 2);
        }

        return [$overallRating, $totalRating];
    }

    public static function get_earning_data($vendor_id)
    {
        $monthly_earning = OrderTransaction::whereMonth('created_at', date('m'))->NotRefunded()->where('vendor_id', $vendor_id)->sum('restaurant_amount');
        $weekly_earning = OrderTransaction::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->NotRefunded()->where('vendor_id', $vendor_id)->sum('restaurant_amount');
        $daily_earning = OrderTransaction::whereDate('created_at', now())->NotRefunded()->where('vendor_id', $vendor_id)->sum('restaurant_amount');

        return['monthely_earning'=>(float)$monthly_earning, 'weekly_earning'=>(float)$weekly_earning, 'daily_earning'=>(float)$daily_earning];
    }

    public static function format_export_restaurants($restaurants)
    {
        $storage = [];
        foreach($restaurants as $item)
        {
            $storage[] = [
                'id'=>$item->restaurants[0]->id,
                'ownerID'=>$item->id,
                'ownerFirstName'=>$item->f_name,
                'ownerLastName'=>$item->l_name,
                'restaurantName'=>$item->restaurants[0]->name,
                'CoverPhoto'=>$item->restaurants[0]->cover_photo,
                'logo'=>$item->restaurants[0]->logo,
                'phone'=>$item->phone,
                'email'=>$item->email,
                'latitude'=>$item->restaurants[0]->latitude,
                'longitude'=>$item->restaurants[0]->longitude,
                'zone_id'=>$item->restaurants[0]->zone_id,
                'Address'=>$item->restaurants[0]->address ?? null,
                'Slug'=> $item->restaurants[0]->slug  ?? null,
                'MinimumOrderAmount'=>$item->restaurants[0]->minimum_order,
                'Comission'=>$item->restaurants[0]->comission ?? 0,
                'Tax'=>$item->restaurants[0]->tax ?? 0,

                'DeliveryTime'=>$item->restaurants[0]->delivery_time ?? '20-30',
                'MinimumDeliveryFee'=>$item->restaurants[0]->minimum_shipping_charge ?? 0,
                'PerKmDeliveryFee'=>$item->restaurants[0]->per_km_shipping_charge ?? 0,
                'MaximumDeliveryFee'=>$item->restaurants[0]->maximum_shipping_charge ?? 0,
                'RestaurantModel'=>$item->restaurants[0]->restaurant_model,
                'ScheduleOrder'=> $item->restaurants[0]->schedule_order == 1 ? 'yes' : 'no',
                'FreeDelivery'=> $item->restaurants[0]->free_delivery == 1 ? 'yes' : 'no',
                'TakeAway'=> $item->restaurants[0]->take_away == 1 ? 'yes' : 'no',
                'Delivery'=> $item->restaurants[0]->delivery == 1 ? 'yes' : 'no',
                'Veg'=> $item->restaurants[0]->veg == 1 ? 'yes' : 'no',
                'NonVeg'=> $item->restaurants[0]->non_veg == 1 ? 'yes' : 'no',
                'OrderSubscription'=> $item->restaurants[0]->order_subscription_active == 1 ? 'yes' : 'no',
                'Status'=> $item->restaurants[0]->status == 1 ? 'active' : 'inactive',
                'FoodSection'=> $item->restaurants[0]->food_section == 1 ? 'active' : 'inactive',
                'ReviewsSection'=> $item->restaurants[0]->reviews_section == 1 ? 'active' : 'inactive',
                'SelfDeliverySystem'=> $item->restaurants[0]->self_delivery_system == 1 ? 'active' : 'inactive',
                'PosSystem'=> $item->restaurants[0]->pos_system == 1 ? 'active' : 'inactive',
                'RestaurantOpen'=> $item->restaurants[0]->active == 1 ? 'yes' : 'no',
            ];
        }

        return $storage;
    }
    public static function format_restaurant_report_export_data($restaurants)
    {
        $storage = [];
        foreach($restaurants as $key => $restaurant)
        {
            if($restaurant->count()<1)
            {
                break;
            }
            if ($restaurant->reviews_count){
                $reviews_count = $restaurant->reviews_count;
            }
            else{
                $reviews_count = 1;
            }

            $restaurant_rating = round($restaurant->reviews_sum_rating /$reviews_count,1);
            $storage[] = [
                '#'=>$key+1,
                translate('messages.restaurant') =>$restaurant->name,
                translate('messages.total_food') =>$restaurant->foods_count ?? 0,
                translate('messages.total_order') =>$restaurant->without_refund_total_orders_count ?? 0,
                translate('messages.total_order').translate('messages.amount') =>$restaurant->transaction_sum_order_amount ?? 0,
                translate('messages.total_discount_given') =>$restaurant->transaction_sum_restaurant_expense ?? 0,
                translate('messages.total_admin_commission') =>$restaurant->transaction_sum_admin_commission ?? 0,
                translate('messages.total_vat_tax') =>$restaurant->transaction_sum_tax ?? 0,
                translate('messages.average_ratings') =>$restaurant_rating,
            ];
        }
        return $storage;
    }

    private static function addOrdersCountIfMissing($query)
    {
        $columns = $query->getQuery()->columns ?? [];
        $grammar = $query->getQuery()->getGrammar();
        foreach ($columns as $col) {
            $colStr = $col instanceof \Illuminate\Contracts\Database\Query\Expression
                ? $col->getValue($grammar)
                : (string) $col;
            if (str_contains($colStr, 'orders_count')) {
                return $query;
            }
        }
        return $query->withCount('orders');
    }

    public static function recently_viewed_restaurants_data($request, $zone_id, $limit = 10, $offset = 1, $type='all',$longitude=0,$latitude=0)
    {
        // 哪吒[2026-07-02 修看过的餐厅]: recently-viewed 路由无 apiGuestCheck, $request->user 恒 null 致列表恒空;
        // 改用 auth('api')->user() 按需解析登录用户(游客→null→空列表, 与原意一致).
        $user_id = null;
        $auth_user = auth('api')->user();
        if($auth_user !== null){
            $user_id = $auth_user->id;
        }

        $paginator = Restaurant::whereHas('users',function ($query) use($user_id){
            $query->where('user_id',$user_id);
        })
        ->withOpen($longitude,$latitude)
        ->withCustomerAvailability()
        ->orderByCustomerAvailability()
        ->with(['discount'=>function($q){
            return $q->validate();
        }])->whereIn('zone_id', $zone_id)
        ->withcount('foods')
            ->withcount('reviews_comments')
        ->Active()
        ->type($type)
        ->withCount('orders')
        ->selectRaw( '(SELECT `visit_count` FROM `visitor_logs` WHERE `restaurants`.`id` = `visitor_logs`.`visitor_log_id`
            AND `user_id` = ? ORDER BY `visit_count` DESC LIMIT 1) as v_count,
            (SELECT `order_count` FROM `visitor_logs` WHERE `restaurants`.`id` = `visitor_logs`.`visitor_log_id`
            AND `user_id` = ? ORDER BY  `order_count` DESC LIMIT 1) as o_count',
            [$user_id, $user_id] )
        ->orderBy('o_count', 'desc')
        ->orderBy('v_count', 'desc')
        ->limit(50)
        ->get();
        return [
            'total_size' => $paginator->count(),
            'limit' => $limit,
            'offset' => $offset,
            'restaurants' => $paginator
        ];
    }
}
