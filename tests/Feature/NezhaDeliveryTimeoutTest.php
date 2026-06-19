<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaOrderTimeout as T;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 哪吒 — 配送阶段(handover/picked_up)超时集中下发测试。
 *
 * 🔴 安全: 本仓 phpunit.xml 未启用独立测试库, APP_ENV=testing 仍连生产 MySQL。
 * 故只用 DatabaseTransactions(事务回滚, 绝不 RefreshDatabase=清库); 且全部用
 * 内存 Order 实例(从不 save), 零订单写入。setUp 设阈值在事务内, 测完回滚。
 *
 * 覆盖需求7: 阈值前不超时 / 刚好达到及超过 / 缺状态时间 / handover·picked_up·delivered·canceled 边界。
 */
class NezhaDeliveryTimeoutTest extends TestCase
{
    use DatabaseTransactions;

    private const HANDOVER_MIN = 45;
    private const PICKED_MIN   = 90;

    protected function setUp(): void
    {
        parent::setUp();
        // 在事务内固定阈值, 使测试不依赖后台当前配置(测完随事务回滚)
        $this->setSetting('nezha_timeout_status', 1);
        $this->setSetting('nezha_timeout_handover_min', self::HANDOVER_MIN);
        $this->setSetting('nezha_timeout_picked_min', self::PICKED_MIN);
    }

    private function setSetting(string $key, $value): void
    {
        $exists = DB::table('business_settings')->where('key', $key)->exists();
        if ($exists) {
            DB::table('business_settings')->where('key', $key)->update(['value' => $value]);
        } else {
            DB::table('business_settings')->insert([
                'key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** 构造一个不入库的订单, 把状态时间列设为 now()-$minsAgo 分钟前; $minsAgo=null 表示无时间记录。 */
    private function mkOrder(string $status, string $type = 'delivery', ?int $minsAgo = null): Order
    {
        $o = new Order();
        $o->order_status = $status;
        $o->order_type   = $type;
        if ($minsAgo !== null) {
            $o->{$status} = now()->subMinutes($minsAgo);
        }
        return $o;
    }

    // ---------- handover ----------

    public function test_handover_before_threshold_no_timeout(): void
    {
        $r = T::describe($this->mkOrder('handover', 'delivery', self::HANDOVER_MIN - 1));
        $this->assertNull($r, '阈值前(44min)不应下发超时对象');
    }

    public function test_handover_exactly_at_threshold_warns(): void
    {
        $r = T::describe($this->mkOrder('handover', 'delivery', self::HANDOVER_MIN));
        $this->assertIsArray($r, '刚好达到阈值(45min)应下发');
        $this->assertSame('warning', $r['severity']);
        $this->assertSame(T::PHASE_HANDOVER, $r['phase']);
    }

    public function test_handover_over_threshold_warns_with_required_fields(): void
    {
        $r = T::describe($this->mkOrder('handover', 'delivery', self::HANDOVER_MIN + 15));
        $this->assertIsArray($r);
        $this->assertSame('warning', $r['severity']);
        // 需求5: 必须含 severity/标题/说明/下一步/联系入口/退款责任
        $this->assertNotEmpty($r['title']);
        $this->assertNotEmpty($r['next_step']);
        $this->assertNotEmpty($r['contact_hint']);
        $this->assertNotEmpty($r['refund_method']);
        // 退款责任=联系商家原路退, 平台不经手
        $this->assertStringContainsString('商家', $r['refund_method']);
    }

    public function test_handover_take_away_exempt(): void
    {
        // take_away 的 handover=可取餐, 非配送延迟, 即便很久也不触发
        $r = T::describe($this->mkOrder('handover', 'take_away', self::HANDOVER_MIN + 99));
        $this->assertNull($r, 'take_away handover 不计配送超时');
    }

    public function test_handover_missing_time_record_is_honest_not_fake(): void
    {
        $r = T::describe($this->mkOrder('handover', 'delivery', null));
        $this->assertIsArray($r);
        $this->assertTrue($r['no_time_record'] ?? false, '缺状态时间应标 no_time_record');
        $this->assertSame('info', $r['severity'], '无时间记录不得报成超时(warning/error)');
        $this->assertNull($r['elapsed_minutes']);
        $this->assertStringContainsString('无法判断', $r['next_step']);
    }

    // ---------- picked_up ----------

    public function test_picked_before_threshold_no_timeout(): void
    {
        $r = T::describe($this->mkOrder('picked_up', 'delivery', self::PICKED_MIN - 1));
        $this->assertNull($r, '阈值前(89min)不应下发超时对象');
    }

    public function test_picked_exactly_at_threshold_warns(): void
    {
        $r = T::describe($this->mkOrder('picked_up', 'delivery', self::PICKED_MIN));
        $this->assertIsArray($r);
        $this->assertSame('warning', $r['severity']);
        $this->assertSame(T::PHASE_PICKED, $r['phase']);
    }

    public function test_picked_over_threshold_warns(): void
    {
        $r = T::describe($this->mkOrder('picked_up', 'delivery', self::PICKED_MIN + 30));
        $this->assertIsArray($r);
        $this->assertSame('warning', $r['severity']);
        $this->assertNotEmpty($r['contact_hint']);
    }

    public function test_picked_missing_time_record_is_honest(): void
    {
        $r = T::describe($this->mkOrder('picked_up', 'delivery', null));
        $this->assertIsArray($r);
        $this->assertTrue($r['no_time_record'] ?? false);
        $this->assertSame('info', $r['severity']);
    }

    // ---------- delivered / canceled 边界 ----------

    public function test_delivered_returns_null(): void
    {
        $this->assertNull(T::describe($this->mkOrder('delivered', 'delivery', 5)));
    }

    public function test_canceled_returns_null(): void
    {
        $this->assertNull(T::describe($this->mkOrder('canceled', 'delivery', 5)));
    }

    // ---------- 不造假断言(需求5): 不提第三方重试/骑手/虚假ETA ----------

    public function test_delivery_messages_make_no_false_claims(): void
    {
        $orders = [
            $this->mkOrder('handover', 'delivery', self::HANDOVER_MIN + 30),
            $this->mkOrder('picked_up', 'delivery', self::PICKED_MIN + 60),
            $this->mkOrder('handover', 'delivery', null),
        ];
        // 禁词: 声称在重试 / 已有骑手 / 给出明确到达时间
        $forbidden = ['正在重试', '重新派单', '已为你安排', '已有骑手', '骑手已接单', '预计.*到达', '预计送达时间为'];
        foreach ($orders as $o) {
            $r = T::describe($o);
            $this->assertIsArray($r);
            $text = ($r['title'] ?? '') . ' ' . ($r['next_step'] ?? '') . ' ' . ($r['contact_hint'] ?? '');
            foreach ($forbidden as $bad) {
                $this->assertDoesNotMatchRegularExpression('/' . $bad . '/u', $text, "不得出现虚假表述: {$bad}");
            }
        }
    }

    // ---------- 阶段映射边界(phase) ----------

    public function test_phase_mapping_boundaries(): void
    {
        $this->assertSame(T::PHASE_HANDOVER, T::phase($this->mkOrder('handover', 'delivery', 1)));
        $this->assertSame(T::PHASE_PICKED, T::phase($this->mkOrder('picked_up', 'delivery', 1)));
        $this->assertNull(T::phase($this->mkOrder('handover', 'take_away', 1)), 'take_away handover 不在范围');
        $this->assertNull(T::phase($this->mkOrder('delivered', 'delivery', 1)));
        $this->assertNull(T::phase($this->mkOrder('canceled', 'delivery', 1)));
    }
}
