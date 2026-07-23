<?php

namespace App\CentralLogics;

use App\Models\NezhaCustomerRefundAddressCredential;
use App\Models\NezhaPaymentAddressCredential;
use App\Models\OfflinePaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 顾客付款前确认的订单级 USDT 退款地址凭据。
 *
 * 所有 MVP 凭据均为 customer_attested；不声称钱包控制权已验证。
 */
class NezhaCustomerRefundAddressCredentialService
{
    public const MODE_KEY = 'nezha_usdt_refund_binding_mode';

    public const LEGAL_GATE_KEY = 'nezha_usdt_refund_legal_gate';

    public const POLICY_VERSION = 'refund-bound-v2';

    public const MODE_ENFORCE = 'enforce';

    public const MODE_DRAIN = 'drain';

    public const MODE_CLOSED = 'closed';

    public static function mode(): string
    {
        $value = strtolower(trim((string) DB::table('business_settings')
            ->where('key', self::MODE_KEY)
            ->value('value')));

        return in_array($value, [self::MODE_ENFORCE, self::MODE_DRAIN, self::MODE_CLOSED], true)
            ? $value
            : self::MODE_CLOSED;
    }

    public static function acceptingNewPayments(): bool
    {
        return self::mode() === self::MODE_ENFORCE
            && (string) DB::table('business_settings')
                ->where('key', self::LEGAL_GATE_KEY)
                ->value('value') === 'approved';
    }

    public static function issue(
        int $userId,
        int $restaurantId,
        int $methodId,
        string $address,
        bool $confirmed,
        ?string $existingToken = null
    ): array {
        if (! self::acceptingNewPayments()) {
            throw new \DomainException('refund_binding_not_accepting_new_payments');
        }
        if (! $confirmed) {
            throw new \DomainException('refund_address_confirmation_required');
        }

        $method = OfflinePaymentMethod::where('id', $methodId)->where('status', 1)->first();
        $network = NezhaPaymentAddressCredentialService::networkForMethod($method);
        if (! $method || $network === null) {
            throw new \DomainException('refund_credential_method_not_available');
        }

        $inspection = NezhaUsdtAddress::inspect($address, $network);
        if (! $inspection['valid']) {
            throw new \DomainException('refund_address_invalid');
        }
        $normalized = (string) $inspection['normalized'];
        $merchantAddress = self::merchantReceiveAddress($restaurantId, $network);
        if ($merchantAddress === null) {
            throw new \DomainException('refund_credential_restaurant_not_available');
        }
        if (NezhaUsdtAddress::equals($normalized, $merchantAddress, $network)) {
            throw new \DomainException('refund_address_matches_merchant_receive_address');
        }

        $fingerprint = (string) NezhaUsdtAddress::fingerprint($normalized, $network);
        $reusable = self::reusableCredential(
            $existingToken,
            $userId,
            $restaurantId,
            $methodId,
            $network,
            $fingerprint
        );
        if ($reusable) {
            return [
                'credential' => $reusable,
                'token' => trim((string) $existingToken),
                'reused' => true,
            ];
        }

        $secret = bin2hex(random_bytes(32));
        $issuedAt = now();
        $credential = NezhaCustomerRefundAddressCredential::create([
            'public_id' => (string) Str::uuid(),
            'secret_hash' => hash('sha256', $secret),
            'user_id' => $userId,
            'restaurant_id' => $restaurantId,
            'method_id' => $methodId,
            'network' => $network,
            'address_snapshot' => $normalized,
            'address_fingerprint' => $fingerprint,
            'verification_status' => 'customer_attested',
            'route_policy_version' => self::POLICY_VERSION,
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt->copy()->addMinutes(
                NezhaPaymentAddressCredentialService::ttlMinutes()
            ),
        ]);

        return [
            'credential' => $credential,
            'token' => $credential->public_id.'.'.$secret,
            'reused' => false,
        ];
    }

    public static function resolveForProof(
        string $token,
        int $userId,
        int $restaurantId,
        int $methodId,
        int $orderId
    ): NezhaCustomerRefundAddressCredential {
        [$publicId, $secret] = self::splitToken($token);
        $credential = NezhaCustomerRefundAddressCredential::where('public_id', $publicId)->first();
        if (! $credential
            || ! hash_equals((string) $credential->secret_hash, hash('sha256', $secret))) {
            throw new \DomainException('refund_credential_invalid');
        }
        self::assertBinding($credential, $userId, $restaurantId, $methodId, $orderId);

        return $credential;
    }

