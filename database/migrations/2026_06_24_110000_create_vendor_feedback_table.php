<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vendor_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('restaurant_id')->nullable()->index();
            $table->string('type', 20)->default('other');     // commission/settlement/feature/other
            $table->string('subject', 150);
            $table->text('description');
            $table->string('status', 20)->default('open');     // open/in_progress/resolved
            $table->text('admin_note')->nullable();            // 平台处理回复(商家可见)
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        // 静态加密(符合 L1-7; 与其它含 PII 表一致)。keyring 已加载, 失败不阻断。
        try {
            DB::statement("ALTER TABLE `vendor_feedback` ENCRYPTION='Y'");
        } catch (\Throwable $e) {
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_feedback');
    }
};
