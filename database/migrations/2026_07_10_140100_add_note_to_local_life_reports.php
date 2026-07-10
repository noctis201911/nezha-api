<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 本地生活举报表加「笔记」维度（批N · additive · 可回滚·0708 merchant_id 同款手法）。
 * 一条举报指向 帖 XOR 商家 XOR 笔记；note_id 有值=举报某条笔记(local_life_merchant_notes.id)。
 * L1-1/L1-7 不变：仅举报记录，detail 可能含 PII → 表已 ENCRYPTION='Y'（沿用建表加密）。
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('local_life_reports') && !Schema::hasColumn('local_life_reports', 'note_id')) {
            Schema::table('local_life_reports', function (Blueprint $table) {
                $table->unsignedBigInteger('note_id')->nullable()->index()->after('merchant_id');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('local_life_reports', 'note_id')) {
            Schema::table('local_life_reports', function (Blueprint $table) {
                $table->dropColumn('note_id');
            });
        }
    }
};
