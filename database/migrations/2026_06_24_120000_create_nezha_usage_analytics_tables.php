<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 「搜了但没结果」聚合(不存 user_id, 天然匿名)
        Schema::create('nezha_search_misses', function (Blueprint $t) {
            $t->id();
            $t->string('keyword', 80);
            $t->string('search_type', 20)->default('product'); // product / restaurant
            $t->unsignedInteger('zone_id')->default(0);
            $t->unsignedInteger('hit_count')->default(1);
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
            $t->index(['search_type', 'zone_id']);
            $t->index('last_seen_at');
        });

        // 加购事件(用于"加购未下单"; user_id=PII, 30天清; converted 由 nezha:purge-analytics 离线回填)
        Schema::create('nezha_cart_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('item_id')->nullable();
            $t->unsignedBigInteger('restaurant_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->boolean('is_guest')->default(false);
            $t->boolean('converted')->default(false);
            $t->timestamps();
            $t->index('created_at');
            $t->index('converted');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_search_misses');
        Schema::dropIfExists('nezha_cart_events');
    }
};