    /**
     * Consume merchant and refund credentials in the caller's payment transaction.
     * Any exception rolls both rows back.
     */
    public static function consumeWithPaymentCredential(
        NezhaCustomerRefundAddressCredential $refundCredential,
        NezhaPaymentAddressCredential $paymentCredential,
        int $orderId,
        ?string $paymentTxHash,
        ?string $paymentFromAddress,
        ?string $paidAtomic,
        string $refundableAmdSnapshot,
        string $orderCurrency
    ): NezhaCustomerRefundAddressCredential {
        return DB::transaction(function () use (
            $refundCredential,
            $paymentCredential,
            $orderId,
            $paymentTxHash,
            $paymentFromAddress,
            $paidAtomic,
            $refundableAmdSnapshot,
            $orderCurrency
        ): NezhaCustomerRefundAddressCredential {
            if (! self::acceptingNewPayments()) {
                throw new \DomainException('refund_binding_not_accepting_new_payments');
            }
            $paymentFresh = NezhaPaymentAddressCredential::whereKey($paymentCredential->id)
                ->lockForUpdate()
                ->first();
            if (! $paymentFresh) {
                throw new \DomainException('credential_not_found');
            }

            $refundFresh = NezhaCustomerRefundAddressCredential::whereKey($refundCredential->id)
                ->lockForUpdate()
                ->first();
            if (! $refundFresh) {
                throw new \DomainException('refund_credential_not_found');
            }

            self::assertBinding(
                $refundFresh,
                (int) $paymentFresh->user_id,
                (int) $paymentFresh->restaurant_id,
                (int) $paymentFresh->method_id,
                $orderId
            );
            if ((string) $refundFresh->network !== (string) $paymentFresh->network) {
                throw new \DomainException('refund_credential_network_mismatch');
            }
            if (NezhaUsdtAddress::equals(
                $refundFresh->address_snapshot,
                $paymentFresh->address_snapshot,
                $refundFresh->network
            )) {
                throw new \DomainException('refund_address_matches_merchant_receive_address');
            }

            // Existing service revalidates the merchant credential under the same row lock.
            NezhaPaymentAddressCredentialService::consume(
                $paymentFresh,
                $orderId,
                $paymentTxHash
            );

            $normalizedTxHash = trim((string) $paymentTxHash);
            $normalizedPaymentFrom = $paymentFromAddress !== null
                ? NezhaUsdtAddress::normalize($paymentFromAddress, $refundFresh->network)
                : null;
            if ($paymentFromAddress !== null
                && trim($paymentFromAddress) !== ''
                && $normalizedPaymentFrom === null) {
                throw new \DomainException('refund_payment_evidence_invalid');
            }
            $contract = self::contractForNetwork($refundFresh->network);
            $decimals = self::decimalsForNetwork($refundFresh->network);
            if ($contract === null || $decimals === null) {
                throw new \DomainException('refund_payment_evidence_invalid');
            }
            $normalizedPaidAtomic = $paidAtomic !== null
                ? NezhaAtomicAmount::normalizeInteger($paidAtomic)
                : null;
            $normalizedRefundable = NezhaAtomicAmount::currencyAmount($refundableAmdSnapshot);
            $normalizedCurrency = strtoupper(trim($orderCurrency));
            if ($normalizedCurrency === '') {
                throw new \DomainException('refund_payment_evidence_invalid');
            }

            $storedTxHash = trim((string) $refundFresh->payment_tx_hash);
            if ($storedTxHash !== '' && $normalizedTxHash !== '') {
                $storedFingerprint = NezhaPaymentAddressCredentialService::transactionFingerprint(
                    $refundFresh->network,
                    $storedTxHash
                );
                $incomingFingerprint = NezhaPaymentAddressCredentialService::transactionFingerprint(
                    $refundFresh->network,
                    $normalizedTxHash
                );
                if ($storedFingerprint === null
                    || $incomingFingerprint === null
                    || ! hash_equals($storedFingerprint, $incomingFingerprint)) {
                    throw new \DomainException('refund_payment_evidence_changed');
                }
            }
            if ($refundFresh->payment_from_address !== null
                && $normalizedPaymentFrom !== null
                && ! NezhaUsdtAddress::equals(
                    $refundFresh->payment_from_address,
                    $normalizedPaymentFrom,
                    $refundFresh->network
                )) {
                throw new \DomainException('refund_payment_evidence_changed');
            }
            if ($refundFresh->asset_contract !== null
                && strtolower((string) $refundFresh->asset_contract) !== strtolower($contract)) {
                throw new \DomainException('refund_payment_evidence_changed');
            }
            if ($refundFresh->asset_decimals !== null
                && (int) $refundFresh->asset_decimals !== $decimals) {
                throw new \DomainException('refund_payment_evidence_changed');
            }
            if ($refundFresh->paid_asset_amount_atomic !== null
                && $normalizedPaidAtomic !== null
                && NezhaAtomicAmount::compare(
                    (string) $refundFresh->paid_asset_amount_atomic,
                    $normalizedPaidAtomic
                ) !== 0) {
                throw new \DomainException('refund_payment_evidence_changed');
            }
            if ($refundFresh->refundable_amd_snapshot !== null
                && NezhaAtomicAmount::currencyAmount(
                    $refundFresh->refundable_amd_snapshot
                ) !== $normalizedRefundable) {
                throw new \DomainException('refund_payment_evidence_changed');
            }
            if ($refundFresh->order_currency_snapshot !== null
                && strtoupper((string) $refundFresh->order_currency_snapshot) !== $normalizedCurrency) {
                throw new \DomainException('refund_payment_evidence_changed');
            }

            $refundFresh->consumed_order_id = $orderId;
            $refundFresh->consumed_at = $refundFresh->consumed_at ?: now();
            $refundFresh->payment_tx_hash = $refundFresh->payment_tx_hash
                ?: ($normalizedTxHash !== '' ? $normalizedTxHash : null);
            $refundFresh->payment_from_address = $refundFresh->payment_from_address
                ?: $normalizedPaymentFrom;
            $refundFresh->asset_contract = $refundFresh->asset_contract ?: $contract;
            $refundFresh->asset_decimals = $refundFresh->asset_decimals ?? $decimals;
            $refundFresh->paid_asset_amount_atomic = $refundFresh->paid_asset_amount_atomic
                ?? $normalizedPaidAtomic;
            $refundFresh->refundable_amd_snapshot = $refundFresh->refundable_amd_snapshot
                ?? $normalizedRefundable;
            $refundFresh->order_currency_snapshot = $refundFresh->order_currency_snapshot
                ?: $normalizedCurrency;
            $refundFresh->save();

            return $refundFresh;
        });
    }

