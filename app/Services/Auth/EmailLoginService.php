<?php

namespace App\Services\Auth;

use App\CentralLogics\Helpers;
use App\Exceptions\EmailLoginException;
use App\Mail\CustomerEmailVerificationCode;
use App\Models\BusinessSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

class EmailLoginService
{
    public function __construct(private readonly CustomerAccessTokenIssuer $tokenIssuer) {}

    public function isEnabled(): bool
    {
        return (bool) config('nezha_email_auth.enabled', false);
    }

    public function isAvailable(): bool
    {
        return $this->isEnabled() && (bool) config('mail.status');
    }

    public function registrationAvailable(): bool
    {
        return $this->isAvailable()
            && (bool) config('nezha_email_auth.allow_new_accounts', false);
    }

    public function begin(
        string $email,
        string $ip,
        string $locale = 'zh-CN',
        bool $termsAccepted = false,
    ): array {
        if (! $this->isAvailable()) {
            throw new EmailLoginException(
                'email_delivery_unavailable',
                'Email verification is temporarily unavailable.',
                503,
            );
        }
        if (! $termsAccepted) {
            throw new EmailLoginException(
                'terms_required',
                'Please agree to the terms before continuing.',
                422,
            );
        }

        $email = $this->normalizeEmail($email);
        $this->assertStartRateLimit($email, $ip);

        $challengeId = (string) Str::uuid();
        $browserSecret = $this->randomToken();
        $code = app()->environment('testing')
            ? '123456'
            : str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = max(120, min(1800, (int) config('nezha_email_auth.challenge_ttl_seconds', 600)));
        $now = now();

        Cache::put($this->challengeKey($challengeId), [
            'email' => $email,
            'browser_secret_hash' => $this->hashToken($browserSecret),
            'code_hash' => $this->hashToken($code),
            'code_attempts' => 0,
            'status' => 'pending',
            'target_user_id' => null,
            'terms_accepted' => true,
            'created_at' => $now->timestamp,
            'expires_at' => $now->copy()->addSeconds($ttl)->timestamp,
        ], $ttl);

        try {
            Mail::to($email)->send(new CustomerEmailVerificationCode(
                $code,
                $this->normalizeLocale($locale),
                $ttl,
            ));
        } catch (Throwable $error) {
            Cache::forget($this->challengeKey($challengeId));
            Log::warning('Customer email verification delivery failed.', [
                'exception' => $error::class,
            ]);

            throw new EmailLoginException(
                'email_delivery_failed',
                'The verification email could not be sent.',
                503,
            );
        }

        return [
            'challenge_id' => $challengeId,
            'browser_secret' => $browserSecret,
            'expires_in' => $ttl,
            'resend_after' => max(30, (int) config('nezha_email_auth.resend_after_seconds', 60)),
        ];
    }

