<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒[users.email 唯一索引 2026-07-23]: 邮箱唯一性此前只靠应用层 validator
 * (sign-up / update_info / update-profile / social_login / 邮箱验证码登录 各自查重),
 * users 表在数据库层没有任何 email 索引 —— 并发注册竞态、后台手工改库、数据导入
 * 三条口子都能绕过应用层查重。此迁移在 DB 层补上兜底。
 *
 * 不改列: email 仍可空, MySQL 唯一索引允许任意多个 NULL, 未填邮箱的账号不受影响。
 *
 * 唯一性判定范围 = 列排序规则 utf8mb4_unicode_ci 的等价类, 比"大小写不敏感"更宽:
 * 大小写(A=a)、重音(josé=jose)、连字(ß=ss)、尾部空格 都折叠成同一个值(线上实测)。
 * 这与现有 `where('email', ...)` / `LOWER(email)` 查重走的是同一套 collation, 因此
 * 不会多拒任何今天能通过的注册, 只是把已有的比较语义固化到 DB 层。
 *
 * 前置(2026-07-23 生产实查): users 9 行 / 非空邮箱 8 / distinct LOWER(email) 8 /
 * 空串 0 / 未 trim 0 / maxlen 37 → 零重复; 既有索引仅 PRIMARY、phone、ref_code、zone_id。
 *
 * 🔴 回滚说明: 部署器 nzdeploy-api.sh 的 `migrate --force` 跑在切 current 之前,
 * 其 rollback_after_switch() 只换软链 + FPM USR2, 从不执行 migrate:rollback。
 * 即: 后续任一闸失败导致代码回退时, 本索引会留在库里。这是刻意接受的 ——
 * 索引是纯增量约束, 旧代码不依赖它, 应用层查重照常工作。
 */
return new class extends Migration
{
    private const INDEX = 'users_email_unique';

    public function up(): void
    {
        // 幂等: 索引已存在(手工加过 / 迁移重跑)直接跳过, 不抛错。
        if (Schema::hasIndex('users', self::INDEX)) {
            return;
        }

        // 落索引前当场复查重复: 部署器的 `php artisan migrate --force` 一失败整个 deploy 就 FATAL,
        // 与其抛 SQLSTATE[23000] 让人回头猜是哪一条, 不如直接把冲突邮箱打进报错里。
        // 注意这只是可读性兜底, 不是锁: 复查与 ALTER 之间仍有空隙, 期间插入的重复行照样 1062。
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
                'users.email 存在重复值, 无法加唯一索引, 请先人工并账后重试: ' . implode(', ', $duplicates)
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email', self::INDEX);
        });
    }

    /**
     * 不主动 drop: 邮箱唯一是本迁移要建立的线上保护, 回滚不应削弱线上约束
     * (同 2026_07_01_160000 users.phone 唯一索引判例)。
     * 确需撤销请人工执行 `DROP INDEX users_email_unique ON users` 并留痕。
     */
    public function down(): void
    {
        // intentionally no-op
    }
};