    public static function snapshotForOrder(int $orderId): ?NezhaCustomerRefundAddressCredential
    {
        return NezhaCustomerRefundAddressCredential::where('consumed_order_id', $orderId)->first();
    }

    /**
     * A v2 order cannot be confirmed paid until its immutable payment evidence
     * contains the actual on-chain USDT atomic amount. Merchant judgement may
     * not substitute for the amount snapshot later used by refund routing.
     */
    public static function ensurePaymentEvidenceForConfirmation($order): void
    {
        $refundCredential = self::snapshotForOrder((int) $order->id);
        if (! $refundCredential) {
            return;
        }
        if ((string) $refundCredential->route_policy_version !== self::POLICY_VERSION) {
            throw new \DomainException('refund_credential_policy_mismatch');
        }

        $paymentCredential = NezhaPaymentAddressCredential::where(
            'consumed_order_id',
            (int) $order->id
        )->first();
        if (! $paymentCredential
            || (int) $paymentCredential->user_id !== (int) $refundCredential->user_id
            || (int) $paymentCredential->restaurant_id !== (int) $refundCredential->restaurant_id
            || (int) $paymentCredential->method_id !== (int) $refundCredential->method_id
            || (string) $paymentCredential->network !== (string) $refundCredential->network) {
            throw new \DomainException('payment_refund_credential_pair_invalid');
        }

        $network = NezhaUsdtAddress::normalizeNetwork($refundCredential->network);
        $chain = $network === NezhaUsdtAddress::BEP20 ? 'bsc' : 'trc20';
        $hash = trim((string) $paymentCredential->submitted_tx_hash);
        $contract = self::contractForNetwork($network);
        $decimals = self::decimalsForNetwork($network);
        if (! NezhaChainVerifier::isValidHashFormat($hash)
            || $contract === null
            || $decimals === null) {
            throw new \DomainException('payment_chain_evidence_not_verified');
        }
        $fingerprint = NezhaPaymentAddressCredentialService::transactionFingerprint(
            $network,
            $hash
        );
        if ($fingerprint === null
            || trim((string) $paymentCredential->submitted_tx_fingerprint) === ''
            || ! hash_equals(
                (string) $paymentCredential->submitted_tx_fingerprint,
                $fingerprint
            )
            || NezhaPaymentAddressCredential::where(
                'submitted_tx_fingerprint',
                $fingerprint
            )->whereKeyNot($paymentCredential->id)->exists()) {
            throw new \DomainException('payment_tx_hash_reused_or_changed');
        }

        $result = NezhaRefundControl::verify_refund_tx(
            $hash,
            $chain,
            (string) $paymentCredential->address_snapshot,
            '1',
            $contract,
            $decimals,
            'at_least'
        );
        if (($result['status'] ?? null) !== 'verified'
            || ! isset($result['detail']['amount_atomic'])) {
            throw new \DomainException('payment_chain_evidence_not_verified');
        }
        $actualAtomic = NezhaAtomicAmount::normalizeInteger(
            (string) $result['detail']['amount_atomic']
        );
        if ($actualAtomic === '0') {
            throw new \DomainException('payment_chain_evidence_not_verified');
        }
        $paymentFrom = NezhaRefundControl::reverse_lookup_from_address($hash, $chain);

        DB::transaction(function () use (
            $refundCredential,
            $actualAtomic,
            $paymentFrom,
            $hash,
            $contract,
            $decimals
        ): void {
            $fresh = NezhaCustomerRefundAddressCredential::whereKey($refundCredential->id)
                ->lockForUpdate()
                ->first();
            if (! $fresh || (int) $fresh->consumed_order_id !== (int) $refundCredential->consumed_order_id) {
                throw new \DomainException('refund_credential_not_found');
            }
            if ($fresh->paid_asset_amount_atomic !== null
                && NezhaAtomicAmount::compare(
                    (string) $fresh->paid_asset_amount_atomic,
                    $actualAtomic
                ) !== 0) {
                throw new \DomainException('payment_chain_evidence_changed');
            }
            if ($fresh->asset_contract !== null
                && strtolower((string) $fresh->asset_contract) !== strtolower($contract)) {
                throw new \DomainException('payment_chain_evidence_changed');
            }
            if ($fresh->asset_decimals !== null && (int) $fresh->asset_decimals !== $decimals) {
                throw new \DomainException('payment_chain_evidence_changed');
            }
            $storedFingerprint = NezhaPaymentAddressCredentialService::transactionFingerprint(
                $fresh->network,
                (string) $fresh->payment_tx_hash
            );
            $verifiedFingerprint = NezhaPaymentAddressCredentialService::transactionFingerprint(
                $fresh->network,
                $hash
            );
            if ($fresh->payment_tx_hash !== null
                && ($storedFingerprint === null
                    || $verifiedFingerprint === null
                    || ! hash_equals($storedFingerprint, $verifiedFingerprint))) {
                throw new \DomainException('payment_chain_evidence_changed');
            }
            if ($fresh->payment_from_address !== null
                && $paymentFrom !== null
                && ! NezhaUsdtAddress::equals(
                    $fresh->payment_from_address,
                    $paymentFrom,
                    $fresh->network
                )) {
                throw new \DomainException('payment_chain_evidence_changed');
            }

            $fresh->payment_tx_hash = $fresh->payment_tx_hash ?: $hash;
            $fresh->payment_from_address = $fresh->payment_from_address ?: $paymentFrom;
            $fresh->asset_contract = $contract;
            $fresh->asset_decimals = $decimals;
            $fresh->paid_asset_amount_atomic = $actualAtomic;
            $fresh->save();
        });
    }