    public function verify(
        string $challengeId,
        string $browserSecret,
        string $code,
    ): array {
        return Cache::lock($this->challengeLockKey($challengeId), 10)->block(
            3,
            function () use ($challengeId, $browserSecret, $code) {
                $challenge = $this->challenge($challengeId, $browserSecret);
                if ($challenge['status'] === 'locked') {
                    throw $this->lockedChallenge();
                }
                if ($challenge['status'] !== 'pending') {
                    throw $this->expiredChallenge();
                }

                if (! hash_equals((string) $challenge['code_hash'], $this->hashToken($code))) {
                    $challenge['code_attempts'] = ((int) $challenge['code_attempts']) + 1;
                    if ($challenge['code_attempts'] >= $this->maxCodeAttempts()) {
                        $challenge['status'] = 'locked';
                    }
                    $this->storeChallenge($challengeId, $challenge);

                    if ($challenge['status'] === 'locked') {
                        throw $this->lockedChallenge();
                    }

                    throw new EmailLoginException(
                        'email_auth_code_invalid',
                        'The verification code is incorrect.',
                        422,
                    );
                }

                $challenge['status'] = 'verified';
                $challenge['code_hash'] = null;

                $owners = $this->emailOwners((string) $challenge['email']);
                if ($owners->count() > 1) {
                    $challenge['status'] = 'consumed';
                    $this->storeChallenge($challengeId, $challenge);
                    throw $this->identityConflict();
                }

                /** @var User|null $user */
                $user = $owners->first();
                if ($user) {
                    $this->assertActive($user);
                    $challenge['target_user_id'] = $user->id;

                    if ((int) $user->is_email_verified !== 1) {
                        $user->forceFill([
                            'is_email_verified' => 1,
                            'email_verified_at' => $user->email_verified_at ?: now(),
                            // A password created before this email was proven may belong
                            // to a pre-registrant. Email ownership now wins, matching the
                            // already-approved Google merge semantics.
                            'password' => bcrypt(Str::random(40)),
                        ])->save();
                    }

                    return $this->authenticateAndConsume($challengeId, $challenge, $user);
                }

                if (! $this->registrationAvailable()) {
                    $challenge['status'] = 'consumed';
                    $this->storeChallenge($challengeId, $challenge);

                    return [
                        'status' => 'registration_unavailable',
                    ];
                }

                if (($challenge['terms_accepted'] ?? false) !== true) {
                    throw new EmailLoginException(
                        'terms_required',
                        'Please agree to the terms before creating an account.',
                        422,
                    );
                }

                $email = (string) $challenge['email'];

                return Cache::lock($this->emailRegistrationLockKey($email), 15)->block(
                    5,
                    function () use ($challengeId, $challenge, $email) {
                        $user = DB::transaction(function () use ($email) {
                            $owners = $this->emailOwners($email, true);
                            if ($owners->count() > 1) {
                                throw $this->identityConflict();
                            }
                            if ($owners->isNotEmpty()) {
                                /** @var User $existing */
                                $existing = $owners->first();
                                $this->assertActive($existing);
                                if ((int) $existing->is_email_verified !== 1) {
                                    $existing->forceFill([
                                        'is_email_verified' => 1,
                                        'email_verified_at' => $existing->email_verified_at ?: now(),
                                        'password' => bcrypt(Str::random(40)),
                                    ])->save();
                                }

                                return $existing;
                            }

                            $user = new User;
                            $user->f_name = $this->defaultName($email);
                            $user->l_name = '';
                            $user->email = $email;
                            $user->phone = null;
                            $user->password = null;
                            $user->is_email_verified = 1;
                            $user->email_verified_at = now();
                            $user->login_medium = 'email_otp';
                            $user->save();
                            $user->ref_code = Helpers::generate_referer_code();
                            $user->save();

                            return $user;
                        }, 3);

                        $challenge['target_user_id'] = $user->id;
                        $this->storeChallenge($challengeId, $challenge);

                        return $this->authenticateAndConsume(
                            $challengeId,
                            $challenge,
                            $user,
                        );
                    },
                );
            },
        );
    }

    private function authenticateAndConsume(
        string $challengeId,
        array $challenge,
        User $user,
    ): array {
        $token = $this->tokenIssuer->issue($user);

        $user->forceFill(['login_medium' => 'email_otp'])->save();
        $challenge['status'] = 'consumed';
        $this->storeChallenge($challengeId, $challenge);

        return [
            'status' => 'authenticated',
            'token' => $token,
            'is_phone_verified' => (int) $user->is_phone_verified,
            'is_email_verified' => 1,
            'is_personal_info' => $user->f_name ? 1 : 0,
            'is_exist_user' => null,
            'login_type' => 'email_otp',
            'email' => $user->getRawOriginal('email'),
        ];
    }

    private function challenge(string $challengeId, string $browserSecret): array
    {
        $challenge = Cache::get($this->challengeKey($challengeId));
        if (
            ! is_array($challenge)
            || (int) ($challenge['expires_at'] ?? 0) <= now()->timestamp
            || ! is_string($challenge['browser_secret_hash'] ?? null)
            || ! hash_equals(
                (string) $challenge['browser_secret_hash'],
                $this->hashToken($browserSecret),
            )
            || in_array($challenge['status'] ?? null, ['consumed', 'locked'], true)
        ) {
            if (($challenge['status'] ?? null) === 'locked') {
                throw $this->lockedChallenge();
            }

            throw $this->expiredChallenge();
        }

        return $challenge;
    }

