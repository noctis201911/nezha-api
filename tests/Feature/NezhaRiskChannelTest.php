<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaRiskControl;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 哪吒[风控通道收口 · 资金完整性守卫]
 *
 * 验证 place_order 服务端复评不信任客户端自由串 payment_channel:
 * evaluate_server_authoritative 由服务端 order->payment_method 权威判定通道——
 * 线下支付通道未定时对 rmb/usdt 两套阈值取最严, 使伪报通道换不到更松阈值。
 *
 * 🔴 安全墙强制 sqlite :memory:；DatabaseTransactions 隔离阈值 fixture，
 *   不写订单。覆盖局限(诚实): 只验通道选择逻辑(评估层), 未跑端到端真实下单(那条在 QA 报告里用真单验过 amount 维度)。
 */
class NezhaRiskChannelTest extends TestCase
{
    use DatabaseTransactions;

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

    protected function setUp(): void
    {
        parent::setUp();
        // 事务内: 风控开 + usdt 单笔严(1000) / 法币单笔松(99999999), 制造通道阈值分化
        $this->setSetting('nezha_risk_control_status', 1);
        $this->setSetting('nezha_risk_usdt_single_limit', 1000);
        $this->setSetting('nezha_risk_single_order_limit', 99999999);
        // 其余维度放宽避免干扰(累计/大额都设很高), 频次按游客(无 user_id)不统计
        $this->setSetting('nezha_risk_usdt_daily_limit', 99999999);
        $this->setSetting('nezha_risk_daily_cumulative_limit', 99999999);
        $this->setSetting('nezha_risk_large_amount_threshold', 99999999);
    }

    /** 线下支付: 即便客户端声称 rmb(松阈值), 也按最严(usdt)评估 → 5000 被拒。 */
    public function test_offline_payment_ignores_claimed_channel_and_uses_strictest(): void
    {
        $ctx = [
            'user_id' => null, // 游客: 跳过累计/频次, 只看单笔(隔离通道变量)
            'guest_id' => 'qa-guest',
            'restaurant_id' => 6,
            'order_amount' => 5000.0,
            'payment_channel' => 'rmb', // 客户端伪报"法币"想用松阈值
        ];
        $res = NezhaRiskControl::evaluate_server_authoritative($ctx, 'offline_payment');
        $this->assertSame('reject', $res['action'],
            '通道收口失效: 线下支付伪报 rmb 仍能绕过 usdt 严阈值(5000>1000应被拒)。');
        $this->assertSame('single_order_limit', $res['reject_code'] ?? null);
    }

    /** 非线下支付(COD): 用 'other'=法币阈值(松), 5000 通过 → 证明不会对所有单一刀切用最严。 */
    public function test_non_offline_payment_uses_other_channel_not_strictest(): void
    {
        $ctx = [
            'user_id' => null,
            'guest_id' => 'qa-guest',
            'restaurant_id' => 6,
            'order_amount' => 5000.0,
            'payment_channel' => 'usdt', // 客户端乱报也无所谓, COD 一律按 other
        ];
        $res = NezhaRiskControl::evaluate_server_authoritative($ctx, 'cash_on_delivery');
        $this->assertSame('pass', $res['action'],
            'COD 单(法币阈值 99999999)不应被 usdt 严阈值误拒(5000应通过)。');
    }

    /** 线下支付低于两套阈值 → 通过(最严逻辑不误伤合规小单)。 */
    public function test_offline_payment_below_both_limits_passes(): void
    {
        $ctx = [
            'user_id' => null,
            'guest_id' => 'qa-guest',
            'restaurant_id' => 6,
            'order_amount' => 500.0, // < usdt 1000 且 < 法币 99999999
            'payment_channel' => 'rmb',
        ];
        $res = NezhaRiskControl::evaluate_server_authoritative($ctx, 'offline_payment');
        $this->assertSame('pass', $res['action'], '合规小单(500<两套阈值)不应被拒。');
    }
}
