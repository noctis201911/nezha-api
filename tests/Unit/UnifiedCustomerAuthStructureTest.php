<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class UnifiedCustomerAuthStructureTest extends TestCase
{
    public function test_only_customer_access_token_issuer_calls_create_token(): void
    {
        $root = dirname(__DIR__, 2);
        $paths = [
            $root.'/app/Http/Controllers/Api/V1/Auth/CustomerAuthController.php',
            $root.'/app/Services/Auth/TelegramLoginService.php',
            $root.'/app/Services/Auth/GoogleLoginService.php',
            $root.'/app/Services/Auth/CustomerEmailAuthService.php',
        ];

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('createToken(', file_get_contents($path), $path);
        }
        $issuer = file_get_contents($root.'/app/Services/Auth/CustomerAccessTokenIssuer.php');
        $this->assertSame(1, substr_count($issuer, 'createToken('));
    }

    public function test_legacy_registration_and_otp_have_server_side_gates(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/Api/V1/Auth/CustomerAuthController.php'
        );

        $this->assertStringContainsString('legacySignupEnabled()', $source);
        $this->assertStringContainsString("['otp_login_status']", $source);
        $this->assertStringContainsString('legacy_link_required', $source);
        $this->assertStringNotContainsString('自动合并:', $source);
    }

    public function test_profile_email_canonicalization_is_not_wired_into_address_creation(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/Api/V1/CustomerController.php'
        );
        $addressSection = strstr($source, 'public function add_new_address');
        $addressSection = strstr($addressSection, 'public function update_address', true);
        $profileSection = strstr($source, 'public function update_profile');
        $profileSection = strstr($profileSection, 'public function update_interest', true);

        $this->assertStringNotContainsString('emailCanonicalizer', $addressSection);
        $this->assertStringContainsString('emailCanonicalizer->canonicalize', $profileSection);
        $this->assertStringContainsString("request->merge(['email' => \$canonicalEmail])", $profileSection);
        $this->assertStringContainsString(
            'update_user_data($user, $request, $canonicalEmail, $emailVerifiedThisRequest)',
            $profileSection
        );
    }

    public function test_email_auth_routes_import_the_auth_controller_namespace(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2).'/routes/api/v1/api.php');

        $this->assertStringContainsString(
            'use App\\Http\\Controllers\\Api\\V1\\Auth\\CustomerEmailAuthController;',
            $routes
        );
        $this->assertStringContainsString(
            "Route::post('start', [CustomerEmailAuthController::class, 'start'])",
            $routes
        );
    }
}
