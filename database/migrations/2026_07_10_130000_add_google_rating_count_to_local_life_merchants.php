<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 本地生活商家详情页 v3（批B·additive）：Google 评价条数。
 * 与 google_rating 同机制（运营人工核录·月度校核），nullable=未录时前端只显分值（§④-1）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_life_merchants', function (Blueprint $table) {
            if (!Schema::hasColumn('local_life_merchants', 'google_rating_count')) {
                $table->unsignedInteger('google_rating_count')->nullable()->after('google_rating_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('local_life_merchants', function (Blueprint $table) {
            if (Schema::hasColumn('local_life_merchants', 'google_rating_count')) {
                $table->dropColumn('google_rating_count');
            }
        });
    }
};
