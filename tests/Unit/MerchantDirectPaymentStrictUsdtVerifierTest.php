<?php

namespace Tests\Unit;

use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentLateCasePolicy as Policy;
use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentStrictUsdtVerifier as Verifier;
use PHPUnit\Framework\TestCase;

class MerchantDirectPaymentStrictUsdtVerifierTest extends TestCase
{
    private const DESTINATION = '0x2222222222222222222222222222222222222222';

    public function test_exact_finalized_transfer_event_is_confirmed(): void
    {
        $result = Verifier::evaluate(
            Policy::CHANNEL_USDT_BEP20,
            self::DESTINATION,
            '1175000',
            $this->observation()
        );

        $this->assertSame('confirmed', $result['status']);
        $this->assertNull($result['failure_code']);
        $this->assertSame(7, $result['event_index']);
        $this->assertSame('1175000', $result['amount_atomic']);
    }

    public function test_tx_sender_cannot_replace_transfer_event_destination_or_amount(): void
    {
        $observation = $this->observation();
        $observation['transaction_from'] = self::DESTINATION;
        $observation['events'][0]['to'] = '0x3333333333333333333333333333333333333333';

        $result = Verifier::evaluate(
            Policy::CHANNEL_USDT_BEP20,
            self::DESTINATION,
            '1175000',
            $observation
        );

        $this->assertSame('mismatch', $result['status']);
        $this->assertSame('transfer_mismatch', $result['failure_code']);
    }

    public function test_wrong_contract_extra_amount_or_non_finalized_event_never_confirms(): void
    {
        foreach ([
            'wrong contract' => ['contract' => '0x1111111111111111111111111111111111111111'],
            'extra amount' => ['amount_atomic' => '1175001'],
            'not finalized' => ['block_number' => '101'],
        ] as $case => $eventOverride) {
            $observation = $this->observation();
            $observation['events'][0] = array_replace($observation['events'][0], $eventOverride);
            $result = Verifier::evaluate(
                Policy::CHANNEL_USDT_BEP20,
                self::DESTINATION,
                '1175000',
                $observation
            );

            $this->assertNotSame('confirmed', $result['status'], $case);
        }
    }

    public function test_provider_failure_and_missing_finality_are_fail_closed(): void
    {
        $unavailable = Verifier::evaluate(
            Policy::CHANNEL_USDT_BEP20,
            self::DESTINATION,
            '1',
            ['provider_status' => 'unavailable']
        );
        $this->assertSame('unavailable', $unavailable['status']);

        $observation = $this->observation();
        unset($observation['finalized_block_number']);
        $missingFinality = Verifier::evaluate(
            Policy::CHANNEL_USDT_BEP20,
            self::DESTINATION,
            '1175000',
            $observation
        );
        $this->assertSame('unavailable', $missingFinality['status']);
        $this->assertSame('finality_unavailable', $missingFinality['failure_code']);
    }

    public function test_provider_evidence_is_allowlisted_and_bounded(): void
    {
        $result = Verifier::evaluate(
            Policy::CHANNEL_USDT_BEP20,
            self::DESTINATION,
            '1175000',
            $this->observation() + ['provider_evidence' => [
                'source' => 'bsc_rpc',
                'reason' => str_repeat('sensitive-detail-', 20),
                'secret' => 'must-not-survive',
            ]]
        );

        $this->assertSame(['source' => 'bsc_rpc'], $result['provider_evidence']);
    }

    private function observation(): array
    {
        return [
            'provider_status' => 'ok',
            'receipt_status' => 'success',
            'finalized_block_number' => '100',
            'events' => [[
                'event_index' => 7,
                'contract' => Verifier::BSC_USDT,
                'from' => '0x1111111111111111111111111111111111111111',
                'to' => self::DESTINATION,
                'amount_atomic' => '1175000',
                'block_number' => '100',
            ]],
        ];
    }
}
