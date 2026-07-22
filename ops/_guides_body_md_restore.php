<?php
/**
 * guides_restore.php -- 攻略 body_md ?v= 写入的【还原】路径（不是备份脚本）
 *
 * 用法:
 *   php guides_restore.php rehearse <backup_dir>            # 临时表演练, 不碰生产行
 *   php guides_restore.php verify   <backup_dir>            # 只读: 当前生产行 vs 备份 逐行对账
 *   php guides_restore.php restore  <backup_dir> --i-mean-it # 真还原生产行
 *
 * 安全设计:
 *   1. 库名硬断言 (只认 sql_api_nezha_am)
 *   2. 事务 + SELECT ... FOR UPDATE (lockForUpdate)
 *   3. 前置条件守卫: 还原前校验"当前行 = 备份行 + 仅多出我们加的 ?v= token"。
 *      若剥掉 token 后与备份 body_md 不逐字节相等 => 说明有第三方写入 => 整体 ABORT, 绝不覆盖。
 *   4. 还原后重新读行, 整行 SHA-256 必须 == 备份 row_sha256, 否则 rollback
 */
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

const EXPECT_DB = 'sql_api_nezha_am';
const PROD_TABLE = 'nezha_guides';
const REHEARSE_TABLE = 'nz_guides_rehearsal_tmp';
// the exact token the planned write will append
const TOKEN = '?v=20260722g';

function rowHash(array $arr): string { return hash('sha256', json_encode($arr, JSON_UNESCAPED_UNICODE)); }
function fail(string $m) { fwrite(STDERR, "\n🔴 ABORT: $m\n"); exit(1); }
function ok(string $m) { echo "  ✅ $m\n"; }

$mode = $argv[1] ?? '';
$dir  = $argv[2] ?? '';
if (!in_array($mode, ['rehearse', 'rehearse-guard', 'verify', 'restore'], true)) fail('mode 必须是 rehearse|rehearse-guard|verify|restore');
if (!is_dir($dir)) fail("备份目录不存在: $dir");
$manifest = json_decode(file_get_contents("$dir/manifest.json"), true);
if (!$manifest) fail('manifest.json 读不到或不是 JSON');

// ---- 1. 库名硬断言 ----
$db = DB::getDatabaseName();
if ($db !== EXPECT_DB) fail("库名不符: 期望 " . EXPECT_DB . ", 实际 $db");
ok("库名硬断言通过: $db");

/** 把计划中的写入施加到 body_md: 给 14 个 /static/guides/*.jpg 加 ?v= */
function applyWrite(string $body): string {
    return preg_replace('#(/static/guides/[A-Za-z0-9._-]+\.jpg)(?!\?)#', '$1' . TOKEN, $body);
}
/** 逆操作: 剥掉我们加的 token */
function stripWrite(string $body): string {
    return str_replace(TOKEN, '', $body);
}

$ids = array_column($manifest, 'id');
$byId = [];
foreach ($manifest as $m) $byId[$m['id']] = $m;

