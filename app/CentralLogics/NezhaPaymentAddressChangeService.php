<?php

namespace App\CentralLogics;

use App\Models\Admin;
use App\Models\NezhaPaymentAddressChange;
use App\Models\NezhaPaymentAddressChangeEvent;
use App\Models\NezhaPaymentAddressCredential;
use App\Models\NezhaPaymentNetworkState;
use App\Models\Restaurant;
use App\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Dormant USDT address-change state machine.
 *
 * This service changes no behaviour while SWITCH_KEY is 0. When enabled, it
 * is the only allowed path for USDT address writes: admin step-up -> merchant
 * owner confirmation -> a distinct admin step-up -> credential drain -> one
 * atomic address write. TOTP codes are never persisted or logged.
 */
class NezhaPaymentAddressChangeService
{
    public const SWITCH_KEY = 'nezha_payment_address_change_status';

    public const APPROVAL_TTL_KEY = 'nezha_payment_address_change_approval_ttl_min';

    private const OPEN_STATES = ['pending_merchant', 'pending_distinct_admin', 'draining', 'applying'];

    public static function enabled(): bool
    {
        return (string) DB::table('business_settings')
            ->where('key', self::SWITCH_KEY)
            ->value('value') === '1';
    }

    public static function initializeNetworkState(int $restaurantId, string $network): NezhaPaymentNetworkState
    {
        $network = self::requireNetwork($network);

        return DB::transaction(function () use ($restaurantId, $network): NezhaPaymentNetworkState {
            $restaurant = DB::table('restaurants')->where('id', $restaurantId)->lockForUpdate()->first();
            if (! $restaurant) {
                throw new \DomainException('address_change_restaurant_not_found');
            }

            $address = self::addressFromRow($restaurant, $network);
            $fingerprint = NezhaUsdtAddress::fingerprint($address, $network);
            if ($fingerprint === null) {
                throw new \DomainException('address_change_current_address_invalid');
            }

            $state = NezhaPaymentNetworkState::where('restaurant_id', $restaurantId)
                ->where('network', $network)
                ->lockForUpdate()
                ->first();
            if (! $state) {
                return NezhaPaymentNetworkState::create([
                    'restaurant_id' => $restaurantId,
                    'network' => $network,
                    'state' => 'active',
                    'active_address_fingerprint' => $fingerprint,
                    'active_version' => 1,
                ]);
            }
            if (! hash_equals((string) $state->active_address_fingerprint, $fingerprint)) {
                throw new \DomainException('address_change_network_state_mismatch');
            }

            return $state;
        });
    }

    public static function requestChange(
        Admin $admin,
        int $restaurantId,
        string $network,
        string $newAddress,
        string $reason,
        string $totpCode,
        string $idempotencyKey
    ): NezhaPaymentAddressChange {
        self::assertEnabled();
        $network = self::requireNetwork($network);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new \DomainException('address_change_reason_invalid');
        }
        $idempotencyHash = self::idempotencyHash($idempotencyKey);

        $inspection = NezhaUsdtAddress::inspect($newAddress, $network);
        if (! $inspection['valid']) {
            throw new \DomainException('address_change_address_invalid');
        }

        $existing = NezhaPaymentAddressChange::where('requested_by_admin_id', $admin->id)
            ->where('idempotency_hash', $idempotencyHash)
            ->first();
        if ($existing) {
            if ((int) $existing->restaurant_id !== $restaurantId
                || $existing->network !== $network
                || ! hash_equals((string) $existing->new_fingerprint, (string) NezhaUsdtAddress::fingerprint($inspection['normalized'], $network))) {
                throw new \DomainException('address_change_idempotency_conflict');
            }

            NezhaPaymentAddressChangeNotifier::change($existing, 'requested');

            return $existing;
        }

        $counter = self::stepUpCounter($admin, $totpCode);

