<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 本地生活 UGC 帖「证据冻结」(legal hold)：
 *   被运营判定违规 / 需配合主管机关调查的帖子，置 legal_hold=1，
 *   则其 contact_info + 图片豁免 30 天到期清除（nezha:purge-locallife-pii 跳过），供留证。
 *
 * L1-7 边界：这是「到期删除」的**有限例外**，仅用于违规/执法留证目的；
 *   非由用户举报自动触发（防滥用、防过度留存），由运营人工设/解；用尽目的应解除冻结让其正常到期清。
 *   §5.3 用户承诺文案同步加「依法/配合调查需保留的除外」例外（见 legal/local-life-terms.md）。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('local_life_posts', 'legal_hold')) {
            Schema::table('local_life_posts', function (Blueprint $table) {
                $table->boolean('legal_hold')->default(false)->after('source');
                $table->string('legal_hold_reason', 255)->nullable()->after('legal_hold');
                $table->timestamp('legal_hold_at')->nullable()->after('legal_hold_reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('local_life_posts', 'legal_hold')) {
            Schema::table('local_life_posts', function (Blueprint $table) {
                $table->dropColumn(['legal_hold', 'legal_hold_reason', 'legal_hold_at']);
            });
        }
    }
};
