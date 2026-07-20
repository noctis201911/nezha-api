<?php

namespace App\CentralLogics\MerchantDirectPayment;

use InvalidArgumentException;

final class MerchantDirectPaymentLateCasePolicy
{
    public const STATE_REVIEW_PENDING = 'late_payment_review_pending';

    public const STATE_REFUND_REQUIRED = 'late_payment_refund_required';

    public const STATE_USDT_REFUND_VERIFICATION_PENDING = 'late_payment_usdt_refund_verification_pending';

    public const STATE_CLOSED_NO_PAYMENT = 'late_payment_closed_no_payment';

    public const STATE_CLOSED_REFUNDED = 'late_payment_closed_refunded';

    public const STATE_DISPUTED = 'late_payment_refund_disputed';

    public const EVENT_PAYMENT_ATTRIBUTED = 'payment_attributed';

    public const EVENT_PAYMENT_NOT_ATTRIBUTED = 'payment_not_attributed';

    public const EVENT_MERCHANT_REFUND_SUBMITTED = 'merchant_refund_submitted';

    public const EVENT_USDT_REFUND_VERIFIED = 'usdt_refund_verified';

    public const EVENT_USDT_REFUND_REJECTED = 'usdt_refund_rejected';

    public const EVENT_CUSTOMER_REPORTS_NOT_RECEIVED = 'customer_reports_not_received';

    public const CHANNEL_ALIPAY = 'alipay';

    public const CHANNEL_USDT_TRC20 = 'usdt_trc20';

    public const CHANNEL_USDT_BEP20 = 'usdt_bep20';

    public const WALLET_SELF_CUSTODY = 'self_custody';

    public const WALLET_EXCHANGE = 'exchange';

    public const EVIDENCE_MERCHANT_DECLARED = 'merchant_declared';

    public const EVIDENCE_CHAIN_VERIFIED = 'chain_verified';

    /** @return list<string> */
    public static function states(): array
    {
        return [
            self::STATE_REVIEW_PENDING,
            self::STATE_REFUND_REQUIRED,
            self::STATE_USDT_REFUND_VERIFICATION_PENDING,
            self::STATE_CLOSED_NO_PAYMENT,
            self::STATE_CLOSED_REFUNDED,
            self::STATE_DISPUTED,
        ];
    }

    public static function transition(string $state, string $event, string $channel): string
    {
        self::assertChannel($channel);
        if ($state === self::STATE_USDT_REFUND_VERIFICATION_PENDING && ! self::isUsdt($channel)) {
            throw new InvalidArgumentException('A USDT refund verification state requires a USDT channel.');
        }

        return match ([$state, $event]) {
            [self::STATE_REVIEW_PENDING, self::EVENT_PAYMENT_ATTRIBUTED] => self::STATE_REFUND_REQUIRED,
            [self::STATE_REVIEW_PENDING, self::EVENT_PAYMENT_NOT_ATTRIBUTED] => self::STATE_CLOSED_NO_PAYMENT,
            [self::STATE_REFUND_REQUIRED, self::EVENT_MERCHANT_REFUND_SUBMITTED] => self::isUsdt($channel)
                    ? self::STATE_USDT_REFUND_VERIFICATION_PENDING
                    : self::STATE_CLOSED_REFUNDED,
            [self::STATE_USDT_REFUND_VERIFICATION_PENDING, self::EVENT_USDT_REFUND_VERIFIED] => self::STATE_CLOSED_REFUNDED,
            [self::STATE_USDT_REFUND_VERIFICATION_PENDING, self::EVENT_USDT_REFUND_REJECTED] => self::STATE_REFUND_REQUIRED,
            [self::STATE_CLOSED_REFUNDED, self::EVENT_CUSTOMER_REPORTS_NOT_RECEIVED] => $channel === self::CHANNEL_ALIPAY
                    ? self::STATE_DISPUTED
                    : throw new InvalidArgumentException('A chain-verified USDT closure is final.'),
            default => throw new InvalidArgumentException("Invalid late-payment transition: {$state} + {$event}."),
        };
    }

