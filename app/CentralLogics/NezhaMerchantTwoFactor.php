<?php

namespace App\CentralLogics;

use App\Models\MerchantTwoFactorChallenge;
use App\Models\MerchantTwoFactorEvent;
use App\Models\Vendor;
use App\Models\VendorEmployee;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class NezhaMerchantTwoFactor
{
    public const OWNER = 'owner';

    public const EMPLOYEE = 'employee';

    public const STATE_OPTIONAL = 'optional';

    public const STATE_ENROLLMENT = 'enrollment_required';

    public const STATE_CHALLENGE = 'challenge_required';

    public const CHALLENGE_TTL_MINUTES = 5;

    public const MAX_CHALLENGE_ATTEMPTS = 5;

    public static function actorType(Authenticatable $actor): string
    {
        return match (true) {
            $actor instanceof Vendor => self::OWNER,
            $actor instanceof VendorEmployee => self::EMPLOYEE,
            default => throw new \InvalidArgumentException('Unsupported merchant actor.'),
        };
    }

    public static function actor(string $type, int $id, bool $lock = false): Vendor|VendorEmployee|null
    {
        $query = match ($type) {
            self::OWNER => Vendor::query(),
            self::EMPLOYEE => VendorEmployee::query(),
            default => null,
        };

        if (! $query) {
            return null;
        }

        return ($lock ? $query->lockForUpdate() : $query)->find($id);
    }

    public static function state(Authenticatable $actor, ?Carbon $at = null): string
    {
        if ($actor->two_factor_enabled && $actor->two_factor_secret) {
            return self::STATE_CHALLENGE;
        }

        if ($actor->two_factor_enabled) {
            return self::STATE_ENROLLMENT;
        }

        return self::STATE_OPTIONAL;
    }

    public static function completeEnrollment(
        Authenticatable $actor,
        string $secret,
        string $code,
        ?int $expectedGeneration = null,
        array $context = []
    ): array {
        $counter = NezhaTotp::matchingCounter($secret, $code);
        if ($counter === null) {
            throw new \DomainException('merchant_2fa_invalid_code');
        }

        return DB::transaction(function () use ($actor, $secret, $counter, $expectedGeneration, $context): array {
            $locked = self::actor(self::actorType($actor), (int) $actor->getAuthIdentifier(), true);
            if (! $locked || ($expectedGeneration !== null && (int) $locked->auth_generation !== $expectedGeneration)) {
                throw new \DomainException('merchant_2fa_challenge_expired');
            }

            [$plain, $hashed] = self::recoveryCodes();
            $locked->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_enabled' => true,
                'two_factor_recovery_codes' => $hashed,
                'two_factor_required_at' => null,
                'two_factor_enrolled_at' => now(),
                'two_factor_last_counter' => $counter,
                'two_factor_grace_pending' => false,
                'auth_generation' => (int) $locked->auth_generation + 1,
                'auth_token' => null,
                'remember_token' => null,
            ])->save();

            self::event($locked, 'enrolled', $context);

            return ['actor' => $locked, 'recovery_codes' => $plain];
        }, 3);
    }

    public static function verifyTotp(
        Authenticatable $actor,
        string $code,
        ?int $expectedGeneration = null,
        array $context = []
    ): Vendor|VendorEmployee {
        return DB::transaction(function () use ($actor, $code, $expectedGeneration, $context) {
            $locked = self::actor(self::actorType($actor), (int) $actor->getAuthIdentifier(), true);
            if (! $locked || ($expectedGeneration !== null && (int) $locked->auth_generation !== $expectedGeneration)) {
                throw new \DomainException('merchant_2fa_challenge_expired');
            }

            self::acceptTotp($locked, $code);
            self::event($locked, 'challenge_passed', $context);

            return $locked;
        }, 3);
    }

    public static function consumeRecoveryCode(
        Authenticatable $actor,
        string $input,
        ?int $expectedGeneration = null,
        array $context = []
    ): Vendor|VendorEmployee {
        $normalized = self::normalizeRecoveryCode($input);
        if ($normalized === '') {
            throw new \DomainException('merchant_2fa_invalid_code');
        }

        return DB::transaction(function () use ($actor, $normalized, $expectedGeneration, $context) {
            $locked = self::actor(self::actorType($actor), (int) $actor->getAuthIdentifier(), true);
            if (! $locked || ($expectedGeneration !== null && (int) $locked->auth_generation !== $expectedGeneration)) {
                throw new \DomainException('merchant_2fa_challenge_expired');
            }

            $matched = false;
            foreach ($locked->two_factor_recovery_codes ?: [] as $hash) {
                if (Hash::check($normalized, $hash)) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                throw new \DomainException('merchant_2fa_invalid_code');
            }

            self::resetTwoFactorState($locked);
            self::event($locked, 'recovery_code_consumed', $context);

            return $locked;
        }, 3);
    }

    public static function verifySensitiveStepUp(
        Vendor|VendorEmployee $actor,
        string $password,
        ?string $code,
        array $context = []
    ): Vendor|VendorEmployee {
        return DB::transaction(function () use ($actor, $password, $code, $context) {
            $locked = self::actor(self::actorType($actor), (int) $actor->getAuthIdentifier(), true);
            if (! $locked || ! Hash::check($password, $locked->password)) {
                throw new \DomainException('merchant_2fa_step_up_failed');
            }

            if ($locked->two_factor_enabled) {
                try {
                    self::acceptTotp($locked, (string) $code);
                } catch (\DomainException) {
                    throw new \DomainException('merchant_2fa_step_up_failed');
                }
            }

            self::event($locked, 'sensitive_step_up_passed', $context);

            return $locked;
        }, 3);
    }

    public static function regenerateRecoveryCodes(
        Vendor|VendorEmployee $actor,
        string $password,
        string $code,
        array $context = []
    ): array {
        return DB::transaction(function () use ($actor, $password, $code, $context): array {
            $locked = self::actor(self::actorType($actor), (int) $actor->getAuthIdentifier(), true);
            if (! $locked || ! Hash::check($password, $locked->password)) {
                throw new \DomainException('merchant_2fa_step_up_failed');
            }

            try {
                self::acceptTotp($locked, $code);
            } catch (\DomainException) {
                throw new \DomainException('merchant_2fa_step_up_failed');
            }

            [$plain, $hashed] = self::recoveryCodes();
            $locked->forceFill([
                'two_factor_recovery_codes' => $hashed,
                'auth_generation' => (int) $locked->auth_generation + 1,
                'auth_token' => null,
                'remember_token' => null,
            ])->save();
            self::event($locked, 'recovery_codes_regenerated', $context);

            return ['actor' => $locked, 'recovery_codes' => $plain];
        }, 3);
    }

    public static function revokeActor(
        Authenticatable $actor,
        string $eventType,
        array $context = [],
        bool $resetTwoFactor = false
    ): Vendor|VendorEmployee {
        return DB::transaction(function () use ($actor, $eventType, $context, $resetTwoFactor) {
            $locked = self::actor(self::actorType($actor), (int) $actor->getAuthIdentifier(), true);
            if (! $locked) {
                throw new \DomainException('merchant_2fa_actor_not_found');
            }

            if ($resetTwoFactor) {
                self::resetTwoFactorState($locked);
            } else {
                $locked->forceFill([
                    'auth_generation' => (int) $locked->auth_generation + 1,
                    'auth_token' => null,
                    'remember_token' => null,
                ])->save();
            }
            self::event($locked, $eventType, $context);

            return $locked;
        }, 3);
    }

    public static function revokeVendorFamily(Vendor $vendor, string $eventType, array $context = []): void
    {
        DB::transaction(function () use ($vendor, $eventType, $context): void {
            $owner = Vendor::query()->lockForUpdate()->find($vendor->id);
            if ($owner) {
                $owner->forceFill([
                    'auth_generation' => (int) $owner->auth_generation + 1,
                    'auth_token' => null,
                    'remember_token' => null,
                ])->save();
                self::event($owner, $eventType, $context);
            }

            VendorEmployee::query()
                ->where('vendor_id', $vendor->id)
                ->lockForUpdate()
                ->get()
                ->each(function (VendorEmployee $employee) use ($eventType, $context): void {
                    $employee->forceFill([
                        'auth_generation' => (int) $employee->auth_generation + 1,
                        'auth_token' => null,
                        'remember_token' => null,
                    ])->save();
                    self::event($employee, $eventType, $context);
                });
        }, 3);
    }

    public static function startAppChallenge(Authenticatable $actor, ?string $ip = null): array
    {
        return DB::transaction(function () use ($actor, $ip): array {
            $locked = self::actor(self::actorType($actor), (int) $actor->getAuthIdentifier(), true);
            if (! $locked) {
                throw new \DomainException('merchant_2fa_actor_not_found');
            }

            $state = self::state($locked);
            if ($state === self::STATE_OPTIONAL) {
                throw new \DomainException('merchant_2fa_challenge_not_required');
            }

            $challengeQuery = MerchantTwoFactorChallenge::query()
                ->where('actor_type', self::actorType($locked))
                ->where('actor_id', $locked->getAuthIdentifier());
            (clone $challengeQuery)
                ->where(function ($query): void {
                    $query->whereNotNull('consumed_at')->orWhere('expires_at', '<=', now());
                })
                ->delete();
            (clone $challengeQuery)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            $plainToken = Str::random(64);
            $secret = $state === self::STATE_ENROLLMENT ? NezhaTotp::generateSecret() : null;
            $challenge = MerchantTwoFactorChallenge::query()->create([
                'token_hash' => hash('sha256', $plainToken),
                'actor_type' => self::actorType($locked),
                'actor_id' => $locked->getAuthIdentifier(),
                'purpose' => $state === self::STATE_ENROLLMENT ? 'enroll' : 'challenge',
                'pending_secret' => $secret,
                'auth_generation' => (int) $locked->auth_generation,
                'ip_hash' => self::requestHash($ip),
                'expires_at' => now()->addMinutes(self::CHALLENGE_TTL_MINUTES),
            ]);

            self::event($locked, 'app_challenge_started', ['ip' => $ip, 'metadata' => [
                'purpose' => $challenge->purpose,
            ]]);

            return [
                'challenge_token' => $plainToken,
                'purpose' => $challenge->purpose,
                'expires_at' => $challenge->expires_at,
                'secret' => $secret,
                'otpauth_uri' => $secret
                    ? NezhaTotp::otpauthUri($secret, self::label($locked), 'Nezha Merchant')
                    : null,
            ];
        }, 3);
    }

    public static function challengeAccountRateKey(string $plainToken): ?string
    {
        $challenge = MerchantTwoFactorChallenge::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->first(['actor_type', 'actor_id']);

        if (! $challenge) {
            return null;
        }

        return 'merchant-app-2fa:account:'.hash(
            'sha256',
            $challenge->actor_type.':'.$challenge->actor_id
        );
    }

    public static function completeAppChallenge(string $plainToken, string $code, ?string $ip = null): array
    {
        $result = DB::transaction(function () use ($plainToken, $code, $ip): array {
            $challenge = MerchantTwoFactorChallenge::query()
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if (! $challenge || $challenge->consumed_at || $challenge->expires_at->isPast()
                || $challenge->attempts >= self::MAX_CHALLENGE_ATTEMPTS) {
                return ['status' => 'invalid'];
            }

            $actor = self::actor($challenge->actor_type, (int) $challenge->actor_id, true);
            if (! $actor || (int) $actor->auth_generation !== (int) $challenge->auth_generation) {
                $challenge->forceFill(['consumed_at' => now()])->save();

                return ['status' => 'invalid'];
            }

            if ($challenge->purpose === 'enroll') {
                $counter = NezhaTotp::matchingCounter((string) $challenge->pending_secret, $code);
                if ($counter === null) {
                    $challenge->increment('attempts');

                    return ['status' => 'invalid'];
                }

                [$plain, $hashed] = self::recoveryCodes();
                $actor->forceFill([
                    'two_factor_secret' => $challenge->pending_secret,
                    'two_factor_enabled' => true,
                    'two_factor_recovery_codes' => $hashed,
                    'two_factor_required_at' => null,
                    'two_factor_enrolled_at' => now(),
                    'two_factor_last_counter' => $counter,
                    'two_factor_grace_pending' => false,
                    'auth_generation' => (int) $actor->auth_generation + 1,
                    'auth_token' => null,
                    'remember_token' => null,
                ])->save();
                $challenge->forceFill(['consumed_at' => now()])->save();
                self::event($actor, 'enrolled', ['ip' => $ip, 'metadata' => ['channel' => 'app']]);

                return ['status' => 'authenticated', 'actor' => $actor, 'recovery_codes' => $plain];
            }

            try {
                self::acceptTotp($actor, $code);
                $challenge->forceFill(['consumed_at' => now()])->save();
                self::event($actor, 'challenge_passed', ['ip' => $ip, 'metadata' => ['channel' => 'app']]);

                return ['status' => 'authenticated', 'actor' => $actor, 'recovery_codes' => null];
            } catch (\DomainException) {
                if (self::matchesRecoveryCode($actor, $code)) {
                    self::resetTwoFactorState($actor);
                    $challenge->forceFill(['consumed_at' => now()])->save();
                    self::event($actor, 'recovery_code_consumed', ['ip' => $ip, 'metadata' => ['channel' => 'app']]);

                    return ['status' => 'authenticated', 'actor' => $actor, 'recovery_codes' => null];
                }
            }

            $challenge->increment('attempts');

            return ['status' => 'invalid'];
        }, 3);

        if ($result['status'] === 'invalid') {
            throw new \DomainException('merchant_2fa_invalid_code');
        }

        return $result;
    }

    public static function scheduleLegacyGrace(Carbon $deadline): array
    {
        throw new \LogicException('Merchant two-factor authentication is voluntary; enforcement scheduling is disabled.');
    }

    public static function requestHash(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private static function acceptTotp(Vendor|VendorEmployee $actor, string $code): void
    {
        if (! $actor->two_factor_enabled || ! $actor->two_factor_secret) {
            throw new \DomainException('merchant_2fa_enrollment_required');
        }

        $counter = NezhaTotp::matchingCounter((string) $actor->two_factor_secret, $code);
        if ($counter === null || ($actor->two_factor_last_counter !== null
            && $counter <= (int) $actor->two_factor_last_counter)) {
            throw new \DomainException('merchant_2fa_invalid_code');
        }

        $actor->forceFill(['two_factor_last_counter' => $counter])->save();
    }

    private static function matchesRecoveryCode(Vendor|VendorEmployee $actor, string $input): bool
    {
        $normalized = self::normalizeRecoveryCode($input);
        if ($normalized === '') {
            return false;
        }

        foreach ($actor->two_factor_recovery_codes ?: [] as $hash) {
            if (Hash::check($normalized, $hash)) {
                return true;
            }
        }

        return false;
    }

    private static function resetTwoFactorState(Vendor|VendorEmployee $actor): void
    {
        $actor->forceFill([
            'two_factor_secret' => null,
            'two_factor_enabled' => false,
            'two_factor_recovery_codes' => null,
            'two_factor_required_at' => null,
            'two_factor_enrolled_at' => null,
            'two_factor_last_counter' => null,
            'two_factor_grace_pending' => false,
            'auth_generation' => (int) $actor->auth_generation + 1,
            'auth_token' => null,
            'remember_token' => null,
        ])->save();
    }

    private static function recoveryCodes(): array
    {
        $plain = [];
        $hashed = [];
        for ($i = 0; $i < 8; $i++) {
            $normalized = strtoupper(bin2hex(random_bytes(5)));
            $plain[] = substr($normalized, 0, 5).'-'.substr($normalized, 5, 5);
            $hashed[] = Hash::make($normalized);
        }

        return [$plain, $hashed];
    }

    private static function normalizeRecoveryCode(string $input): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
    }

    private static function event(Authenticatable $actor, string $eventType, array $context = []): void
    {
        MerchantTwoFactorEvent::query()->create([
            'actor_type' => self::actorType($actor),
            'actor_id' => $actor->getAuthIdentifier(),
            'event_type' => $eventType,
            'auth_generation' => (int) $actor->auth_generation,
            'initiator_type' => $context['initiator_type'] ?? null,
            'initiator_id' => $context['initiator_id'] ?? null,
            'approver_one_id' => $context['approver_one_id'] ?? null,
            'approver_two_id' => $context['approver_two_id'] ?? null,
            'reason' => isset($context['reason']) ? mb_substr((string) $context['reason'], 0, 500) : null,
            'ip_hash' => self::requestHash($context['ip'] ?? null),
            'user_agent_hash' => self::requestHash($context['user_agent'] ?? null),
            'metadata' => array_intersect_key((array) ($context['metadata'] ?? []), array_flip([
                'channel',
                'purpose',
                'route',
            ])),
        ]);
    }

    private static function label(Authenticatable $actor): string
    {
        return $actor->email ?: self::actorType($actor).'-'.$actor->getAuthIdentifier();
    }
}
