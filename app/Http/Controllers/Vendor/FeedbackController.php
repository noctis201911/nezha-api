<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\VendorFeedback;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * 商家端「向平台反馈/求助」(方案B)。
 *  - index: 提交表单 + 自己历史(含平台回复)
 *  - store: 提交一条反馈, 通知超管(Telegram)
 * 不碰资金/佣金逻辑, 纯收集渠道(L3)。
 */
class FeedbackController extends Controller
{
    public function index()
    {
        $vendor = Helpers::get_vendor_data();
        $list = VendorFeedback::where('vendor_id', $vendor->id)->orderByDesc('id')->paginate(15);
        return view('vendor-views.feedback.index', compact('list'));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:commission,settlement,feature,other',
            'subject' => 'required|string|max:150',
            'description' => 'required|string|max:4000',
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $vendor = Helpers::get_vendor_data();
        $restaurant = $vendor->restaurants->first() ?? null;

        $fb = VendorFeedback::create([
            'vendor_id' => $vendor->id,
            'restaurant_id' => $restaurant?->id,
            'type' => $request->type,
            'subject' => $request->subject,
            'description' => $request->description,
            'status' => 'open',
        ]);

        // 通知超管(失败不影响提交)
        try {
            $name = $restaurant?->name ?? ('商家#' . $vendor->id);
            $typeLabel = VendorFeedback::TYPE_LABELS[$request->type] ?? $request->type;
            Helpers::sendTelegramToAdmin("🛎️ 新商家反馈 #{$fb->id}\n商家: {$name}\n类型: {$typeLabel}\n主题: {$request->subject}\n(后台「商家反馈」可查看并处理)");
        } catch (\Throwable $e) {
        }

        Toastr::success('反馈已提交，平台会尽快处理。处理进度和回复会显示在下方列表里。');
        return back();
    }
}
