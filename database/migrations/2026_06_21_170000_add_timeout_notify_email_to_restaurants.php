<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('restaurants', 'timeout_notify_email')) {
            Schema::table('restaurants', function (Blueprint $table) {
                // 哪吒: 订单超时提醒是否同时给商家发邮件。1=系统(面板)+邮箱, 0=仅系统(面板)。
                // 默认 1 保持历史行为不变。敏感邮件(自动取消+需原路退款)无视本开关恒发。
                $table->boolean('timeout_notify_email')->default(1)->after('telegram_chat_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('restaurants', 'timeout_notify_email')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->dropColumn('timeout_notify_email');
            });
        }
    }
};
