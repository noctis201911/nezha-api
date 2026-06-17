<?php

namespace App\Http\Controllers\Api\V1\Vendor;

use App\Models\Food;
use App\Models\Category;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;

class VendorCategoryController extends Controller
{
    public function get_categories(Request $request)
    {
        try {

            $name= $request->query('name');
            $search = $request->query('search');
            $categories = Category::when($search, function($query)use($search){
                return $query->where('name', 'like', "%{$search}%");
            })->withCount(['products','childes'])->with(['childes' => function($query)  {
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
            }) ->orderBy('priority','desc') ->get();

                // N+1 fix: 原逐分类各发 count()+sum() 两条查询. 改为预取子分类映射 + 一条 groupBy 分组查询.
                $__nz_rid = $request['vendor']->restaurants[0]->id;
                $__nz_topIds = collect($categories)->pluck('id')->all();
                $__nz_childMap = [];
                foreach (Category::whereIn('parent_id', $__nz_topIds)->get(['id', 'parent_id']) as $__nz_c) {
                    $__nz_childMap[$__nz_c->parent_id][] = $__nz_c->id;
                }
                $__nz_allCatIds = $__nz_topIds;
                foreach ($__nz_childMap as $__nz_kids) { $__nz_allCatIds = array_merge($__nz_allCatIds, $__nz_kids); }
                $__nz_allCatIds = array_values(array_unique($__nz_allCatIds));
                $__nz_agg = Food::active()
                    ->where('restaurant_id', $__nz_rid)
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
                return response()->json($categories, 200);
        } catch (\Exception $e) {
            return response()->json([$e->getMessage()]);
        }
    }



    public function get_childes(Request $request,$id)
    {
        try {
            $categories = Category::when(is_numeric($id),function ($qurey) use($id){
                $qurey->where(['parent_id' => $id,'status'=>1]);
                })
                ->when(!is_numeric($id),function ($qurey) use($id){
                    $qurey->where(['slug' => $id,'status'=>1]);
                })
            ->orderBy('priority','desc')->get();
            return response()->json(Helpers::category_data_formatting($categories, true), 200);
        } catch (\Exception $e) {
            return response()->json([$e->getMessage()], 200);
        }
    }

    public function category_wise_products(Request $request)
    {
        $limit = $request->limit?$request->limit:25;
        $offset = $request->offset?$request->offset:1;
        $category_id = $request->category_id ? $request->category_id : 0;

        $type = $request->query('type', 'all');

        $paginator = Food::type($type)->where('restaurant_id', $request['vendor']->restaurants[0]->id);
        if($request->sub_category == 1){
            $paginator = $paginator->where('category_id', $category_id);
        } else {
            $paginator = $paginator->whereRaw(
                "JSON_CONTAINS(category_ids, JSON_OBJECT('id', CAST(? AS CHAR), 'position', ?), '$')",
                [$category_id, 1]
            );
        }
        $paginator = $paginator->latest()->paginate($limit, ['*'], 'page', $offset);
        $data = [
            'total_size' => $paginator->total(),
            'limit' => $limit,
            'offset' => $offset,
            'products' => Helpers::product_data_formatting(data:$paginator->items(), multi_data: true, trans:true, local:app()->getLocale())
        ];

        return response()->json($data, 200);
    }
}
