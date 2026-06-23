<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-3 后台危险操作审计日志 (append-only)。
 * 记录: 改风控阈值(L2 业务参数) / 角色权限变更 / 员工增删。
 * 🔴 不存任何 API 密钥或密码明文 (见 AdminAuditLog::record 调用方脱敏约定)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('actor_admin_id')->nullable()->index();
            $table->string('actor_name', 191)->nullable();
            $table->string('action', 64)->index();          // risk_settings_update / admin_role_* / admin_employee_*
            $table->string('target_type', 64)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 64)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};
