<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaCsAssistant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 哪吒 商家助手：商家后台的 AI 问答（怎么上传/改菜品/排版/功能怎么用）。
 * 白名单护栏在 NezhaCsAssistant::merchantAssistant（绝不提 StackFood、没列的功能不瞎答）。
 */
class NezhaAssistantController extends Controller
{
    public function index()
    {
        return view('vendor-views.assistant.index');
    }

    public function ask(Request $request)
    {
        $request->validate(['question' => 'required|string|max:500']);
        $q = trim($request->question);

        // 哪吒: 单商家限速 30 次/分钟, 防连点刷 DeepSeek 烧平台 token (员工与店主共享同一桶 = 按店限)
        $vendorId = Helpers::get_vendor_id();
        $key = 'nezha-assistant-ask:' . ($vendorId ?: $request->ip());
        if (RateLimiter::tooManyAttempts($key, 30)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->with('ma_q', $q)->with('ma_a', '您问得太快啦，请 ' . $seconds . ' 秒后再试～');
        }
        RateLimiter::hit($key, 60); // 60 秒滑动窗口

        $answer = NezhaCsAssistant::merchantAssistant($q);
        return back()->with('ma_q', $q)->with('ma_a', $answer);
    }
}
