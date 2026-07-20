<?php

namespace App\CentralLogics\MerchantDirectPayment;

use App\CentralLogics\NezhaPaymentAddressCredentialService;
use App\CentralLogics\NezhaUsdtAddress;
use App\Models\MerchantDirectPaymentLateCase;
use App\Models\NezhaPaymentAddressCredential;
use App\Models\NezhaRefundRecord;
use App\Models\NezhaRefundRecordEvent;
use App\Models\OfflinePaymentMethod;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Transaction owner for V2 late-payment cases.
 *
 * Canonical lock order is Order -> NezhaRefundRecord -> credential/evidence.
 * Every mutation rechecks that the original order is still canceled and never
 * writes orders.order_status or any wallet/fund ledger.
 */
final class MerchantDirectPaymentLateCaseService
{
    public const SWITCH_KEY = 'nezha_direct_payment_late_v2_status';

    public const SOURCE = MerchantDirectPaymentLateCase::SOURCE;

    public static function enabled(): bool
    {
        return (string) DB::table('business_settings')
            ->where('key', self::SWITCH_KEY)
            ->value('value') === '1';
    }

    public function report(
        Order $order,
        string $channel,
        int $methodId,
        string $walletType,
        string $paymentReference,
        ?string $credentialToken,
        string $actorType,
        ?int $actorId,
        ?string $manualProofPath = null
    ): NezhaRefundRecord {
        $this->assertEnabled();
        $this->assertActor($actorType, $actorId);
        $this->assertReportingOwner($order, $actorType, $actorId);
        $this->assertPaymentReference($channel, $paymentReference);
        $method = OfflinePaymentMethod::whereKey($methodId)->where('status', 1)->first();
        if (! $method) {
            throw new InvalidArgumentException('offline_payment_method_not_found');
        }
        $this->assertMethodMatchesChannel($method, $channel);

        $credential = null;
        if (MerchantDirectPaymentLateCasePolicy::isUsdt($channel)) {
            if ($order->is_guest || ! $actorId || ! NezhaPaymentAddressCredentialService::enabled()) {
                throw new InvalidArgumentException('usdt_address_credential_required');
            }
            $credential = $this->resolveOrderCredential(
                $order,
                $methodId,
                $actorId,
                $credentialToken
            );
            if (NezhaUsdtAddress::normalizeNetwork($credential->network)
                !== MerchantDirectPaymentStrictUsdtVerifier::network($channel)) {
                throw new InvalidArgumentException('credential_network_mismatch');
            }
        } elseif ($walletType !== MerchantDirectPaymentLateCasePolicy::WALLET_SELF_CUSTODY) {
            // Wallet type has no meaning for Alipay; retain one canonical value.
            $walletType = MerchantDirectPaymentLateCasePolicy::WALLET_SELF_CUSTODY;
        }

        $caseKey = hash('sha256', self::SOURCE.'|order|'.$order->id);
        $normalizedReference = MerchantDirectPaymentLateCasePolicy::isUsdt($channel)
            ? $this->normalizeTransactionHash($paymentReference)
            : trim($paymentReference);

        return DB::transaction(function () use (
            $order,
            $channel,
            $methodId,
            $walletType,
            $normalizedReference,
            $credential,
            $actorType,
            $actorId,
            $caseKey,
            $manualProofPath
        ): NezhaRefundRecord {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertCanceledOrder($lockedOrder);
            $this->assertSameOrderOwner($order, $lockedOrder);

            $existing = MerchantDirectPaymentLateCase::where('case_key', $caseKey)->lockForUpdate()->first();
            if ($existing) {
                $this->assertSameReport(
                    $existing,
                    $channel,
                    $methodId,
                    $walletType,
                    $normalizedReference,
                    $credential?->id
                );

                return $existing;
            }

            $record = MerchantDirectPaymentLateCase::create([
                'order_id' => $lockedOrder->id,
                'restaurant_id' => $lockedOrder->restaurant_id,
                'user_id' => $lockedOrder->is_guest ? null : $lockedOrder->user_id,
                'guest_id' => $lockedOrder->is_guest ? (string) $lockedOrder->user_id : null,
                'payment_channel' => $channel,
                'order_amount' => $lockedOrder->order_amount ?? 0,
                'refund_amount' => 0,
                'reason_category' => 'late_payment_after_timeout',
                'reason_note' => 'Timed-out order remains canceled; late payment requires independent review and refund.',
                'status' => MerchantDirectPaymentLateCasePolicy::STATE_REVIEW_PENDING,
                'source_domain' => self::SOURCE,
                'case_public_id' => (string) Str::uuid(),
                'case_key' => $caseKey,
                'state_version' => 1,
                'credential_id' => $credential?->id,
                'method_id' => $methodId,
                'wallet_type' => $walletType,
                'network' => MerchantDirectPaymentStrictUsdtVerifier::network($channel),
                'token_contract' => MerchantDirectPaymentStrictUsdtVerifier::tokenContract($channel),
                'token_decimals' => MerchantDirectPaymentStrictUsdtVerifier::tokenDecimals($channel),
                'late_payment_tx_hash' => $normalizedReference,
                'refund_proof_image' => $manualProofPath,
                'reported_at' => now(),
            ]);

            if ($credential) {
                NezhaPaymentAddressCredentialService::consume(
                    $credential,
                    (int) $lockedOrder->id,
                    $normalizedReference
                );
            }

            $this->appendEvent(
                $record,
                'late_payment_reported',
                null,
                $record->status,
                $actorType,
                $actorId,
                null,
                [
                    'channel' => $channel,
                    'method_id' => $methodId,
                    'wallet_type' => $walletType,
                    'credential_public_id' => $credential?->public_id,
                    'manual_proof_present' => $manualProofPath !== null,
                    'order_status_projection' => MerchantDirectPaymentLateCasePolicy::orderStatus(),
                ]
            );

            return $record->fresh();
        }, 3);
    }

