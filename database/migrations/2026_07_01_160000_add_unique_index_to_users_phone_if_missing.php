<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 哪吒[防漂移 2026-07-01]: 线上 users.phone 早有唯一约束(users_phone_unique, 来自 StackFood
     * 原始安装 SQL), 但从未写进迁移 -> 全新部署/重建库会丢这层保护, 顾客手机号去重将仅靠应用层
     * 校验, 而 OTP 建号路径不走该校验。此迁移幂等补齐: 仅当 phone 上无唯一索引时才创建。
     * 生产已存在 -> up() 为 no-op 仅记录已运行; 全新库 -> 真正建立约束。
     */
    public function up(): void
    {
        $hasUnique = collect(DB::select(
            "SHOW INDEX FROM users WHERE Column_name = 'phone' AND Non_unique = 0"
        ))->isNotEmpty();

        if (! $hasUnique) {
            Schema::table('users', function ($table) {
                $table->unique('phone', 'users_phone_unique');
            });
        }
    }

    /**
     * 不主动 drop: phone 唯一是生产既有保护, 回滚不应削弱线上约束。
     */
    public function down(): void
    {
        // intentionally no-op
    }
};
