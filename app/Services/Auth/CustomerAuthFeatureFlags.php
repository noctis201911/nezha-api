<?php

namespace App\Services\Auth;

use App\Models\BusinessSetting;

class CustomerAuthFeatureFlags
{
    public function legacySignupEnabled(): bool
    {
        return (bool) config('customer_auth.legacy_signup_enabled', false);
    }

    public function emailLoginEnabled(): bool
    {
        return $this->businessFlag('email_auth_login_status');
    }

    public function emailRegistrationEnabled(): bool
    {
        return $this->emailLoginEnabled()
            && $this->businessFlag('email_auth_registration_status');
    }

    public function emailDeliveryEnabled(): bool
    {
        return $this->businessFlag('email_auth_mail_status')
            && (bool) config('mail.status');
    }

    public function googleRegistrationEnabled(): bool
    {
        return $this->businessFlag('google_auth_registration_status');
    }

    private function businessFlag(string $key): bool
    {
        return (int) (BusinessSetting::query()->where('key', $key)->value('value') ?? 0) === 1;
    }
}