    public function attributePayment(
        NezhaRefundRecord $case,
        string $receivedAmountAtomic,
        array $observation,
        string $actorType,
        ?int $actorId
    ): NezhaRefundRecord {
        $this->assertEnabled();
        MerchantDirectPaymentLateCasePolicy::refundTerms($receivedAmountAtomic, $receivedAmountAtomic);

        return $this->mutate($case, function (Order $order, NezhaRefundRecord $record) use (
            $receivedAmountAtomic,
            $observation,
            $actorType,
            $actorId
        ): void {
            if ($record->status !== MerchantDirectPaymentLateCasePolicy::STATE_REVIEW_PENDING) {
                throw new InvalidArgumentException('late_payment_case_not_reviewable');
            }

            $authority = MerchantDirectPaymentLateCasePolicy::EVIDENCE_MERCHANT_DECLARED;
            $evidence = ['status' => 'merchant_declared'];
            if (MerchantDirectPaymentLateCasePolicy::isUsdt($record->payment_channel)) {
                $this->assertObservationBound($observation, (string) $record->late_payment_tx_hash);
                $credential = NezhaPaymentAddressCredential::whereKey($record->credential_id)->first();
                if (! $credential || (int) $credential->consumed_order_id !== (int) $order->id) {
                    throw new InvalidArgumentException('credential_evidence_missing');
                }
                $evidence = MerchantDirectPaymentStrictUsdtVerifier::evaluate(
                    $record->payment_channel,
                    (string) $credential->address_snapshot,
                    $receivedAmountAtomic,
                    $observation
                );
                if ($evidence['status'] !== 'confirmed') {
                    throw new InvalidArgumentException('payment_chain_evidence_not_confirmed');
                }
                $authority = MerchantDirectPaymentLateCasePolicy::EVIDENCE_CHAIN_VERIFIED;
                $record->late_payment_event_index = $evidence['event_index'];
                $record->late_payment_claim_key = $this->claimKey(
                    $record->payment_channel,
                    (string) $record->late_payment_tx_hash,
                    (int) $evidence['event_index']
                );
                if ($record->wallet_type === MerchantDirectPaymentLateCasePolicy::WALLET_SELF_CUSTODY) {
                    $record->late_refund_destination = $evidence['from'];
                    $record->late_refund_destination_fingerprint = NezhaUsdtAddress::fingerprint(
                        $evidence['from'],
                        $record->network
                    );
                    $record->refund_destination_source = MerchantDirectPaymentLateCasePolicy::refundDestinationMode(
                        MerchantDirectPaymentLateCasePolicy::WALLET_SELF_CUSTODY
                    );
                }
            }

            $from = $record->status;
            $record->status = MerchantDirectPaymentLateCasePolicy::transition(
                $record->status,
                MerchantDirectPaymentLateCasePolicy::EVENT_PAYMENT_ATTRIBUTED,
                $record->payment_channel
            );
            $record->received_amount_atomic = $receivedAmountAtomic;
            $record->payment_attributed_at = now();
            $record->evidence_authority = $authority;
            $this->saveAndAppend(
                $record,
                'payment_attributed',
                $from,
                $actorType,
                $actorId,
                $authority,
                ['received_amount_atomic' => $receivedAmountAtomic, 'verification' => $evidence]
            );
        });
    }

