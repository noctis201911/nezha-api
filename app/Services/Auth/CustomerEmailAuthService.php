<?php

namespace App\Services\Auth;

use App\CentralLogics\Helpers;
use App\Exceptions\CustomerLoginException;
use App\Mail\EmailVerification;
use App\Models\CustomerAuthConsent;
use App\Models\CustomerEmailAuthChallenge;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class CustomerEmailAuthService
{
    private const PURPOSE = 'unified_auth';

    public function __construct(
        private readonly CustomerAuthFeatureFlags $flags,
        private readonly CustomerLoginFinalizer $loginFinalizer,
        private readonly EmailCanonicalizer $canonicalizer,
    ) {}

    public function start(string $email): array
    {
        $this->assertEmailLoginAvailable();
        if (! $this->flags->emailDeliveryEnabled()) {
            throw new CustomerLoginException(
                'email_delivery_unavailable',
                'Email verification is temporarily unavailable.',
                503,
            );
        }

        try {
            $canonical = $this->canonicalizer->canonicalize($email);
        } catch (InvalidArgumentException) {
            throw new CustomerLoginException('email_invalid', 'Please enter a valid email address.', 422);
        }
        $lookupHash = $this->hashSubject($canonical);
        if (CustomerEmailAuthChallenge::query()
            ->where('email_lookup_hash', $lookupHash)
            ->where('created_at', '>=', now()->subHour())
            ->count() >= 5) {
            throw new CustomerLoginException(
                'email_auth_rate_limited',
                'Please wait before requesting another code.',
                429,
            );
        }

        $publicId = $this->randomToken(32);
        $browserSecret = $this->randomToken(32);
        $otp = (string) random_int(100000, 999999);
        $ttl = max(300, min(900, (int) config('customer_auth.challenge_ttl_seconds', 600)));
        $resendSeconds = max(30, min(300, (int) config('customer_auth.challenge_resend_seconds', 60)));
        $attempts = max(3, min(10, (int) config('customer_auth.challenge_max_attempts', 5)));

        $challenge = $this->createActiveChallenge(
            $publicId,
            $browserSecret,
            $otp,
            $canonical,
            $lookupHash,
            $ttl,
            $resendSeconds,
            $attempts,
        );

        try {
            Mail::to($canonical)->send(new EmailVerification($otp, '用户'));
        } catch (Throwable $error) {
            CustomerEmailAuthChallenge::query()
                ->whereKey($challenge->id)
                ->where('status', 'pending_delivery')
                ->update([
                    'status' => 'delivery_failed',
                    'active_email_hash' => null,
                    'updated_at' => now(),
                ]);

            throw new CustomerLoginException(
                'email_delivery_failed',
                'The verification email could not be sent. Please try another method.',
                503,
            );
        }

        CustomerEmailAuthChallenge::query()
            ->whereKey($challenge->id)
            ->where('status', 'pending_delivery')
            ->update([
                'status' => 'code_sent',
                'delivery_succeeded_at' => now(),
                'updated_at' => now(),
            ]);

        return [
            'status' => 'code_sent',
            'challenge_id' => $publicId,
            'browser_secret' => $browserSecret,
            'expires_in' => $ttl,
            'resend_after' => $resendSeconds,
        ];
    }

    public function verify(string $publicId, string $browserSecret, string $otp): array
    {
        $this->assertEmailLoginAvailable();
        $completionToken = $this->randomToken(32);

        $result = DB::transaction(function () use ($publicId, $browserSecret, $otp, $completionToken) {
            $challenge = CustomerEmailAuthChallenge::query()
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->first();
            $this->assertVerifiable($challenge, $browserSecret);

            if (! hash_equals((string) $challenge->otp_hash, $this->hashOtp($publicId, $otp))) {
                $remaining = max(0, (int) $challenge->attempts_remaining - 1);
                $challenge->forceFill([
                    'attempts_remaining' => $remaining,
                    'status' => $remaining > 0 ? 'code_sent' : 'locked',
                    'active_email_hash' => $remaining > 0 ? $challenge->active_email_hash : null,
                ])->save();

                return [
                    '__error' => [
                        'code' => $remaining > 0 ? 'email_auth_code_invalid' : 'email_auth_locked',
                        'message' => $remaining > 0
                            ? 'The verification code is incorrect.'
                            : 'Too many incorrect attempts.',
                    ],
                ];
            }

            $canonical = (string) $challenge->email_ciphertext;
            $challenge->forceFill([
                'verified_at' => now(),
                'active_email_hash' => null,
                'completion_token_hash' => $this->hashToken($completionToken),
                'status' => 'verified',
            ])->save();

            $owner = User::query()
                ->without('storage')
                ->where('email_canonical', $canonical)
                ->lockForUpdate()
                ->first();
            if ($owner) {
                $challenge->forceFill([
                    'target_user_id' => $owner->id,
                    'status' => 'consumed',
                    'consumed_at' => now(),
                    'completion_token_hash' => null,
                ])->save();

                return $this->authenticatedPayload(
                    $owner,
                    $this->loginFinalizer->issue($owner, 'email_otp'),
                );
            }

            $legacyCandidates = $this->legacyEmailCandidates($canonical);
            if ($legacyCandidates->isNotEmpty()) {
                $legacy = $legacyCandidates->count() === 1
                    ? $legacyCandidates->first()
                    : null;
                $hasPassword = $legacy
                    && is_string($legacy->getRawOriginal('password'))
                    && $legacy->getRawOriginal('password') !== '';
                $challenge->forceFill([
                    'target_user_id' => $legacy?->id,
                    'status' => 'legacy_link_required',
                ])->save();

                return [
                    'status' => 'legacy_link_required',
                    'challenge_id' => $publicId,
                    'completion_token' => $completionToken,
                    'can_use_password' => $hasPassword,
                    'support_required' => ! $hasPassword,
                ];
            }

            if (! $this->registrationAvailable()) {
                $challenge->forceFill([
                    'status' => 'registration_unavailable',
                    'completion_token_hash' => null,
                    'consumed_at' => now(),
                ])->save();

                return ['status' => 'registration_unavailable'];
            }

            $challenge->forceFill(['status' => 'registration_ready'])->save();

            return [
                'status' => 'registration_required',
                'challenge_id' => $publicId,
                'completion_token' => $completionToken,
                'terms_version' => (string) config('customer_auth.terms_version'),
                'privacy_version' => (string) config('customer_auth.privacy_version'),
            ];
        }, 3);

        if (isset($result['__error'])) {
            throw new CustomerLoginException(
                $result['__error']['code'],
                $result['__error']['message'],
                403,
            );
        }

        return $result;
    }

    public function proveLegacyPassword(
        string $publicId,
        string $browserSecret,
        string $completionToken,
        string $password,
    ): array {
        return DB::transaction(function () use ($publicId, $browserSecret, $completionToken, $password) {
            $challenge = $this->lockedCompletionChallenge(
                $publicId,
                $browserSecret,
                $completionToken,
                'legacy_link_required',
            );
            $user = User::query()->without('storage')->lockForUpdate()->find($challenge->target_user_id);
            if (! $user || ! is_string($user->getRawOriginal('password'))
                || ! Hash::check($password, $user->getRawOriginal('password'))) {
                throw new CustomerLoginException(
                    'legacy_reauthentication_failed',
                    'The existing account password is incorrect.',
                );
            }

            $canonical = (string) $challenge->email_ciphertext;
            $conflict = User::query()
                ->without('storage')
                ->where('email_canonical', $canonical)
                ->where('id', '<>', $user->id)
                ->lockForUpdate()
                ->exists();
            if ($conflict) {
                throw new CustomerLoginException(
                    'identity_conflict',
                    'This email is already linked to another account.',
                    409,
                );
            }

            $this->revokeAllSessions($user);
            $user->email = $canonical;
            $user->email_canonical = $canonical;
            $user->email_verified_at = now();
            $user->email_verification_method = 'email_otp_password';
            $user->is_email_verified = 1;
            $user->remember_token = null;
            $user->save();

            $challenge->forceFill([
                'status' => 'consumed',
                'consumed_at' => now(),
                'completion_token_hash' => null,
            ])->save();

            return $this->authenticatedPayload(
                $user,
                $this->loginFinalizer->issue($user, 'email_otp'),
            );
        }, 3);
    }

    public function completeRegistration(
        string $publicId,
        string $browserSecret,
        string $completionToken,
        string $name,
        bool $termsAccepted,
        string $locale,
        ?string $refCode,
    ): array {
        if (! $termsAccepted || ! $this->registrationAvailable()) {
            throw new CustomerLoginException(
                'registration_unavailable',
                'Account creation requirements are not satisfied.',
                403,
            );
        }

        return DB::transaction(function () use (
            $publicId,
            $browserSecret,
            $completionToken,
            $name,
            $locale,
            $refCode,
        ) {
            $challenge = $this->lockedCompletionChallenge(
                $publicId,
                $browserSecret,
                $completionToken,
                'registration_ready',
            );
            $canonical = (string) $challenge->email_ciphertext;

            if (User::query()->without('storage')->where('email_canonical', $canonical)->lockForUpdate()->exists()
                || $this->legacyEmailCandidates($canonical)->isNotEmpty()) {
                throw new CustomerLoginException(
                    'identity_conflict',
                    'This email now requires account recovery.',
                    409,
                );
            }

            [$firstName, $lastName] = $this->splitName($name);
            $referrer = null;
            if (is_string($refCode) && trim($refCode) !== '') {
                $referrer = User::query()
                    ->without('storage')
                    ->where('ref_code', trim($refCode))
                    ->where('status', 1)
                    ->lockForUpdate()
                    ->first();
                if (! $referrer) {
                    throw new CustomerLoginException(
                        'referral_code_invalid',
                        'The referral code is invalid.',
                        403,
                    );
                }
            }

            $user = new User;
            $user->f_name = $firstName;
            $user->l_name = $lastName;
            $user->email = $canonical;
            $user->email_canonical = $canonical;
            $user->email_verified_at = now();
            $user->email_verification_method = 'email_otp';
            $user->is_email_verified = 1;
            $user->phone = null;
            $user->is_phone_verified = 0;
            $user->password = null;
            $user->login_medium = 'email_otp';
            $user->ref_by = $referrer?->id;
            try {
                $user->save();
            } catch (QueryException $error) {
                if ($this->isDuplicateKey($error)) {
                    throw new CustomerLoginException(
                        'identity_conflict',
                        'This email now requires account recovery.',
                        409,
                    );
                }

                throw $error;
            }
            $user->ref_code = Helpers::generate_referer_code($user);
            $user->save();

            CustomerAuthConsent::query()->create([
                'user_id' => $user->id,
                'action' => 'account_created',
                'terms_version' => (string) config('customer_auth.terms_version'),
                'privacy_version' => (string) config('customer_auth.privacy_version'),
                'locale' => Str::limit($locale ?: 'zh-CN', 16, ''),
                'channel' => 'customer_h5',
                'auth_method' => 'email_otp',
                'accepted_at' => now(),
            ]);

            $challenge->forceFill([
                'target_user_id' => $user->id,
                'status' => 'consumed',
                'consumed_at' => now(),
                'completion_token_hash' => null,
            ])->save();

            return $this->authenticatedPayload(
                $user,
                $this->loginFinalizer->issue($user, 'email_otp'),
            );
        }, 3);
    }

    private function createActiveChallenge(
        string $publicId,
        string $browserSecret,
        string $otp,
        string $canonical,
        string $lookupHash,
        int $ttl,
        int $resendSeconds,
        int $attempts,
    ): CustomerEmailAuthChallenge {
        for ($try = 1; $try <= 3; $try++) {
            try {
                return DB::transaction(function () use (
                    $publicId,
                    $browserSecret,
                    $otp,
                    $canonical,
                    $lookupHash,
                    $ttl,
                    $resendSeconds,
                    $attempts,
                ) {
                    $active = CustomerEmailAuthChallenge::query()
                        ->where('active_email_hash', $lookupHash)
                        ->lockForUpdate()
                        ->first();
                    if ($active && $active->resend_after->isFuture()) {
                        throw new CustomerLoginException(
                            'email_auth_rate_limited',
                            'Please wait before requesting another code.',
                            429,
                        );
                    }
                    if ($active) {
                        $active->forceFill([
                            'active_email_hash' => null,
                            'status' => 'superseded',
                        ])->save();
                    }

                    return CustomerEmailAuthChallenge::query()->create([
                        'public_id' => $publicId,
                        'purpose' => self::PURPOSE,
                        'email_ciphertext' => $canonical,
                        'email_lookup_hash' => $lookupHash,
                        'active_email_hash' => $lookupHash,
                        'otp_hash' => $this->hashOtp($publicId, $otp),
                        'browser_secret_hash' => $this->hashToken($browserSecret),
                        'status' => 'pending_delivery',
                        'attempts_remaining' => $attempts,
                        'generation' => ($active?->generation ?? 0) + 1,
                        'expires_at' => now()->addSeconds($ttl),
                        'resend_after' => now()->addSeconds($resendSeconds),
                    ]);
                }, 3);
            } catch (QueryException $error) {
                if ($try === 3 || ! $this->isDuplicateKey($error)) {
                    throw $error;
                }
            }
        }

        throw new CustomerLoginException('email_auth_conflict', 'Please request a new code.', 409);
    }

    private function assertVerifiable(?CustomerEmailAuthChallenge $challenge, string $browserSecret): void
    {
        if (! $challenge
            || $challenge->purpose !== self::PURPOSE
            || $challenge->status !== 'code_sent'
            || $challenge->expires_at->isPast()
            || $challenge->consumed_at !== null
            || $challenge->attempts_remaining < 1
            || ! hash_equals((string) $challenge->browser_secret_hash, $this->hashToken($browserSecret))) {
            throw new CustomerLoginException(
                'email_auth_expired',
                'This verification attempt has expired. Please request a new code.',
                403,
            );
        }
    }

    private function lockedCompletionChallenge(
        string $publicId,
        string $browserSecret,
        string $completionToken,
        string $status,
    ): CustomerEmailAuthChallenge {
        $challenge = CustomerEmailAuthChallenge::query()
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first();
        if (! $challenge
            || $challenge->status !== $status
            || $challenge->expires_at->isPast()
            || $challenge->consumed_at !== null
            || ! hash_equals((string) $challenge->browser_secret_hash, $this->hashToken($browserSecret))
            || ! hash_equals((string) $challenge->completion_token_hash, $this->hashToken($completionToken))) {
            throw new CustomerLoginException(
                'email_auth_expired',
                'This verification attempt has expired. Please start again.',
                403,
            );
        }

        return $challenge;
    }

    private function legacyEmailCandidates(string $canonical)
    {
        return User::query()
            ->without('storage')
            ->whereNull('email_canonical')
            ->whereRaw('LOWER(TRIM(email)) = ?', [$canonical])
            ->orderBy('id')
            ->limit(2)
            ->lockForUpdate()
            ->get();
    }

    private function registrationAvailable(): bool
    {
        return $this->flags->emailRegistrationEnabled()
            && is_string(config('customer_auth.terms_version'))
            && trim((string) config('customer_auth.terms_version')) !== ''
            && is_string(config('customer_auth.privacy_version'))
            && trim((string) config('customer_auth.privacy_version')) !== '';
    }

    private function assertEmailLoginAvailable(): void
    {
        if (! $this->flags->emailLoginEnabled()) {
            throw new CustomerLoginException(
                'email_auth_unavailable',
                'Email verification login is not available yet.',
                403,
            );
        }
    }

    private function revokeAllSessions(User $user): void
    {
        if (Schema::hasTable('oauth_access_tokens')) {
            $accessIds = DB::table('oauth_access_tokens')
                ->where('user_id', $user->id)
                ->pluck('id');
            if ($accessIds->isNotEmpty() && Schema::hasTable('oauth_refresh_tokens')) {
                DB::table('oauth_refresh_tokens')
                    ->whereIn('access_token_id', $accessIds)
                    ->update(['revoked' => 1]);
            }
            DB::table('oauth_access_tokens')
                ->where('user_id', $user->id)
                ->update(['revoked' => 1]);
        }
        if (Schema::hasTable('sessions') && Schema::hasColumn('sessions', 'user_id')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('external_identity_login_attempts')) {
            DB::table('external_identity_login_attempts')
                ->where('target_user_id', $user->id)
                ->whereNull('consumed_at')
                ->update([
                    'status' => 'expired',
                    'provider_payload' => null,
                    'consumed_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    private function authenticatedPayload(User $user, string $token): array
    {
        return [
            'status' => 'authenticated',
            'token' => $token,
            'is_personal_info' => $user->f_name ? 1 : 0,
            'login_type' => 'email_otp',
        ];
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2);
        $first = Str::limit((string) ($parts[0] ?? ''), 100, '');
        if ($first === '') {
            throw new CustomerLoginException('name_required', 'Please enter your name.', 422);
        }

        return [$first, Str::limit((string) ($parts[1] ?? ''), 100, '')];
    }

    private function hashSubject(string $canonical): string
    {
        return hash_hmac('sha256', 'email|'.$canonical, $this->hmacKey());
    }

    private function hashOtp(string $publicId, string $otp): string
    {
        return hash_hmac('sha256', 'otp|'.$publicId.'|'.$otp, $this->hmacKey());
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', 'token|'.$token, $this->hmacKey());
    }

    private function hmacKey(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new CustomerLoginException('auth_configuration_error', 'Authentication is unavailable.', 503);
        }

        return $key;
    }

    private function randomToken(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function isDuplicateKey(QueryException $error): bool
    {
        return in_array((string) ($error->errorInfo[0] ?? ''), ['23000', '23505'], true);
    }
}
