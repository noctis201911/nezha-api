<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * SEC-3 后台危险操作审计 (append-only)。
 *
 * 用 AdminAuditLog::record(...) 写一行。任何写入失败只记 Log, 绝不打断业务动作
 * (审计是观测层, fail-open: 审计挂了不能连累改阈值/改权限/改员工本身)。
 *
 * 🔴 调用方负责脱敏: 绝不把 API 密钥 / 密码明文写进 $before / $after。
 */
class AdminAuditLog extends Model
{
    public $timestamps = false;   // append-only, 仅 created_at

    protected $fillable = [
        'actor_admin_id', 'actor_name', 'action',
        'target_type', 'target_id', 'before', 'after', 'ip', 'created_at',
    ];

    protected $casts = [
        'before' => 'array',
        'after'  => 'array',
    ];

    public static function record(string $action, ?string $targetType = null, $targetId = null, $before = null, $after = null): void
    {
        try {
            $admin = auth('admin')->user();
            $name  = $admin ? trim(($admin->f_name ?? '') . ' ' . ($admin->l_name ?? '')) : null;
            self::create([
                'actor_admin_id' => $admin?->id,
                'actor_name'     => ($name !== null && $name !== '') ? $name : null,
                'action'         => $action,
                'target_type'    => $targetType,
                'target_id'      => $targetId !== null ? (string) $targetId : null,
                'before'         => $before,
                'after'          => $after,
                'ip'             => request()?->ip(),
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AdminAuditLog record failed: ' . $e->getMessage());
        }
    }
}