    public static function orderStatus(): string
    {
        return 'canceled';
    }

    public static function refundDestinationMode(string $walletType): string
    {
        return match ($walletType) {
            self::WALLET_SELF_CUSTODY => 'original_sender_address',
            self::WALLET_EXCHANGE => 'merchant_contacts_customer_for_address',
            default => throw new InvalidArgumentException("Unsupported payer wallet type: {$walletType}."),
        };
    }

    /**
     * The platform records the merchant/customer negotiated net refund amount. It does not
     * impose a fee default and does not require an in-platform customer confirmation gate.
     *
     * @return array{
     *     received_amount_atomic: string,
     *     refund_amount_atomic: string,
     *     negotiated_fee_amount_atomic: string,
     *     amount_source: string,
     *     customer_confirmation_required: bool
     * }
     */
    public static function refundTerms(string $receivedAmountAtomic, string $refundAmountAtomic): array
    {
        self::assertCanonicalPositiveInteger($receivedAmountAtomic, 'received amount');
        self::assertCanonicalPositiveInteger($refundAmountAtomic, 'refund amount');

        if (self::compareUnsignedIntegers($refundAmountAtomic, $receivedAmountAtomic) > 0) {
            throw new InvalidArgumentException('The negotiated refund amount cannot exceed the received amount.');
        }

        return [
            'received_amount_atomic' => $receivedAmountAtomic,
            'refund_amount_atomic' => $refundAmountAtomic,
            'negotiated_fee_amount_atomic' => self::subtractUnsignedIntegers(
                $receivedAmountAtomic,
                $refundAmountAtomic,
            ),
            'amount_source' => 'merchant_customer_negotiated_net_amount',
            'customer_confirmation_required' => false,
        ];
    }

    public static function closureEvidence(string $channel): string
    {
        self::assertChannel($channel);

        return self::isUsdt($channel)
            ? self::EVIDENCE_CHAIN_VERIFIED
            : self::EVIDENCE_MERCHANT_DECLARED;
    }

    public static function isProviderVerifiedClosure(string $channel): bool
    {
        return self::closureEvidence($channel) === self::EVIDENCE_CHAIN_VERIFIED;
    }

    /** @return list<string> */
    public static function usdtClosureRequirements(): array
    {
        return [
            'receipt_success',
            'finalized_or_solidified',
            'usdt_contract_matches_network',
            'destination_matches_case_address',
            'amount_matches_negotiated_refund_atomic',
        ];
    }

    public static function isUsdt(string $channel): bool
    {
        return in_array($channel, [self::CHANNEL_USDT_TRC20, self::CHANNEL_USDT_BEP20], true);
    }

    private static function assertChannel(string $channel): void
    {
        if (! in_array($channel, [self::CHANNEL_ALIPAY, self::CHANNEL_USDT_TRC20, self::CHANNEL_USDT_BEP20], true)) {
            throw new InvalidArgumentException("Unsupported direct-payment channel: {$channel}.");
        }
    }

    private static function assertCanonicalPositiveInteger(string $value, string $label): void
    {
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new InvalidArgumentException("The {$label} must be a canonical positive integer.");
        }
    }

    private static function compareUnsignedIntegers(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private static function subtractUnsignedIntegers(string $left, string $right): string
    {
        $borrow = 0;
        $result = '';
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;

        while ($leftIndex >= 0) {
            $digit = (int) $left[$leftIndex] - $borrow;
            $subtrahend = $rightIndex >= 0 ? (int) $right[$rightIndex] : 0;
            if ($digit < $subtrahend) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }

            $result = (string) ($digit - $subtrahend).$result;
            $leftIndex--;
            $rightIndex--;
        }

        return ltrim($result, '0') ?: '0';
    }
}