    public function closeNoPayment(
        NezhaRefundRecord $case,
        string $actorType,
        ?int $actorId,
        string $reason
    ): NezhaRefundRecord {
        $this->assertEnabled();
        if (trim($reason) === '') {
            throw new InvalidArgumentException('review_reason_required');
        }

        return $this->mutate($case, function (Order $order, NezhaRefundRecord $record) use ($actorType, $actorId, $reason): void {
            if ($record->status !== MerchantDirectPaymentLateCasePolicy::STATE_REVIEW_PENDING) {
                throw new InvalidArgumentException('late_payment_case_not_reviewable');
            }
            $from = $record->status;
            $record->status = MerchantDirectPaymentLateCasePolicy::transition(
                $record->status,
                MerchantDirectPaymentLateCasePolicy::EVENT_PAYMENT_NOT_ATTRIBUTED,
                $record->payment_channel
            );
            $record->closed_at = now();
            $this->saveAndAppend(
                $record,
                'payment_not_attributed',
                $from,
                $actorType,
                $actorId,
                'manual_review',
                ['reason' => mb_substr(trim($reason), 0, 1000)]
            );
        });
    }

    public function setRefundTerms(
        NezhaRefundRecord $case,
        string $refundAmountAtomic,
        ?string $destination,
        int $restaurantId,
        ?int $actorId
    ): NezhaRefundRecord {
        $this->assertEnabled();

        return $this->mutate($case, function (Order $order, NezhaRefundRecord $record) use (
            $refundAmountAtomic,
            $destination,
            $restaurantId,
            $actorId
        ): void {
            $this->assertRestaurantScope($record, $restaurantId);
            if ($record->status !== MerchantDirectPaymentLateCasePolicy::STATE_REFUND_REQUIRED) {
                throw new InvalidArgumentException('late_payment_refund_not_required');
            }
            $terms = MerchantDirectPaymentLateCasePolicy::refundTerms(
                (string) $record->received_amount_atomic,
                $refundAmountAtomic
            );

            if (MerchantDirectPaymentLateCasePolicy::isUsdt($record->payment_channel)) {
                if ($record->wallet_type === MerchantDirectPaymentLateCasePolicy::WALLET_EXCHANGE) {
                    $normalized = NezhaUsdtAddress::normalize($destination, $record->network);
                    if ($normalized === null) {
                        throw new InvalidArgumentException('customer_refund_address_invalid');
                    }
                    $record->late_refund_destination = $normalized;
                    $record->late_refund_destination_fingerprint = NezhaUsdtAddress::fingerprint(
                        $normalized,
                        $record->network
                    );
                    $record->refund_destination_source = MerchantDirectPaymentLateCasePolicy::refundDestinationMode(
                        MerchantDirectPaymentLateCasePolicy::WALLET_EXCHANGE
                    );
                } elseif (! $record->late_refund_destination) {
                    throw new InvalidArgumentException('original_sender_address_missing');
                }
            } else {
                $record->refund_destination_source = 'alipay_original_route';
            }

            $record->refund_amount_atomic = $terms['refund_amount_atomic'];
            $record->merchant_refund_note = 'Net refund amount negotiated by merchant and customer; fee is not set by the platform.';
            $this->saveAndAppend(
                $record,
                'refund_terms_recorded',
                $record->status,
                'merchant',
                $actorId,
                'merchant_customer_agreement',
                $terms + ['destination_source' => $record->refund_destination_source]
            );
        });
    }

