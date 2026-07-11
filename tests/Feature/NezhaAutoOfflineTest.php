<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaAutoOffline;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 哪吒 — 商家「长期不确认订单 → 自动暂停接单(auto-offline)」测试。
 *
 * 🔴 安全: 本仓 phpunit.xml 未启用独立测试库, 仍连生产 MySQL。
 * 故只用 DatabaseTransactions(事务回滚, 绝不 RefreshDatabase=清库); 造的店/单/账本随事务回滚, 零残留。
 * Mail::fake() + 清空 nezha_risk_admin_chat_id 避免真发邮件/TG。
 *
 * 覆盖: 总闸关 no-op / 达阈值+不在场→下线 / 未达阈值不下线 / 达阈值但在场不下线 /
 *   cancel_unpaid 不计 / 预约单不计 / 已下线幂等 / 恢复清标记 / 与退款逾期挂起独立 / helper。
 */
class NezhaAutoOfflineTest extends TestCase
{
    use DatabaseTransactions;

    private int $vendorId;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setSetting('nezha_autooffline_status', 1);
        $this->setSetting('nezha_autooffline_strike_count', 3);
        $this->setSetting('nezha_autooffline_window_hours', 2);
        $this->setSetting('nezha_risk_admin_chat_id', ''); // 避免真发 TG
        $this->vendorId = (int) (DB::table('vendors')->value('id') ?: 1);
    }

    private function setSetting(string $key, $value): void
    {
        if (DB::table('business_settings')->where('key', $key)->exists()) {
            DB::table('business_settings')->where('key', $key)->update(['value' => $value]);
        } else {
            DB::table('business_settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function mkRestaurant(): int
    {
        return (int) DB::table('restaurants')->insertGetId([
            'name' => 'NZ自动下线测试店', 'phone' => '00000000', 'vendor_id' => $this->vendorId,
            'zone_id' => 3, 'nezha_auto_offline' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 造单; $stamps 例 ['canceled'=>now(), 'processing'=>now()->subMinutes(10)]。 */
    private function mkOrder(int $rid, string $status, int $scheduled = 0, array $stamps = []): int
    {
        $row = [
            'restaurant_id' => $rid, 'restaurant_discount_amount' => 0,
            'order_status' => $status, 'scheduled' => $scheduled,
            'created_at' => now(), 'updated_at' => now(),
        ];
        foreach ($stamps as $col => $val) { $row[$col] = $val; }
        return (int) DB::table('orders')->insertGetId($row);
    }

    private function mkStrike(int $orderId, string $action = 'cancel_paid_refund', int $minsAgo = 30): void
    {
        DB::table('nezha_order_timeout_events')->insert([
            'order_id' => $orderId, 'action' => $action,
            'fired_at' => now()->subMinutes($minsAgo),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedStrikes(int $rid, int $count, string $action = 'cancel_paid_refund', int $scheduled = 0): void
    {
        for ($i = 0; $i < $count; $i++) {
            $oid = $this->mkOrder($rid, 'canceled', $scheduled, ['canceled' => now()->subMinutes(30)]);
            $this->mkStrike($oid, $action);
        }
    }

    private function runSweep(): void
    {
        Artisan::call('nezha:merchant-autooffline-sweep');
    }

    private function isOffline(int $rid): bool
    {
        return (int) DB::table('restaurants')->where('id', $rid)->value('nezha_auto_offline') === 1;
    }

    // ---------- 总闸 ----------

    public function test_disabled_switch_is_noop(): void
    {
        $this->setSetting('nezha_autooffline_status', 0);
        $rid = $this->mkRestaurant();
        $this->seedStrikes($rid, 3);
        $this->runSweep();
        $this->assertFalse($this->isOffline($rid), '总闸关时不得下线任何店');
    }

    // ---------- 触发 ----------

    public function test_striking_and_absent_merchant_is_offlined(): void
    {
        $rid = $this->mkRestaurant();
        $this->seedStrikes($rid, 3); // 3 单商家责任超时取消, 窗口内无成功接单
        $this->runSweep();
        $this->assertTrue($this->isOffline($rid), '3 单超时且不在场应被自动下线');
        $this->assertTrue(
            DB::table('nezha_auto_offline_events')->where('restaurant_id', $rid)->where('action', 'auto_offline')->exists(),
            '应写 auto_offline 审计留痕'
        );
    }

    public function test_below_threshold_not_offlined(): void
    {
        $rid = $this->mkRestaurant();
        $this->seedStrikes($rid, 2); // 只 2 单, 未达 N=3
        $this->runSweep();
        $this->assertFalse($this->isOffline($rid), '未达阈值不得下线');
    }

    public function test_present_merchant_not_offlined(): void
    {
        $rid = $this->mkRestaurant();
        $this->seedStrikes($rid, 3);
        // 窗口内成功推进过一单(processing 时间戳落在窗口内)=在场
        $this->mkOrder($rid, 'processing', 0, ['processing' => now()->subMinutes(10)]);
        $this->runSweep();
        $this->assertFalse($this->isOffline($rid), '在场(窗口内有成功处理单)不得下线');
    }

    public function test_cancel_unpaid_does_not_count(): void
    {
        $rid = $this->mkRestaurant();
        $this->seedStrikes($rid, 3, 'cancel_unpaid'); // 顾客没付, 非商家责任
        $this->runSweep();
        $this->assertFalse($this->isOffline($rid), 'cancel_unpaid(顾客没付)不得计入 strike');
    }

    public function test_scheduled_orders_excluded(): void
    {
        $rid = $this->mkRestaurant();
        $this->seedStrikes($rid, 3, 'cancel_paid_refund', 1); // 预约单
        $this->runSweep();
        $this->assertFalse($this->isOffline($rid), '预约单不计入 strike');
    }

    // ---------- 幂等 / 恢复 / 独立性 ----------

    public function test_already_offline_is_idempotent(): void
    {
        $rid = $this->mkRestaurant();
        NezhaAutoOffline::offline($rid, '预置');
        $this->seedStrikes($rid, 3);
        $before = DB::table('nezha_auto_offline_events')->where('restaurant_id', $rid)->where('action', 'auto_offline')->count();
        $this->runSweep();
        $after = DB::table('nezha_auto_offline_events')->where('restaurant_id', $rid)->where('action', 'auto_offline')->count();
        $this->assertSame($before, $after, '已下线的店不得重复写 auto_offline 事件');
        $this->assertTrue($this->isOffline($rid));
    }

    public function test_recover_clears_flag_and_logs(): void
    {
        $rid = $this->mkRestaurant();
        NezhaAutoOffline::offline($rid, 'x');
        $this->assertTrue($this->isOffline($rid));
        $ok = NezhaAutoOffline::recover($rid, 'self');
        $this->assertTrue($ok);
        $this->assertFalse($this->isOffline($rid), '恢复后应清标记');
        $this->assertTrue(
            DB::table('nezha_auto_offline_events')->where('restaurant_id', $rid)->where('action', 'self_recover')->exists(),
            '自助恢复应写 self_recover 留痕'
        );
    }

    public function test_offline_independent_of_refund_overdue_suspend(): void
    {
        $rid = $this->mkRestaurant();
        NezhaAutoOffline::offline($rid, 'x');
        $r = DB::table('restaurants')->where('id', $rid)->first();
        $this->assertSame(1, (int) $r->nezha_auto_offline);
        $this->assertSame(0, (int) ($r->nezha_order_suspended ?? 0), '自动下线不得连带置退款逾期挂起(两来源独立)');
    }

    // ---------- helper ----------

    public function test_is_offline_reads_column_in_memory(): void
    {
        $r = new Restaurant();
        $r->nezha_auto_offline = 1;
        $this->assertTrue(NezhaAutoOffline::is_offline($r));
        $r->nezha_auto_offline = 0;
        $this->assertFalse(NezhaAutoOffline::is_offline($r));
        $this->assertFalse(NezhaAutoOffline::is_offline(null));
    }

    public function test_threshold_helpers_read_settings_with_fallback(): void
    {
        $this->assertSame(3, NezhaAutoOffline::strikeCount());
        $this->assertSame(2, NezhaAutoOffline::windowHours());
        $this->setSetting('nezha_autooffline_strike_count', 5);
        $this->setSetting('nezha_autooffline_window_hours', 4);
        $this->assertSame(5, NezhaAutoOffline::strikeCount());
        $this->assertSame(4, NezhaAutoOffline::windowHours());
        // 空值回落默认
        $this->setSetting('nezha_autooffline_strike_count', '');
        $this->assertSame(3, NezhaAutoOffline::strikeCount());
    }
}