// =====================================================================
if ($mode === 'rehearse') {
    echo "\n=== 演练开始 (TEMPORARY TABLE, 会话级, 其它连接不可见, 断开即消失) ===\n";
    DB::statement('DROP TEMPORARY TABLE IF EXISTS ' . REHEARSE_TABLE);
    DB::statement('CREATE TEMPORARY TABLE ' . REHEARSE_TABLE . ' LIKE ' . PROD_TABLE);
    DB::statement('INSERT INTO ' . REHEARSE_TABLE . ' SELECT * FROM ' . PROD_TABLE);
    $n = DB::table(REHEARSE_TABLE)->count();
    ok("已把生产 $n 行复制进临时表 (生产表全程只读)");

    // baseline: 临时表当前状态应与备份完全一致
    $bad = 0;
    foreach (DB::table(REHEARSE_TABLE)->get() as $r) {
        $h = rowHash((array) $r);
        if ($h !== $byId[$r->id]['row_sha256']) { echo "  ⚠️  #{$r->id} 基线哈希与备份不符 (备份后生产被改过?)\n"; $bad++; }
    }
    if ($bad) echo "  注意: $bad 行基线与备份不同, 演练仍继续(演练只证明还原逻辑)\n";
    else ok('基线: 临时表 7 行整行 SHA-256 与备份逐行一致');

    // 模拟写入
    $before = [];
    foreach (DB::table(REHEARSE_TABLE)->get() as $r) $before[$r->id] = rowHash((array) $r);
    $touched = 0;
    foreach (DB::table(REHEARSE_TABLE)->get() as $r) {
        $new = applyWrite((string) $r->body_md);
        if ($new !== $r->body_md) {
            DB::table(REHEARSE_TABLE)->where('id', $r->id)
              ->update(['body_md' => $new, 'updated_at' => date('Y-m-d H:i:s')]);
            $touched++;
        }
    }
    ok("模拟写入完成: $touched 行 body_md 被加了 " . TOKEN);
    $after = [];
    $changed = 0;
    foreach (DB::table(REHEARSE_TABLE)->get() as $r) {
        $after[$r->id] = rowHash((array) $r);
        if ($after[$r->id] !== $before[$r->id]) $changed++;
    }
    ok("写入后 $changed 行整行哈希已改变 (证明写入确实发生, 不是空操作)");

    // 跑还原
    echo "\n--- 对临时表执行 restore 逻辑 ---\n";
    $res = doRestore(REHEARSE_TABLE, $byId, $dir, true);
    echo "\n--- 演练结论 ---\n";
    $back = 0; $miss = 0;
    foreach (DB::table(REHEARSE_TABLE)->get() as $r) {
        $h = rowHash((array) $r);
        if ($h === $byId[$r->id]['row_sha256']) $back++;
        else { $miss++; echo "  🔴 #{$r->id} 未回到备份哈希\n  期望 " . $byId[$r->id]['row_sha256'] . "\n  实际 $h\n"; }
    }
    echo ($miss === 0
        ? "  ✅ 演练通过: $back/7 行整行 SHA-256 精确回到备份原值\n"
        : "  🔴 演练失败: $miss 行未回到原值\n");
    DB::statement('DROP TEMPORARY TABLE IF EXISTS ' . REHEARSE_TABLE);
    ok('临时表已清理');
    exit($miss === 0 ? 0 : 1);
}

// =====================================================================
// 反向演练: 证明"前置条件守卫"真的会拦 —— 只跑 happy path 不算证明
if ($mode === 'rehearse-guard') {
    echo "\n=== 反向演练: 模拟第三方写入, 守卫必须拦住且一行都不改 ===\n";
    DB::statement('DROP TEMPORARY TABLE IF EXISTS ' . REHEARSE_TABLE);
    DB::statement('CREATE TEMPORARY TABLE ' . REHEARSE_TABLE . ' LIKE ' . PROD_TABLE);
    DB::statement('INSERT INTO ' . REHEARSE_TABLE . ' SELECT * FROM ' . PROD_TABLE);
    ok('临时表已就绪 (生产表只读)');

    foreach (DB::table(REHEARSE_TABLE)->get() as $r) {
        $new = applyWrite((string) $r->body_md);
        if ($new !== $r->body_md) DB::table(REHEARSE_TABLE)->where('id', $r->id)->update(['body_md' => $new]);
    }
    ok('已施加我们计划中的 ?v= 写入');

    // 第三方写入: 别的窗口/运营改了正文
    $victim = 7;
    DB::table(REHEARSE_TABLE)->where('id', $victim)
        ->update(['body_md' => DB::raw("CONCAT(body_md, '\\n\\n运营后来补的一段话')")]);
    ok("已模拟第三方改动: #$victim 正文被追加了一段文字");

    $pre = [];
    foreach (DB::table(REHEARSE_TABLE)->get() as $r) $pre[$r->id] = rowHash((array) $r);

    $res = doRestore(REHEARSE_TABLE, $byId, $dir, true);
    echo "\n--- 反向演练结论 ---\n";
    if ($res['ok']) {
        echo "  🔴 失败: 守卫没有拦住, 竟然还原成功了 —— 这会覆盖掉第三方的改动\n";
        DB::statement('DROP TEMPORARY TABLE IF EXISTS ' . REHEARSE_TABLE);
        exit(1);
    }
    echo "  ✅ 守卫按预期拦下: " . $res['error'] . "\n";
    $drift = 0;
    foreach (DB::table(REHEARSE_TABLE)->get() as $r) if (rowHash((array) $r) !== $pre[$r->id]) $drift++;
    echo ($drift === 0
        ? "  ✅ 拦下后 7 行整行哈希与拦截前完全一致 —— 一行都没被改(事务已回滚)\n"
        : "  🔴 有 $drift 行被改动了, 回滚不彻底\n");
    DB::statement('DROP TEMPORARY TABLE IF EXISTS ' . REHEARSE_TABLE);
    ok('临时表已清理');
    exit($drift === 0 ? 0 : 1);
}

