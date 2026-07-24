<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Coupon;
use App\Models\CouponClaim;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\CentralLogics\CouponLogic;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CouponController extends Controller
{
    public function list(Request $request)
    {
        Helpers::getZoneIds($request);

        // 哪吒[券包 Slice3 修]: list 是公共路由(无 auth 中间件), 默认 guard 下 Auth::user()=null 把登录用户当 guest →
        //   first_order 券(NEZHA-NEW)永远算可用 → 结算自动选券会探测它经 applyCoupon 返 406 留 console 噪音。
        //   显式用 api(passport) guard 读 Bearer token, 让登录用户的 first_order/限领资格被正确判定(游客无 token 仍 null, 行为不变)。
        $customer_id = Auth::guard('api')->user()?->id;
        $zone_id = json_decode($request->header('zoneId'), true);

        $available = [];
        $unavailable = [];

        $coupons = Coupon::with('restaurant:id,name')->active()->valid()
            ->when(isset($request->restaurant_id), function ($query) use ($request) {
                $query->where(function ($q) use ($request) {
                    $q->where('restaurant_id', $request->restaurant_id)
                        ->orWhere(function ($query) use ($request) {
                            $query->where('coupon_type', 'restaurant_wise')
                                ->whereJsonContains('data', $request->restaurant_id);
                        });
                });
            })
            ->get();

        foreach ($coupons as $key => $coupon) {
            $visible = false;

            if ($coupon->coupon_type == 'restaurant_wise') {
                $temp = Restaurant::active()
                    ->whereIn('zone_id', $zone_id)
                    ->whereIn('id', json_decode($coupon->data, true))
                    ->first();
                if ($temp && (in_array("all", json_decode($coupon->customer_id, true)) || in_array($customer_id, json_decode($coupon->customer_id, true)))) {
                    // $coupon->data = $temp->name;
                    $visible = true;
                }
            } elseif ($coupon->coupon_type == 'zone_wise') {
                foreach ($zone_id as $z_id) {
                    if (in_array($z_id, json_decode($coupon->data, true))) {
                        $visible = true;
                        break;
                    }
                }
            } elseif (isset($coupon->restaurant_id)) {
                $temp = Restaurant::active()
                    ->whereIn('zone_id', $zone_id)
                    ->where('id', $coupon->restaurant_id)
                    ->exists();
                if ($temp) {
                    $visible = true;
                }
            } else {
                if (in_array("all", json_decode($coupon->customer_id, true)) || in_array($customer_id, json_decode($coupon->customer_id, true))) {
                    $visible = true;
                }
            }

            if ($visible) {
                // 哪吒[券包 Slice3 修]: restaurant_wise 券 restaurant_id FK 为 null → is_valide 第一道餐厅匹配判 404 误入 unavailable(致结算自动选券与券列表漏掉店铺专属券)。按券型取正确 restaurant_id。
                $nezha_rid = $coupon->restaurant_id;
                if ($coupon->coupon_type == 'restaurant_wise') {
                    $nezha_d = json_decode($coupon->data, true);
                    $nezha_rid = (is_array($nezha_d) && count($nezha_d)) ? $nezha_d[0] : null;
                }
                $status = CouponLogic::is_valide($coupon, $customer_id, $nezha_rid, $request->order_restaurant_id, $request->order_amount);
                if ($status === 200) {
                    $available[] = $coupon;
                } else {
                    $unavailable[] = $coupon;
                }
            }
        }

        return response()->json([
            'available' => $available,
            'unavailable' => $unavailable,
        ], 200);
    }


    public function apply(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'code' => 'required',
            'restaurant_id' => 'required',
        ]);

        if ($validator->errors()->count()>0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        try {
            $coupon = Coupon::active()->where(['code' => $request['code']])->first();
            if (isset($coupon)) {
                $staus = CouponLogic::is_valide($coupon, $request->user()->id ,$request['restaurant_id'],$request['restaurant_id'],$request['order_amount']);

                switch ($staus) {
                case 200:
                    return response()->json($coupon, 200);
                case 406:
                    return response()->json([
                        'errors' => [
                            ['code' => 'coupon', 'message' => translate('messages.coupon_usage_limit_over')]
                        ]
                    ], 406);
                case 407:
                    return response()->json([
                        'errors' => [
                            ['code' => 'coupon', 'message' => translate('messages.coupon_expire')]
                        ]
                    ], 407);
                case 408:
                    return response()->json([
                        'errors' => [
                            ['code' => 'coupon', 'message' => translate('messages.You_are_not_eligible_for_this_coupon')]
                        ]
                    ], 403);
                case 409:
                    return response()->json([
                        'errors' => [
                            ['code' => 'coupon', 'message' => translate('Minimum_purchase_amount_not_met.')]
                        ]
                    ], 403);
                default:
                    return response()->json([
                        'errors' => [
                            ['code' => 'coupon', 'message' => translate('messages.not_found')]
                        ]
                    ], 404);
                }
            } else {
                return response()->json([
                    'errors' => [
                        ['code' => 'coupon', 'message' => translate('messages.not_found')]
                    ]
                ], 404);
            }
        } catch (\Exception $e) {
            // 哪吒[安全 2026-07-24]: 原始异常串不回客户端(QueryException 会连 Host/Port/Database 与 bindings 外泄)。
            \Illuminate\Support\Facades\Log::warning('nz_coupon_apply_failed', ['ex' => get_class($e), 'code' => $e->getCode()]);
            return response()->json(['errors' => '出现错误，请重试'], 403);
        }
    }

    public function restaurant_wise_coupon(Request $request){
        Helpers::getZoneIds($request);
        if (!$request->restaurant_id) {
            $errors = [];
            array_push($errors, ['code' => 'restaurant_id', 'message' => translate('messages.restaurant_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }
        $zone_id= json_decode($request->header('zoneId'), true);
        $data = [];
            $coupons = Coupon::with('restaurant:id,name')
            ->when(isset($request->restaurant_id), function($query) use($request){
                $query->where('restaurant_id',$request->restaurant_id)
                        ->orWhere(function($query)use($request){
                            $query->where('coupon_type','restaurant_wise')->whereJsonContains('data',$request->restaurant_id);
                        });
            })
            ->active()->whereDate('expire_date', '>=', date('Y-m-d'))->whereDate('start_date', '<=', date('Y-m-d'))
            ->get();
            foreach($coupons as $key=>$coupon)
            {
                if($coupon->coupon_type == 'restaurant_wise')
                {
                    $temp = Restaurant::active()->whereIn('zone_id', $zone_id)->whereIn('id', json_decode($coupon->data, true))->first();
                    if($temp && (in_array("all", json_decode($coupon->customer_id, true)) ))
                    {
                        $coupon->data = $temp->name;
                        $data[] = $coupon;
                    }
                }
                else if($coupon->coupon_type == 'zone_wise')
                {
                    foreach($zone_id as $z_id) {
                        if(in_array($z_id, json_decode($coupon->data,true)))
                        {
                            $data[] = $coupon;
                            break;
                        }
                    }
                }
                else if(isset($coupon->restaurant_id) )
                {
                    $temp = Restaurant::active()->where('id', $coupon->restaurant_id)->exists();
                    if($temp){
                        $data[] = $coupon;
                    }
                }
            }
            return response()->json($data, 200);
    }

    // 哪吒[券包 2026-06-25 Slice2]: 领取券到券包。领取不设门槛(领了进券包, 没领符合条件结算也能用),
    // 仅拦"过期/无资格/不符店", 不拦"已用满"(已用满仍可留在券包)。唯一索引 user+coupon + firstOrCreate = 防重复领。
    public function claim(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'coupon_id' => 'required_without:code',
            'code'      => 'required_without:coupon_id',
        ]);
        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $user_id = $request->user()->id;

        $coupon = Coupon::active()
            ->when($request->coupon_id, function ($q) use ($request) {
                $q->where('id', $request->coupon_id);
            })
            ->when(!$request->coupon_id && $request->code, function ($q) use ($request) {
                $q->where('code', $request->code);
            })
            ->first();

        if (!$coupon) {
            return response()->json(['errors' => [['code' => 'coupon', 'message' => translate('messages.not_found')]]], 404);
        }

        // 复用全系统一致的有效性判定(order_amount=null 不卡最低消费)
        $status = CouponLogic::is_valide($coupon, $user_id, $coupon->restaurant_id, $coupon->restaurant_id, null);
        if (in_array($status, [404, 407, 408])) {
            $message = $status == 407
                ? translate('messages.coupon_expire')
                : translate('messages.You_are_not_eligible_for_this_coupon');
            return response()->json(['errors' => [['code' => 'coupon', 'message' => $message]]], $status == 407 ? 407 : 403);
        }

        try {
            $claim = CouponClaim::firstOrCreate(
                ['user_id' => $user_id, 'coupon_id' => $coupon->id],
                ['claimed_at' => now()]
            );
            $already = !$claim->wasRecentlyCreated;
        } catch (\Illuminate\Database\QueryException $e) {
            // 并发狂点撞唯一索引 → 视为已领取
            $already = true;
        }

        return response()->json([
            'success'         => true,
            'already_claimed' => $already,
            'coupon'          => $coupon,
        ], 200);
    }

    // 哪吒[券包 2026-06-25 Slice2]: 我的券包。返回已领取的券, 附 redeem_status(available/used_up/expired/unavailable)。
    public function myCoupons(Request $request)
    {
        $user_id = $request->user()->id;

        $claims = CouponClaim::with(['coupon.restaurant:id,name'])
            ->where('user_id', $user_id)
            ->orderByDesc('claimed_at')
            ->get();

        $available = [];
        $unavailable = [];

        foreach ($claims as $claim) {
            $coupon = $claim->coupon;
            if (!$coupon) {
                continue;
            }
            // 哪吒[券包 Slice3 修]: restaurant_wise 券的 restaurant_id FK 为 null(餐厅在 data 里); 传 null 致 is_valide 餐厅匹配判 404 误显"暂不可用"。按券型取正确 restaurant_id。
            $nezha_rid = $coupon->restaurant_id;
            if ($coupon->coupon_type == 'restaurant_wise') {
                $nezha_d = json_decode($coupon->data, true);
                $nezha_rid = (is_array($nezha_d) && count($nezha_d)) ? $nezha_d[0] : null;
            }
            $status = CouponLogic::is_valide($coupon, $user_id, $nezha_rid, $nezha_rid, null);
            $coupon->redeem_status = $status == 200 ? 'available' : ($status == 406 ? 'used_up' : ($status == 407 ? 'expired' : 'unavailable'));
            $coupon->claimed_at = $claim->claimed_at;

            if ($status == 200) {
                $available[] = $coupon;
            } else {
                $unavailable[] = $coupon;
            }
        }

        return response()->json(['available' => $available, 'unavailable' => $unavailable], 200);
    }
}