        $change = self::runStepUpTransaction($admin, $counter, function () use (
            $admin,
            $restaurantId,
            $network,
            $inspection,
            $reason,
            $idempotencyHash,
            $counter
        ): NezhaPaymentAddressChange {
            $restaurant = DB::table('restaurants')->where('id', $restaurantId)->lockForUpdate()->first();
            if (! $restaurant) {
                throw new \DomainException('address_change_restaurant_not_found');
            }

            $oldAddress = self::addressFromRow($restaurant, $network);
            $oldInspection = NezhaUsdtAddress::inspect($oldAddress, $network);
            if (! $oldInspection['valid']) {
                throw new \DomainException('address_change_current_address_invalid');
            }
            if (NezhaUsdtAddress::equals($oldInspection['normalized'], $inspection['normalized'], $network)) {
                throw new \DomainException('address_change_address_unchanged');
            }

            $state = NezhaPaymentNetworkState::where('restaurant_id', $restaurantId)
                ->where('network', $network)
                ->lockForUpdate()
                ->first();
            if (! $state) {
                throw new \DomainException('address_change_network_state_missing');
            }
            if (! in_array($state->state, ['active', 'paused'], true)) {
                throw new \DomainException('address_change_network_not_requestable');
            }
            if ($state->pending_change_id !== null) {
                throw new \DomainException('address_change_already_pending');
            }
            $oldFingerprint = NezhaUsdtAddress::fingerprint($oldInspection['normalized'], $network);
            if (! hash_equals((string) $state->active_address_fingerprint, (string) $oldFingerprint)) {
                throw new \DomainException('address_change_network_state_mismatch');
            }

            $change = NezhaPaymentAddressChange::create([
                'public_id' => (string) Str::uuid(),
                'restaurant_id' => $restaurantId,
                'network' => $network,
                'source_state' => $state->state,
                'old_address' => $oldInspection['normalized'],
                'new_address' => $inspection['normalized'],
                'old_fingerprint' => $oldFingerprint,
                'new_fingerprint' => NezhaUsdtAddress::fingerprint($inspection['normalized'], $network),
                'expected_version' => $state->active_version,
                'state' => 'pending_merchant',
                'requested_by_admin_id' => $admin->id,
                'idempotency_hash' => $idempotencyHash,
                'reason' => $reason,
                'expires_at' => now()->addMinutes(self::approvalTtlMinutes()),
            ]);

            $state->pending_change_id = $change->id;
            $state->save();
            self::appendEvent(
                $state,
                $change,
                'requested',
                null,
                'pending_merchant',
                'admin',
                (int) $admin->id,
                $counter,
                ['approval_ttl_min' => self::approvalTtlMinutes()]
            );

            return $change->fresh();
        });
        NezhaPaymentAddressChangeNotifier::change($change, 'requested');

