<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaNewOrderNag;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 哪吒 — 新单「反复提醒商家接单」(方案 B) 测试。
 *
 * 🔴 安全: phpunit.xml 已强制 DB_CONNECTION=sqlite / DB_DATABASE=:memory:, 本组用例**不碰任何生产库**。
 *   建表走 Tests\Support\IsolatedDatabaseFixtures(含 new_order_repeat_* 5 列与 nezha_notification_log)。
 *   仍保留 DatabaseTransactions: 同进程内存库跨用例复用, 事务回滚保证造店/单零残留。
 *   telegram_bot_token='' + 同步模式 → sendTelegramToRestaurant 无 token 返 false, 零真实网络。
 *   (旧注曾称「仍连生产 MySQL / 新列须等 migrate」, 随内存库切换已失效, 2026-07-20 更正。)
 *
 * 覆盖: 纯判定 shouldNagNow / scope 口径(confirmed 桶含与排除) / 命令 E2E(总闸关 no-op·催+节流·未勾类别不催)。
 */
class NezhaNewOrderNagSweepTest extends TestCase
{
    use DatabaseTransactions;

    private int $vendorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSetting('telegram_bot_token', '');      // 无 token → 零真实发送
        $this->setSetting('nezha_notif_async_status', 0); // 同步, 不甩真实队列
        $this->setSetting('nezha_notif_log_status', 1);   // 确保记 NezhaNotifyLog
        $this->vendorId = (int) (DB::table('vendors')->value('id') ?: 1);
    }

    /**
     * 🔴 只写 DB 不够: Helpers::get_business_settings 里的 static $allSettings 是**进程级**静态,
     * 被本进程首次读取锁死 -> 同类内先跑的用例(如总闸=0 那条)会把旧值带给后跑的用例, 造成
     * 「单独跑绿、连跑红」。故同时走 Config 缝 <key>_conf(get_business_settings 首先判断它,
     * 且 Config 每个用例由 TestCase 重建, 天然随用例隔离) —— 与 NezhaVoiceSampleWebhookTest 同法。
     * DB 写保留: 供绕过 helper 直接查 business_settings 的代码路径。
     */
    private function setSetting(string $key, $value): void
    {
        if (DB::table('business_settings')->where('key', $key)->exists()) {
            DB::table('business_settings')->where('key', $key)->update(['value' => $value]);
        } else {
            DB::table('business_settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }

        Config::set($key . '_conf', ['value' => $value]);
    }

    private function mkRestaurant(array $extra = []): int
    {
        return (int) DB::table('restaurants')->insertGetId(array_merge([
            'name' => 'NZ新单催单测试店', 'phone' => '00000000', 'vendor_id' => $this->vendorId,
            'zone_id' => 3, 'telegram_chat_id' => '123456', 'timeout_notify_telegram' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    /** 造一单(即时: schedule_at=created_at); 默认 confirmed 待接的 COD 单。$cols 覆盖字段。 */
    private function mkOrder(int $rid, array $cols = []): int
    {
        $t = now();

        return (int) DB::table('orders')->insertGetId(array_merge([
            'restaurant_id' => $rid, 'restaurant_discount_amount' => 0,
            'order_status' => 'confirmed', 'payment_method' => 'cash_on_delivery',
            'order_type' => 'delivery', 'checked' => 0, 'scheduled' => 0,
            'confirmed' => $t, 'created_at' => $t, 'updated_at' => $t, 'schedule_at' => $t,
        ], $cols));
    }

    // ---------- 纯判定 shouldNagNow(无 DB) ----------

    public function test_should_nag_pure_logic(): void
    {
        $now   = Carbon::parse('2026-07-18 12:00:00');
        $start = $now->copy()->subSeconds(90); // 已挂 90s

        $this->assertTrue(NezhaNewOrderNag::shouldNagNow($start, null, 60, 300, $now), '首次(无 lastRing)且窗口内: 催');
        $this->assertFalse(NezhaNewOrderNag::shouldNagNow($start, $now->timestamp - 30, 60, 300, $now), '30s前刚响(间隔60): 不催');
        $this->assertTrue(NezhaNewOrderNag::shouldNagNow($start, $now->timestamp - 70, 60, 300, $now), '70s前响(间隔60): 到点催');
        $this->assertFalse(NezhaNewOrderNag::shouldNagNow($now->copy()->subSeconds(300), null, 60, 300, $now), '挂满上限(300s): 停');
        $this->assertFalse(NezhaNewOrderNag::shouldNagNow(null, null, 60, 300, $now), '无时钟(窗口未启): 不催');
    }

    // ---------- scope 口径(DB · 无需新列) ----------

    public function test_confirmed_bucket_selects_and_excludes(): void
    {
        $rid  = $this->mkRestaurant();
        $keep = $this->mkOrder($rid);                                    // confirmed 待接 → 入
        $this->mkOrder($rid, ['order_status' => 'processing']);           // 备餐 → 不入
        $this->mkOrder($rid, ['checked' => 1]);                          // 已查看 → 不入
        $this->mkOrder($rid, ['payment_method' => 'digital_payment', 'order_status' => 'pending', 'confirmed' => null]); // 在线未付 → NotDigital 排除

        $this->assertSame(1, NezhaNewOrderNag::counts($rid)['confirmed'], 'confirmed 桶应只含 1 单');

        $ids = NezhaNewOrderNag::bucketsForRestaurant($rid, true, false)['accept']->pluck('id')->all();
        $this->assertContains($keep, $ids, '待接 confirmed 单应在 accept 桶');
        $this->assertCount(1, $ids, 'accept 桶应只含 1 单');
    }

    public function test_scope_off_returns_empty(): void
    {
        $rid = $this->mkRestaurant();
        $this->mkOrder($rid);
        $b = NezhaNewOrderNag::bucketsForRestaurant($rid, false, false);
        $this->assertCount(0, $b['accept'], '未勾任何类别 accept 桶应空');
        $this->assertCount(0, $b['payment'], '未勾任何类别 payment 桶应空');
    }

    // ---------- 命令 E2E(需新列 · 未迁移则跳过) ----------

    private function skipIfNoColumns(): void
    {
        if (! Schema::hasColumn('restaurants', 'new_order_repeat_enabled')) {
            $this->markTestSkipped('new_order_repeat_* 列缺失: 请在 IsolatedDatabaseFixtures 的 restaurants 表补齐');
        }
    }

    private function nagCount(int $oid): int
    {
        return (int) DB::table('nezha_notification_log')
            ->where('order_id', $oid)->where('event_type', 'new_order_nag')->count();
    }

    public function test_switch_off_is_noop(): void
    {
        $this->skipIfNoColumns();
        $this->setSetting('nezha_new_order_nag_status', 0);
        $rid = $this->mkRestaurant(['new_order_repeat_enabled' => 1, 'new_order_repeat_scope_accept' => 1]);
        $oid = $this->mkOrder($rid);
        Cache::forget('nezha_new_order_nag_' . $oid);
        Artisan::call('nezha:new-order-nag-sweep');
        $this->assertSame(0, $this->nagCount($oid), '总闸关(dormant)时不得催任何单');
    }

    public function test_enabled_restaurant_gets_nagged_and_throttled(): void
    {
        $this->skipIfNoColumns();
        $this->setSetting('nezha_new_order_nag_status', 1);
        $rid = $this->mkRestaurant([
            'new_order_repeat_enabled' => 1, 'new_order_repeat_interval_sec' => 60,
            'new_order_repeat_max_minutes' => 5, 'new_order_repeat_scope_accept' => 1, 'new_order_repeat_scope_payment' => 0,
        ]);
        $oid = $this->mkOrder($rid);
        Cache::forget('nezha_new_order_nag_' . $oid);

        Artisan::call('nezha:new-order-nag-sweep');
        $this->assertSame(1, $this->nagCount($oid), '开了反复的店应催 1 次(留 NezhaNotifyLog 一行)');

        Artisan::call('nezha:new-order-nag-sweep'); // 立即再跑 → 未到间隔应节流
        $this->assertSame(1, $this->nagCount($oid), '未到间隔应节流, 不重复催');

        Cache::forget('nezha_new_order_nag_' . $oid); // 清理测试键(Cache 不随事务回滚)
    }

    public function test_scope_accept_off_does_not_nag(): void
    {
        $this->skipIfNoColumns();
        $this->setSetting('nezha_new_order_nag_status', 1);
        $rid = $this->mkRestaurant([
            'new_order_repeat_enabled' => 1, 'new_order_repeat_scope_accept' => 0, 'new_order_repeat_scope_payment' => 0,
        ]);
        $oid = $this->mkOrder($rid);
        Cache::forget('nezha_new_order_nag_' . $oid);
        Artisan::call('nezha:new-order-nag-sweep');
        $this->assertSame(0, $this->nagCount($oid), '商家未勾「待接单」类别不得催其 confirmed 单');
    }
}
