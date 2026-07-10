<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LocalLifeMerchantNote;
use App\Models\LocalLifeReport;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 本地生活商家页「笔记」审核（批N · 超管）。
 * 笔记审核队列：待审列表 + 通过/驳回(带理由) + 已过审可下架；举报徽标沿用 posts 手法。
 * 商家(/m)与客户(H5)提交的笔记都进这里（全复审门）。总闸 nezha_merchant_notes_status 控前台展示，审核台恒可见。
 */
class LocalLifeNoteController extends Controller
{
    public function list(Request $request)
    {
        $search       = $request->input('search');
        $statusFilter = $request->input('status'); // 空=全部，否则按 status 值筛

        $q = LocalLifeMerchantNote::with(['merchant:id,name,category', 'user:id,f_name,l_name']);

        if ($search) {
            $key = explode(' ', $search);
            $q->where(function ($qq) use ($key) {
                foreach ($key as $value) {
                    $qq->orWhere('title', 'like', "%{$value}%")
                        ->orWhere('body', 'like', "%{$value}%");
                }
            });
        }
        if ($statusFilter !== null && $statusFilter !== '') {
            $q->where('status', (int) $statusFilter);
        }

        $pendingCount = LocalLifeMerchantNote::where('status', LocalLifeMerchantNote::STATUS_PENDING)->count();
        // 待处理笔记举报总数
        $reportPendingTotal = LocalLifeReport::whereNotNull('note_id')
            ->where('status', LocalLifeReport::STATUS_PENDING)->count();
        $notesEnabled = (string) (DB::table('business_settings')->where('key', 'nezha_merchant_notes_status')->value('value')) === '1';

        $notes = $q->latest()->paginate(config('default_pagination'))->appends([
            'search' => $search,
            'status' => $statusFilter,
        ]);

        // 当前页笔记的待处理举报数 map（避免 N+1）
        $reportCounts = LocalLifeReport::select('note_id', DB::raw('count(*) as c'))
            ->where('status', LocalLifeReport::STATUS_PENDING)
            ->whereIn('note_id', collect($notes->items())->pluck('id'))
            ->groupBy('note_id')
            ->pluck('c', 'note_id');

        return view('admin-views.local-life-notes.list', compact('notes', 'search', 'statusFilter', 'pendingCount', 'reportPendingTotal', 'notesEnabled', 'reportCounts'));
    }

    // 审核通过：待审(0) -> 过审(1)，清历史驳回理由
    public function approve($id)
    {
        $note = LocalLifeMerchantNote::findOrFail($id);
        $note->status = LocalLifeMerchantNote::STATUS_APPROVED;
        $note->reject_reason = null;
        $note->save();
        Toastr::success('已通过并展示');
        return back();
    }

    // 审核驳回：-> 驳回(2)，记录理由（对提交商家在 /m 可见；客户侧 v1 不推送）
    public function reject(Request $request, $id)
    {
        $request->validate(['reject_reason' => 'nullable|string|max:255']);
        $note = LocalLifeMerchantNote::findOrFail($id);
        $note->status = LocalLifeMerchantNote::STATUS_REJECTED;
        $note->reject_reason = $request->reject_reason ?: '内容不符合笔记发布规则';
        $note->save();
        Toastr::warning('已驳回该笔记');
        return back();
    }

    // 下架已过审笔记：-> 下架(3)，该笔记所有待处理举报 -> 已处理(1)
    public function offline($id)
    {
        $note = LocalLifeMerchantNote::findOrFail($id);
        $note->status = LocalLifeMerchantNote::STATUS_OFFLINE;
        $note->save();
        LocalLifeReport::where('note_id', $id)
            ->where('status', LocalLifeReport::STATUS_PENDING)
            ->update(['status' => LocalLifeReport::STATUS_HANDLED, 'updated_at' => now()]);
        Toastr::success('已下架该笔记并标记相关举报为已处理');
        return back();
    }

    // 删除笔记（彻底移除，含图引用）
    public function destroy(Request $request)
    {
        $note = LocalLifeMerchantNote::find($request->id);
        if ($note) {
            $note->delete();
            Toastr::success('已删除');
        }
        return back();
    }

    // 笔记功能总闸（真实影响开关，默认关；前台展示受控，审核台不受影响）
    public function toggle(Request $request)
    {
        $enable = $request->boolean('enable');
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'nezha_merchant_notes_status'],
            ['value' => $enable ? '1' : '0', 'updated_at' => now()]
        );
        Toastr::success($enable ? '已开放笔记展示（前台商家页）' : '已关闭笔记展示');
        return back();
    }

    /* ============================ 举报处理 ============================ */

    // 某条笔记的举报列表
    public function reports($id)
    {
        $note = LocalLifeMerchantNote::with('merchant:id,name')->findOrFail($id);
        $reports = LocalLifeReport::where('note_id', $id)
            ->orderByRaw('FIELD(status, 0, 1, 2)')
            ->latest()
            ->paginate(config('default_pagination'));
        return view('admin-views.local-life-notes.reports', compact('note', 'reports'));
    }

    // 驳回举报：举报 -> 已驳回(2)，笔记保留
    public function dismissReport($reportId)
    {
        $report = LocalLifeReport::findOrFail($reportId);
        $report->status = LocalLifeReport::STATUS_REJECTED;
        $report->save();
        Toastr::success('已驳回该举报，笔记保留');
        return back();
    }
}
