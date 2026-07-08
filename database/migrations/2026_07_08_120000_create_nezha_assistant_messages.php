<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UX1-E 商家助手会话化：助手消息持久层（这是「不显示聊天记录」的根治）。
 * per-restaurant（全店员共享同一店的助手会话，与限速桶/店铺操作同粒度）。
 * role/content + 动作卡三态(action_type·payload·status)/created_at。
 * L3 呈现层 additive，不碰资金/L1。保留期 nezha_assistant_retention_days（默认 180 天，业主 0708 定）
 * 由 nezha:purge-assistant-messages 每日清扫（含偶发非顾客 PII 的到期删除）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nezha_assistant_messages')) {
            Schema::create('nezha_assistant_messages', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('restaurant_id');
                $table->string('role', 16);                    // user | ai
                $table->text('content')->nullable();           // 文本消息（纯动作卡时可为空）
                $table->string('action_type', 24)->nullable(); // pause | resume | price | feedback（动作卡才有）
                $table->json('payload')->nullable();           // 结构化草稿/详情（改价:food_id/food_name/old_price/new_price 等）
                $table->string('status', 16)->nullable();      // pending | done | cancelled（仅动作卡）
                $table->timestamps();
                $table->index(['restaurant_id', 'id'], 'nzam_rid_id_idx'); // 取最近 N + 游标分页
                $table->index('created_at', 'nzam_created_idx');           // 保留期清扫
            });
        }

        // 保留期配置项（后台可调；命令端有 ?? 180 兜底，故此处失败不阻断建表）
        try {
            if (!DB::table('business_settings')->where('key', 'nezha_assistant_retention_days')->exists()) {
                DB::table('business_settings')->insert([
                    'key'        => 'nezha_assistant_retention_days',
                    'value'      => '180',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // business_settings 结构异常时静默跳过（不影响持久层可用）
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_assistant_messages');
    }
};