    public function submitRefund(
        NezhaRefundRecord $case,
        ?string $refundReference,
        array $observation,
        int $restaurantId,
        ?int $actorId,
        ?string $note = null
    ): NezhaRefundRecord {
        $this->assertEnabled();

        return $this->mutate($case, function (Order $order, NezhaRefundRecord $record) use (
            $refundReference,
            $observation,
            $restaurantId,
            $actorId,
            $note
        ): void {
            $this->assertRestaurantScope($record, $restaurantId);
            if ($record->status !== MerchantDirectPaymentLateCasePolicy::STATE_REFUND_REQUIRED
                || ! $record->refund_amount_atomic) {
                throw new InvalidArgumentException('late_payment_refund_terms_missing');
            }

            $from = $record->status;
            $record->merchant_refund_note = $note ? mb_substr(trim($note), 0, 255) : $record->merchant_refund_note;
            $record->refund_submitted_at = now();
            if (! MerchantDirectPaymentLateCasePolicy::isUsdt($record->payment_channel)) {
                $record->late_refund_tx_hash = $refundReference ? trim($refundReference) : null;
                $record->status = MerchantDirectPaymentLateCasePolicy::transition(
                    $record->status,
                    MerchantDirectPaymentLateCasePolicy::EVENT_MERCHANT_REFUND_SUBMITTED,
                    $record->payment_channel
                );
                $record->merchant_refunded_at = now();
                $record->closed_at = now();
                $record->evidence_authority = MerchantDirectPaymentLateCasePolicy::EVIDENCE_MERCHANT_DECLARED;
                $this->saveAndAppend(
                    $record,
                    'merchant_refund_declared',
                    $from,
                    'merchant',
                    $actorId,
                    MerchantDirectPaymentLateCasePolicy::EVIDENCE_MERCHANT_DECLARED,
                    ['reference_present' => $record->late_refund_tx_hash !== null]
                );

                return;
            }

            $normalizedHash = $this->normalizeTransactionHash((string) $refundReference);
            if (hash_equals($normalizedHash, (string) $record->late_payment_tx_hash)) {
                throw new InvalidArgumentException('refund_transaction_reuses_payment_transaction');
            }
            if (! $record->late_refund_destination) {
                throw new InvalidArgumentException('refund_destination_missing');
            }
            $record->late_refund_tx_hash = $normalizedHash;
            $record->late_refund_claim_key = hash('sha256', $record->payment_channel.'|'.$normalizedHash);
            $record->status = MerchantDirectPaymentLateCasePolicy::transition(
                $record->status,
                MerchantDirectPaymentLateCasePolicy::EVENT_MERCHANT_REFUND_SUBMITTED,
                $record->payment_channel
            );
            $record->evidence_authority = null;
            $this->saveAndAppend(
                $record,
                'merchant_refund_submitted',
                $from,
                'merchant',
                $actorId,
                null,
                ['transaction_fingerprint' => substr($record->late_refund_claim_key, 0, 16)]
            );

            $this->applyRefundVerification($record, $observation, 'system', null);
        });
    }

    public function retryUsdtRefundVerification(
        NezhaRefundRecord $case,
        array $observation,
        string $actorType,
        ?int $actorId
    ): NezhaRefundRecord {
        $this->assertEnabled();

        return $this->mutate($case, function (Order $order, NezhaRefundRecord $record) use ($observation, $actorType, $actorId): void {
            if ($record->status !== MerchantDirectPaymentLateCasePolicy::STATE_USDT_REFUND_VERIFICATION_PENDING
                || ! MerchantDirectPaymentLateCasePolicy::isUsdt($record->payment_channel)) {
                throw new InvalidArgumentException('usdt_refund_not_pending_verification');
            }
            $this->applyRefundVerification($record, $observation, $actorType, $actorId);
        });
    }

    public function disputeClosedRefund(
        NezhaRefundRecord $case,
        string $actorType,
        ?int $actorId,
        string $reason
    ): NezhaRefundRecord {
        $this->assertEnabled();
        if (trim($reason) === '') {
            throw new InvalidArgumentException('dispute_reason_required');
        }

        return $this->mutate($case, function (Order $order, NezhaRefundRecord $record) use ($actorType, $actorId, $reason): void {
            $this->assertCaseOwner($record, $actorType, $actorId);
            $from = $record->status;
            $record->status = MerchantDirectPaymentLateCasePolicy::transition(
                $record->status,
                MerchantDirectPaymentLateCasePolicy::EVENT_CUSTOMER_REPORTS_NOT_RECEIVED,
                $record->payment_channel
            );
            $this->saveAndAppend(
                $record,
                'customer_reports_refund_not_received',
                $from,
                $actorType,
                $actorId,
                'customer_declared',
                ['reason' => mb_substr(trim($reason), 0, 1000)]
            );
        });
    }

