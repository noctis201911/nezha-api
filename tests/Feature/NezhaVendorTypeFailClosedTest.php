<?php

namespace Tests\Feature;

use App\Http\Middleware\VendorTokenIsValid;
use App\Models\Vendor;
use App\Models\VendorEmployee;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NezhaVendorTypeFailClosedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('vendors', function (Blueprint $table): void {
            $table->string('auth_token')->nullable();
        });
        Schema::table('vendor_employees', function (Blueprint $table): void {
            $table->string('auth_token')->nullable();
        });

        DB::table('vendors')->where('id', 1)->update([
            'auth_token' => 'owner-token',
        ]);
        DB::table('vendor_employees')->insert([
            'id' => 7,
            'f_name' => 'Fixture',
            'l_name' => 'Employee',
            'email' => 'fixture-employee@example.test',
            'employee_role_id' => 1,
            'vendor_id' => 1,
            'restaurant_id' => 6,
            'password' => 'not-used',
            'status' => 1,
            'auth_token' => 'employee-token',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_known_vendor_types_reach_the_next_middleware_with_the_expected_identity(): void
    {
        foreach ([
            ['owner', 'owner-token', Vendor::class, null],
            ['employee', 'employee-token', Vendor::class, VendorEmployee::class],
        ] as [$vendorType, $token, $vendorClass, $employeeClass]) {
            $request = $this->request($token, $vendorType);
            $reached = false;

            $response = (new VendorTokenIsValid)->handle(
                $request,
                function (Request $resolved) use (&$reached, $vendorClass, $employeeClass) {
                    $reached = true;
                    $this->assertInstanceOf($vendorClass, $resolved['vendor']);

                    if ($employeeClass === null) {
                        $this->assertNull($resolved['vendor_employee']);
                    } else {
                        $this->assertInstanceOf($employeeClass, $resolved['vendor_employee']);
                    }

                    return response()->noContent();
                }
            );

            $this->assertTrue($reached, "Known vendorType {$vendorType} did not reach the next middleware.");
            $this->assertSame(204, $response->getStatusCode());
        }
    }

    public function test_unknown_vendor_type_is_rejected_before_the_next_middleware(): void
    {
        $reached = false;

        $response = (new VendorTokenIsValid)->handle(
            $this->request('owner-token', 'partner'),
            function () use (&$reached) {
                $reached = true;

                return response()->noContent();
            }
        );

        $this->assertFalse($reached);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('vendor_type', $response->getData(true)['errors'][0]['code']);
    }

    public function test_missing_vendor_type_is_rejected_before_the_next_middleware(): void
    {
        $reached = false;

        $response = (new VendorTokenIsValid)->handle(
            $this->request('owner-token'),
            function () use (&$reached) {
                $reached = true;

                return response()->noContent();
            }
        );

        $this->assertFalse($reached);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('vendor_type', $response->getData(true)['errors'][0]['code']);
    }

    private function request(string $token, ?string $vendorType = null): Request
    {
        $request = Request::create('/vendor-type-probe', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$token);

        if ($vendorType !== null) {
            $request->headers->set('vendorType', $vendorType);
        }

        return $request;
    }
}
