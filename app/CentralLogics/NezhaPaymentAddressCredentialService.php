<?php

namespace App\CentralLogics;

use App\Models\NezhaPaymentAddressCredential;
use App\Models\OfflinePaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 付款地址凭据：把“平台何时向哪个登录顾客展示了哪个地址”持久化。
 *
 * 凭据不创建订单、不占优惠券、不冻结金额。客户端持有 public_id + 随机密钥，
 * 数据库只保存随机密钥的 SHA-256；地址/交易哈希由模型 encrypted cast 加密。
 */
class NezhaPaymentAddressCredentialService
{
    public const SWITCH_KEY = 'nezha_payment_address_credential_status';

    public static function enabled(): bool
    {
        return (string) DB::table('business_settings')->where('key', self::SWITCH_KEY)->value('value') === '1';
    }

    public static function networkForMethod($method): ?string
    {
        $name = $method instanceof OfflinePaymentMethod
            ? (string) $method->method_name
            : (string) $method;

        if (! preg_match('/usdt/i', $name)) {
            return null;
        }

        return preg_match('/bep ?20|bsc|bnb/i', $name)
            ? NezhaUsdtAddress::BEP20
            : NezhaUsdtAddress::TRC20;
    }

    public static function issue(int $userId, int $restaurantId, int $methodId): array
    {
        if (! self::enabled()) {
            throw new \DomainException('credential_feature_disabled');
        }

        $method = OfflinePaymentMethod::where('id', $methodId)->where('status', 1)->first();
        $network = self::networkForMethod($method);
        if (! $method || $network === null) {
            throw new \DomainException('credential_method_not_available');
        }

        $restaurant = DB::table('restaurants')
            ->where('id', $restaurantId)
            ->first(['id', 'usdt_address', 'usdt_bep20_address']);
        if (! $restaurant) {
            throw new \DomainException('credential_restaurant_not_found');
        }

        $rawAddress = $network === NezhaUsdtAddress::BEP20
            ? (string) ($restaurant->usdt_bep20_address ?? '')
            : (string) ($restaurant->usdt_address ?? '');
        $inspection = NezhaUsdtAddress::inspect($rawAddress, $network);
        if (! $inspection['valid']) {
            throw new \DomainException('credential_address_invalid');
        }
        if (! NezhaPaymentAddressChangeService::credentialNetworkAvailable($restaurantId, $network)) {
            throw new \DomainException('credential_network_unavailable');
        }

        $secret = bin2hex(random_bytes(32));
        $issuedAt = now();
        $credential = NezhaPaymentAddressCredential::create([
            'public_id' => (string) Str::uuid(),
            'secret_hash' => hash('sha256', $secret),
            'user_id' => $userId,
            'restaurant_id' => $restaurantId,
            'method_id' => $methodId,
            'network' => $network,
            'address_snapshot' => $inspection['normalized'],
            'address_fingerprint' => NezhaUsdtAddress::fingerprint(
                $inspection['normalized'],
                $network
            ),
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt->copy()->addMinutes(self::ttlMinutes()),
        ]);

        return [
            'credential' => $credential,
            'token' => $credential->public_id.'.'.$secret,
        ];
    }

    public static function resolveForProof(
        string $token,
        int $userId,
        int $restaurantId,
        int $methodId,
        int $orderId
    ): NezhaPaymentAddressCredential {
        [$publicId, $secret] = self::splitToken($token);

        $credential = NezhaPaymentAddressCredential::where('public_id', $publicId)->first();
        if (! $credential || ! hash_equals((string) $credential->secret_hash, hash('sha256', $secret))) {
            throw new \DomainException('credential_invalid');
        }
        if ((int) $credential->user_id !== $userId
            || (int) $credential->restaurant_id !== $restaurantId
            || (int) $credential->method_id !== $methodId) {
            throw new \DomainException('credential_binding_mismatch');
        }
        if ($credential->consumed_order_id !== null
            && (int) $credential->consumed_order_id !== $orderId) {
            throw new \DomainException('credential_already_consumed');
        }
        if ($credential->consumed_order_id === null) {
            self::assertActive($credential);
        }
        if (! NezhaUsdtAddress::isValid($credential->address_snapshot, $credential->network)) {
            throw new \DomainException('credential_snapshot_invalid');
        }

        return $credential;
    }

    public static function consume(
        NezhaPaymentAddressCredential $credential,
        int $orderId,
        ?string $txHash
    ): NezhaPaymentAddressCredential {
        return DB::transaction(function () use ($credential, $orderId, $txHash) {
            $fresh = NezhaPaymentAddressCredential::whereKey($credential->id)->lockForUpdate()->first();
            if (! $fresh) {
                throw new \DomainException('credential_not_found');
            }
            if ($fresh->consumed_order_id !== null && (int) $fresh->consumed_order_id !== $orderId) {
                throw new \DomainException('credential_already_consumed');
            }
            if ($fresh->consumed_order_id === null) {
                self::assertActive($fresh);
            }

            $fresh->consumed_order_id = $orderId;
            $fresh->consumed_at = $fresh->consumed_at ?: now();
            if ($fresh->submitted_tx_hash === null && $txHash !== null && $txHash !== '') {
                $fresh->submitted_tx_hash = $txHash;
            }
            $fresh->save();

            return $fresh;
        });
    }

    public static function evidence(NezhaPaymentAddressCredential $credential): array
    {
        $currentAddress = self::currentAddress((int) $credential->restaurant_id, $credential->network);
        $state = 'issued';
        if ($credential->revoked_at) {
            $state = 'revoked';
        } elseif ($credential->consumed_at) {
            $state = 'consumed';
        } elseif ($credential->expires_at && $credential->expires_at->isPast()) {
            $state = 'expired';
        }

        return [
            'credential_id' => (string) $credential->public_id,
            'address_version' => substr((string) $credential->address_fingerprint, 0, 16),
            'network' => (string) $credential->network,
            'issued_at' => $credential->issued_at?->toIso8601String(),
            'expires_at' => $credential->expires_at?->toIso8601String(),
            'state' => $state,
            'is_current_address' => $currentAddress !== null
                ? NezhaUsdtAddress::equals(
                    $credential->address_snapshot,
                    $currentAddress,
                    $credential->network
                )
                : null,
        ];
    }

    public static function ttlMinutes(): int
    {
        $configured = (int) (DB::table('business_settings')
            ->where('key', 'nezha_timeout_unpaid_cancel_min')
            ->value('value') ?: 10);

        return max(1, min(120, $configured));
    }

    private static function currentAddress(int $restaurantId, string $network): ?string
    {
        $column = NezhaUsdtAddress::columnForNetwork($network);
        if ($column === null) {
            return null;
        }
        $address = DB::table('restaurants')->where('id', $restaurantId)->value($column);

        return $address !== null ? (string) $address : null;
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
            throw new \DomainException('credential_invalid');
        }

        return $parts;
    }

    private static function assertActive(NezhaPaymentAddressCredential $credential): void
    {
        if ($credential->revoked_at !== null) {
            throw new \DomainException('credential_revoked');
        }
        if ($credential->expires_at === null || $credential->expires_at->isPast()) {
            throw new \DomainException('credential_expired');
        }
    }
}
