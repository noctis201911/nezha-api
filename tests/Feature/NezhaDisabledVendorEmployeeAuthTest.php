<?php

namespace Tests\Feature;

use App\Http\Controllers\LoginController;
use App\Http\Middleware\VendorMiddleware;
use App\Http\Middleware\VendorTokenIsValid;
use App\Models\VendorEmployee;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NezhaDisabledVendorEmployeeAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('vendor_employees', function (Blueprint $table): void {
            $table->string('auth_token')->nullable();
        });
        Schema::create('data_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key');
            $table->string('type')->nullable();
            $table->string('value');
            $table->timestamps();
        });
        DB::table('data_settings')->insert([
            'key' => 'restaurant_employee_login_url',
            'value' => 'restaurant-employee',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            [7, 'active-employee@example.test', 'active-token', 1],
            [8, 'disabled-employee@example.test', 'disabled-token', 0],
        ] as [$id, $email, $token, $status]) {
            DB::table('vendor_employees')->insert([
                'id' => $id,
                'f_name' => 'Fixture',
                'l_name' => 'Employee',
                'email' => $email,
                'employee_role_id' => 1,
                'vendor_id' => 1,
                'restaurant_id' => 6,
                'password' => Hash::make('employee-secret'),
                'status' => $status,
                'auth_token' => $token,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function test_disabled_employee_cannot_start_a_new_web_session(): void
    {
        $controller = new LoginController;

        $this->assertFalse($controller->login_attemp(
            'vendor_employee',
            'disabled-employee@example.test',
            'employee-secret',
            '127.0.0.1'
        ));
        $this->assertFalse(Auth::guard('vendor_employee')->check());

        $this->assertSame('vendor', $controller->login_attemp(
            'vendor_employee',
            'active-employee@example.test',
            'employee-secret',
            '127.0.0.1'
        ));
        $this->assertTrue(Auth::guard('vendor_employee')->check());
    }

    public function test_disabled_employee_stale_web_session_is_logged_out_before_downstream(): void
    {
        $employee = VendorEmployee::findOrFail(8);
        $this->actingAs($employee, 'vendor_employee');
        $request = Request::create('/vendor/stale-session-probe', 'GET');
        $request->setLaravelSession(app('session')->driver());
        $reached = false;

        $response = (new VendorMiddleware)->handle($request, function () use (&$reached) {
            $reached = true;

            return response()->noContent();
        });

        $this->assertFalse($reached);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse(Auth::guard('vendor_employee')->check());
    }

    public function test_employee_api_tokens_require_an_active_employee(): void
    {
        foreach ([
            ['disabled-token', false, 401],
            ['active-token', true, 204],
        ] as [$token, $shouldReachNext, $expectedStatus]) {
            $request = Request::create('/vendor-api/employee-probe', 'GET');
            $request->headers->set('Authorization', 'Bearer '.$token);
            $request->headers->set('vendorType', 'employee');
            $reached = false;

            $response = (new VendorTokenIsValid)->handle($request, function (Request $resolved) use (&$reached) {
                $reached = true;
                $this->assertSame(7, $resolved['vendor_employee']->id);

                return response()->noContent();
            });

            $this->assertSame($shouldReachNext, $reached);
            $this->assertSame($expectedStatus, $response->getStatusCode());
        }
    }
}