// =====================================================================
if ($mode === 'verify') {
    echo "\n=== 只读对账: 生产 " . PROD_TABLE . " vs 备份 ===\n";
    foreach (DB::table(PROD_TABLE)->whereIn('id', $ids)->get() as $r) {
        $h = rowHash((array) $r);
        $exp = $byId[$r->id]['row_sha256'];
        $cur = (string) $r->body_md;
        $stripped = stripWrite($cur);
        $state = $h === $exp ? '与备份一致(未写入)' :
            ($stripped === file_get_contents("$dir/body_md-{$r->id}.txt") ? '已写入 ?v=(可安全还原)' : '🔴 存在未知第三方改动');
        printf("  #%-3d %-24s %s\n", $r->id, $byId[$r->id]['slug'], $state);
    }
    exit(0);
}

// =====================================================================
if ($mode === 'restore') {
    if (($argv[3] ?? '') !== '--i-mean-it') fail('真还原需要显式加 --i-mean-it');
    echo "\n=== 真还原生产 " . PROD_TABLE . " ===\n";
    doRestore(PROD_TABLE, $byId, $dir, false);
    exit(0);
}

// =====================================================================
function doRestore(string $table, array $byId, string $dir, bool $rehearsing): array {
    DB::beginTransaction();
    try {
        $ids = array_keys($byId);
        // 2. 行级锁
        $rows = DB::table($table)->whereIn('id', $ids)->lockForUpdate()->get();
        ok('已对 ' . count($rows) . ' 行加 FOR UPDATE 行锁');

        $plan = [];
        foreach ($rows as $r) {
            $b = $byId[$r->id];
            $curHash = rowHash((array) $r);
            if ($curHash === $b['row_sha256']) { $plan[$r->id] = 'SKIP(已是备份态)'; continue; }
            // 3. 前置条件守卫
            $backupBody = file_get_contents("$dir/body_md-{$r->id}.txt");
            if ($backupBody === false) throw new RuntimeException("备份 body_md 文件缺失: #{$r->id}");
            if (stripWrite((string) $r->body_md) !== $backupBody) {
                throw new RuntimeException(
                    "#{$r->id} ({$b['slug']}) 剥掉 " . TOKEN . " 后与备份 body_md 不逐字节相等 => 存在未知第三方写入, 整体放弃, 一行都不改"
                );
            }
            $plan[$r->id] = 'RESTORE';
        }
        ok('前置条件守卫通过: ' . implode(', ', array_map(fn($k, $v) => "#$k=$v", array_keys($plan), $plan)));

        foreach ($plan as $id => $act) {
            if ($act !== 'RESTORE') continue;
            $row = json_decode(file_get_contents("$dir/row-$id.json"), true);
            unset($row['id']);
            DB::table($table)->where('id', $id)->update($row);
        }
        ok('已按备份整行回写');

        // 4. 回写后整行哈希对账
        $bad = [];
        foreach (DB::table($table)->whereIn('id', $ids)->get() as $r) {
            if (rowHash((array) $r) !== $byId[$r->id]['row_sha256']) $bad[] = $r->id;
        }
        if ($bad) throw new RuntimeException('还原后整行 SHA-256 仍不符: #' . implode(',#', $bad));
        ok('还原后 ' . count($ids) . ' 行整行 SHA-256 全部 == 备份原值');

        DB::commit();
        ok('事务已提交');
        return ['ok' => true, 'error' => ''];
    } catch (\Throwable $e) {
        DB::rollBack();
        echo "  ↩︎ 已回滚, 数据未变。原因: " . $e->getMessage() . "\n";
        if (!$rehearsing) fail('还原未执行: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
