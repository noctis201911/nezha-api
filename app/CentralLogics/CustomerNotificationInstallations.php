<?php

namespace App\CentralLogics;

use App\Models\CustomerNotificationInstallation;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;

class CustomerNotificationInstallations
{
    public const MAX_ACTIVE_INSTALLATIONS = 10;

    private const DEADLOCK_ATTEMPTS = 3;

    public static function register(User $customer, array $attributes, $accessToken = null): CustomerNotificationInstallation
    {
        $token = $attributes['cm_firebase_token'];

        return self::registerInstallation($customer, [
            'installation_id' => $attributes['installation_id'],
            'transport' => 'fcm_web',
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'platform' => $attributes['platform'] ?? null,
        ], $accessToken, true);
    }

    public static function registerWebPush(User $customer, array $attributes, $accessToken = null): CustomerNotificationInstallation
    {
        $subscription = [
            'endpoint' => $attributes['subscription']['endpoint'],
            'keys' => [
                'p256dh' => $attributes['subscription']['keys']['p256dh'],
                'auth' => $attributes['subscription']['keys']['auth'],
            ],
            'contentEncoding' => 'aes128gcm',
        ];
        $token = json_encode($subscription, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return self::registerInstallation($customer, [
            'installation_id' => $attributes['installation_id'],
            'transport' => 'web_push',
            'token' => $token,
            'token_hash' => hash('sha256', $subscription['endpoint']),
            'platform' => 'ios_web',
        ], $accessToken, false);
    }

    private static function registerInstallation(
        User $customer,
        array $attributes,
        $accessToken,
        bool $assignLegacyToken
    ): CustomerNotificationInstallation {
        $token = $attributes['token'];
        $tokenHash = $attributes['token_hash'];

        return DB::transaction(function () use ($accessToken, $assignLegacyToken, $attributes, $customer, $token, $tokenHash): CustomerNotificationInstallation {
            self::lockAndAssertAccessTokenIsStillActive($accessToken);

            $installationMatch = CustomerNotificationInstallation::query()
                ->where('installation_id', $attributes['installation_id'])
                ->lockForUpdate()
                ->first();
            $tokenMatch = CustomerNotificationInstallation::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            $displacedOwnerId = $installationMatch && $installationMatch->user_id !== $customer->id
                ? $installationMatch->user_id
                : null;
            $displacedToken = $displacedOwnerId ? $installationMatch->token : null;
            $displacedTransport = $displacedOwnerId ? $installationMatch->transport : null;

            if ($tokenMatch && $tokenMatch->isNot($installationMatch)) {
                $installationMatch?->delete();
                $installation = $tokenMatch;
            } else {
                $installation = $installationMatch ?? new CustomerNotificationInstallation;
            }

            $installation->fill([
                'user_id' => $customer->id,
                'installation_id' => $attributes['installation_id'],
                'transport' => $attributes['transport'],
                'token' => $token,
                'token_hash' => $tokenHash,
                'platform' => $attributes['platform'] ?? null,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ])->save();

            if ($displacedOwnerId && $displacedToken && str_starts_with((string) $displacedTransport, 'fcm_')) {
                self::repointLegacyToken($displacedOwnerId, $displacedToken);
            }
            if ($assignLegacyToken) {
                self::assignLegacyTokenToCurrentCustomer($customer, $token);
            }
            self::pruneCustomerInstallations($customer);

            return $installation;
        }, self::DEADLOCK_ATTEMPTS);
    }

    public static function registerLegacy(User $customer, string $token, $accessToken = null): void
    {
        $tokenHash = hash('sha256', $token);

        DB::transaction(function () use ($accessToken, $customer, $token, $tokenHash): void {
            self::lockAndAssertAccessTokenIsStillActive($accessToken);

            $tokenMatch = CustomerNotificationInstallation::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();
            CustomerNotificationInstallation::query()
                ->where('user_id', $customer->id)
                ->where('transport', 'fcm_web_legacy')
                ->when($tokenMatch, function ($query) use ($tokenMatch) {
                    $query->where('id', '!=', $tokenMatch->id);
                })
                ->delete();

            $installation = $tokenMatch ?? new CustomerNotificationInstallation;
            $installation->fill([
                'user_id' => $customer->id,
                'installation_id' => 'legacy:'.$tokenHash,
                'transport' => 'fcm_web_legacy',
                'token' => $token,
                'token_hash' => $tokenHash,
                'platform' => null,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ])->save();

            self::assignLegacyTokenToCurrentCustomer($customer, $token);
            self::pruneCustomerInstallations($customer);
        }, self::DEADLOCK_ATTEMPTS);
    }

    public static function logout(User $customer, $accessToken, ?string $installationId): void
    {
        DB::transaction(function () use ($accessToken, $customer, $installationId): void {
            $lockedAccessToken = self::lockAccessToken($accessToken);
            if ($lockedAccessToken && method_exists($lockedAccessToken, 'revoke')) {
                $lockedAccessToken->revoke();
            }

            if ($installationId) {
                self::revoke($customer, $installationId);
            } else {
                self::revokeLegacy($customer);
            }
        }, self::DEADLOCK_ATTEMPTS);
    }

    public static function revokeLegacy(User $customer): bool
    {
        return DB::transaction(function () use ($customer): bool {
            $legacyInstallations = CustomerNotificationInstallation::query()
                ->where('user_id', $customer->id)
                ->where('transport', 'fcm_web_legacy')
                ->lockForUpdate()
                ->get();
            $revoked = CustomerNotificationInstallation::query()
                ->whereIn('id', $legacyInstallations->pluck('id'))
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
            $legacyTokens = $legacyInstallations->pluck('token')->all();
            $legacyTokenQuery = DB::table('users')
                ->where('id', $customer->id)
                ->whereNotNull('cm_firebase_token');
            if ($legacyTokens !== []) {
                $legacyTokenQuery->whereIn('cm_firebase_token', $legacyTokens);
            }
            $repointed = $legacyTokenQuery->update([
                'cm_firebase_token' => self::latestActiveToken($customer->id),
            ]);

            return $revoked > 0 || $repointed > 0;
        }, self::DEADLOCK_ATTEMPTS);
    }

    public static function revoke(User $customer, string $installationId): bool
    {
        return DB::transaction(function () use ($customer, $installationId): bool {
            $installation = CustomerNotificationInstallation::query()
                ->where('user_id', $customer->id)
                ->where('installation_id', $installationId)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if (! $installation) {
                return false;
            }

            $installation->update(['revoked_at' => now()]);
            if (str_starts_with((string) $installation->transport, 'fcm_')) {
                self::repointLegacyToken($customer->id, $installation->token);
            }

            return true;
        }, self::DEADLOCK_ATTEMPTS);
    }

    private static function lockAndAssertAccessTokenIsStillActive($accessToken): void
    {
        if (! is_object($accessToken)) {
            return;
        }

        $lockedAccessToken = self::lockAccessToken($accessToken);
        if (! $lockedAccessToken || (bool) ($lockedAccessToken->revoked ?? false)) {
            throw new AuthenticationException('Unauthenticated.');
        }
    }

    private static function lockAccessToken($accessToken)
    {
        if (! is_object($accessToken)) {
            return null;
        }

        if (method_exists($accessToken, 'newQuery') && method_exists($accessToken, 'getKey') && $accessToken->getKey() !== null) {
            return $accessToken->newQuery()
                ->whereKey($accessToken->getKey())
                ->lockForUpdate()
                ->first();
        }

        return method_exists($accessToken, 'fresh') ? $accessToken->fresh() : $accessToken;
    }

    private static function assignLegacyTokenToCurrentCustomer(User $customer, string $token): void
    {
        $previousCustomerIds = DB::table('users')
            ->where('cm_firebase_token', $token)
            ->where('id', '!=', $customer->id)
            ->pluck('id');
        foreach ($previousCustomerIds as $previousCustomerId) {
            self::repointLegacyToken((int) $previousCustomerId, $token);
        }

        DB::table('users')
            ->where('id', $customer->id)
            ->update(['cm_firebase_token' => $token]);
    }

    private static function repointLegacyToken(int $customerId, string $revokedOrMovedToken): void
    {
        DB::table('users')
            ->where('id', $customerId)
            ->where('cm_firebase_token', $revokedOrMovedToken)
            ->update(['cm_firebase_token' => self::latestActiveToken($customerId)]);
    }

    private static function latestActiveToken(int $customerId): ?string
    {
        return CustomerNotificationInstallation::query()
            ->where('user_id', $customerId)
            ->whereNull('revoked_at')
            ->whereIn('transport', ['fcm_web', 'fcm_web_legacy'])
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->first()?->token;
    }

    private static function pruneCustomerInstallations(User $customer): void
    {
        CustomerNotificationInstallation::query()
            ->where('user_id', $customer->id)
            ->whereNotNull('revoked_at')
            ->delete();

        $keptInstallationIds = CustomerNotificationInstallation::query()
            ->where('user_id', $customer->id)
            ->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ACTIVE_INSTALLATIONS)
            ->pluck('id');

        $prunedFcmTokenHashes = collect();
        if ($keptInstallationIds->isNotEmpty()) {
            $prunedFcmTokenHashes = CustomerNotificationInstallation::query()
                ->where('user_id', $customer->id)
                ->whereNull('revoked_at')
                ->whereNotIn('id', $keptInstallationIds)
                ->whereIn('transport', ['fcm_web', 'fcm_web_legacy'])
                ->pluck('token_hash');
            CustomerNotificationInstallation::query()
                ->where('user_id', $customer->id)
                ->whereNull('revoked_at')
                ->whereNotIn('id', $keptInstallationIds)
                ->delete();
        }

        $legacyToken = DB::table('users')->where('id', $customer->id)->value('cm_firebase_token');
        if (is_string($legacyToken) && $prunedFcmTokenHashes->contains(hash('sha256', $legacyToken))) {
            DB::table('users')->where('id', $customer->id)->update([
                'cm_firebase_token' => self::latestActiveToken($customer->id),
            ]);
        }
    }
}
