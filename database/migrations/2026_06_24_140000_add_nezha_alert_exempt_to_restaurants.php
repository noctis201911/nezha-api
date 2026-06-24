<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('restaurants') && !Schema::hasColumn('restaurants', 'nezha_alert_exempt')) {
            Schema::table('restaurants', function (Blueprint $t) {
                // 上店硬闸豁免: 1=该店走"常开后台设备"接单, 激活时不强制要求绑 Telegram。默认 0(必绑)。
                $t->boolean('nezha_alert_exempt')->default(false)->after('telegram_chat_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('restaurants') && Schema::hasColumn('restaurants', 'nezha_alert_exempt')) {
            Schema::table('restaurants', function (Blueprint $t) {
                $t->dropColumn('nezha_alert_exempt');
            });
        }
    }
};
