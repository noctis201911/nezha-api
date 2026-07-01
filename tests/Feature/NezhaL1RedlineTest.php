<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaRefundControl;
use App\CentralLogics\NezhaSanctionScreen;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 哪吒[L1 合规红线 CI 断言 · 子项C]
 *
 * 把 INVARIANTS.md 的 L1 红线写成自动化断言: 红线被违反(开关被翻开/结构守卫被删)
 * 时测试直接红, 而不是等 QA 才发现。
 *
 * 🔴 安全: 本仓 phpunit.xml 未启用独立测试库, APP_ENV=testing 仍连生产 MySQL。
 *   故全部 DatabaseTransactions(事务回滚, 绝不 RefreshDatabase=清库) + 内存实例,
 *   临时插入的制裁地址随事务回滚, 零持久写入。
 *
 * 覆盖: L1-1(平台不碰钱) / L1-2,3(退款只原路) / L1-5(二清腿已拔) / L1-6(制裁命中即拒)。
 * 覆盖局限(诚实): 这是"结构/开关层"红线断言(开关态、方法签名、源码守卫、匹配器命中);
 *   不替代端到端资金闭环 QA(QA_FUNDLOOP_PLAYBOOK)。链上反查(reverse_lookup)依赖外网,
 *   故制裁测试只验确定性的地址匹配器核心, 不打真链。
 */