    public static function evidence(NezhaCustomerRefundAddressCredential $credential): array
    {
        return [
            'credential_id' => (string) $credential->public_id,
            'network' => (string) $credential->network,
            'address' => (string) $credential->address_snapshot,
            'address_fingerprint' => (string) $credential->address_fingerprint,
            'verification_status' => 'customer_attested',
            'route_policy_version' => (string) $credential->route_policy_version,
            'issued_at' => $credential->issued_at?->toIso8601String(),
            'expires_at' => $credential->expires_at?->toIso8601String(),
            'consumed_at' => $credential->consumed_at?->toIso8601String(),
        ];
    }

    public static function contractForNetwork($network): ?string
    {
        return match (NezhaUsdtAddress::normalizeNetwork($network)) {
            NezhaUsdtAddress::TRC20 => NezhaRefundControl::TRC_USDT,
            NezhaUsdtAddress::BEP20 => NezhaRefundControl::BSC_USDT,
            default => null,
        };
    }

    public static function decimalsForNetwork($network): ?int
    {
        return match (NezhaUsdtAddress::normalizeNetwork($network)) {
            NezhaUsdtAddress::TRC20 => NezhaRefundControl::TRC_DEC,
            NezhaUsdtAddress::BEP20 => NezhaRefundControl::BSC_DEC,
            default => null,
        };
    }

