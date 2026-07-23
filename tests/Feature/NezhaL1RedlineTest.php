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
 * 🔴 安全墙: tests/bootstrap.php 在 Laravel 启动前强制 sqlite :memory:；生产 config cache 会被直接拒绝。
 *   DatabaseTransactions 只负责用例隔离；配合内存实例与隔离 fixture，
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

    // ───────────────── L1-2 / L1-3 顾客付款前绑定的订单级退款目标 ─────────────────

    /**
     * lock_route 只接受订单本身(签名仅 $order), 结构上无法被喂任意目标地址。
     * USDT 目标只能来自付款前消费的顾客退款凭据；tx.from 仅作来源证据。
     */
    public function test_L1_2_3_refund_route_cannot_take_arbitrary_destination(): void
    {
        $ref = new ReflectionMethod(NezhaRefundControl::class, 'lock_route');
        $this->assertSame(1, $ref->getNumberOfParameters(),
            'L1-2/3 违反: lock_route 新增了参数(疑似可传入退款目标地址)。退款目标只能由订单快照解析, 不可外部指定。');

        // 通道未知/默认订单 → note 必须明确"禁止退第三方", 且不返回任何外部可控目标地址。
        $order = new Order();
        $order->payment_method = 'cash_on_delivery';
        $route = NezhaRefundControl::lock_route($order);
        $this->assertStringContainsString('禁止退第三方', $route['note'] ?? '',
            'L1-2/3 违反: lock_route 未声明"禁止退第三方"。');
        $this->assertArrayNotHasKey('arbitrary_address', $route,
            'L1-2/3 违反: lock_route 返回了外部可控目标地址字段。');

        $control = file_get_contents(app_path('CentralLogics/NezhaRefundControl.php'));
        $this->assertStringContainsString(
            'NezhaCustomerRefundAddressCredentialService::snapshotForOrder',
            $control,
            'L1-2/3 违反: lock_route 未读取付款前消费的退款地址凭据。'
        );
        $this->assertStringNotContainsString(
            "'locked_to_address' => \$from",
            $control,
            'L1-2/3 违反: tx.from 被恢复为退款目标。'
        );
        $this->assertStringContainsString(
            "'payment_from_address' => \$paymentFrom",
            $control,
            'L1-2/3 违反: tx.from 未被限制在来源证据字段。'
        );
    }

    public function test_L1_2_3_payment_submission_consumes_both_address_credentials_atomically(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/V1/OrderController.php'));
        $resolvePayment = strpos($source, 'NezhaPaymentAddressCredentialService::resolveForProof');
        $resolveRefund = strpos(
            $source,
            'NezhaCustomerRefundAddressCredentialService::resolveForProof'
        );
        $transaction = strpos($source, 'DB::beginTransaction()', $resolveRefund ?: 0);
        $consumePair = strpos(
            $source,
            'NezhaCustomerRefundAddressCredentialService::consumeWithPaymentCredential',
            $transaction ?: 0
        );
        $commit = strpos($source, 'DB::commit()', $consumePair ?: 0);

        $this->assertNotFalse($resolvePayment);
        $this->assertNotFalse($resolveRefund);
        $this->assertNotFalse($transaction);
        $this->assertNotFalse($consumePair);
        $this->assertNotFalse($commit);
        $this->assertLessThan($transaction, $resolveRefund);
        $this->assertLessThan($consumePair, $transaction);
        $this->assertLessThan($commit, $consumePair);
    }

    public function test_L1_2_3_confirmation_requires_verified_payment_atomic_snapshot(): void
    {
        $logic = file_get_contents(app_path('CentralLogics/OrderLogic.php'));
        $service = file_get_contents(
            app_path('CentralLogics/NezhaCustomerRefundAddressCredentialService.php')
        );

        $this->assertStringContainsString(
            'ensurePaymentEvidenceForConfirmation($order)',
            $logic
        );
        $this->assertStringContainsString(
            "'payment_chain_evidence_not_verified'",
            $service
        );
        $this->assertStringContainsString(
            'paid_asset_amount_atomic',
            $service
        );
        $this->assertStringContainsString(
            "NezhaRefundControl::verify_refund_tx",
            $service
        );
    }

    public function test_L1_2_3_refund_tx_must_match_destination_network_contract_and_atomic_amount(): void
    {
        $method = new ReflectionMethod(NezhaRefundControl::class, 'verify_refund_tx');
        $parameters = collect($method->getParameters())->keyBy(fn ($parameter) => $parameter->getName());
        $this->assertSame('string', (string) $parameters['expectAtomic']->getType());

        $source = file_get_contents(app_path('CentralLogics/NezhaRefundControl.php'));
        foreach ([
            'amountMatches',
            'expectContract',
            'required_confirmations',
            'verification_pending',
            "NezhaUsdtAddress::equals",
        ] as $guard) {
            $this->assertStringContainsString($guard, $source);
        }
        $this->assertStringNotContainsString(
            'float $expectAmount',
            $source,
            'L1-2/3 违反: 退款 verifier 恢复使用 float。'
        );
        $this->assertStringNotContainsString(
            '$amount + 1e-9',
            $source,
            'L1-2/3 违反: 退款金额恢复浮点容差比较。'
        );
    }

    public function test_L1_2_3_usdt_refund_hash_is_required_and_verified_before_transition(): void
    {
        $vendor = file_get_contents(app_path('Http/Controllers/Vendor/OrderController.php'));
        $admin = file_get_contents(app_path('Http/Controllers/Admin/NezhaRefundController.php'));
        $record = file_get_contents(app_path('Models/NezhaRefundRecord.php'));
        $views = file_get_contents(resource_path('views/vendor-views/order/order-view.blade.php'))
            .file_get_contents(resource_path('views/vendor-views/order/partials/_detail_modes.blade.php'));

        $this->assertStringContainsString('NezhaRefundControl::verifyAndComplete', $vendor);
        $this->assertStringContainsString('NezhaRefundControl::verifyAndComplete', $admin);
        $this->assertStringContainsString("\$verifyStatus !== 'verified'", $record);
        $this->assertStringContainsString('! $record->reconfirmed_at', $record);
        $this->assertStringContainsString('name="refund_tx_hash"', $views);
        $this->assertStringContainsString('required', $views);
        foreach (['merchant_refund_tx', 'merchant_note', 'actual_refund_amount'] as $deadField) {
            $this->assertStringNotContainsString($deadField, $views);
        }
    }

    public function test_refund_binding_schema_has_required_integrity_and_encryption_guards(): void
    {
        $migration = file_get_contents(database_path(
            'migrations/2026_07_23_130000_create_nezha_customer_refund_address_credentials.php'
        ));
        $model = file_get_contents(app_path('Models/NezhaCustomerRefundAddressCredential.php'));

        $this->assertStringContainsString(
            "\$table->unique('consumed_order_id', 'nz_refund_cred_order_uq')",
            $migration
        );
        $this->assertStringContainsString('nz_payment_tx_fingerprint_uq', $migration);
        $this->assertStringContainsString(
            'payment_tx_hash_reused_or_changed',
            file_get_contents(app_path(
                'CentralLogics/NezhaCustomerRefundAddressCredentialService.php'
            ))
        );
        $this->assertStringContainsString("ENCRYPTION='Y'", $migration);
        $this->assertStringContainsString(
            'Refusing to drop consumed or retained customer refund address evidence',
            $migration
        );
        $this->assertStringContainsString("'address_snapshot' => 'encrypted'", $model);
        $this->assertStringContainsString("'secret_hash'", $model);
    }

    public function test_L1_1_bound_refund_still_never_uses_platform_wallet(): void
    {
        $sources = [
            file_get_contents(app_path('CentralLogics/NezhaCustomerRefundAddressCredentialService.php')),
            file_get_contents(app_path('CentralLogics/NezhaRefundReconfirmationService.php')),
            file_get_contents(app_path('CentralLogics/NezhaRefundControl.php')),
        ];
        $joined = implode("\n", $sources);

        foreach ([
            'wallet_transaction',
            'add_fund',
            'create_transaction',
            'withdraw',
            'auto_transfer',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($joined));
        }
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

    /** 退款目标必须在执行前独立筛查；命中或名单未知态都只能 hold。 */
    public function test_L1_6_refund_destination_screen_is_fail_closed(): void
    {
        $screen = file_get_contents(base_path('app/CentralLogics/NezhaSanctionScreen.php'));
        $refund = file_get_contents(base_path('app/CentralLogics/NezhaRefundControl.php'));
        $migration = file_get_contents(
            base_path('database/migrations/2026_07_23_130000_create_nezha_customer_refund_address_credentials.php')
        );

        $this->assertStringContainsString('screen_refund_destination', $screen);
        $this->assertStringContainsString('sanction_list_unavailable_or_stale', $screen);
        $this->assertStringContainsString('NezhaSanctionScreen::screen_refund_destination($locked)', $refund);
        $this->assertStringContainsString('refund_destination_sanction_match', $refund);
        $this->assertStringContainsString('refund_destination_sanction_unresolved', $refund);
        $this->assertStringContainsString('nezha_refund_sanction_max_sync_age_hours', $migration);
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
        $this->assertStringContainsString('recordFrozenReversalOwed', $src,
            'L1-8 违反: 冻结期 refund_reversal 未记 frozen_reversal_owed(§C3 非回充需留痕待人工核算)。');
    }

    // ───────────────── L1-8③ step5 制裁实时 re-screen + approve 4 门 + 退出开关默认关 ─────────────────

    /**
     * step5 §D1/§D3/§H 结构守卫: 制裁实时 re-screen(screen_names 而非读入驻旧 screen_status·fail-closed)
     * + approve() 4 道 fail-closed 放款门 + 退出开关默认关。这些是资金流出闸(INVARIANTS L1-8③)。
     * 【源码守卫层: 保证调用点/放款门存在与开关默认态, 不替代资金闭环 staging 下单 harness(DESIGN §I 断言分层)】。
     */
    public function test_L1_8_step5_sanction_rescreen_and_approve_gates_present(): void
    {
        $src = file_get_contents(base_path('app/CentralLogics/NezhaOffboard.php'));

        // §D1: 审批放款前用【当前】名单实时 RE-run screen_names(实时制裁核验方法在)
        $this->assertStringContainsString('function rescreenSanctions(', $src,
            'L1-8③ 违反: rescreenSanctions(step5 制裁实时 re-screen)被移除。');
        $this->assertStringContainsString('NezhaKycScreen::screen_names(', $src,
            'L1-8③ 违反: rescreenSanctions 未实时 RE-run screen_names(制裁实时核验被移除 → 退款可能放行给受制裁主体)。');
        // fail-closed: 仅 clear 才置 sanction_rescreen_at(possible/hit 不置 → approve 拒放行)
        $this->assertStringContainsString("if (\$st === 'clear')", $src,
            'L1-8③ 违反: 制裁 re-screen 的 clear 分支被改(fail-closed 结构破坏)。');
        $this->assertStringContainsString('$s->sanction_rescreen_at = Carbon::now();', $src,
            'L1-8③ 违反: clear 时置 sanction_rescreen_at 被移除/外提(possible/hit 可能被误放行)。');

        // approve() 4 道 fail-closed 放款门(状态/冷静期/制裁 re-screen/户名核对)缺一即资金流出无门
        $this->assertStringContainsString("\$s->status !== 'applied'", $src,
            'L1-8 违反: approve 状态门(applied)被删。');
        $this->assertStringContainsString('Carbon::now()->lt($s->cooldown_until)', $src,
            'L1-8 违反: approve 冷静期门被删。');
        $this->assertStringContainsString('$s->sanction_rescreen_at === null', $src,
            'L1-8 违反: approve 制裁 re-screen 门被删(未 re-screen 即可放款)。');
        $this->assertStringContainsString('!$s->holder_verified', $src,
            'L1-8 违反: approve 户名核对门(holder_verified)被删。');

        // 退出功能开关默认关(灰度·服务端强制); 默认改为 '1' = 部署即暴露资金流出路径
        $this->assertStringContainsString("self::cfg('nezha_offboard_status', '0')", $src,
            'L1-8 违反: 退出开关 nezha_offboard_status 默认态被改(应默认 0 关, 上线灰度由用户开)。');
    }

    /** L1-8 中途退回押金(S3-B·NezhaGuaranteeRefund) 逐门结构守卫 —— /debate 硬化的门被删即红。 */
    public function test_L1_8_guarantee_refund_gates_present(): void
    {
        $src = file_get_contents(base_path('app/CentralLogics/NezhaGuaranteeRefund.php'));
        $this->assertStringContainsString("self::cfg('nezha_topup_refund_status', 0)", $src,
            'L1-8 违反: 中途退款开关默认态被改(应默认 0 关 dormant)。');
        $this->assertStringContainsString('is_deposit_credit_frozen', $src,
            'L1-8 违反: 退款互斥门被降级(应用 is_deposit_credit_frozen 覆盖 owing, 非仅 settling)。');
        $this->assertStringContainsString('NezhaKycScreen::screen_names(', $src,
            'L1-8③ 违反: 退款制裁实时复筛被移除。');
        $this->assertStringContainsString('NezhaKycScreen::record_risk(', $src,
            'L1-8③ 违反: 制裁 possible/hit 的 fail-closed 转人工留痕被移除。');
        $this->assertStringContainsString('normHolder', $src,
            'L1-8② 违反: CJK-safe 户名归一化被移除(中文商户户名核对会失效)。');
        $this->assertStringContainsString('hash_equals((string) $req->kyc_apply_fp', $src,
            'L1-8② 违反: 身份指纹对比被移除(申请后改第三方账户可绕过)。');
        $this->assertStringContainsString('lockForUpdate()', $src,
            'L1-8 违反: 放款钱包行锁被移除(并发可超额抽干)。');
        $this->assertStringContainsString('$req->guarantee_snapshot', $src,
            'L1-8 违反: C4 审批快照校验被移除(放款竞态防线破坏)。');
        $this->assertStringContainsString('押金应缴档未设', $src,
            'L1-8 违反: tier=NULL 的 fail-closed 挡退被移除。');
    }
}
