<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\NezhaCsAssistant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

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
        $answer = NezhaCsAssistant::merchantAssistant($q);
        return back()->with('ma_q', $q)->with('ma_a', $answer);
    }
}
