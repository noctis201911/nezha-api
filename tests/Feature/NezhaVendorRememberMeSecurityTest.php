<?php

namespace Tests\Feature;

use App\Http\Controllers\LoginController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NezhaVendorRememberMeSecurityTest extends TestCase
{
    public function test_remember_me_uses_the_framework_guard_without_persisting_reversible_credentials(): void
    {
        $password = 'remember-me-secret';
        DB::table('vendors')->where('id', 1)->update([
            'email' => 'remember-vendor@example.test',
            'password' => Hash::make($password),
        ]);

        $result = (new LoginController)->login_attemp(
            'vendor',
            'remember-vendor@example.test',
            $password,
            '127.0.0.1',
            true
        );

        $this->assertSame('vendor', $result);
        $this->assertTrue(Auth::guard('vendor')->check());

        $queued = collect(Cookie::getQueuedCookies())->keyBy->getName();
        foreach (['p_token', 'e_token', 'role'] as $legacyCookie) {
            $this->assertTrue($queued->has($legacyCookie), "Legacy cookie {$legacyCookie} was not retired.");
            $this->assertContains($queued->get($legacyCookie)->getValue(), [null, ''], true);
            $this->assertLessThan(time(), $queued->get($legacyCookie)->getExpiresTime());
        }
    }

    public function test_login_templates_never_render_a_server_supplied_password_value(): void
    {
        foreach ([
            resource_path('views/auth/login.blade.php'),
            resource_path('views/auth/admin-login.blade.php'),
        ] as $template) {
            $source = file_get_contents($template);

            $this->assertNotFalse($source);
            $this->assertStringNotContainsString('value="{{ $password', $source);
        }
    }
}
