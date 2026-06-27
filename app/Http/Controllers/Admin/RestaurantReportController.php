<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RestaurantReport;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;

/**
 * 后台: 顾客举报商家列表 + 处置(标记已处理 / 驳回)。
 * 让举报不进黑洞: 落库即可在后台「举报商家」菜单看到、筛选、处置。
 * 仅记录与人工核实, 不自动惩罚商家、不碰任何资金(L1-1)。
 */
class RestaurantReportController extends Controller
{
    public function list(Request $request)
    {
        $statusFilter = $request->input('status'); // 空 = 全部
        $search = $request->input('search');

        $q = RestaurantReport::with('restaurant')->latest();

        if ($statusFilter !== null && $statusFilter !== '') {
            $q->where('status', (int) $statusFilter);
        }
        if ($search) {
            $q->where(function ($qq) use ($search) {
                $qq->where('reason', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('restaurant_id', $search);
            });
        }

        $pendingTotal = RestaurantReport::where('status', RestaurantReport::STATUS_PENDING)->count();
        $reports = $q->paginate(config('default_pagination'))->appends([
            'status' => $statusFilter,
            'search' => $search,
        ]);

        return view('admin-views.restaurant-report.list', compact('reports', 'statusFilter', 'search', 'pendingTotal'));
    }

    public function updateStatus(Request $request, $id)
    {
        $report = RestaurantReport::findOrFail($id);
        $status = (int) $request->input('status');
        $allowed = [
            RestaurantReport::STATUS_PENDING,
            RestaurantReport::STATUS_HANDLED,
            RestaurantReport::STATUS_REJECTED,
        ];
        if (!in_array($status, $allowed, true)) {
            Toastr::error('无效的状态');
            return back();
        }
        $report->status = $status;
        $report->save();
        Toastr::success('举报状态已更新');
        return back();
    }
}
