<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;

/**
 * SEC-3 后台危险操作审计日志「只读查看页」。
 *
 * 表 admin_audit_logs 由 AdminAuditLog::record() 在 7 处写入
 * (改风控阈值 / 角色权限增删改 / 员工增删改)。
 *
 * 🔴 本控制器只提供 index 只读列表(分页 + action/时间筛选)——
 *    不提供任何 写 / 删 / 改 入口, 保 append-only 审计不可篡改。
 * 路由挂 module:audit: 该模块名不在任何自定义角色的 modules 里,
 *    故仅超管(role_id=1, 在 module_permission_check 里恒 bypass)可见。
 */
class AdminAuditLogController extends Controller
{
    /** 审计日志只读列表(分页 + action/时间筛选) */
    public function index(Request $request)
    {
        $action = $request->get('action', 'all');
        $from   = trim((string) $request->get('from', ''));
        $to     = trim((string) $request->get('to', ''));

        $query = AdminAuditLog::orderBy('id', 'desc');

        if ($action !== 'all' && $action !== '') {
            $query->where('action', $action);
        }
        if ($from !== '') {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to !== '') {
            $query->whereDate('created_at', '<=', $to);
        }

        $logs = $query->paginate(30)->appends($request->only(['action', 'from', 'to']));

        // 筛选下拉来源: 表里实际出现过的全部 action(只读聚合, 不硬编码列表)
        $actions = AdminAuditLog::select('action')->distinct()->orderBy('action')->pluck('action');

        return view('admin-views.nezha-audit.logs', compact('logs', 'actions', 'action', 'from', 'to'));
    }
}