class NezhaL1RedlineTest extends TestCase
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

    // ───────────────── L1-1 平台全程不碰资金 ─────────────────

    /** 平台持币型支付通道(数字支付/钱包/充值)必须全关; 任一被翻开=平台开始碰钱=违反 L1-1。 */
    public function test_L1_1_platform_holds_no_money_gateways_must_be_off(): void
    {
        $digital = json_decode(DB::table('business_settings')->where('key', 'digital_payment')->value('value') ?? '{}', true);
        $this->assertSame('0', (string) ($digital['status'] ?? '0'),
            'L1-1 违反: digital_payment 被开启 → 平台聚合收款=二清。B方案下必须关。');

        $this->assertSame('0', (string) (DB::table('business_settings')->where('key', 'wallet_status')->value('value') ?? '0'),
            'L1-1 违反: 顾客钱包(wallet_status)被开启 → 平台持币。B方案下必须关。');

        $this->assertSame('0', (string) (DB::table('business_settings')->where('key', 'add_fund_status')->value('value') ?? '0'),
            'L1-1 违反: 钱包充值(add_fund_status)被开启 → 平台归集顾客预存资金=持币。必须关。');
    }

    // ───────────────── L1-5 二清打款腿已拔(直付单不累加 total_earning) ─────────────────

    /**
     * 直付单(offline_payment)在 OrderLogic 记账时不得累加 total_earning(平台不欠商家/永不打款)。
     * 结构守卫: create_transaction 内 total_earning 累加必须被 if(!$is_direct_pay) 包住。
     * 守卫被删=直付单重新累加=平台对商家产生应付=退回二清打款结构。
     */
    public function test_L1_5_direct_pay_does_not_accrue_total_earning_guard_present(): void
    {
        $src = file_get_contents(base_path('app/CentralLogics/OrderLogic.php'));

        $this->assertStringContainsString(
            "\$is_direct_pay = (\$order->payment_method == 'offline_payment')", $src,
            'L1-5 违反: 直付判定 $is_direct_pay 被移除/改写; 无法区分直付单, total_earning 守卫失效。'
        );
        // total_earning 的累加(+=)必须存在于 !is_direct_pay 分支(即 if(!$is_direct_pay){...}内)
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$is_direct_pay\s*\)/', $src,
            'L1-5 违反: if(!$is_direct_pay) 守卫被删; 直付单可能重新累加 total_earning → 平台欠商家 → 二清打款腿复活。'
        );
    }

    // ───────────────── L1-2 / L1-3 退款只原路退回 ─────────────────

    /**
     * lock_route 只接受订单本身(签名仅 $order), 结构上无法被喂任意目标地址。
     * 退款目标只能来自原始付款(USDT=原tx反查的from地址 / 法币=原付款人), 一律禁止第三方。
     */
    public function test_L1_2_3_refund_route_cannot_take_arbitrary_destination(): void
    {
        $ref = new ReflectionMethod(NezhaRefundControl::class, 'lock_route');
        $this->assertSame(1, $ref->getNumberOfParameters(),
            'L1-2/3 违反: lock_route 新增了参数(疑似可传入退款目标地址)。退款目标只能由原始付款反查, 不可外部指定。');

        // 通道未知/默认订单 → note 必须明确"禁止退第三方", 且不返回任何外部可控目标地址。
        $order = new Order();
        $order->payment_method = 'cash_on_delivery';
        $route = NezhaRefundControl::lock_route($order);
        $this->assertStringContainsString('禁止退第三方', $route['note'] ?? '',
            'L1-2/3 违反: lock_route 未声明"禁止退第三方"。');
        $this->assertArrayNotHasKey('arbitrary_address', $route,
            'L1-2/3 违反: lock_route 返回了外部可控目标地址字段。');
    }

    // ───────────────── L1-6 制裁名单命中即拒 ─────────────────

    /** 制裁筛查总开关默认开; 关闭=L1-6 不生效(关闭须用户批准, 此处断言默认态为开)。 */
    public function test_L1_6_sanction_screen_enabled_by_default(): void
    {
        // 不改动线上开关; 仅断言当前生效态为开(若被关, 红线提示用户)。
        $this->assertTrue(NezhaSanctionScreen::enabled(),
            'L1-6 违反: 制裁筛查(nezha_sanction_screen_status)被关闭 → USDT 来源地址不再筛查。关闭须用户批准。');
    }

    /** 制裁地址匹配器: 命中名单返回 matched, 干净地址返回 null。匹配器坏=命中漏放=违反 L1-6。 */
    public function test_L1_6_sanction_matcher_hits_listed_address_and_misses_clean(): void
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $tronAddr = 'T' . substr($alphabet, 0, 33); // 合法 Tron 地址格式(34位)
        $this->assertSame('tron', NezhaSanctionScreen::kind($tronAddr), '测试地址应被识别为 tron');

        // 事务内临时插入一条制裁地址(随事务回滚, 不留库)
        DB::table('nezha_sanction_addresses')->insert([
            'addr_kind'     => 'tron',
            'address'       => $tronAddr,
            'source'        => 'OFAC_SDN_TEST',
            'sdn_uid'       => '999999',
            'currency_type' => 'USDT',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $hit = NezhaSanctionScreen::screen_address($tronAddr);
        $this->assertNotNull($hit, 'L1-6 违反: 已列入制裁名单的地址未被匹配命中。');
        $this->assertSame('OFAC_SDN_TEST', $hit['source'] ?? null);

        // 干净地址(同格式但未入表)必须 miss
        $cleanAddr = 'T' . substr($alphabet, 1, 33);
        $this->assertNull(NezhaSanctionScreen::screen_address($cleanAddr),
            'L1-6 异常: 未入名单的地址被误命中(假阳性会误拒正常顾客)。');
    }

    // ───────────────── L1-9 平台不出资促销(商家自掏折扣账务定性) ─────────────────

    /** 商家自掏折扣(满减/POS·discount_on_product_by=vendor) 不得把 折扣×佣金率 记 admin_expense; 否则报表虚显平台补贴+重复扣净利, 违反 L1-9 / L1-1"平台不出资"。结构守卫: 禁止恢复 amount_admin 的 admin 拆分。 */
    public function test_L1_9_vendor_funded_discount_charges_no_platform_subsidy(): void
    {
        $src = file_get_contents(base_path('app/CentralLogics/OrderLogic.php'));
        $this->assertStringNotContainsString('$amount_admin = $comission?', $src,
            'L1-9 违反: vendor 折扣的"折扣×佣金率"admin 拆分被恢复 → 商家自掏促销被误记为平台出资(账务定性红线, 见 INVARIANTS L1-9)。');
        $this->assertStringContainsString('商家自掏折扣(满减/POS)100%记vendor', $src,
            'L1-9 违反: vendor 折扣 100% 记 vendor 的账务定性实现/说明被移除。');
    }

    // ───────────────── L1-8 退出结算 三账户隔离(INV-1)+净额公式 结构守卫 (step4-3) ─────────────────

    /**
     * 退出结算三腿"各退各账": deposit_refund 只置零 deposit / ad_refund 只置零 ad / guarantee_refund 只置零 guarantee,
     * 抵扣不跨户(INV-1 / L1-8④, 守 ad 资金隔离); net = 三账户和 − pending_clawback(无悬空 penalty)。
     * 【源码守卫层: 只保证账户列↔退款 type 配对与 net 公式不被改写; 资金置零/leg 幂等/C4 快照拒付
     *   由 staging 下单 harness 唯一验收(DESIGN §I 断言分层, 本测试不替代资金闭环)。】
     */
    public function test_L1_8_offboard_three_leg_account_isolation_and_net_formula(): void
    {
        $src = file_get_contents(base_path('app/CentralLogics/NezhaOffboard.php'));
        // 三腿严格一一配对(账户列 ↔ 退款 type), 不跨户
        $this->assertStringContainsString("self::payLeg(\$w, 'deposit_balance', 'deposit_refund'", $src,
            'L1-8 违反: deposit 腿(账户列,type)配对被改写。');
        $this->assertStringContainsString("self::payLeg(\$w, 'ad_balance', 'ad_refund'", $src,
            'L1-8 违反: ad 腿必须只置零 ad_balance 且 type=ad_refund(INV-1 ad 资金隔离)。');
        $this->assertStringContainsString("self::payLeg(\$w, 'guarantee_balance', 'guarantee_refund'", $src,
            'L1-8 违反: guarantee 腿必须只置零 guarantee_balance 且 type=guarantee_refund。');
        // payLeg 只按传入账户列置零(参数化, 不硬编码跨户写某余额)
        $this->assertMatchesRegularExpression('/\$w->\{\$balanceCol\}\s*=\s*0\s*;/', $src,
            'L1-8 违反: payLeg 不再按参数账户列置零(疑似硬编码跨户写余额)。');
        // net = 三账户和 − pending_clawback(不得引入无来源 penalty 减项)
        $this->assertStringContainsString('$deposit + $guarantee + $ad - $clawback', $src,
            'L1-8 违反: net 公式被改(应为 deposit+guarantee+ad−pending_clawback, 无 penalty)。');
    }

    /**
     * 退出冻结期 refund_reversal「记 shortfall 非回充」(§C3): 退出中/已退出的店(offboard_status!=active)
     * 退款不得自动回充 deposit(污染结算快照 / 打入已关闭死账户漏损), 改记 shortfall 待人工核算。
     */
    public function test_L1_8_frozen_refund_reversal_does_not_credit_deposit(): void
    {
        $src = file_get_contents(base_path('app/CentralLogics/OrderLogic.php'));
        $this->assertStringContainsString('NezhaOffboard::is_deposit_credit_frozen($order->restaurant_id)', $src,
            'L1-8 违反: refund_reversal 冻结判定被移除 → 退出中/已退出店会自动回充 deposit。');
        $this->assertStringContainsString('recordFrozenReversalShortfall', $src,
            'L1-8 违反: 冻结期 refund_reversal 未记 shortfall(§C3 非回充需留痕待人工核算)。');
    }
}
