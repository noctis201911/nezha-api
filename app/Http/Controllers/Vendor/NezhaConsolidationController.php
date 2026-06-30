<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\CentralLogics\Helpers;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Brian2694\Toastr\Facades\Toastr;

/**
 * 哪吒 平台集运申报 — 商家端需求登记表。
 * 商家填写进货需求意向(品类/货量/频率/物流成本/推荐服务方/建议)，平台据此评估货量、找货代谈价。
 * 仅采集意向数据，平台不碰钱(与 L1-1 无关)。一商家一份，可随时回来更新。
 */
class NezhaConsolidationController extends Controller
{
    public function index()
    {
        $vendorId = Helpers::get_vendor_id();
        $survey = DB::table('nezha_consolidation_surveys')->where('vendor_id', $vendorId)->first();

        return view('vendor-views.nezha-consolidation.index', compact('survey'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'intent'     => 'required|in:yes,maybe,no',
            'categories' => 'required|array|min:1',
            'frequency'  => 'required|string|max:16',
        ], [
            'intent.required'     => translate('请选择参与意向'),
            'categories.required' => translate('请至少选择一个货物品类'),
            'frequency.required'  => translate('请选择进货频率'),
        ]);

        $vendorId = Helpers::get_vendor_id();
        $restaurant = Restaurant::where('vendor_id', $vendorId)->first();

        // 多选清洗: 去空、限长、最多 20 项
        $clean = function ($arr) {
            if (!is_array($arr)) {
                return [];
            }
            $out = array_values(array_filter(array_map(
                fn ($x) => mb_substr(trim((string) $x), 0, 64),
                $arr
            ), fn ($x) => $x !== ''));
            return array_slice($out, 0, 20);
        };
        $str = fn ($v, $max) => ($v === null || $v === '') ? null : mb_substr(trim((string) $v), 0, $max);

        // 货量单位由商家填了哪一行推导(体积/重量/箱数任填一种)
        $volM3 = in_array($request->volume_m3, ['<1', '1-3', '3-5', '5-10', '>10']) ? $request->volume_m3 : null;
        $volKg = in_array($request->weight_kg, ['<100', '100-500', '500-1000', '>1000']) ? $request->weight_kg : null;
        $box = $str($request->box_count, 32);
        $unit = $volM3 ? 'm3' : ($volKg ? 'kg' : ($box ? 'box' : null));

        $data = [
            'vendor_id'           => $vendorId,
            'restaurant_id'       => $restaurant->id ?? null,
            'intent'              => $request->intent,
            'current_channels'    => json_encode($clean($request->current_channels), JSON_UNESCAPED_UNICODE),
            'pain_points'         => json_encode($clean($request->pain_points), JSON_UNESCAPED_UNICODE),
            'categories'          => json_encode($clean($request->categories), JSON_UNESCAPED_UNICODE),
            'category_other'      => $str($request->category_other, 255),
            'category_examples'   => $str($request->category_examples, 255),
            'times_3m'            => in_array($request->times_3m, ['0', '1-2', '3-5', '6+']) ? $request->times_3m : null,
            'volume_unit'         => $unit,
            'volume_m3'           => $volM3,
            'weight_kg'           => $volKg,
            'box_count'           => $box,
            'box_size'            => $str($request->box_size, 64),
            'frequency'           => in_array($request->frequency, ['weekly', 'biweekly', 'monthly', 'quarterly', 'irregular']) ? $request->frequency : null,
            'lead_time'           => in_array($request->lead_time, ['fast', 'mid', 'slow']) ? $request->lead_time : null,
            'current_cost'        => $str($request->current_cost, 160),
            'expected_saving'     => in_array($request->expected_saving, ['little', 's15', 's30']) ? $request->expected_saving : null,
            'refer_provider'      => in_array($request->refer_provider, ['yes', 'no']) ? $request->refer_provider : null,
            'refer_provider_info' => $str($request->refer_provider_info, 255),
            'suggestion'          => $str($request->suggestion, 2000),
            'updated_at'          => now(),
        ];

        $exists = DB::table('nezha_consolidation_surveys')->where('vendor_id', $vendorId)->exists();
        if ($exists) {
            DB::table('nezha_consolidation_surveys')->where('vendor_id', $vendorId)->update($data);
        } else {
            $data['created_at'] = now();
            DB::table('nezha_consolidation_surveys')->insert($data);
        }

        Toastr::success(translate('已提交，感谢您的支持！符合条件后平台会有工作人员与您联系。'));
        return back();
    }
}
