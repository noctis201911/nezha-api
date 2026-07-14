<?php

namespace App\CentralLogics;

use App\Models\Admin;
use App\Models\NezhaPaymentAddressChange;
use App\Models\NezhaPaymentNetworkState;
use App\Models\Restaurant;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Schema;

/** Read model for the admin A page, C review drawer and merchant owner card. */
class NezhaPaymentAddressChangeView
{
    private const OPEN_STATES = ['pending_merchant', 'pending_distinct_admin', 'draining', 'applying'];

    public static function admin(Restaurant $restaurant, ?string $reviewPublicId, ?Admin $admin): array
    {
        $storageReady = self::storageReady();
        $enabled = $storageReady && NezhaPaymentAddressChangeService::enabled();
        $states = collect();
        $changes = collect();

        if ($storageReady) {
            $states = NezhaPaymentNetworkState::where('restaurant_id', $restaurant->id)
                ->whereIn('network', [NezhaUsdtAddress::TRC20, NezhaUsdtAddress::BEP20])
                ->get()
                ->keyBy('network');
            $changes = NezhaPaymentAddressChange::where('restaurant_id', $restaurant->id)
                ->whereIn('state', self::OPEN_STATES)
                ->latest('id')
                ->get();
        }

        $networks = collect([
            NezhaUsdtAddress::TRC20 => (string) $restaurant->usdt_address,
            NezhaUsdtAddress::BEP20 => (string) $restaurant->usdt_bep20_address,
        ])->map(function (string $address, string $network) use ($states, $changes): array {
            $inspection = NezhaUsdtAddress::inspect($address, $network);
            $state = $states->get($network);
            $pending = $changes->firstWhere('network', $network);

            return [
                'network' => $network,
                'address' => $address,
                'configured' => trim($address) !== '',
                'valid' => (bool) $inspection['valid'],
                'validation_error' => $inspection['error'],
                'fingerprint' => $inspection['valid']
                    ? NezhaUsdtAddress::fingerprint($inspection['normalized'], $network)
                    : null,
                'state' => $state,
                'pending' => $pending,
                'requestable' => $inspection['valid']
                    && $state
                    && in_array($state->state, ['active', 'paused'], true)
                    && $state->pending_change_id === null,
            ];
        });

        $review = null;
        if ($storageReady && $reviewPublicId) {
            $review = NezhaPaymentAddressChange::where('restaurant_id', $restaurant->id)
                ->where('public_id', $reviewPublicId)
                ->first();
        }

        $totpAdminCount = $storageReady
            ? Admin::where('two_factor_enabled', true)->whereNotNull('two_factor_secret')->count()
            : 0;
        $reviewerCanApprove = $review
            && $review->state === 'pending_distinct_admin'
            && $admin
            && (int) $review->requested_by_admin_id !== (int) $admin->id
            && $admin->two_factor_enabled
            && $admin->two_factor_secret
            && $totpAdminCount >= 2;

        return [
            'enabled' => $enabled,
            'storage_ready' => $storageReady,
            'networks' => $networks,
            'open_changes' => $changes,
            'review' => $review,
            'totp_admin_count' => $totpAdminCount,
            'reviewer_can_approve' => (bool) $reviewerCanApprove,
            'current_admin_id' => $admin ? (int) $admin->id : null,
        ];
    }

    public static function merchant(Restaurant $restaurant, bool $isOwner): array
    {
        $storageReady = self::storageReady();
        $enabled = $storageReady && NezhaPaymentAddressChangeService::enabled();
        $changes = collect();
        if ($enabled && $isOwner) {
            $changes = NezhaPaymentAddressChange::where('restaurant_id', $restaurant->id)
                ->whereIn('state', self::OPEN_STATES)
                ->latest('id')
                ->get();
        }
        $notifications = collect();
        if ($isOwner && Schema::hasTable('user_notifications') && (int) $restaurant->vendor_id > 0) {
            $notifications = UserNotification::where('vendor_id', $restaurant->vendor_id)
                ->latest('id')
                ->limit(100)
                ->get()
                ->filter(static fn (UserNotification $notification): bool =>
                    ($notification->data['type'] ?? null) === 'nezha_payment_address_security'
                )
                ->take(10)
                ->values();
        }

        return [
            'enabled' => $enabled,
            'storage_ready' => $storageReady,
            'is_owner' => $isOwner,
            'open_changes' => $changes,
            'notifications' => $notifications,
        ];
    }

    public static function markMerchantSecurityNotificationsViewed(int $vendorId): int
    {
        if ($vendorId < 1 || ! Schema::hasTable('user_notifications')) {
            return 0;
        }

        $count = 0;
        UserNotification::where('vendor_id', $vendorId)
            ->where('status', 1)
            ->latest('id')
            ->limit(100)
            ->get()
            ->filter(static fn (UserNotification $notification): bool =>
                ($notification->data['type'] ?? null) === 'nezha_payment_address_security'
            )
            ->take(10)
            ->each(function (UserNotification $notification) use (&$count): void {
                $notification->status = 0;
                $notification->save();
                $count++;
            });

        return $count;
    }

    private static function storageReady(): bool
    {
        return Schema::hasTable('nezha_payment_network_states')
            && Schema::hasTable('nezha_payment_address_changes')
            && Schema::hasTable('nezha_payment_address_change_events');
    }
}
