<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 S3-B 退款: 给 nezha_topup_requests 加 kyc_apply_fp —— 退款申请提交当刻的 KYC 身份指纹
 * hash(normHolder(法人)|normHolder(户名)|账户)。放款审批时重算对比: 不一致=KYC 身份/收款账户在申请后有变更→挡
 * (防"本人缴、第三方退")。只哈希身份三字段, 免疫制裁复筛 apply_to_profile 对 screen_* 列的写回(否则 updated_at 被顶误报)。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nezha_topup_requests') && !Schema::hasColumn('nezha_topup_requests', 'kyc_apply_fp')) {
            Schema::table('nezha_topup_requests', function (Blueprint $table) {
                $table->string('kyc_apply_fp', 64)->nullable()->comment('退款申请当刻KYC身份指纹(放款对比防第三方)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('nezha_topup_requests') && Schema::hasColumn('nezha_topup_requests', 'kyc_apply_fp')) {
            Schema::table('nezha_topup_requests', function (Blueprint $table) {
                $table->dropColumn('kyc_apply_fp');
            });
        }
    }
};