    private static function merchantReceiveAddress(int $restaurantId, string $network): ?string
    {
        $column = NezhaUsdtAddress::columnForNetwork($network);
        if ($column === null) {
            return null;
        }
        $value = DB::table('restaurants')->where('id', $restaurantId)->value($column);
        $normalized = NezhaUsdtAddress::normalize($value, $network);

        return $normalized !== null ? (string) $normalized : null;
    }

    private static function assertBinding(
        NezhaCustomerRefundAddressCredential $credential,
        int $userId,
        int $restaurantId,
        int $methodId,
        int $orderId
    ): void {
        if ((int) $credential->user_id !== $userId
            || (int) $credential->restaurant_id !== $restaurantId
            || (int) $credential->method_id !== $methodId) {
            throw new \DomainException('refund_credential_binding_mismatch');
        }
        $method = OfflinePaymentMethod::where('id', $methodId)->where('status', 1)->first();
        $network = NezhaPaymentAddressCredentialService::networkForMethod($method);
        if ($network === null || $network !== (string) $credential->network) {
            throw new \DomainException('refund_credential_network_mismatch');
        }
        if ($credential->consumed_order_id !== null
            && (int) $credential->consumed_order_id !== $orderId) {
            throw new \DomainException('refund_credential_already_consumed');
        }
        if ($credential->consumed_order_id === null) {
            self::assertActive($credential);
        }
        if ((string) $credential->verification_status !== 'customer_attested'
            || (string) $credential->route_policy_version !== self::POLICY_VERSION) {
            throw new \DomainException('refund_credential_not_eligible');
        }
        if (! NezhaUsdtAddress::isValid($credential->address_snapshot, $credential->network)) {
            throw new \DomainException('refund_credential_snapshot_invalid');
        }
    }

    private static function reusableCredential(
        ?string $token,
        int $userId,
        int $restaurantId,
        int $methodId,
        string $network,
        string $fingerprint
    ): ?NezhaCustomerRefundAddressCredential {
        if ($token === null || trim($token) === '') {
            return null;
        }
        try {
            [$publicId, $secret] = self::splitToken($token);
        } catch (\DomainException $e) {
            return null;
        }

        $credential = NezhaCustomerRefundAddressCredential::where('public_id', $publicId)->first();
        if (! $credential
            || ! hash_equals((string) $credential->secret_hash, hash('sha256', $secret))
            || (int) $credential->user_id !== $userId
            || (int) $credential->restaurant_id !== $restaurantId
            || (int) $credential->method_id !== $methodId
            || (string) $credential->network !== $network
            || ! hash_equals((string) $credential->address_fingerprint, $fingerprint)
            || $credential->consumed_order_id !== null
            || $credential->redacted_at !== null) {
            return null;
        }
        try {
            self::assertActive($credential);
        } catch (\DomainException $e) {
            return null;
        }

        return $credential;
    }

    private static function splitToken(string $token): array
    {
        $parts = explode('.', trim($token), 2);
        if (count($parts) !== 2
            || ! preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $parts[0]
            )
            || ! preg_match('/^[0-9a-f]{64}$/i', $parts[1])) {
            throw new \DomainException('refund_credential_invalid');
        }

        return $parts;
    }

    private static function assertActive(NezhaCustomerRefundAddressCredential $credential): void
    {
        if ($credential->revoked_at !== null) {
            throw new \DomainException('refund_credential_revoked');
        }
        if ($credential->expires_at === null || $credential->expires_at->isPast()) {
            throw new \DomainException('refund_credential_expired');
        }
    }
}
