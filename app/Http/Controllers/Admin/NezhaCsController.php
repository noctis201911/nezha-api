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
        $humanStart = (int) $get('nezha_cs_human_hours_start', '9');
        $humanEnd = (int) $get('nezha_cs_human_hours_end', '18');
        $welcome = $get('nezha_cs_welcome', '') ?: NezhaCsAssistant::defaultWelcome();
        $handoffChat = (string) $get('nezha_cs_handoff_chat_id', '');

        $tickets = DB::table('nezha_cs_tickets')
            ->where('status', 'open')
            ->orderByDesc('id')
            ->paginate(30);

        $feedback = DB::table('nezha_cs_feedback')
            ->orderByDesc('id')
            ->limit(60)
            ->get();
        $fbPos = (int) DB::table('nezha_cs_feedback')->where('sentiment', 'positive')->count();
        $fbNeg = (int) DB::table('nezha_cs_feedback')->where('sentiment', 'negative')->count();

        // 反馈日报(方案A): 总开关 + 最近 14 份历史日报。表可能尚未迁移, 防御性判断。
        $digestStatus = (int) $get('nezha_feedback_digest_status', '0');
        $digests = \Illuminate\Support\Facades\Schema::hasTable('nezha_feedback_digests')
            ? DB::table('nezha_feedback_digests')->orderByDesc('digest_date')->orderByDesc('id')->limit(14)->get()
            : collect();

        return view('admin-views.nezha-cs.index', compact('status', 'relay', 'faq', 'model', 'hasKey', 'tickets', 'feedback', 'fbPos', 'fbNeg', 'digestStatus', 'digests', 'humanStart', 'humanEnd', 'welcome', 'handoffChat'));
    }

    /** 运营数据助手：超管在后台问小哪客服运营情况。 */
    public function ask(Request $request)
    {
        $request->validate(['question' => 'required|string|max:500']);
        $q = trim($request->question);
        $answer = \App\CentralLogics\NezhaCsAssistant::adminAssistant($q);
        return back()->with('cs_admin_q', $q)->with('cs_admin_a', $answer);
    }

    public function saveSettings(Request $request)
    {
        $request->validate([
            'nezha_cs_ai_status' => 'required|in:0,1',
            'nezha_cs_merchant_relay_status' => 'required|in:0,1',
            'nezha_cs_faq' => 'nullable|string|max:6000',
            'nezha_cs_ai_model' => 'nullable|string|max:64',
            'nezha_feedback_digest_status' => 'nullable|in:0,1',
            'nezha_cs_human_hours_start' => 'nullable|integer|min:0|max:23',
            'nezha_cs_human_hours_end' => 'nullable|integer|min:1|max:24',
            'nezha_cs_welcome' => 'nullable|string|max:2000',
            'nezha_cs_handoff_chat_id' => 'nullable|string|max:64',
        ]);

        foreach ([
            'nezha_cs_ai_status' => (string) ((int) $request->nezha_cs_ai_status),
            'nezha_cs_merchant_relay_status' => (string) ((int) $request->nezha_cs_merchant_relay_status),
            'nezha_cs_faq' => (string) ($request->nezha_cs_faq ?? ''),
            'nezha_cs_ai_model' => (string) ($request->nezha_cs_ai_model ?: 'deepseek-chat'),
            'nezha_feedback_digest_status' => (string) ((int) ($request->nezha_feedback_digest_status ?? 0)),
            'nezha_cs_human_hours_start' => (string) ((int) ($request->nezha_cs_human_hours_start ?? 9)),
            'nezha_cs_human_hours_end' => (string) ((int) ($request->nezha_cs_human_hours_end ?? 18)),
            'nezha_cs_welcome' => (string) ($request->nezha_cs_welcome ?? ''),
            'nezha_cs_handoff_chat_id' => (string) ($request->nezha_cs_handoff_chat_id ?? ''),
        ] as $k => $v) {
            BusinessSetting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Cache::forget('business_settings_all_data');

        Toastr::success((int) $request->nezha_cs_ai_status === 1
            ? '已保存。AI 在线客服已开启，顾客发客服消息会自动回复/转接。'
            : '已保存。AI 在线客服已关闭，顾客客服消息不再自动处理。');
        return back();
    }

    public function closeTicket(Request $request, $id)
    {
        DB::table('nezha_cs_tickets')->where('id', $id)->update([
            'status' => 'closed',
            'updated_at' => now(),
        ]);
        Toastr::success('工单已标记为处理完成。');
        return back();
    }
}
