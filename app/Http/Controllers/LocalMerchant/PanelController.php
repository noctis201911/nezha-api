<?php

namespace App\Http\Controllers\LocalMerchant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 本地生活商户轻管理面 —— 面板（登录后）。
 * 全程作用域锚定登录账号的 merchant_id（EnsureLocalMerchant 已把门），绝不信任请求里的 id。
 * INC-2：仅 home 占位（证明鉴权端到端通）。INC-3 填充预览/编辑/提交待审/历史。
 */
class PanelController extends Controller
{
    private const GUARD = 'local_merchant';

    public function home(Request $request)
    {
        $account  = Auth::guard(self::GUARD)->user();
        $merchant = $account?->merchant;

        return view('local_merchant.home', compact('account', 'merchant'));
    }
}
