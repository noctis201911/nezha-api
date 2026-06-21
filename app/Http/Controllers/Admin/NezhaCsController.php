<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\NezhaCsAssistant;
use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒 AI 在线客服「小哪」后台管理。
 *  - index        : 开关 + FAQ + 模型 + 待处理工单
 *  - saveSettings : 保存总开关/商家转达开关/FAQ/模型（写 business_settings + 清缓存）
 *  - closeTicket  : 标记工单处理完成
 */
class NezhaCsController extends Controller
{
    public function index()
    {
        $get = function ($k, $d = '') {
            return BusinessSetting::where('key', $k)->value('value') ?? $d;
        };
        $status = (int) $get('nezha_cs_ai_status', '0');
        $relay = (int) $get('nezha_cs_merchant_relay_status', '0');
        $faq = $get('nezha_cs_faq', '') ?: NezhaCsAssistant::defaultFaq();
        $model = $get('nezha_cs_ai_model', 'deepseek-chat');
        $hasKey = (bool) $get('nezha_cs_ai_api_key', '');

        $tickets = DB::table('nezha_cs_tickets')
            ->where('status', 'open')
            ->orderByDesc('id')
            ->paginate(30);

        $negFeedback = DB::table('nezha_cs_feedback')
            ->where('sentiment', 'negative')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
        $fbPos = (int) DB::table('nezha_cs_feedback')->where('sentiment', 'positive')->count();
        $fbNeg = (int) DB::table('nezha_cs_feedback')->where('sentiment', 'negative')->count();

        return view('admin-views.nezha-cs.index', compact('status', 'relay', 'faq', 'model', 'hasKey', 'tickets', 'negFeedback', 'fbPos', 'fbNeg'));
    }

    public function saveSettings(Request $request)
    {
        $request->validate([
            'nezha_cs_ai_status' => 'required|in:0,1',
            'nezha_cs_merchant_relay_status' => 'required|in:0,1',
            'nezha_cs_faq' => 'nullable|string|max:6000',
            'nezha_cs_ai_model' => 'nullable|string|max:64',
        ]);

        foreach ([
            'nezha_cs_ai_status' => (string) ((int) $request->nezha_cs_ai_status),
            'nezha_cs_merchant_relay_status' => (string) ((int) $request->nezha_cs_merchant_relay_status),
            'nezha_cs_faq' => (string) ($request->nezha_cs_faq ?? ''),
            'nezha_cs_ai_model' => (string) ($request->nezha_cs_ai_model ?: 'deepseek-chat'),
        ] as $k => $v) {
            BusinessSetting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Cache::forget('business_settings_all_data');

        Toastr::success((int) $request->nezha_cs_ai_status === 1
            ? translate('已保存。AI 在线客服已开启，顾客发客服消息会自动回复/转接。')
            : translate('已保存。AI 在线客服已关闭，顾客客服消息不再自动处理。'));
        return back();
    }

    public function closeTicket(Request $request, $id)
    {
        DB::table('nezha_cs_tickets')->where('id', $id)->update([
            'status' => 'closed',
            'updated_at' => now(),
        ]);
        Toastr::success(translate('工单已标记为处理完成。'));
        return back();
    }
}
