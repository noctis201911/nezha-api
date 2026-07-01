<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Discount;
use App\Models\DiscountTier;
use App\Models\Restaurant;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 哪吒[多级满减] 商家自助配置本店"满额自动减"(多档·顾客下单自动取最省档·不叠加)。
// IDOR: restaurant_id 一律服务端 Helpers::get_restaurant_id() 取, 不信客户端。全程后端重算校验, 不信前端。
class NezhaTieredDiscountController extends Controller
{
    public function index()
    {
        $restaurantId = Helpers::get_restaurant_id();
        $restaurant   = Restaurant::find($restaurantId);
        $discount     = Discount::with(['tiers' => fn ($q) => $q->orderBy('min_purchase')])
                            ->where('restaurant_id', $restaurantId)->first();
        $tiers        = $discount ? $discount->tiers : collect();
        $featureOn    = (int) (BusinessSetting::where('key', 'nezha_tiered_discount_status')->value('value') ?? 0) === 1;
        $minOrder     = (float) ($restaurant->minimum_order ?? 0);
        $tiersData    = $tiers->map(fn ($t) => [
            'min'  => (float) $t->min_purchase,
            'type' => $t->discount_type,
            'disc' => (float) $t->discount,
            'max'  => (float) $t->max_discount,
        ])->values();

        return view('vendor-views.nezha-discount.index', compact('discount', 'tiers', 'tiersData', 'featureOn', 'minOrder', 'restaurant'));
    }

    public function save(Request $request)
    {
        $restaurantId = Helpers::get_restaurant_id();
        $restaurant   = Restaurant::find($restaurantId);
        $minOrder     = (float) ($restaurant->minimum_order ?? 0);

        $request->validate([
            'status'                => 'required|in:0,1',
            'start_date'            => 'nullable|date',
            'end_date'              => 'nullable|date|after_or_equal:start_date',
            'start_time'            => 'nullable|date_format:H:i',
            'end_time'              => 'nullable|date_format:H:i',
            'tiers'                 => 'array',
            'tiers.*.min_purchase'  => 'required|numeric|min:1',
            'tiers.*.discount_type' => 'required|in:amount,percent',
            'tiers.*.discount'      => 'required|numeric|min:1',
            'tiers.*.max_discount'  => 'nullable|numeric|min:0',
        ], [
            'tiers.*.min_purchase.required' => '每一档都要填"满多少"。',
            'tiers.*.discount.required'     => '每一档都要填减免额/百分比。',
        ]);

        $raw = $request->input('tiers', []);
        if ((int) $request->status === 1 && count($raw) === 0) {
            return back()->withInput()->withErrors(['tiers' => '开启满减至少要设一档(满 X 减 Y)。']);
        }

        // 服务端逐档校验 + 规范化(全程不信前端)
        $clean = [];
        $seen  = [];
        foreach ($raw as $t) {
            $min  = round((float) ($t['min_purchase'] ?? 0), 2);
            $type = ($t['discount_type'] ?? 'amount') === 'percent' ? 'percent' : 'amount';
            $disc = round((float) ($t['discount'] ?? 0), 2);
            $max  = round((float) ($t['max_discount'] ?? 0), 2);
            if ($min <= 0 || $disc <= 0) {
                continue;
            }
            if ($type === 'percent') {
                if ($disc > 100) {
                    return back()->withInput()->withErrors(['tiers' => '百分比减免不能超过 100%。']);
                }
            } else {
                // 固定额: 减免必须 < 门槛(否则等于/低于门槛 = 白送或负价)
                if ($disc >= $min) {
                    return back()->withInput()->withErrors(['tiers' => "固定减免额必须小于门槛（满 {$min} 减 {$disc} 不成立）。"]);
                }
                $max = 0;
            }
            if (in_array($min, $seen, true)) {
                return back()->withInput()->withErrors(['tiers' => "门槛不能重复（满 {$min} 出现多次）。"]);
            }
            $seen[]  = $min;
            $clean[] = ['min_purchase' => $min, 'discount_type' => $type, 'discount' => $disc, 'max_discount' => $max];
        }

        if ((int) $request->status === 1 && count($clean) === 0) {
            return back()->withInput()->withErrors(['tiers' => '开启满减至少要设一档有效档位。']);
        }

        // 最低档门槛 ≥ 起送价(否则满减把订单压到起送价下会被下单风控拒)
        if (count($clean) && $minOrder > 0) {
            $lowest = min(array_column($clean, 'min_purchase'));
            if ($lowest < $minOrder) {
                return back()->withInput()->withErrors(['tiers' => "最低一档门槛（满 {$lowest}）不能低于本店起送价（{$minOrder}）。"]);
            }
        }

        usort($clean, fn ($a, $b) => $a['min_purchase'] <=> $b['min_purchase']);

        DB::transaction(function () use ($restaurantId, $request, $clean) {
            $discount = Discount::firstOrNew(['restaurant_id' => $restaurantId]);
            $discount->start_date    = $request->start_date ?: null;
            $discount->end_date      = $request->end_date ?: null;
            $discount->start_time    = $request->start_time ? $request->start_time . ':00' : null;
            $discount->end_time      = $request->end_time ? $request->end_time . ':00' : null;
            $discount->status        = (int) $request->status;
            // 多级满减活动: 旧单档字段中性化(不参与 tiered 计算, 且防旧 admin 单档路径误触)
            $discount->min_purchase  = 0;
            $discount->max_discount  = 0;
            $discount->discount      = 0;
            $discount->discount_type = 'amount';
            $discount->save();

            $discount->tiers()->delete();
            foreach ($clean as $i => $t) {
                DiscountTier::create([
                    'discount_id'   => $discount->id,
                    'min_purchase'  => $t['min_purchase'],
                    'discount_type' => $t['discount_type'],
                    'discount'      => $t['discount'],
                    'max_discount'  => $t['max_discount'],
                    'sort'          => $i + 1,
                ]);
            }
        });

        Toastr::success((int) $request->status === 1 ? '多级满减已保存并开启' : '多级满减已保存（当前关闭）');
        return back();
    }
}
