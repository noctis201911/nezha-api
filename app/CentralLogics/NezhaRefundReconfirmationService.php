<?php

namespace App\CentralLogics;

use App\Models\NezhaCustomerRefundAddressCredential;
use App\Models\NezhaRefundRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class NezhaRefundReconfirmationService
{
    public static function issueChallenge(
        NezhaRefundRecord $record,
        User $user,
        Request $request
    ): array {
        self::assertRecordEligible($record, $user);
        if (NezhaCustomerRefundAddressCredentialService::mode()
            === NezhaCustomerRefundAddressCredentialService::MODE_CLOSED) {
            throw new \DomainException('refund_mode_closed');
        }

        $authMethod = self::normalizeAuthMethod($user->login_medium);
        if ($authMethod === null) {
            throw new \DomainException('fresh_auth_method_unavailable');
        }

        $secret = bin2hex(random_bytes(32));
        $ttl = max(60, min(600, (int) DB::table('business_settings')
            ->where('key', 'nezha_refund_reconfirm_ttl_seconds')
            ->value('value') ?: 300));
        $record->reconfirm_challenge_hash = self::challengeHash($secret, $record);
        $record->reconfirm_initial_token_id = self::accessTokenId($request);
        $record->reconfirm_auth_method = $authMethod;
        $record->reconfirm_expires_at = now()->addSeconds($ttl);
        $record->reconfirm_consumed_at = null;
        $record->save();

        return [
            'challenge_token' => $secret,
            'expires_at' => $record->reconfirm_expires_at?->toIso8601String(),
            'required_auth_method' => $authMethod,
            'refund' => $record->customerProjection(),
        ];
    }

    public static function confirm(
        int $recordId,
        User $user,
        Request $request,
        string $challengeToken,
        ?string $password
    ): NezhaRefundRecord {
        return DB::transaction(function () use (
            $recordId,
            $user,
            $request,
            $challengeToken,
            $password
        ): NezhaRefundRecord {
            $record = NezhaRefundRecord::whereKey($recordId)->lockForUpdate()->first();
            if (! $record) {
                throw new \DomainException('refund_record_not_found');
            }
            self::assertRecordEligible($record, $user);
            if (NezhaCustomerRefundAddressCredentialService::mode()
                === NezhaCustomerRefundAddressCredentialService::MODE_CLOSED) {
                throw new \DomainException('refund_mode_closed');
            }
            if ($record->reconfirm_consumed_at !== null) {
                throw new \DomainException('refund_reconfirm_already_consumed');
            }
            if (! $record->reconfirm_challenge_hash
                || ! $record->reconfirm_expires_at
                || $record->reconfirm_expires_at->isPast()) {
                throw new \DomainException('refund_reconfirm_expired');
            }
            $expected = self::challengeHash(trim($challengeToken), $record);
            if (! hash_equals((string) $record->reconfirm_challenge_hash, $expected)) {
                throw new \DomainException('refund_reconfirm_invalid');
            }

            $credential = NezhaCustomerRefundAddressCredential::whereKey(
                $record->refund_address_credential_id
            )->first();
            if (! $credential
                || (int) $credential->consumed_order_id !== (int) $record->order_id
                || ! hash_equals(
                    (string) $credential->address_fingerprint,
                    (string) $record->address_fingerprint
                )
                || ! NezhaUsdtAddress::equals(
                    $credential->address_snapshot,
                    $record->locked_to_address,
                    $record->asset_network
                )) {
                throw new \DomainException('refund_snapshot_changed');
            }

            $authMethod = self::normalizeAuthMethod($user->login_medium);
            if ($authMethod === null
                || $authMethod !== (string) $record->reconfirm_auth_method) {
                throw new \DomainException('fresh_auth_method_mismatch');
            }

            if ($authMethod === 'manual') {
                if (! is_string($password)
                    || $password === ''
                    || ! $user->password
                    || ! Hash::check($password, (string) $user->password)) {
                    throw new \DomainException('fresh_auth_failed');
                }
            } else {
                $currentTokenId = self::accessTokenId($request);
                $createdAt = self::accessTokenCreatedAt($request);
                if (! $currentTokenId
                    || ! $createdAt
                    || hash_equals(
                        (string) $record->reconfirm_initial_token_id,
                        (string) $currentTokenId
                    )
                    || $createdAt->lt($record->updated_at)) {
                    throw new \DomainException('fresh_auth_required');
                }
            }

            $record->reconfirmed_at = now();
            $record->reconfirm_consumed_at = now();
            $record->reconfirm_challenge_hash = null;
            $record->reconfirm_initial_token_id = null;
            $record->hold_reason = null;
            $record->status = 'pending_merchant_refund';
            $record->save();

            return $record;
        });
    }

    private static function assertRecordEligible(NezhaRefundRecord $record, User $user): void
    {
        if ((int) $record->user_id !== (int) $user->id
            || $record->payment_channel !== 'usdt') {
            throw new \DomainException('refund_record_not_found');
        }
        if ($record->status !== 'awaiting_customer_reconfirm') {
            throw new \DomainException('refund_reconfirm_not_available');
        }
        if (! $record->locked_to_address
            || ! $record->address_fingerprint
            || ! $record->asset_network
            || ! $record->asset_contract
            || $record->refund_asset_amount_atomic === null
            || $record->verification_status !== 'customer_attested') {
            throw new \DomainException('refund_destination_hold');
        }
    }

    private static function challengeHash(string $secret, NezhaRefundRecord $record): string
    {
        $context = implode('|', [
            (int) $record->user_id,
            (int) $record->order_id,
            (int) $record->refund_address_credential_id,
            (string) $record->address_fingerprint,
            (string) $record->asset_network,
            (string) $record->refund_asset_amount_atomic,
        ]);

        return hash('sha256', $secret.'|'.$context);
    }

    private static function normalizeAuthMethod($method): ?string
    {
        $value = strtolower(trim((string) $method));

        return match ($value) {
            'manual', 'password' => 'manual',
            'otp', 'email', 'phone' => 'otp',
            'telegram' => 'telegram',
            'google' => 'google',
            'facebook' => 'facebook',
            'apple' => 'apple',
            default => null,
        };
    }

    private static function accessTokenId(Request $request): ?string
    {
        try {
            $id = $request->user()?->token()?->id;

            return $id !== null ? (string) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function accessTokenCreatedAt(Request $request)
    {
        try {
            return $request->user()?->token()?->created_at;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
