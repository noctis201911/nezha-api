<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 — 通知投递结果日志(P4 outbox 轻量踏脚石)。
 * 记录每次通知(站内/TG/邮件/推送)的尝试结果, 供日常运营检查「通知有没有送达」。写入方 = App\CentralLogics\NezhaNotifyLog::record()。
 * 🔴 零顾客 PII(只存渠道/角色/事件/结果 + 内部 order_id/restaurant_id + 无 PII 短原因)。best-effort: 记失败不影响真实通知。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('nezha_notification_log')) {
            Schema::create('nezha_notification_log', function (Blueprint $t) {
                $t->id();
                $t->string('channel', 16);                 // site / telegram / email / push
                $t->string('target', 16);                  // merchant / owner / support
                $t->string('event_type', 40);              // new_order / remind / prep_overtime / cancel_refund / owner_escalate
                $t->string('outcome', 16);                 // ok / failed / skipped / no_recipient
                $t->unsignedBigInteger('order_id')->nullable()->index();
                $t->unsignedBigInteger('restaurant_id')->nullable()->index();
                $t->string('detail', 255)->nullable();     // 无 PII 短原因/标记
                $t->timestamps();
                $t->index('created_at', 'nezha_nl_created_idx');
                $t->index(['channel', 'outcome'], 'nezha_nl_chan_outcome_idx');
            });
        }
        // 杀掉开关(默认 1=记): 置 0 即停记, 不影响任何真实通知。
        if (!DB::table('business_settings')->where('key', 'nezha_notif_log_status')->exists()) {
            DB::table('business_settings')->insert([
                'key'        => 'nezha_notif_log_status',
                'value'      => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_notification_log');
        DB::table('business_settings')->where('key', 'nezha_notif_log_status')->delete();
    }
};
