<?php

namespace App\Services\Auth;

use App\Exceptions\CustomerLoginException;
use App\Exceptions\TelegramLoginException;
use App\Models\User;
use App\Models\UserExternalIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class GoogleLoginService
{
    private const PROVIDER = 'google';

    public function __construct(
        private readonly GoogleTokenVerifier $verifier,
        private readonly EmailCanonicalizer $canonicalizer,
        private readonly CustomerAuthFeatureFlags $flags,
        private readonly CustomerLoginFinalizer $loginFinalizer,
    ) {}

    public function authenticateCredential(string $credential): array
    {
        try {
            $profile = $this->verifier->verify($credential);
        } catch (TelegramLoginException $error) {
            throw new CustomerLoginException(
                'google_credential_invalid',
                'Google verification could not be completed.',
                $error->httpStatus,
            );
        }

        return DB::transaction(function () use ($profile) {
            $subject = (string) $profile['sub'];
            try {
                $canonical = $this->canonicalizer->canonicalize((string) $profile['email']);
            } catch (InvalidArgumentException) {
                throw new CustomerLoginException(
                    'google_email_invalid',
                    'Google returned an unsupported email address.',
                    403,
                );
            }
            $identity = UserExternalIdentity::query()
                ->where('provider', self::PROVIDER)
                ->where('provider_subject', $subject)
                ->lockForUpdate()
                ->first();

            if ($identity) {
                $user = User::query()->without('storage')->lockForUpdate()->find($identity->user_id);
                if (! $user) {
                    throw new CustomerLoginException(
                        'google_identity_conflict',
                        'This Google identity is unavailable. Please contact support.',
                        409,
                    );
                }
                $identity->forceFill(['last_login_at' => now()])->save();

                return $this->finish($user, $profile);
            }

            $user = User::query()
                ->without('storage')
                ->where('email_canonical', $canonical)
                ->lockForUpdate()
                ->first();
            if (! $user) {
                $legacy = User::query()
                    ->without('storage')
                    ->whereNull('email_canonical')
                    ->whereRaw('LOWER(TRIM(email)) = ?', [$canonical])
                    ->lockForUpdate()
                    ->first();
                if ($legacy) {
                    throw new CustomerLoginException(
                        'legacy_link_required',
                        'Use the existing password or contact support to recover this account.',
                        409,
                    );
                }

                if (! $this->flags->googleRegistrationEnabled()) {
                    throw new CustomerLoginException(
                        'registration_unavailable',
                        'New account registration is not available yet.',
                        403,
                    );
                }

                throw new CustomerLoginException(
                    'google_registration_completion_required',
                    'Complete the new account details before continuing.',
                    409,
                );
            }

            if ((int) $user->is_email_verified !== 1 && $user->email_verified_at === null) {
                throw new CustomerLoginException(
                    'legacy_link_required',
                    'Use the existing password or contact support to recover this account.',
                    409,
                );
            }

            $userIdentity = UserExternalIdentity::query()
                ->where('provider', self::PROVIDER)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if ($userIdentity && ! hash_equals((string) $userIdentity->provider_subject, $subject)) {
                throw new CustomerLoginException(
                    'google_identity_replacement_required',
                    'This account already has a different Google identity.',
                    409,
                );
            }
            if ($userIdentity) {
                $userIdentity->forceFill(['last_login_at' => now()])->save();

                return $this->finish($user, $profile);
            }

            try {
                UserExternalIdentity::query()->create([
                    'user_id' => $user->id,
                    'provider' => self::PROVIDER,
                    'provider_subject' => $subject,
                    'last_login_at' => now(),
                ]);
            } catch (QueryException $error) {
                if (in_array((string) ($error->errorInfo[0] ?? ''), ['23000', '23505'], true)) {
                    throw new CustomerLoginException(
                        'google_identity_conflict',
                        'Google sign-in changed concurrently. Please try again.',
                        409,
                    );
                }

                throw $error;
            }

            return $this->finish($user, $profile);
        }, 3);
    }

    private function finish(User $user, array $profile): array
    {
        if (! $user->f_name) {
            $user->f_name = Str::limit(trim((string) ($profile['given_name'] ?? $profile['name'] ?? 'Google 用户')), 100, '');
            $user->l_name = Str::limit(trim((string) ($profile['family_name'] ?? '')), 100, '');
        }
        $user->email_verified_at ??= now();
        $user->email_verification_method ??= 'google';
        $user->is_email_verified = 1;
        $user->save();

        return [
            'status' => 'authenticated',
            'token' => $this->loginFinalizer->issue($user, self::PROVIDER),
            'is_phone_verified' => (int) $user->is_phone_verified,
            'is_email_verified' => 1,
            'is_personal_info' => $user->f_name ? 1 : 0,
            'is_exist_user' => null,
            'login_type' => self::PROVIDER,
        ];
    }
}
