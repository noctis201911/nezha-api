<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaOrderTimeout as T;
use App\Models\Order;
use App\Models\OfflinePayments;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 哪吒 — 付款凭证识别(凭证审核 phase A)测试。
 *
 * 🔴 安全: 本仓 phpunit.xml 未启用独立测试库, APP_ENV=testing 仍连生产 MySQL。
 * 故只用 DatabaseTransactions(事务回滚, 绝不 RefreshDatabase=清库); 全部用内存 Order/
 * OfflinePayments 实例(从不 save), 零订单写入。describe() 仅只读 business_settings。
 *
 * 锁定修复(2026-06-22): USDT 链下付款只交「交易哈希」(method_fields text/required)的已付款单,
 * 此前 hasProofImage 只认图片 → 被超时sweep当未付款(cancel_unpaid 10min, 无退款留痕)取消。
 * 修复后 hasPaymentProof = 图 或 有效64位hex哈希 → 哈希单走与图片单一致的 cancel(20min)+退款留痕路径。
 * 同时: 乱填/非hex文本不算有效凭证(避免给未真付款单凭空造退款义务)。
 */
class NezhaProofDetectionTest extends TestCase
{
    use DatabaseTransactions;

    private const VALID_HASH = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2'; // 64 hex
    private const VALID_HASH_0X = '0xa1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2';

    private function mkUsdt(string $hashVal, ?string $imgPath = null): Order
    {
        $o = new Order();
        $o->order_status   = 'pending';
        $o->payment_method = 'offline_payment';
        $o->order_type     = 'delivery';
        $o->pending        = now()->subMinutes(12); // 已过 unpaid_cancel(10) 未过 cancel(20)
        $info = ['method_id' => '2', 'method_name' => 'USDT · 波场TRC20', '交易哈希(Hash)' => $hashVal];
        if ($imgPath !== null) { $info['付款截图(可选)'] = $imgPath; }
        $op = new OfflinePayments();
        $op->payment_info  = json_encode($info, JSON_UNESCAPED_UNICODE);
        $op->method_fields = json_encode([
            ['input_field_name' => '交易哈希(Hash)', 'input_type' => 'text', 'is_required' => 1],
            ['input_field_name' => '付款截图(可选)', 'input_type' => 'file', 'is_required' => 0],
        ], JSON_UNESCAPED_UNICODE);
        $op->created_at = now()->subMinutes(12);
        $o->setRelation('offline_payments', $op);
        return $o;
    }

    private function mkAlipay(string $imgPath): Order
    {
        $o = new Order();
        $o->order_status   = 'pending';
        $o->payment_method = 'offline_payment';
        $o->order_type     = 'delivery';
        $o->pending        = now()->subMinutes(12);
        $op = new OfflinePayments();
        $op->payment_info  = json_encode(['method_id' => '1', 'method_name' => '支付宝', '付款截图' => $imgPath], JSON_UNESCAPED_UNICODE);
        $op->method_fields = json_encode([
            ['input_field_name' => '付款截图', 'input_type' => 'file', 'is_required' => 1],
        ], JSON_UNESCAPED_UNICODE);
        $op->created_at = now()->subMinutes(12);
        $o->setRelation('offline_payments', $op);
        return $o;
    }

    // ---------- 有效 USDT 哈希单 = 有凭证 ----------
    public function test_usdt_valid_hash_is_proof(): void
    {
        $o = $this->mkUsdt(self::VALID_HASH);
        $this->assertFalse(T::hasProofImage($o), '纯哈希无图: hasProofImage 应 false');
        $this->assertTrue(T::hasValidHashText($o), '有效64hex哈希: hasValidHashText 应 true');
        $this->assertTrue(T::hasPaymentProof($o), '哈希单应被视为已提交凭证');
    }

    public function test_usdt_hash_with_0x_prefix_is_proof(): void
    {
        $this->assertTrue(T::hasValidHashText($this->mkUsdt(self::VALID_HASH_0X)), '0x前缀的64hex也应有效');
    }

    // ---------- 乱填/非hex 不算凭证(防凭空造退款义务) ----------
    public function test_usdt_garbage_hash_is_not_proof(): void
    {
        foreach (['', 'not-a-hash', '123', 'xyz 随便填', 'zzzz...'] as $bad) {
            $o = $this->mkUsdt($bad);
            $this->assertFalse(T::hasValidHashText($o), "乱填'$bad'不应算有效哈希");
            $this->assertFalse(T::hasPaymentProof($o), "乱填'$bad'不应算已付凭证");
        }
    }

    // ---------- 支付宝图片凭证 ----------
    public function test_alipay_image_is_proof(): void
    {
        $o = $this->mkAlipay('offline_payment/2026-06-22-abc.webp');
        $this->assertTrue(T::hasProofImage($o), '有图: hasProofImage 应 true');
        $this->assertTrue(T::hasPaymentProof($o));
    }

    public function test_alipay_empty_image_is_not_proof(): void
    {
        $o = $this->mkAlipay('');
        $this->assertFalse(T::hasPaymentProof($o), '空图不应算已付凭证');
    }

    // ---------- describe(): 哈希单走「已付款·原路退」而非「无需退款」 ----------
    public function test_describe_hash_order_treated_as_paid_not_unpaid(): void
    {
        $r = T::describe($this->mkUsdt(self::VALID_HASH));
        $this->assertIsArray($r);
        $this->assertSame(T::PHASE_PROOF, $r['phase']);
        // 修复前: refund_method='未完成付款，无需退款'; 修复后应为联系商家原路退
        $this->assertStringNotContainsString('无需退款', (string) ($r['refund_method'] ?? ''),
            '哈希已付款单不得显示「无需退款」');
        $this->assertStringContainsString('商家', (string) ($r['refund_method'] ?? ''),
            '应显示联系商家原路退回');
    }

    public function test_describe_no_proof_order_still_unpaid(): void
    {
        $r = T::describe($this->mkUsdt('garbage'));
        $this->assertIsArray($r);
        $this->assertSame(T::PHASE_PROOF, $r['phase']);
        // 真未付款(无有效凭证)仍走「无需退款」路径, 不得误放行
        $this->assertStringContainsString('无需退款', (string) ($r['refund_method'] ?? ''),
            '无有效凭证单应仍判未付款·无需退款');
    }
}
