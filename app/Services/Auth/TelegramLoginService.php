<?php

namespace App\Services\Auth;

use App\CentralLogics\Helpers;
use App\Exceptions\TelegramLoginException;
use App\Models\ExternalIdentityLoginAttempt;
use App\Models\User;
use App\Models\UserExternalIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramLoginService
{
    private const PROVIDER = 'telegram';

    public function __construct(
        private readonly TelegramOidcClient $oidc,
        private readonly GoogleTokenVerifier $googleVerifier,
        private readonly CustomerAccessTokenIssuer $tokenIssuer,
    ) {}

    public function isAvailable(): bool
    {
        return $this->oidc->isConfigured();
    }

    public function begin(): array
    {
        $this->oidc->assertConfigured();
        $this->pruneExpiredAttempts();

        $state = $this->randomToken(48);
        $nonce = $this->randomToken(32);
        $codeVerifier = $this->randomToken(64);
        $browserSecret = $this->randomToken(48);
        $ttl = max(120, min(1800, (int) config('telegram_login.attempt_ttl_seconds', 600)));

        ExternalIdentityLoginAttempt::create([
            'provider' => self::PROVIDER,
            'state_hash' => $this->hashToken($state),
            'browser_secret_hash' => $this->hashToken($browserSecret),
            'oidc_nonce' => $nonce,
            'code_verifier' => $codeVerifier,
            'status' => 'initiated',
            'expires_at' => now()->addSeconds($ttl),
        ]);

        return [
            'authorization_url' => $this->oidc->authorizationUrl(
                $state,
                $nonce,
                $this->base64Url(hash('sha256', $codeVerifier, true)),
            ),
            'browser_secret' => $browserSecret,
            'expires_in' => $ttl,
        ];
    }

    public function completeCallback(string $state, string $authorizationCode): string
    {
        $attempt = ExternalIdentityLoginAttempt::query()
            ->where('provider', self::PROVIDER)
            ->where('state_hash', $this->hashToken($state))
            ->first();

        $this->assertInitiatedAttempt($attempt);
        $claims = $this->oidc->exchangeAuthorizationCode(
            $authorizationCode,
            (string) $attempt->code_verifier,
            (string) $attempt->oidc_nonce,
        );

        $subject = (string) $claims['sub'];
        $phone = $this->verifiedPhone($claims);
        $exchangeCode = $this->randomToken(48);

        DB::transaction(function () use ($attempt, $claims, $subject, $phone, $exchangeCode) {
            $locked = ExternalIdentityLoginAttempt::query()->lockForUpdate()->find($attempt->id);
            $this->assertInitiatedAttempt($locked);

            $identity = UserExternalIdentity::query()
                ->where('provider', self::PROVIDER)
                ->where('provider_subject', $subject)
                ->lockForUpdate()
                ->first();

            $targetUserId = null;
            $status = 'registration_ready';
            $payload = [
                'phone' => $phone,
                'given_name' => $this->profileValue($claims['given_name'] ?? null),
                'family_name' => $this->profileValue($claims['family_name'] ?? null),
                'name' => $this->profileValue($claims['name'] ?? null),
            ];

            if ($identity) {
                $targetUserId = $identity->user_id;
                $status = 'login_ready';
                $payload = null;

                $linkedUser = $this->lockedUser($identity->user_id);
                $linkedPhone = $linkedUser?->getRawOriginal('phone');
                if ($linkedUser && $linkedPhone && $linkedPhone !== $phone) {
                    Log::warning('Telegram login phone differs from the established identity owner.', [
                        'provider' => self::PROVIDER,
                        'user_id' => $linkedUser->id,
                    ]);
                }
            } else {
                $phoneOwner = User::query()
                    ->without('storage')
                    ->where('phone', $phone)
                    ->lockForUpdate()
                    ->first();
                if ($phoneOwner) {
                    $targetUserId = $phoneOwner->id;
                    $status = 'link_required';
                    $payload = null;
                }
            }

            $locked->forceFill([
                'exchange_code_hash' => $this->hashToken($exchangeCode),
                'provider_subject' => $subject,
                'provider_payload' => $payload,
                'target_user_id' => $targetUserId,
                'status' => $status,
                'oidc_nonce' => null,
                'code_verifier' => null,
            ])->save();
        }, 3);

        return $exchangeCode;
    }

    public function exchange(string $exchangeCode, string $browserSecret): array
    {
        return DB::transaction(function () use ($exchangeCode, $browserSecret) {
            $attempt = $this->lockedExchangeAttempt($exchangeCode, $browserSecret);

            if ($attempt->status === 'link_required') {
                return $this->linkRequiredPayload($attempt);
            }

            if ($attempt->status === 'login_ready') {
                $user = $this->activeLockedUser((int) $attempt->target_user_id);
                $identity = UserExternalIdentity::query()
                    ->where('provider', self::PROVIDER)
                    ->where('provider_subject', $attempt->provider_subject)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $identity) {
                    throw new TelegramLoginException(
                        'telegram_identity_conflict',
                        'Telegram identity ownership changed. Please contact support.',
                        409,
                    );
                }

                $identity->forceFill(['last_login_at' => now()])->save();

                return $this->finishLogin($attempt, $user);
            }

            if ($attempt->status !== 'registration_ready') {
                throw $this->expiredAttempt();
            }

            $identity = UserExternalIdentity::query()
                ->where('provider', self::PROVIDER)
                ->where('provider_subject', $attempt->provider_subject)
                ->lockForUpdate()
                ->first();
            if ($identity) {
                $user = $this->activeLockedUser((int) $identity->user_id);
                $identity->forceFill(['last_login_at' => now()])->save();
                $attempt->forceFill([
                    'target_user_id' => $user->id,
                    'status' => 'login_ready',
                    'provider_payload' => null,
                ])->save();

                return $this->finishLogin($attempt, $user);
            }

            $payload = $attempt->provider_payload;
            $phone = is_array($payload) ? ($payload['phone'] ?? null) : null;
            if (! is_string($phone) || ! $this->isE164($phone)) {
                throw new TelegramLoginException(
                    'telegram_phone_required',
                    'A Telegram-verified phone number is required.',
                    403,
                );
            }

            $phoneOwner = User::query()
                ->without('storage')
                ->where('phone', $phone)
                ->lockForUpdate()
                ->first();
            if ($phoneOwner) {
                $attempt->forceFill([
                    'target_user_id' => $phoneOwner->id,
                    'status' => 'link_required',
                    'provider_payload' => null,
                ])->save();

                return $this->linkRequiredPayload($attempt, $phoneOwner);
            }

            if (! (bool) config('telegram_login.allow_new_accounts', false)) {
                return [
                    'status' => 'registration_unavailable',
                    'message' => 'Telegram registration is not available yet. Please use email or Google.',
                ];
            }

            $user = new User;
            $user->f_name = $this->firstName($payload);
            $user->l_name = $this->lastName($payload);
            $user->phone = $phone;
            $user->is_phone_verified = 1;
            $user->login_medium = self::PROVIDER;
            $user->password = null;
            $user->save();
            $user->ref_code = Helpers::generate_referer_code();
            $user->save();

            UserExternalIdentity::create([
                'user_id' => $user->id,
                'provider' => self::PROVIDER,
                'provider_subject' => $attempt->provider_subject,
                'last_login_at' => now(),
            ]);

            $attempt->forceFill([
                'target_user_id' => $user->id,
                'provider_payload' => null,
            ])->save();

            return $this->finishLogin($attempt, $user);
        }, 3);
    }

    public function linkWithPassword(
        string $exchangeCode,
        string $browserSecret,
        string $email,
        string $password,
    ): array {
        return DB::transaction(function () use ($exchangeCode, $browserSecret, $email, $password) {
            $attempt = $this->lockedLinkAttempt($exchangeCode, $browserSecret);
            $user = $this->activeLockedUser((int) $attempt->target_user_id);

            if (
                ! is_string($user->getRawOriginal('email'))
                || ! hash_equals(
                    mb_strtolower(trim($user->getRawOriginal('email'))),
                    mb_strtolower(trim($email)),
                )
                || ! is_string($user->getRawOriginal('password'))
                || $user->getRawOriginal('password') === ''
                || ! Hash::check($password, $user->getRawOriginal('password'))
            ) {
                throw new TelegramLoginException(
                    'telegram_reauthentication_failed',
                    'The email or password does not match the existing account.',
                    403,
                );
            }

            return $this->bindAndLogin($attempt, $user);
        }, 3);
    }

    public function linkWithGoogle(
        string $exchangeCode,
        string $browserSecret,
        string $googleCredential,
    ): array {
        $googleProfile = $this->googleVerifier->verify($googleCredential);

        return DB::transaction(function () use ($exchangeCode, $browserSecret, $googleProfile) {
            $attempt = $this->lockedLinkAttempt($exchangeCode, $browserSecret);
            $user = $this->activeLockedUser((int) $attempt->target_user_id);

            if (
                ! is_string($user->getRawOriginal('email'))
                || ! hash_equals(
                    mb_strtolower(trim($user->getRawOriginal('email'))),
                    mb_strtolower(trim((string) $googleProfile['email'])),
                )
            ) {
                throw new TelegramLoginException(
                    'telegram_reauthentication_failed',
                    'Google verification did not match the existing account.',
                    403,
                );
            }

            return $this->bindAndLogin($attempt, $user);
        }, 3);
    }

    private function bindAndLogin(ExternalIdentityLoginAttempt $attempt, User $user): array
    {
        $subjectOwner = UserExternalIdentity::query()
            ->where('provider', self::PROVIDER)
            ->where('provider_subject', $attempt->provider_subject)
            ->lockForUpdate()
            ->first();
        if ($subjectOwner && (int) $subjectOwner->user_id !== (int) $user->id) {
            throw new TelegramLoginException(
                'telegram_identity_conflict',
                'This Telegram identity already belongs to another account.',
                409,
            );
        }

        $userIdentity = UserExternalIdentity::query()
            ->where('provider', self::PROVIDER)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
        if ($userIdentity && ! hash_equals($userIdentity->provider_subject, (string) $attempt->provider_subject)) {
            throw new TelegramLoginException(
                'telegram_identity_replacement_required',
                'This account already has a different Telegram login identity.',
                409,
            );
        }

        $identity = $subjectOwner ?: $userIdentity;
        if (! $identity) {
            $identity = UserExternalIdentity::create([
                'user_id' => $user->id,
                'provider' => self::PROVIDER,
                'provider_subject' => $attempt->provider_subject,
            ]);
        }
        $identity->forceFill(['last_login_at' => now()])->save();

        return $this->finishLogin($attempt, $user);
    }

    private function finishLogin(ExternalIdentityLoginAttempt $attempt, User $user): array
    {
        $token = $this->tokenIssuer->issue($user);
        $attempt->forceFill([
            'status' => 'consumed',
            'consumed_at' => now(),
            'provider_payload' => null,
        ])->save();

        return [
            'status' => 'authenticated',
            'token' => $token,
            'is_personal_info' => 1,
            'login_type' => self::PROVIDER,
        ];
    }

    private function linkRequiredPayload(
        ExternalIdentityLoginAttempt $attempt,
        ?User $user = null,
    ): array {
        $user ??= $this->activeLockedUser((int) $attempt->target_user_id);
        $rawEmail = $user->getRawOriginal('email');
        $rawPassword = $user->getRawOriginal('password');
        $hasEmail = is_string($rawEmail) && trim($rawEmail) !== '';
        $hasPassword = is_string($rawPassword) && $rawPassword !== '';

        return [
            'status' => 'link_required',
            'can_use_email' => $hasEmail && $hasPassword,
            'can_use_google' => $hasEmail,
            'support_required' => ! $hasEmail,
        ];
    }

    private function lockedExchangeAttempt(
        string $exchangeCode,
        string $browserSecret,
    ): ExternalIdentityLoginAttempt {
        $attempt = ExternalIdentityLoginAttempt::query()
            ->where('provider', self::PROVIDER)
            ->where('exchange_code_hash', $this->hashToken($exchangeCode))
            ->lockForUpdate()
            ->first();

        if (
            ! $attempt
            || $attempt->expires_at->isPast()
            || $attempt->consumed_at !== null
            || ! hash_equals($attempt->browser_secret_hash, $this->hashToken($browserSecret))
        ) {
            throw $this->expiredAttempt();
        }

        return $attempt;
    }

    private function lockedLinkAttempt(
        string $exchangeCode,
        string $browserSecret,
    ): ExternalIdentityLoginAttempt {
        $attempt = $this->lockedExchangeAttempt($exchangeCode, $browserSecret);
        if ($attempt->status !== 'link_required' || ! $attempt->target_user_id || ! $attempt->provider_subject) {
            throw $this->expiredAttempt();
        }

        return $attempt;
    }

    private function assertInitiatedAttempt(?ExternalIdentityLoginAttempt $attempt): void
    {
        if (
            ! $attempt
            || $attempt->status !== 'initiated'
            || $attempt->expires_at->isPast()
            || $attempt->consumed_at !== null
        ) {
            throw $this->expiredAttempt();
        }
    }

    private function activeLockedUser(int $userId): User
    {
        $user = $this->lockedUser($userId);
        if (! $user) {
            throw new TelegramLoginException(
                'telegram_account_unavailable',
                'The existing account is unavailable.',
                409,
            );
        }
        if (! (bool) $user->status) {
            throw new TelegramLoginException(
                'telegram_account_blocked',
                'The existing account is blocked. Please contact support.',
                403,
            );
        }

        return $user;
    }

    private function lockedUser(int $userId): ?User
    {
        return User::query()->without('storage')->lockForUpdate()->find($userId);
    }

    private function verifiedPhone(array $claims): string
    {
        // Telegram documents phone_number as the verified number returned for
        // the requested `phone` scope; it does not require a separate
        // phone_number_verified claim. Keep the optional negative claim as a
        // fail-closed signal if Telegram ever sends it explicitly.
        $explicitlyUnverified = array_key_exists('phone_number_verified', $claims)
            && $claims['phone_number_verified'] === false;
        $phone = is_string($claims['phone_number'] ?? null)
            ? trim($claims['phone_number'])
            : null;
        if ($explicitlyUnverified || ! is_string($phone)) {
            throw new TelegramLoginException(
                'telegram_phone_required',
                'Please allow Telegram to share its verified phone number.',
                403,
            );
        }

        // Telegram's current OIDC example returns international digits without
        // a leading plus; the customer table stores canonical E.164 with it.
        if (preg_match('/^[1-9][0-9]{7,14}$/', $phone) === 1) {
            $phone = '+'.$phone;
        }
        if (! $this->isE164($phone)) {
            throw new TelegramLoginException(
                'telegram_phone_required',
                'Please allow Telegram to share its verified phone number.',
                403,
            );
        }

        return $phone;
    }

    private function isE164(string $phone): bool
    {
        return preg_match('/^\+[1-9][0-9]{7,14}$/', $phone) === 1;
    }

    private function firstName(?array $payload): string
    {
        $givenName = trim((string) ($payload['given_name'] ?? ''));
        if ($givenName !== '') {
            return Str::limit($givenName, 100, '');
        }

        $name = trim((string) ($payload['name'] ?? ''));

        return Str::limit($name !== '' ? $name : 'Telegram 用户', 100, '');
    }

    private function lastName(?array $payload): string
    {
        return Str::limit(trim((string) ($payload['family_name'] ?? '')), 100, '');
    }

    private function profileValue(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::limit(trim($value), 100, '');
    }

    private function randomToken(int $bytes): string
    {
        return $this->base64Url(random_bytes($bytes));
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function pruneExpiredAttempts(): void
    {
        ExternalIdentityLoginAttempt::query()
            ->where('expires_at', '<', now()->subDay())
            ->delete();
    }

    private function expiredAttempt(): TelegramLoginException
    {
        return new TelegramLoginException(
            'telegram_login_expired',
            'Telegram login request expired or was already used.',
            410,
        );
    }
}
