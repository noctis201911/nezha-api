<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Food;
use App\Models\Category;
use App\Models\PriorityList;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\Models\BusinessSetting;
use App\CentralLogics\CategoryLogic;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class CategoryController extends Controller
{
    public function get_categories(Request $request)
    {
        try {
            $category_list_default_status = BusinessSetting::where('key', 'category_list_default_status')->first()?->value ?? 1;
            $category_list_sort_by_general = PriorityList::where('name', 'category_list_sort_by_general')->where('type','general')->first()?->value ?? '';
            Helpers::getZoneIds($request);
            $zone_id=  $request->header('zoneId') ? json_decode($request->header('zoneId'), true) : [];
            $name= $request->query('name');
            $categories = Category::withCount(['products','childes'])->with(['childes' => function($query)  {
                $query->withCount(['products','childes']);
            }])
            ->where(['position'=>0,'status'=>1])

            ->when($name, function($q)use($name){
                $key = explode(' ', $name);
                $q->where(function($q)use($key){
                    foreach ($key as $value){
                        $q->orWhere('name', 'like', '%'.$value.'%')->orWhere('slug', 'like', '%'.$value.'%');
                    }
                    return $q;
                });
            })

            ->when($category_list_default_status  == 1 , function ($query) {
                $query->orderBy('priority','desc');
            })


            ->when($category_list_default_status  != 1 &&  $category_list_sort_by_general == 'latest', function ($query) {
                $query->latest();
            })
            ->when($category_list_default_status  != 1 &&  $category_list_sort_by_general == 'oldest', function ($query) {
                $query->oldest();
            })
            ->when($category_list_default_status  != 1 &&  $category_list_sort_by_general == 'a_to_z', function ($query) {
                $query->orderby('name');
            })
            ->when($category_list_default_status  != 1 &&  $category_list_sort_by_general == 'z_to_a', function ($query) {
                $query->orderby('name','desc');
            })


            ->get();



            if(count($zone_id) > 0){
                // N+1 fix: 原逐分类各发 count()+sum() 两条查询(分类多则线性爆). 改为预取子分类映射 + 一条 groupBy 分组查询.
                $__nz_topIds = collect($categories)->pluck('id')->all();
                $__nz_childMap = [];
                foreach (Category::whereIn('parent_id', $__nz_topIds)->get(['id', 'parent_id']) as $__nz_c) {
                    $__nz_childMap[$__nz_c->parent_id][] = $__nz_c->id;
                }
                $__nz_allCatIds = $__nz_topIds;
                foreach ($__nz_childMap as $__nz_kids) { $__nz_allCatIds = array_merge($__nz_allCatIds, $__nz_kids); }
                $__nz_allCatIds = array_values(array_unique($__nz_allCatIds));
                $__nz_agg = Food::active()
                    ->whereHas('restaurant', function ($query) use ($zone_id) {
                        $query->whereIn('zone_id', $zone_id);
                    })
                    ->whereIn('category_id', $__nz_allCatIds)
                    ->selectRaw('category_id, count(*) as cnt, coalesce(sum(order_count), 0) as ord')
                    ->groupBy('category_id')
                    ->get()
                    ->keyBy('category_id');
                foreach ($categories as $category) {
                    $__nz_ids = array_merge([$category->id], $__nz_childMap[$category->id] ?? []);
                    $__nz_pc = 0; $__nz_oc = 0;
                    foreach ($__nz_ids as $__nz_cid) {
                        if ($__nz_row = $__nz_agg->get($__nz_cid)) { $__nz_pc += (int) $__nz_row->cnt; $__nz_oc += (int) $__nz_row->ord; }
                    }
                    $category['products_count'] = $__nz_pc;
                    $category['order_count'] = $__nz_oc ? (string) $__nz_oc : 0;
                }
                if($category_list_default_status  != 1 &&  $category_list_sort_by_general == 'order_count'){

                    $categories = $categories->sortByDesc('order_count')->values()->all();
                }


                return response()->json($categories, 200);
            }

            return response()->json(Helpers::category_data_formatting($categories, true), 200);
        } catch (\Exception $e) {
            // 哪吒[安全 2026-07-23]: 原始异常串不回客户端。/api/v1/categories 无鉴权中间件, 游客可直接打。
            // 保持原响应形状(数组, 无显式状态码即 200)不变。
            \Illuminate\Support\Facades\Log::warning('nz_categories_failed', [
                'ex' => get_class($e),
                'code' => $e->getCode(),
            ]);
            return response()->json(['出现错误，请重试']);
        }
    }

    public function get_childes($id)
    {
        $categoryId = Category::when(!is_numeric($id),function ($qurey) use($id){
                    $qurey->where('slug',$id);
                },function ($qurey) use($id){
                    $qurey->where('id',$id);
                })->first()?->id;
        try {
            $categories = Category::where(['parent_id' => $categoryId,'status'=>1])->orderBy('priority','desc')->get();
            return response()->json(Helpers::category_data_formatting($categories, true), 200);
        } catch (\Exception $e) {
            // 哪吒[安全 2026-07-23]: 同上判例。/api/v1/categories/childes/{id} 无鉴权中间件, 游客可直接打。
            \Illuminate\Support\Facades\Log::warning('nz_category_childes_failed', [
                'ex' => get_class($e),
                'code' => $e->getCode(),
            ]);
            return response()->json(['出现错误，请重试'], 200);
        }
    }

    public function get_products($id, Request $request)
    {
        Helpers::getZoneIds($request);
        $validator = Validator::make($request->all(), [
            'limit' => 'required',
            'offset' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $additional_data=[
            'category_id' =>  $id,
            'zone_id' => json_decode($request->header('zoneId'), true),
            'limit' =>  $request['limit'] ?? 25,
            'offset' =>  $request['offset'] ?? 1,
            'type' =>  $request->query('type', 'all') ?? 'all',
            'veg' =>  $request->veg ?? 0,
            'non_veg' =>  $request->non_veg ?? 0,
            'new' =>  $request->new ?? 0,
            'avg_rating' => $request->avg_rating ?? 0,
            'top_rated' =>  $request->top_rated ?? 0,
            'request' => $request,
            'longitude' =>$request->header('longitude') ?? 0,
            'latitude' => $request->header('latitude') ?? 0,
            'sort_by' => $request->query('sort_by') ?? 'default',
            'filter_by' => $request->query('filter_by', []) ?? [],
            'order_type' => $request->query('order_type',[]) ?? [],
        ];


        $data = CategoryLogic::products($additional_data);
        $data['products'] = Helpers::product_data_formatting($data['products'] , true, false, app()->getLocale());

        if($request->user !== null){
            $customer_id = $request->user->id;
            Helpers::visitor_log('category',$customer_id,$id,false);
        }

        return response()->json($data, 200);
    }


    public function get_restaurants($id, Request $request)
    {
        Helpers::getZoneIds($request);
        $validator = Validator::make($request->all(), [
            'limit' => 'required',
            'offset' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $additional_data=[
            'category_id' =>  $id,
            'zone_id' => json_decode($request->header('zoneId'), true),
            'limit' =>  $request['limit'] ?? 25,
            'offset' =>  $request['offset'] ?? 1,
            'type' =>  $request->query('type', 'all') ?? 'all',
            'longitude' =>$request->header('longitude') ?? 0,
            'latitude' => $request->header('latitude') ?? 0,
            'veg' =>  $request->veg ?? 0,
            'non_veg' =>  $request->non_veg ?? 0,
            'new' =>  $request->new ?? 0,
            'avg_rating' => $request->avg_rating ?? 0,
            'top_rated' =>  $request->top_rated ?? 0,
            'request' => $request,
            'sort_by' => $request->query('sort_by') ?? 'default',
            'filter_by' => $request->query('filter_by', []) ?? [],
            'cuisine' => $request->query('cuisine', []) ?? [],
            'order_type' => $request->query('order_type',[]) ?? [],
        ];


        $data = CategoryLogic::restaurants($additional_data);
        $data['restaurants'] = Helpers::restaurant_data_formatting($data['restaurants'] , true);

        return response()->json($data, 200);
    }



    public function get_all_products($id,Request $request)
    {
        try {
            return response()->json(Helpers::product_data_formatting(CategoryLogic::all_products($id), true, false, app()->getLocale()), 200);
        } catch (\Exception $e) {
            return response()->json([], 200);
        }
    }
}
