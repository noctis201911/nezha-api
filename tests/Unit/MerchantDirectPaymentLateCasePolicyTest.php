<?php

namespace Tests\Unit;

use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentLateCasePolicy as Policy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MerchantDirectPaymentLateCasePolicyTest extends TestCase
{
    public function test_timed_out_order_never_resurrects_while_late_case_moves_independently(): void
    {
        $state = Policy::transition(
            Policy::STATE_REVIEW_PENDING,
            Policy::EVENT_PAYMENT_ATTRIBUTED,
            Policy::CHANNEL_USDT_TRC20,
        );
        $this->assertSame(Policy::STATE_REFUND_REQUIRED, $state);
        $this->assertSame('canceled', Policy::orderStatus());

        $state = Policy::transition(
            $state,
            Policy::EVENT_MERCHANT_REFUND_SUBMITTED,
            Policy::CHANNEL_USDT_TRC20,
        );
        $this->assertSame(Policy::STATE_USDT_REFUND_VERIFICATION_PENDING, $state);
        $this->assertSame('canceled', Policy::orderStatus());

        $state = Policy::transition(
            $state,
            Policy::EVENT_USDT_REFUND_VERIFIED,
            Policy::CHANNEL_USDT_TRC20,
        );
        $this->assertSame(Policy::STATE_CLOSED_REFUNDED, $state);
        $this->assertSame('canceled', Policy::orderStatus());
    }

    public function test_exchange_refund_address_is_collected_by_merchant_without_a_platform_ownership_gate(): void
    {
        $this->assertSame(
            'merchant_contacts_customer_for_address',
            Policy::refundDestinationMode(Policy::WALLET_EXCHANGE),
        );
        $this->assertSame(
            'original_sender_address',
            Policy::refundDestinationMode(Policy::WALLET_SELF_CUSTODY),
        );
    }

    public function test_overpayment_refund_uses_the_negotiated_net_amount_without_a_platform_fee_default(): void
    {
        $terms = Policy::refundTerms('1200000', '1175000');

        $this->assertSame('1200000', $terms['received_amount_atomic']);
        $this->assertSame('1175000', $terms['refund_amount_atomic']);
        $this->assertSame('25000', $terms['negotiated_fee_amount_atomic']);
        $this->assertSame('merchant_customer_negotiated_net_amount', $terms['amount_source']);
        $this->assertFalse($terms['customer_confirmation_required']);

        $this->assertSame('0', Policy::refundTerms('1200000', '1200000')['negotiated_fee_amount_atomic']);
    }

    public function test_alipay_closes_on_merchant_declaration_but_does_not_claim_provider_verification(): void
    {
        $state = Policy::transition(
            Policy::STATE_REFUND_REQUIRED,
            Policy::EVENT_MERCHANT_REFUND_SUBMITTED,
            Policy::CHANNEL_ALIPAY,
        );

        $this->assertSame(Policy::STATE_CLOSED_REFUNDED, $state);
        $this->assertSame(Policy::EVIDENCE_MERCHANT_DECLARED, Policy::closureEvidence(Policy::CHANNEL_ALIPAY));
        $this->assertFalse(Policy::isProviderVerifiedClosure(Policy::CHANNEL_ALIPAY));

        $this->assertSame(
            Policy::STATE_DISPUTED,
            Policy::transition(
                $state,
                Policy::EVENT_CUSTOMER_REPORTS_NOT_RECEIVED,
                Policy::CHANNEL_ALIPAY,
            ),
        );
        $this->assertSame('canceled', Policy::orderStatus());
    }

    public function test_usdt_requires_strict_chain_facts_before_closure(): void
    {
        $submitted = Policy::transition(
            Policy::STATE_REFUND_REQUIRED,
            Policy::EVENT_MERCHANT_REFUND_SUBMITTED,
            Policy::CHANNEL_USDT_BEP20,
        );

        $this->assertSame(Policy::STATE_USDT_REFUND_VERIFICATION_PENDING, $submitted);
        $this->assertSame(Policy::EVIDENCE_CHAIN_VERIFIED, Policy::closureEvidence(Policy::CHANNEL_USDT_BEP20));
        $this->assertTrue(Policy::isProviderVerifiedClosure(Policy::CHANNEL_USDT_BEP20));
        $this->assertSame([
            'receipt_success',
            'finalized_or_solidified',
            'usdt_contract_matches_network',
            'destination_matches_case_address',
            'amount_matches_negotiated_refund_atomic',
        ], Policy::usdtClosureRequirements());

        $closed = Policy::transition(
            $submitted,
            Policy::EVENT_USDT_REFUND_VERIFIED,
            Policy::CHANNEL_USDT_BEP20,
        );
        $this->assertSame(Policy::STATE_CLOSED_REFUNDED, $closed);

        $this->expectException(InvalidArgumentException::class);
        Policy::transition(
            $closed,
            Policy::EVENT_CUSTOMER_REPORTS_NOT_RECEIVED,
            Policy::CHANNEL_USDT_BEP20,
        );
    }

    public function test_rejected_usdt_chain_evidence_returns_case_to_refund_required(): void
    {
        $this->assertSame(
            Policy::STATE_REFUND_REQUIRED,
            Policy::transition(
                Policy::STATE_USDT_REFUND_VERIFICATION_PENDING,
                Policy::EVENT_USDT_REFUND_REJECTED,
                Policy::CHANNEL_USDT_TRC20,
            ),
        );
    }

    public function test_alipay_cannot_be_mislabeled_as_a_usdt_verification_state(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Policy::transition(
            Policy::STATE_USDT_REFUND_VERIFICATION_PENDING,
            Policy::EVENT_USDT_REFUND_VERIFIED,
            Policy::CHANNEL_ALIPAY,
        );
    }

    public function test_no_payment_found_closes_only_the_case_and_keeps_order_canceled(): void
    {
        $this->assertSame(
            Policy::STATE_CLOSED_NO_PAYMENT,
            Policy::transition(
                Policy::STATE_REVIEW_PENDING,
                Policy::EVENT_PAYMENT_NOT_ATTRIBUTED,
                Policy::CHANNEL_ALIPAY,
            ),
        );
        $this->assertSame('canceled', Policy::orderStatus());
    }

    public function test_amount_contract_rejects_non_atomic_or_over_refund_values(): void
    {
        foreach ([
            ['10.5', '10'],
            ['010', '10'],
            ['10', '0'],
            ['10', '11'],
        ] as [$received, $refund]) {
            try {
                Policy::refundTerms($received, $refund);
                $this->fail("Invalid refund terms were accepted: {$received} / {$refund}.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_policy_is_pure_and_has_no_database_or_network_dependency(): void
    {
        $source = file_get_contents((new \ReflectionClass(Policy::class))->getFileName());
        $this->assertIsString($source);

        foreach (['Illuminate\\', 'DB::', 'Http::', 'Guzzle', 'curl_', 'file_get_contents("http'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }
}