    /** @return array<string,mixed> */
    public function customerProjection(NezhaRefundRecord $record): array
    {
        $this->assertV2($record);

        return [
            'case_id' => $record->case_public_id,
            'order_id' => (int) $record->order_id,
            'order_status' => MerchantDirectPaymentLateCasePolicy::orderStatus(),
            'case_status' => $record->status,
            'channel' => $record->payment_channel,
            'received_amount_atomic' => $record->received_amount_atomic,
            'refund_amount_atomic' => $record->refund_amount_atomic,
            'refund_destination_source' => $record->refund_destination_source,
            'evidence_authority' => $record->evidence_authority,
            'reported_at' => $record->reported_at?->toIso8601String(),
            'closed_at' => $record->closed_at?->toIso8601String(),
            'must_reorder_for_goods' => true,
        ];
    }

    /** Structured evidence for authenticated staff surfaces; no credential secret is exposed. */
    public function staffProjection(NezhaRefundRecord $record): array
    {
        $this->assertV2($record);
        $record->loadMissing(['events' => fn ($query) => $query->orderBy('sequence')]);

        return $this->customerProjection($record) + [
            'restaurant_id' => (int) $record->restaurant_id,
            'user_id' => $record->user_id !== null ? (int) $record->user_id : null,
            'guest_id' => $record->guest_id,
            'method_id' => $record->method_id !== null ? (int) $record->method_id : null,
            'wallet_type' => $record->wallet_type,
            'network' => $record->network,
            'token_contract' => $record->token_contract,
            'token_decimals' => $record->token_decimals,
            'payment_reference' => $record->late_payment_tx_hash,
            'payment_event_index' => $record->late_payment_event_index,
            'refund_destination' => $record->late_refund_destination,
            'refund_reference' => $record->late_refund_tx_hash,
            'refund_event_index' => $record->late_refund_event_index,
            'refund_submitted_at' => $record->refund_submitted_at?->toIso8601String(),
            'events' => $record->events->map(static fn (NezhaRefundRecordEvent $event): array => [
                'event_id' => $event->public_id,
                'sequence' => (int) $event->sequence,
                'type' => $event->event_type,
                'state_from' => $event->state_from,
                'state_to' => $event->state_to,
                'actor_type' => $event->actor_type,
                'actor_id' => $event->actor_id,
                'evidence_authority' => $event->evidence_authority,
                'payload' => $event->payload,
                'payload_hash' => $event->payload_hash,
                'recorded_at' => $event->recorded_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function applyRefundVerification(
        NezhaRefundRecord $record,
        array $observation,
        string $actorType,
        ?int $actorId
    ): void {
        $this->assertObservationBound($observation, (string) $record->late_refund_tx_hash);
        $result = MerchantDirectPaymentStrictUsdtVerifier::evaluate(
            $record->payment_channel,
            (string) $record->late_refund_destination,
            (string) $record->refund_amount_atomic,
            $observation
        );
        $from = $record->status;
        if ($result['status'] === 'confirmed') {
            $record->status = MerchantDirectPaymentLateCasePolicy::transition(
                $record->status,
                MerchantDirectPaymentLateCasePolicy::EVENT_USDT_REFUND_VERIFIED,
                $record->payment_channel
            );
            $record->late_refund_event_index = $result['event_index'];
            $record->merchant_refunded_at = now();
            $record->closed_at = now();
            $record->evidence_authority = MerchantDirectPaymentLateCasePolicy::EVIDENCE_CHAIN_VERIFIED;
            $this->saveAndAppend(
                $record,
                'usdt_refund_verified',
                $from,
                $actorType,
                $actorId,
                MerchantDirectPaymentLateCasePolicy::EVIDENCE_CHAIN_VERIFIED,
                ['verification' => $result]
            );

            return;
        }

        if ($result['status'] === 'mismatch') {
            $record->status = MerchantDirectPaymentLateCasePolicy::transition(
                $record->status,
                MerchantDirectPaymentLateCasePolicy::EVENT_USDT_REFUND_REJECTED,
                $record->payment_channel
            );
            $record->late_refund_tx_hash = null;
            $record->late_refund_claim_key = null;
            $record->refund_submitted_at = null;
            $this->saveAndAppend(
                $record,
                'usdt_refund_rejected',
                $from,
                $actorType,
                $actorId,
                null,
                ['verification' => $result]
            );

            return;
        }

        $this->saveAndAppend(
            $record,
            'usdt_refund_verification_deferred',
            $from,
            $actorType,
            $actorId,
            null,
            ['verification' => $result]
        );
    }

    private function mutate(NezhaRefundRecord $case, callable $callback): NezhaRefundRecord
    {
        return DB::transaction(function () use ($case, $callback): NezhaRefundRecord {
            $snapshot = MerchantDirectPaymentLateCase::whereKey($case->id)->firstOrFail();
            $order = Order::whereKey($snapshot->order_id)->lockForUpdate()->firstOrFail();
            $this->assertCanceledOrder($order);
            $record = MerchantDirectPaymentLateCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->assertV2($record);
            $callback($order, $record);

            return $record->fresh();
        }, 3);
    }

    private function saveAndAppend(
        NezhaRefundRecord $record,
        string $eventType,
        ?string $stateFrom,
        string $actorType,
        ?int $actorId,
        ?string $evidenceAuthority,
        array $payload
    ): void {
        $this->assertActor($actorType, $actorId);
        $record->state_version = ((int) $record->state_version) + 1;
        $record->save();
        $this->appendEvent(
            $record,
            $eventType,
            $stateFrom,
            $record->status,
            $actorType,
            $actorId,
            $evidenceAuthority,
            $payload
        );
    }

    private function appendEvent(
        NezhaRefundRecord $record,
        string $eventType,
        ?string $stateFrom,
        string $stateTo,
        string $actorType,
        ?int $actorId,
        ?string $evidenceAuthority,
        array $payload
    ): void {
        NezhaRefundRecordEvent::create([
            'public_id' => (string) Str::uuid(),
            'refund_record_id' => $record->id,
            'sequence' => $record->state_version,
            'event_type' => $eventType,
            'state_from' => $stateFrom,
            'state_to' => $stateTo,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'evidence_authority' => $evidenceAuthority,
            'payload' => $payload,
            'payload_hash' => hash('sha256', $this->canonicalJson($payload)),
            'recorded_at' => now(),
        ]);
    }

    private function assertEnabled(): void
    {
        if (! self::enabled()) {
            throw new InvalidArgumentException('direct_payment_late_v2_disabled');
        }
    }

    private function assertCanceledOrder(Order $order): void
    {
        if ($order->order_status !== MerchantDirectPaymentLateCasePolicy::orderStatus()
            || $order->payment_method !== 'offline_payment') {
            throw new InvalidArgumentException('late_payment_requires_canceled_offline_order');
        }
    }

    private function assertReportingOwner(Order $order, string $actorType, ?int $actorId): void
    {
        $expectedType = $order->is_guest ? 'guest' : 'customer';
        if ($actorType !== $expectedType || (int) $order->user_id !== (int) $actorId) {
            throw new InvalidArgumentException('late_payment_order_owner_mismatch');
        }
    }

    private function resolveOrderCredential(
        Order $order,
        int $methodId,
        int $actorId,
        ?string $credentialToken
    ): NezhaPaymentAddressCredential {
        $credential = NezhaPaymentAddressCredential::where('consumed_order_id', $order->id)->first();
        if (! $credential) {
            $credential = NezhaPaymentAddressCredentialService::resolveForProof(
                (string) $credentialToken,
                $actorId,
                (int) $order->restaurant_id,
                $methodId,
                (int) $order->id
            );
        }
        if ((int) $credential->user_id !== $actorId
            || (int) $credential->restaurant_id !== (int) $order->restaurant_id
            || (int) $credential->method_id !== $methodId
            || ($credential->consumed_order_id !== null
                && (int) $credential->consumed_order_id !== (int) $order->id)
            || ! NezhaUsdtAddress::isValid($credential->address_snapshot, $credential->network)) {
            throw new InvalidArgumentException('credential_binding_mismatch');
        }

        return $credential;
    }

    private function assertSameReport(
        NezhaRefundRecord $record,
        string $channel,
        int $methodId,
        string $walletType,
        string $paymentReference,
        ?int $credentialId
    ): void {
        if ($record->payment_channel !== $channel
            || (int) $record->method_id !== $methodId
            || $record->wallet_type !== $walletType
            || ! hash_equals((string) $record->late_payment_tx_hash, $paymentReference)
            || (int) ($record->credential_id ?? 0) !== (int) ($credentialId ?? 0)) {
            throw new InvalidArgumentException('late_payment_report_conflict');
        }
    }

    private function assertSameOrderOwner(Order $expected, Order $actual): void
    {
        if ((int) $expected->user_id !== (int) $actual->user_id
            || (bool) $expected->is_guest !== (bool) $actual->is_guest
            || (int) $expected->restaurant_id !== (int) $actual->restaurant_id) {
            throw new InvalidArgumentException('order_owner_changed');
        }
    }

    private function assertV2(NezhaRefundRecord $record): void
    {
        if ($record->source_domain !== self::SOURCE || ! $record->case_public_id) {
            throw new InvalidArgumentException('not_a_direct_payment_late_v2_case');
        }
    }

    private function assertRestaurantScope(NezhaRefundRecord $record, int $restaurantId): void
    {
        if ((int) $record->restaurant_id !== $restaurantId) {
            throw new InvalidArgumentException('late_payment_case_restaurant_mismatch');
        }
    }

    private function assertCaseOwner(NezhaRefundRecord $record, string $actorType, ?int $actorId): void
    {
        $expectedType = $record->guest_id !== null ? 'guest' : 'customer';
        $expectedId = $record->guest_id !== null ? (int) $record->guest_id : (int) $record->user_id;
        if ($actorType !== $expectedType || ! $actorId || $expectedId !== $actorId) {
            throw new InvalidArgumentException('late_payment_case_owner_mismatch');
        }
    }

    private function assertActor(string $actorType, ?int $actorId): void
    {
        if (! in_array($actorType, ['customer', 'guest', 'merchant', 'admin', 'system'], true)
            || ($actorType !== 'system' && (! $actorId || $actorId < 1))) {
            throw new InvalidArgumentException('invalid_late_payment_actor');
        }
    }

    private function assertMethodMatchesChannel(OfflinePaymentMethod $method, string $channel): void
    {
        $network = NezhaPaymentAddressCredentialService::networkForMethod($method);
        if (MerchantDirectPaymentLateCasePolicy::isUsdt($channel)) {
            if ($network !== MerchantDirectPaymentStrictUsdtVerifier::network($channel)) {
                throw new InvalidArgumentException('payment_method_channel_mismatch');
            }

            return;
        }
        $name = strtoupper((string) $method->method_name);
        if ($channel !== MerchantDirectPaymentLateCasePolicy::CHANNEL_ALIPAY
            || $network !== null
            || (! str_contains($name, 'ALIPAY') && ! str_contains((string) $method->method_name, '支付宝'))) {
            throw new InvalidArgumentException('payment_method_channel_mismatch');
        }
    }

    private function assertPaymentReference(string $channel, string $reference): void
    {
        if (MerchantDirectPaymentLateCasePolicy::isUsdt($channel)) {
            $this->normalizeTransactionHash($reference);

            return;
        }
        $length = mb_strlen(trim($reference));
        if ($length < 1 || $length > 120) {
            throw new InvalidArgumentException('payment_reference_invalid');
        }
    }

    private function normalizeTransactionHash(string $hash): string
    {
        $normalized = strtolower(trim($hash));
        if (str_starts_with($normalized, '0x')) {
            $normalized = substr($normalized, 2);
        }
        if (preg_match('/^[0-9a-f]{64}$/D', $normalized) !== 1) {
            throw new InvalidArgumentException('transaction_hash_invalid');
        }

        return $normalized;
    }

    private function claimKey(string $channel, string $hash, int $eventIndex): string
    {
        return hash('sha256', $channel.'|'.$this->normalizeTransactionHash($hash).'|'.$eventIndex);
    }

    private function assertObservationBound(array $observation, string $expectedHash): void
    {
        $attested = $observation['attested_transaction_hash'] ?? null;
        if (! is_string($attested)
            || ! hash_equals($this->normalizeTransactionHash($expectedHash), $this->normalizeTransactionHash($attested))) {
            throw new InvalidArgumentException('chain_observation_transaction_mismatch');
        }
    }

    private function canonicalJson(array $payload): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $normalize($item);
            }

            return $value;
        };

        return json_encode($normalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
