<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users.email 加唯一索引(数据库层兜底)。
 *
 * 背景:邮箱唯一性此前只靠应用层 validator(sign-up / update_info / update-profile /
 *      social_login / 邮箱验证码登录 各自查重),users 表在数据库层没有任何 email 索引
 *      —— 并发注册竞态、后台手工改库、数据导入三条口子都能绕过应用层查重。
 *
 * 语义:email 可空,MySQL 唯一索引允许任意多个 NULL,不影响未填邮箱的账号,列本身不改。
 *      列排序规则 utf8mb4_unicode_ci(大小写不敏感),唯一性判定与现有
 *      `where('email', ...)` / `LOWER(email)` 查重语义一致 —— 不会多拒任何今天能通过的注册。
 *
 * 前置:2026-07-23 生产实查 users 共 9 行、非空邮箱 8 个、distinct LOWER(email) 8 个、
 *      空串 0 个,零重复;既有索引仅 PRIMARY / phone / ref_code / zone_id。
 */
return new class extends Migration
{
    private const INDEX = 'users_email_unique';

    public function up(): void
    {
        // 幂等:索引已存在(手工加过 / 迁移重跑)直接跳过,不抛错。
        if (Schema::hasIndex('users', self::INDEX)) {
            return;
        }

        // 落索引前当场复查重复:部署器的 `php artisan migrate --force` 一失败整个 deploy 就 FATAL,
        // 与其抛 SQLSTATE[23000] 让人回头猜是哪一条,不如直接把冲突邮箱打进报错里。
        $duplicates = DB::table('users')
            ->selectRaw('LOWER(email) AS value, COUNT(*) AS hits')
            ->whereNotNull('email')
            ->groupByRaw('LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->pluck('value')
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException(
                'users.email 存在重复值,无法加唯一索引,请先人工并账后重试: ' . implode(', ', $duplicates)
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('users', self::INDEX)) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });
    }
};
