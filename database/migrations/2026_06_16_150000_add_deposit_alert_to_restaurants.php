<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒 B方案 组4: 商家预存佣金低额邮件告警(商家自选阈值+指定邮箱)。
return new class extends Migration {
    public function up(): void {
        Schema::table('restaurants', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurants','deposit_alert_enabled'))     $table->boolean('deposit_alert_enabled')->default(0)->after('comission');
            if (!Schema::hasColumn('restaurants','deposit_alert_threshold'))   $table->decimal('deposit_alert_threshold',10,2)->nullable()->after('deposit_alert_enabled');
            if (!Schema::hasColumn('restaurants','deposit_alert_email'))       $table->string('deposit_alert_email')->nullable()->after('deposit_alert_threshold');
            if (!Schema::hasColumn('restaurants','deposit_alert_last_sent_at'))$table->timestamp('deposit_alert_last_sent_at')->nullable()->after('deposit_alert_email');
        });
    }
    public function down(): void {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['deposit_alert_enabled','deposit_alert_threshold','deposit_alert_email','deposit_alert_last_sent_at']);
        });
    }
};