        return $change;
    }

    public static function merchantConfirm(
        Vendor $vendor,
        string $publicId,
        string $newFingerprint
    ): NezhaPaymentAddressChange {
        self::assertEnabled();

        $change = DB::transaction(function () use ($vendor, $publicId, $newFingerprint): NezhaPaymentAddressChange {
            $change = self::changeForUpdate($publicId);
            self::assertChangeState($change, 'pending_merchant');
            self::assertNotExpired($change);
            self::assertFingerprint($change, $newFingerprint);

            $ownerId = (int) DB::table('restaurants')->where('id', $change->restaurant_id)->value('vendor_id');
            if ($ownerId < 1 || $ownerId !== (int) $vendor->id) {
                throw new \DomainException('address_change_vendor_mismatch');
            }

            $state = self::lockedStateForChange($change);
            $change->state = 'pending_distinct_admin';
            $change->merchant_confirmed_by_vendor_id = $vendor->id;
            $change->merchant_confirmed_at = now();
            $change->save();
            self::appendEvent(
                $state,
                $change,
                'merchant_confirmed',
                'pending_merchant',
                'pending_distinct_admin',
                'vendor',
                (int) $vendor->id
            );

            return $change->fresh();
        });
        NezhaPaymentAddressChangeNotifier::change($change, 'merchant_confirmed');

        return $change;
    }

    public static function merchantReject(
        Vendor $vendor,
        string $publicId,
        string $newFingerprint
    ): NezhaPaymentAddressChange {
        self::assertEnabled();

        $change = DB::transaction(function () use ($vendor, $publicId, $newFingerprint): NezhaPaymentAddressChange {
            $change = self::changeForUpdate($publicId);
            if (! in_array($change->state, ['pending_merchant', 'pending_distinct_admin'], true)) {
                throw new \DomainException('address_change_state_invalid');
            }
            self::assertFingerprint($change, $newFingerprint);
            $ownerId = (int) DB::table('restaurants')->where('id', $change->restaurant_id)->value('vendor_id');
            if ($ownerId < 1 || $ownerId !== (int) $vendor->id) {
                throw new \DomainException('address_change_vendor_mismatch');
            }

            $state = self::lockedStateForChange($change);
            $from = $change->state;
            $change->state = 'rejected';
            $change->rejected_at = now();
            $change->save();
            self::releasePendingState($state, $change);
            self::appendEvent($state, $change, 'merchant_rejected', $from, 'rejected', 'vendor', (int) $vendor->id);

            return $change->fresh();
        });
        NezhaPaymentAddressChangeNotifier::change($change, 'merchant_rejected');

        return $change;
    }

    public static function approveChange(
        Admin $admin,
        string $publicId,
        string $newFingerprint,
        string $totpCode
    ): NezhaPaymentAddressChange {
        self::assertEnabled();
        $preview = NezhaPaymentAddressChange::where('public_id', $publicId)->first();
        if (! $preview) {
            throw new \DomainException('address_change_not_found');
        }
        if ((int) $preview->requested_by_admin_id === (int) $admin->id) {
            throw new \DomainException('address_change_distinct_admin_required');
        }
        $counter = self::stepUpCounter($admin, $totpCode);

        $change = self::runStepUpTransaction($admin, $counter, function () use (
            $admin,
            $publicId,
            $newFingerprint,
            $counter
        ): NezhaPaymentAddressChange {
            $change = self::changeForUpdate($publicId);
            self::assertChangeState($change, 'pending_distinct_admin');
            self::assertNotExpired($change);
            self::assertFingerprint($change, $newFingerprint);
            if ((int) $change->requested_by_admin_id === (int) $admin->id) {
                throw new \DomainException('address_change_distinct_admin_required');
            }
            if (! $change->merchant_confirmed_at || ! $change->merchant_confirmed_by_vendor_id) {
                throw new \DomainException('address_change_merchant_confirmation_required');
            }

            $state = self::lockedStateForChange($change);
            if (! in_array($state->state, ['active', 'paused'], true)) {
                throw new \DomainException('address_change_network_not_approvable');
            }

            $maxExpiry = NezhaPaymentAddressCredential::where('restaurant_id', $change->restaurant_id)
                ->where('network', $change->network)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->max('expires_at');
            $drainUntil = $maxExpiry ? Carbon::parse($maxExpiry) : now();

            $change->state = 'draining';
            $change->approved_by_admin_id = $admin->id;
            $change->approved_at = now();
            $change->drain_until = $drainUntil;
            $change->save();

            $state->state = 'draining';
            $state->drain_until = $drainUntil;
            $state->save();
            self::appendEvent(
                $state,
                $change,
                'distinct_admin_approved',
                'pending_distinct_admin',
                'draining',
                'admin',
                (int) $admin->id,
                $counter,
                ['drain_until' => $drainUntil->toIso8601String()]
            );

            return $change->fresh();
        });
        NezhaPaymentAddressChangeNotifier::change($change, 'distinct_admin_approved');

        return $change;
    }

    public static function rejectChange(
        Admin $admin,
        string $publicId,
        string $newFingerprint,
        string $totpCode,
        ?string $reason = null
    ): NezhaPaymentAddressChange {
        self::assertEnabled();
        $preview = NezhaPaymentAddressChange::where('public_id', $publicId)->first();
        if (! $preview) {
            throw new \DomainException('address_change_not_found');
        }
        if ((int) $preview->requested_by_admin_id === (int) $admin->id) {
            throw new \DomainException('address_change_distinct_admin_required');
        }
        $counter = self::stepUpCounter($admin, $totpCode);
        $reviewReason = trim((string) $reason);

        $change = self::runStepUpTransaction($admin, $counter, function () use (
            $admin,
            $publicId,
            $newFingerprint,
            $counter,
            $reviewReason
        ): NezhaPaymentAddressChange {
            $change = self::changeForUpdate($publicId);
            self::assertChangeState($change, 'pending_distinct_admin');
            self::assertNotExpired($change);
            self::assertFingerprint($change, $newFingerprint);
            if ((int) $change->requested_by_admin_id === (int) $admin->id) {
                throw new \DomainException('address_change_distinct_admin_required');
            }
            if (! $change->merchant_confirmed_at || ! $change->merchant_confirmed_by_vendor_id) {
                throw new \DomainException('address_change_merchant_confirmation_required');
            }

            $state = self::lockedStateForChange($change);
            $change->state = 'rejected';
            $change->rejected_at = now();
            $change->save();
            self::releasePendingState($state, $change);
            self::appendEvent(
                $state,
                $change,
                'distinct_admin_rejected',
                'pending_distinct_admin',
                'rejected',
                'admin',
                (int) $admin->id,
                $counter,
                $reviewReason === '' ? null : ['review_reason' => $reviewReason]
            );

            return $change->fresh();
        });
        NezhaPaymentAddressChangeNotifier::change($change, 'distinct_admin_rejected');

        return $change;
    }

    public static function cancelChange(
        Admin $admin,
        string $publicId,
        string $totpCode
    ): NezhaPaymentAddressChange {
        self::assertEnabled();
        $counter = self::stepUpCounter($admin, $totpCode);

        $change = self::runStepUpTransaction($admin, $counter, function () use ($admin, $publicId, $counter): NezhaPaymentAddressChange {
            $change = self::changeForUpdate($publicId);
            if (! in_array($change->state, ['pending_merchant', 'pending_distinct_admin', 'draining'], true)) {
                throw new \DomainException('address_change_state_invalid');
            }
            $state = self::lockedStateForChange($change);
            $from = $change->state;
            $change->state = 'canceled';
            $change->canceled_at = now();
            $change->save();
            self::releasePendingState($state, $change);
            self::appendEvent($state, $change, 'admin_canceled', $from, 'canceled', 'admin', (int) $admin->id, $counter);

            return $change->fresh();
        });
        NezhaPaymentAddressChangeNotifier::change($change, 'admin_canceled');

        return $change;
    }

    public static function applyReadyChange(string $publicId): NezhaPaymentAddressChange
    {
        self::assertEnabled();

        $change = DB::transaction(function () use ($publicId): NezhaPaymentAddressChange {
            $change = self::changeForUpdate($publicId);
            self::assertChangeState($change, 'draining');
            $state = self::lockedStateForChange($change);
            if (! $change->drain_until || $change->drain_until->isFuture()) {
                throw new \DomainException('address_change_drain_pending');
            }

            $stillActive = NezhaPaymentAddressCredential::where('restaurant_id', $change->restaurant_id)
                ->where('network', $change->network)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();
            if ($stillActive) {
                throw new \DomainException('address_change_drain_pending');
            }

            $restaurant = DB::table('restaurants')->where('id', $change->restaurant_id)->lockForUpdate()->first();
            if (! $restaurant) {
                return self::failLocked($state, $change, 'restaurant_missing');
            }
            $currentAddress = self::addressFromRow($restaurant, $change->network);
            $currentFingerprint = NezhaUsdtAddress::fingerprint($currentAddress, $change->network);
            if ($currentFingerprint === null || ! hash_equals((string) $change->old_fingerprint, $currentFingerprint)) {
                return self::failLocked($state, $change, 'old_address_drift');
            }
            if ((int) $state->active_version !== (int) $change->expected_version
                || ! hash_equals((string) $state->active_address_fingerprint, (string) $change->old_fingerprint)) {
                return self::failLocked($state, $change, 'network_version_drift');
            }

            $change->state = 'applying';
            $change->save();
            self::appendEvent($state, $change, 'applying', 'draining', 'applying', 'system', null);

            $column = NezhaUsdtAddress::columnForNetwork($change->network);
            DB::table('restaurants')->where('id', $change->restaurant_id)->update([
                $column => $change->new_address,
                'updated_at' => now(),
            ]);

            $state->state = 'active';
            $state->active_address_fingerprint = $change->new_fingerprint;
            $state->active_version = (int) $state->active_version + 1;
            $state->pending_change_id = null;
            $state->drain_until = null;
            $state->paused_at = null;
            $state->paused_by_admin_id = null;
            $state->pause_reason = null;
            $state->save();

            $change->state = 'applied';
            $change->applied_at = now();
            $change->save();
            self::appendEvent($state, $change, 'applied', 'applying', 'applied', 'system', null);

            return $change->fresh();
        });
        NezhaPaymentAddressChangeNotifier::change(
            $change,
            $change->state === 'applied' ? 'applied' : 'apply_failed'
        );

        return $change;
    }

    public static function emergencyPause(
        Admin $admin,
        int $restaurantId,
        string $network,
        string $reason,
        string $totpCode
    ): NezhaPaymentNetworkState {
        self::assertEnabled();
        $network = self::requireNetwork($network);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new \DomainException('address_change_reason_invalid');
        }
        $counter = self::stepUpCounter($admin, $totpCode);

        $state = self::runStepUpTransaction($admin, $counter, function () use (
            $admin,
            $restaurantId,
            $network,
            $reason,
            $counter
        ): NezhaPaymentNetworkState {
            $restaurant = DB::table('restaurants')->where('id', $restaurantId)->lockForUpdate()->first();
            if (! $restaurant) {
                throw new \DomainException('address_change_restaurant_not_found');
            }
            $address = self::addressFromRow($restaurant, $network);
            $state = self::stateForUpdate($restaurantId, $network, $address);

            $pendingChange = null;
            $pendingChangeFrom = null;
            if ($state->pending_change_id !== null) {
                $pendingChange = NezhaPaymentAddressChange::whereKey($state->pending_change_id)->lockForUpdate()->first();
                if ($pendingChange && in_array($pendingChange->state, self::OPEN_STATES, true)) {
                    $pendingChangeFrom = $pendingChange->state;
                    $pendingChange->state = 'canceled';
                    $pendingChange->canceled_at = now();
                    $pendingChange->save();
                }
            }

            $state->state = 'paused';
            $state->pending_change_id = null;
            $state->drain_until = null;
            $state->paused_at = now();
            $state->paused_by_admin_id = $admin->id;
            $state->pause_reason = $reason;
            $state->save();

            $credentials = NezhaPaymentAddressCredential::where('restaurant_id', $restaurantId)
                ->where('network', $network)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get();
            foreach ($credentials as $credential) {
                $credential->revoked_at = now();
                $credential->revoked_reason = 'emergency_network_pause';
                $credential->save();
            }

            self::appendEvent(
                $state,
                $pendingChange,
                'emergency_paused',
                $pendingChangeFrom,
                'paused',
                'admin',
                (int) $admin->id,
                $counter,
                ['revoked_unconsumed_credentials' => $credentials->count()]
            );

            return $state->fresh();
        });
        NezhaPaymentAddressChangeNotifier::emergencyPause($state);

        return $state;
    }

    public static function credentialNetworkAvailable(int $restaurantId, string $network): bool
    {
        $network = NezhaUsdtAddress::normalizeNetwork($network);
        if ($network === null) {
            return false;
        }
        // A recorded pause/drain remains authoritative even if the change switch
        // is later closed during rollback. Before this migration exists, the
        // credential-only dormant stage keeps its original behaviour.
        if (! Schema::hasTable('nezha_payment_network_states')) {
            return ! self::enabled();
        }
        $state = NezhaPaymentNetworkState::where('restaurant_id', $restaurantId)
            ->where('network', $network)
            ->first();
        if (! $state) {
            return ! self::enabled();
        }
        if ($state->state !== 'active') {
            return false;
        }
        $column = NezhaUsdtAddress::columnForNetwork($network);
        $address = DB::table('restaurants')->where('id', $restaurantId)->value($column);
        $fingerprint = NezhaUsdtAddress::fingerprint($address, $network);

        return $fingerprint !== null
            && hash_equals((string) $state->active_address_fingerprint, $fingerprint);
    }

    public static function assertLegacyUsdtWriteAllowed(Restaurant $restaurant, array $input): void
    {
        if (! self::enabled()) {
            return;
        }

        foreach ([
            'usdt_address' => NezhaUsdtAddress::TRC20,
            'usdt_bep20_address' => NezhaUsdtAddress::BEP20,
        ] as $field => $network) {
            if (! array_key_exists($field, $input)) {
                continue;
            }
            $current = trim((string) $restaurant->{$field});
            $proposed = trim((string) $input[$field]);
            if ($current === '' && $proposed === '') {
                continue;
            }
            if (! NezhaUsdtAddress::equals($current, $proposed, $network)) {
                throw new \DomainException('address_change_legacy_write_blocked');
            }
        }
        if (array_key_exists('usdt_network', $input)
            && NezhaUsdtAddress::normalizeNetwork($restaurant->usdt_network)
                !== NezhaUsdtAddress::normalizeNetwork($input['usdt_network'])) {
            throw new \DomainException('address_change_legacy_write_blocked');
        }
    }

    public static function expireStaleChanges(int $limit = 100): int
    {
        if (! self::enabled()) {
            return 0;
        }
        $ids = NezhaPaymentAddressChange::whereIn('state', ['pending_merchant', 'pending_distinct_admin'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(max(1, min(1000, $limit)))
            ->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $expiredChange = DB::transaction(function () use ($id): ?NezhaPaymentAddressChange {
                $change = NezhaPaymentAddressChange::whereKey($id)->lockForUpdate()->first();
                if (! $change
                    || ! in_array($change->state, ['pending_merchant', 'pending_distinct_admin'], true)
                    || ! $change->expires_at
                    || $change->expires_at->isFuture()) {
                    return null;
                }
                $state = self::lockedStateForChange($change);
                $from = $change->state;
                $change->state = 'expired';
                $change->expired_at = now();
                $change->save();
                self::releasePendingState($state, $change);
                self::appendEvent(
                    $state,
                    $change,
                    'expired',
                    $from,
                    'expired',
                    'system',
                    null,
                    null,
                    ['rejection_code' => 'approval_timeout']
                );

                return $change->fresh();
            });
            if ($expiredChange) {
                NezhaPaymentAddressChangeNotifier::change($expiredChange, 'expired');
                $count++;
            }
        }

        return $count;
    }

    public static function applyReadyChanges(int $limit = 100): array
    {
        if (! self::enabled()) {
            return ['applied' => 0, 'failed' => 0, 'pending' => 0];
        }
        $publicIds = NezhaPaymentAddressChange::where('state', 'draining')
            ->whereNotNull('drain_until')
            ->where('drain_until', '<=', now())
            ->orderBy('id')
            ->limit(max(1, min(1000, $limit)))
            ->pluck('public_id');
        $result = ['applied' => 0, 'failed' => 0, 'pending' => 0];
        foreach ($publicIds as $publicId) {
            try {
                $change = self::applyReadyChange((string) $publicId);
                $result[$change->state === 'applied' ? 'applied' : 'failed']++;
            } catch (\DomainException $e) {
                if ($e->getMessage() !== 'address_change_drain_pending') {
                    throw $e;
                }
                $result['pending']++;
            }
        }

        return $result;
    }

    private static function failLocked(
        NezhaPaymentNetworkState $state,
        NezhaPaymentAddressChange $change,
        string $failureCode
    ): NezhaPaymentAddressChange {
        $from = $change->state;
        $change->state = 'failed';
        $change->failed_at = now();
        $change->failure_code = $failureCode;
        $change->save();

        $state->state = 'paused';
        $state->pending_change_id = null;
        $state->drain_until = null;
        $state->paused_at = now();
        $state->pause_reason = 'apply_failed:'.$failureCode;
        $state->save();
        self::appendEvent(
            $state,
            $change,
            'apply_failed',
            $from,
            'failed',
            'system',
            null,
            null,
            ['failure_code' => $failureCode]
        );

        return $change->fresh();
    }

    private static function stateForUpdate(
        int $restaurantId,
        string $network,
        string $currentAddress
    ): NezhaPaymentNetworkState {
        $state = NezhaPaymentNetworkState::where('restaurant_id', $restaurantId)
            ->where('network', $network)
            ->lockForUpdate()
            ->first();
        if ($state) {
            return $state;
        }

        $fingerprint = NezhaUsdtAddress::fingerprint($currentAddress, $network);
        if ($fingerprint === null) {
            throw new \DomainException('address_change_current_address_invalid');
        }

        return NezhaPaymentNetworkState::create([
            'restaurant_id' => $restaurantId,
            'network' => $network,
            'state' => 'active',
            'active_address_fingerprint' => $fingerprint,
            'active_version' => 1,
        ]);
    }

    private static function changeForUpdate(string $publicId): NezhaPaymentAddressChange
    {
        $change = NezhaPaymentAddressChange::where('public_id', $publicId)->lockForUpdate()->first();
        if (! $change) {
            throw new \DomainException('address_change_not_found');
        }

        return $change;
    }

    private static function lockedStateForChange(NezhaPaymentAddressChange $change): NezhaPaymentNetworkState
    {
        $state = NezhaPaymentNetworkState::where('restaurant_id', $change->restaurant_id)
            ->where('network', $change->network)
            ->lockForUpdate()
            ->first();
        if (! $state || (int) $state->pending_change_id !== (int) $change->id) {
            throw new \DomainException('address_change_network_state_mismatch');
        }

        return $state;
    }

    private static function releasePendingState(
        NezhaPaymentNetworkState $state,
        NezhaPaymentAddressChange $change
    ): void {
        $state->pending_change_id = null;
        $state->drain_until = null;
        $state->state = $change->source_state === 'paused' ? 'paused' : 'active';
        $state->save();
    }

    private static function appendEvent(
        NezhaPaymentNetworkState $state,
        ?NezhaPaymentAddressChange $change,
        string $eventType,
        ?string $from,
        string $to,
        string $actorType,
        ?int $actorId,
        ?int $totpCounter = null,
        ?array $context = null
    ): void {
        if ($totpCounter !== null
            && NezhaPaymentAddressChangeEvent::where('actor_type', $actorType)
                ->where('actor_id', $actorId)
                ->where('totp_counter', $totpCounter)
                ->exists()) {
            throw new \DomainException('address_change_totp_replayed');
        }

        NezhaPaymentAddressChangeEvent::create([
            'change_id' => $change?->id,
            'network_state_id' => $state->id,
            'event_type' => $eventType,
            'state_from' => $from,
            'state_to' => $to,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'totp_counter' => $totpCounter,
            'context' => $context,
        ]);
    }

    private static function stepUpCounter(Admin $admin, string $code): int
    {
        if (! $admin->two_factor_enabled || ! $admin->two_factor_secret) {
            throw new \DomainException('address_change_step_up_required');
        }
        $rateKey = 'payment-address-step-up:'.(int) $admin->id;
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            throw new \DomainException('address_change_totp_rate_limited');
        }
        $counter = NezhaTotp::matchingCounter((string) $admin->two_factor_secret, $code);
        if ($counter === null) {
            RateLimiter::hit($rateKey, 300);
            throw new \DomainException('address_change_totp_invalid');
        }
        RateLimiter::clear($rateKey);

        return $counter;
    }

    private static function runStepUpTransaction(Admin $admin, int $counter, callable $callback)
    {
        try {
            return DB::transaction($callback);
        } catch (QueryException $e) {
            $replayed = NezhaPaymentAddressChangeEvent::where('actor_type', 'admin')
                ->where('actor_id', $admin->id)
                ->where('totp_counter', $counter)
                ->exists();
            if ($replayed) {
                throw new \DomainException('address_change_totp_replayed');
            }
            throw $e;
        }
    }

    private static function assertEnabled(): void
    {
        if (! self::enabled()) {
            throw new \DomainException('address_change_feature_disabled');
        }
    }

    private static function assertChangeState(NezhaPaymentAddressChange $change, string $expected): void
    {
        if ($change->state !== $expected) {
            throw new \DomainException('address_change_state_invalid');
        }
    }

    private static function assertNotExpired(NezhaPaymentAddressChange $change): void
    {
        if ($change->expires_at && $change->expires_at->isPast()) {
            throw new \DomainException('address_change_expired');
        }
    }

    private static function assertFingerprint(NezhaPaymentAddressChange $change, string $fingerprint): void
    {
        if (! preg_match('/^[0-9a-f]{64}$/i', $fingerprint)
            || ! hash_equals((string) $change->new_fingerprint, strtolower($fingerprint))) {
            throw new \DomainException('address_change_fingerprint_mismatch');
        }
    }

    private static function requireNetwork(string $network): string
    {
        $normalized = NezhaUsdtAddress::normalizeNetwork($network);
        if ($normalized === null) {
            throw new \DomainException('address_change_network_invalid');
        }

        return $normalized;
    }

    private static function addressFromRow(object $restaurant, string $network): string
    {
        $column = NezhaUsdtAddress::columnForNetwork($network);

        return (string) ($restaurant->{$column} ?? '');
    }

    private static function idempotencyHash(string $key): string
    {
        $key = trim($key);
        if (strlen($key) < 8 || strlen($key) > 191) {
            throw new \DomainException('address_change_idempotency_invalid');
        }

        return hash('sha256', $key);
    }

    private static function approvalTtlMinutes(): int
    {
        $configured = (int) (DB::table('business_settings')
            ->where('key', self::APPROVAL_TTL_KEY)
            ->value('value') ?: 1440);

        return max(30, min(10080, $configured));
    }
}
