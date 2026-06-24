<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\VendorFeedback;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;

/**
 * 超管端「商家反馈」管理 (方案B)。
 *  - index:   按状态分页查看商家提交的反馈
 *  - resolve: 标状态(待处理/处理中/已处理)+ 写回复(商家可见), 已处理时通知商家(Telegram)
 * 权限复用 module:nezha_cs(同一运营角色)。不碰资金/佣金逻辑。
 */
class VendorFeedbackController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'open');
        $q = VendorFeedback::with('restaurant')->orderByDesc('id');
        if (in_array($status, ['open', 'in_progress', 'resolved'], true)) {
            $q->where('status', $status);
        }
        $list = $q->paginate(20)->appends(['status' => $status]);

        $counts = [
            'open' => VendorFeedback::where('status', 'open')->count(),
            'in_progress' => VendorFeedback::where('status', 'in_progress')->count(),
            'resolved' => VendorFeedback::where('status', 'resolved')->count(),
        ];

        return view('admin-views.vendor-feedback.index', compact('list', 'status', 'counts'));
    }

    public function resolve(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:open,in_progress,resolved',
            'admin_note' => 'nullable|string|max:2000',
        ]);

        $fb = VendorFeedback::findOrFail($id);
        $fb->status = $request->status;
        $fb->admin_note = $request->admin_note;
        if ($request->status === 'resolved' && !$fb->resolved_at) {
            $fb->resolved_at = now();
        }
        $fb->save();

        // 通知商家(让商家"被听到": Telegram, 失败不影响)
        try {
            if ($fb->restaurant) {
                $statusLabel = VendorFeedback::STATUS_LABELS[$fb->status] ?? $fb->status;
                $msg = "📩 平台已回复你的反馈 #{$fb->id}（{$fb->subject}）\n状态: {$statusLabel}";
                if ($fb->admin_note) {
                    $msg .= "\n回复: {$fb->admin_note}";
                }
                $msg .= "\n(可在商家后台「问题反馈」查看)";
                Helpers::sendTelegramToRestaurant($fb->restaurant, $msg);
            }
        } catch (\Throwable $e) {
        }

        Toastr::success('已更新该反馈的状态/回复。');
        return back();
    }
}