    private function storeChallenge(string $challengeId, array $challenge): void
    {
        $ttl = max(1, ((int) $challenge['expires_at']) - now()->timestamp);
        Cache::put($this->challengeKey($challengeId), $challenge, $ttl);
    }

    private function emailOwners(string $email, bool $lock = false)
    {
        $query = User::query()
            ->without('storage')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->limit(2);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function assertActive(User $user): void
    {
        if (! (bool) $user->status) {
            throw new EmailLoginException(
                'email_account_blocked',
                'The existing account is blocked. Please contact support.',
                403,
            );
        }
    }

    private function resolveReferrer(?string $refCode): ?User
    {
        if (! is_string($refCode) || trim($refCode) === '') {
            return null;
        }

        $enabled = BusinessSetting::query()
            ->where('key', 'ref_earning_status')
            ->value('value');
        if ((string) $enabled !== '1') {
            throw new EmailLoginException(
                'referral_unavailable',
                'Referral codes are not available.',
                422,
            );
        }

        $referrer = User::query()
            ->without('storage')
            ->where('ref_code', trim($refCode))
            ->first();
        if (! $referrer || ! $referrer->status) {
            throw new EmailLoginException(
                'referral_code_invalid',
                'The referral code is invalid.',
                422,
            );
        }

        return $referrer;
    }

    private function assertStartRateLimit(string $email, string $ip): void
    {
        $emailKey = 'customer-email-auth:start:email:'.hash('sha256', $email);
        $ipKey = 'customer-email-auth:start:ip:'.hash('sha256', $ip);
        $emailLimit = max(1, (int) config('nezha_email_auth.start_email_limit', 3));
        $ipLimit = max(1, (int) config('nezha_email_auth.start_ip_limit', 10));
        $decay = max(60, (int) config('nezha_email_auth.start_decay_seconds', 600));

        if (
            RateLimiter::tooManyAttempts($emailKey, $emailLimit)
            || RateLimiter::tooManyAttempts($ipKey, $ipLimit)
        ) {
            throw new EmailLoginException(
                'email_auth_rate_limited',
                'Too many verification emails were requested. Try again later.',
                429,
            );
        }

        RateLimiter::hit($emailKey, $decay);
        RateLimiter::hit($ipKey, $decay);
    }

    private function defaultName(string $email): string
    {
        $localPart = Str::before($email, '@');

        return mb_substr($localPart !== '' ? $localPart : 'Customer', 0, 20);
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = trim($locale);

        return in_array($locale, ['zh-CN', 'en', 'hy'], true) ? $locale : 'zh-CN';
    }

    private function challengeKey(string $challengeId): string
    {
        return 'customer-email-auth:challenge:'.$challengeId;
    }

    private function challengeLockKey(string $challengeId): string
    {
        return 'customer-email-auth:challenge-lock:'.hash('sha256', $challengeId);
    }

    private function emailRegistrationLockKey(string $email): string
    {
        return 'customer-email-auth:registration-lock:'.hash('sha256', $email);
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    private function maxCodeAttempts(): int
    {
        return max(3, min(10, (int) config('nezha_email_auth.max_code_attempts', 5)));
    }

    private function expiredChallenge(): EmailLoginException
    {
        return new EmailLoginException(
            'email_auth_expired',
            'This verification request expired or was already used.',
            410,
        );
    }

    private function lockedChallenge(): EmailLoginException
    {
        return new EmailLoginException(
            'email_auth_locked',
            'Too many incorrect attempts. Request a new code.',
            429,
        );
    }

    private function identityConflict(): EmailLoginException
    {
        return new EmailLoginException(
            'identity_conflict',
            'This email requires account recovery. Contact support.',
            409,
        );
    }
}
