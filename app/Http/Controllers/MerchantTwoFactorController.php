<?php

namespace App\Http\Controllers;

use App\CentralLogics\NezhaMerchantTwoFactor;
use App\CentralLogics\NezhaTotp;
use App\Models\DataSetting;
use App\Models\Vendor;
use App\Models\VendorEmployee;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class MerchantTwoFactorController extends Controller
{
    public const PENDING_TYPE = 'merchant_2fa.pending_type';

    public const PENDING_ID = 'merchant_2fa.pending_id';

    public const PENDING_GENERATION = 'merchant_2fa.pending_generation';

    public const PENDING_EXPIRES = 'merchant_2fa.pending_expires';

    public const PENDING_LOGIN_URL = 'merchant_2fa.pending_login_url';

    public const PENDING_SETUP_REQUIRED = 'merchant_2fa.pending_setup_required';

    public const SESSION_GENERATION = 'merchant_2fa.auth_generation';

    public const SESSION_PASSED_GENERATION = 'merchant_2fa.passed_generation';

    public const ONBOARDING_RESTAURANT_ID = 'merchant_2fa.onboarding_restaurant_id';

    public const ONBOARDING_AUTHORIZED = 'merchant_2fa.onboarding_authorized';

    public static function beginPending(
        Request $request,
        Authenticatable $actor,
        string $loginUrl,
        bool $setupRequired = false
    ): void
    {
        $request->session()->put([
            self::PENDING_TYPE => NezhaMerchantTwoFactor::actorType($actor),
            self::PENDING_ID => (int) $actor->getAuthIdentifier(),
            self::PENDING_GENERATION => (int) $actor->auth_generation,
            self::PENDING_EXPIRES => now()->addMinutes(5)->getTimestamp(),
            self::PENDING_LOGIN_URL => $loginUrl,
            self::PENDING_SETUP_REQUIRED => $setupRequired,
        ]);
        $request->session()->forget([
            'merchant_2fa.setup_secret',
            'merchant_2fa.setup_generation',
        ]);
    }

    public static function finishLogin(Request $request, Authenticatable $actor, bool $secondFactorPassed): void
    {
        $guard = NezhaMerchantTwoFactor::actorType($actor) === NezhaMerchantTwoFactor::OWNER
            ? 'vendor'
            : 'vendor_employee';

        auth($guard)->login($actor, false);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_GENERATION, (int) $actor->auth_generation);
        if ($secondFactorPassed) {
            $request->session()->put(self::SESSION_PASSED_GENERATION, (int) $actor->auth_generation);
        } else {
            $request->session()->forget(self::SESSION_PASSED_GENERATION);
        }
        if ($actor instanceof Vendor) {
            $restaurant = $actor->restaurants()->first();
            if ($restaurant
                && (int) $request->session()->get(self::ONBOARDING_RESTAURANT_ID) === (int) $restaurant->id) {
                $request->session()->put(self::ONBOARDING_AUTHORIZED, true);
            }
        }
        self::clearPending($request);
    }

    public function challenge(Request $request)
    {
        $actor = $this->pendingActor($request);
        if (! $actor) {
            return $this->loginRedirect($request);
        }
        $state = NezhaMerchantTwoFactor::state($actor);
        if ($state === NezhaMerchantTwoFactor::STATE_OPTIONAL) {
            self::finishLogin($request, $actor, false);

            return redirect()->to(self::continuationUrl($actor));
        }
        if ($state === NezhaMerchantTwoFactor::STATE_ENROLLMENT) {
            return redirect()->route('merchant.2fa.setup');
        }

        return $this->twoFactorView([
            'mode' => 'challenge',
            'continuation_url' => self::continuationUrl($actor),
            'site_direction' => session('vendor_site_direction', 'ltr'),
            'locale' => session('vendor_local', 'en'),
        ]);
    }

    public function verifyChallenge(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'max:32']]);
        $actor = $this->pendingActor($request);
        if (! $actor) {
            return $this->loginRedirect($request);
        }
        if ($this->limited($request, $actor)) {
            return back()->withErrors(['code' => 'Unable to verify that code. Wait before trying again.']);
        }

        $context = $this->context($request, 'web');
        try {
            $verified = NezhaMerchantTwoFactor::verifyTotp(
                $actor,
                (string) $request->input('code'),
                (int) $request->session()->get(self::PENDING_GENERATION),
                $context
            );
            $this->clearLimits($request, $actor);
            self::finishLogin($request, $verified, true);

            return redirect()->to(self::continuationUrl($verified));
        } catch (\DomainException) {
            $this->hitLimits($request, $actor);

            return back()->withErrors(['code' => 'Unable to verify that code.']);
        }
    }

    public function setup(Request $request)
    {
        $pendingActor = $this->pendingActor($request);
        $actor = $pendingActor ?: $this->authenticatedActor();
        if (! $actor) {
            return $this->loginRedirect($request);
        }
        if ($pendingActor
            && NezhaMerchantTwoFactor::state($actor) === NezhaMerchantTwoFactor::STATE_OPTIONAL
            && ! $request->session()->get(self::PENDING_SETUP_REQUIRED, false)) {
            self::finishLogin($request, $actor, false);

            return redirect()->to(self::continuationUrl($actor));
        }

        if ($actor->two_factor_enabled) {
            return $this->twoFactorView([
                'mode' => 'enabled',
                'continuation_url' => self::continuationUrl($actor),
                'site_direction' => session('vendor_site_direction', 'ltr'),
                'locale' => session('vendor_local', 'en'),
            ]);
        }

        $secret = null;
        $encryptedSecret = $request->session()->get('merchant_2fa.setup_secret');
        $setupGeneration = $request->session()->get('merchant_2fa.setup_generation');
        if ($encryptedSecret && (int) $setupGeneration === (int) $actor->auth_generation) {
            try {
                $secret = Crypt::decryptString((string) $encryptedSecret);
            } catch (\Throwable) {
                $secret = null;
            }
        }
        if (! $secret) {
            $secret = NezhaTotp::generateSecret();
            $request->session()->put([
                'merchant_2fa.setup_secret' => Crypt::encryptString($secret),
                'merchant_2fa.setup_generation' => (int) $actor->auth_generation,
            ]);
        }

        $uri = NezhaTotp::otpauthUri(
            $secret,
            $actor->email ?: NezhaMerchantTwoFactor::actorType($actor).'-'.$actor->getAuthIdentifier(),
            'Nezha Merchant'
        );

        return $this->twoFactorView([
            'mode' => 'setup',
            'secret' => $secret,
            'qr_svg' => base64_encode(QrCode::format('svg')->size(220)->margin(1)->generate($uri)),
            'continuation_url' => self::continuationUrl($actor),
            'site_direction' => session('vendor_site_direction', 'ltr'),
            'locale' => session('vendor_local', 'en'),
        ]);
    }

    public function enable(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);
        $actor = $this->pendingActor($request) ?: $this->authenticatedActor();
        try {
            $secret = Crypt::decryptString((string) $request->session()->get('merchant_2fa.setup_secret'));
        } catch (\Throwable) {
            $secret = '';
        }
        $generation = $request->session()->get('merchant_2fa.setup_generation');
        if (! $actor || ! $secret || $generation === null) {
            return $this->loginRedirect($request);
        }
        if ($this->limited($request, $actor)) {
            return back()->withErrors(['code' => 'Unable to verify that code. Wait before trying again.']);
        }

        try {
            $result = NezhaMerchantTwoFactor::completeEnrollment(
                $actor,
                $secret,
                (string) $request->input('code'),
                (int) $generation,
                $this->context($request, 'web')
            );
        } catch (\DomainException) {
            $this->hitLimits($request, $actor);

            return back()->withErrors(['code' => 'Unable to verify that code.']);
        }

        $this->clearLimits($request, $actor);
        self::finishLogin($request, $result['actor'], true);

        return redirect()->route('merchant.2fa.setup');
    }

    public function cancel(Request $request)
    {
        $authenticatedActor = $this->authenticatedActor();
        if ($authenticatedActor && ! $request->session()->has(self::PENDING_ID)) {
            $request->session()->forget([
                'merchant_2fa.setup_secret',
                'merchant_2fa.setup_generation',
            ]);

            return redirect()->route('vendor.profile.view');
        }

        $url = (string) $request->session()->get(self::PENDING_LOGIN_URL, 'vendor');
        self::clearPending($request);
        foreach (['vendor', 'vendor_employee'] as $guard) {
            if (auth($guard)->check()) {
                auth($guard)->logout();
            }
        }
        $request->session()->forget([
            self::SESSION_GENERATION,
            self::SESSION_PASSED_GENERATION,
            self::ONBOARDING_RESTAURANT_ID,
            self::ONBOARDING_AUTHORIZED,
        ]);
        $request->session()->regenerate();

        return redirect()->route('login', ['tab' => $url]);
    }

    public function disable(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:16'],
        ]);
        $actor = $this->authenticatedActor();
        if (! $actor || $this->limited($request, $actor)) {
            return back()->withErrors(['code' => 'Unable to verify those credentials.']);
        }

        try {
            $disabled = NezhaMerchantTwoFactor::disableTwoFactor(
                $actor,
                (string) $request->input('current_password'),
                (string) $request->input('code'),
                $this->context($request, 'web')
            );
        } catch (\DomainException) {
            $this->hitLimits($request, $actor);

            return back()->withErrors(['code' => 'Unable to verify those credentials.']);
        }

        // Disabling revokes every session including this one; re-establish the
        // current browser session so the merchant is not bounced to login.
        $this->clearLimits($request, $actor);
        self::finishLogin($request, $disabled, false);

        return redirect()->route('merchant.2fa.setup')
            ->with('merchant_2fa.disabled_notice', true);
    }

    public function replaceAuthenticator(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:16'],
        ]);
        $actor = $this->authenticatedActor();
        if (! $actor || $this->limited($request, $actor)) {
            return back()->withErrors(['code' => 'Unable to verify those credentials.']);
        }

        try {
            NezhaMerchantTwoFactor::verifySensitiveStepUp(
                $actor,
                (string) $request->input('current_password'),
                (string) $request->input('code'),
                $this->context($request, 'web')
            );
            $recovery = NezhaMerchantTwoFactor::revokeActor(
                $actor,
                'authenticator_replacement_started',
                $this->context($request, 'web'),
                true
            );
        } catch (\DomainException) {
            $this->hitLimits($request, $actor);

            return back()->withErrors(['code' => 'Unable to verify those credentials.']);
        }

        $this->clearLimits($request, $actor);
        $loginUrl = $this->loginUrl($recovery);
        self::beginPending($request, $recovery, $loginUrl, true);
        auth(NezhaMerchantTwoFactor::actorType($actor) === NezhaMerchantTwoFactor::OWNER
            ? 'vendor'
            : 'vendor_employee')->logout();
        $request->session()->forget([self::SESSION_GENERATION, self::SESSION_PASSED_GENERATION]);

        return redirect()->route('merchant.2fa.setup');
    }

    public static function clearPending(Request $request): void
    {
        $request->session()->forget([
            self::PENDING_TYPE,
            self::PENDING_ID,
            self::PENDING_GENERATION,
            self::PENDING_EXPIRES,
            self::PENDING_LOGIN_URL,
            self::PENDING_SETUP_REQUIRED,
            'merchant_2fa.setup_secret',
            'merchant_2fa.setup_generation',
        ]);
    }

    private function pendingActor(Request $request): Vendor|VendorEmployee|null
    {
        $expires = (int) $request->session()->get(self::PENDING_EXPIRES, 0);
        if (! $expires) {
            return null;
        }
        if ($expires < now()->getTimestamp()) {
            self::clearPending($request);

            return null;
        }

        $actor = NezhaMerchantTwoFactor::actor(
            (string) $request->session()->get(self::PENDING_TYPE),
            (int) $request->session()->get(self::PENDING_ID)
        );
        if (! $actor || ! $this->active($actor)) {
            self::clearPending($request);

            return null;
        }

        return $actor;
    }

    private function authenticatedActor(): Vendor|VendorEmployee|null
    {
        $actor = auth('vendor')->user() ?: auth('vendor_employee')->user();
        if (! $actor || ! $this->active($actor)) {
            return null;
        }
        if (session(self::SESSION_GENERATION) !== (int) $actor->auth_generation) {
            return null;
        }

        return $actor;
    }

    private function active(Vendor|VendorEmployee $actor): bool
    {
        if ($actor instanceof Vendor) {
            $restaurant = $actor->restaurants()->first();
            if ((int) session(self::ONBOARDING_RESTAURANT_ID) === (int) $restaurant?->id) {
                if ($restaurant?->restaurant_model === 'none') {
                    return true;
                }
                if ($restaurant?->restaurant_model === 'subscription'
                    && ! $actor->status
                    && $restaurant?->restaurant_sub_trans?->transaction_status == 0) {
                    return true;
                }
            }

            return (bool) $actor->status && (bool) $actor->restaurants()->where('status', 1)->exists();
        }

        return (bool) $actor->status && (bool) $actor->restaurant?->status;
    }

    private function limited(Request $request, Authenticatable $actor): bool
    {
        return collect($this->rateKeys($request, $actor))
            ->contains(fn (string $key): bool => RateLimiter::tooManyAttempts($key, 5));
    }

    private function hitLimits(Request $request, Authenticatable $actor): void
    {
        foreach ($this->rateKeys($request, $actor) as $key) {
            RateLimiter::hit($key, 120);
        }
    }

    private function clearLimits(Request $request, Authenticatable $actor): void
    {
        foreach ($this->rateKeys($request, $actor) as $key) {
            RateLimiter::clear($key);
        }
    }

    private function rateKeys(Request $request, Authenticatable $actor): array
    {
        return [
            'merchant-2fa:ip:'.NezhaMerchantTwoFactor::requestHash($request->ip()),
            'merchant-2fa:account:'.hash('sha256', NezhaMerchantTwoFactor::actorType($actor).':'.$actor->getAuthIdentifier()),
        ];
    }

    private function context(Request $request, string $channel): array
    {
        return [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => ['channel' => $channel, 'route' => optional($request->route())->getName()],
        ];
    }

    private function twoFactorView(array $data)
    {
        return response()
            ->view('auth.merchant-two-factor', $data)
            ->withHeaders([
                'Cache-Control' => 'no-store, private',
                'Pragma' => 'no-cache',
                'Referrer-Policy' => 'no-referrer',
            ]);
    }

    private function loginRedirect(Request $request)
    {
        $url = (string) $request->session()->get(self::PENDING_LOGIN_URL, 'vendor');
        self::clearPending($request);

        return redirect()->route('login', ['tab' => $url]);
    }

    private function loginUrl(Authenticatable $actor): string
    {
        $key = NezhaMerchantTwoFactor::actorType($actor) === NezhaMerchantTwoFactor::OWNER
            ? 'restaurant_login_url'
            : 'restaurant_employee_login_url';

        return DataSetting::where('key', $key)->value('value') ?: $key;
    }

    public static function continuationUrl(Authenticatable $actor): string
    {
        if ($actor instanceof Vendor) {
            $restaurant = $actor->restaurants()->first();
            if ((int) session(self::ONBOARDING_RESTAURANT_ID) === (int) $restaurant?->id) {
                if ($restaurant?->restaurant_model === 'none') {
                    return route('restaurant.business_plan');
                }
                if ($restaurant?->restaurant_model === 'subscription'
                    && ! $actor->status
                    && $restaurant?->restaurant_sub_trans?->transaction_status == 0) {
                    return route('vendor.subscription.digital_payment_methods', [
                        'subscription_transaction_id' => $restaurant->restaurant_sub_trans->id,
                        'type' => 'new_join',
                    ]);
                }
            }
        }

        return route('vendor.dashboard');
    }
}
