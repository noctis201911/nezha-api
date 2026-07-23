<?php

namespace Tests\Feature;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Controllers\MerchantTwoFactorController;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\EmployeeRole;
use App\Models\Vendor;
use App\Models\VendorEmployee;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression coverage for the latent admin/vendor authorization cluster and
 * the remaining H-2 cross-restaurant proof download.
 *
 * Safety: phpunit.xml pins SQLite :memory:. The restricted admin and vendor
 * employee are temporary rows and are explicitly deleted in each denial test.
 */
class NezhaLatentAuthorizationClusterTest extends TestCase
{
    private string $viewStubRoot;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('DOMAIN_POINTED_DIRECTORY')) {
            define('DOMAIN_POINTED_DIRECTORY', 'public');
        }
        $this->viewStubRoot = sys_get_temp_dir().'/nezha-rbac-denial-view-'.getmypid();
        File::ensureDirectoryExists($this->viewStubRoot.'/admin-views/errors');
        File::put($this->viewStubRoot.'/admin-views/errors/no-permission.blade.php', 'permission denied {{ $module }}');
        $this->app['view']->getFinder()->prependLocation($this->viewStubRoot);

        foreach ([
            'orders',
            'shifts',
            'delivery_men',
            'restaurants',
            'vendors',
            'vendor_employees',
            'employee_roles',
            'admins',
            'admin_roles',
            'translations',
            'business_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('translations', function (Blueprint $table): void {
            $table->id();
            $table->string('translationable_type')->nullable();
            $table->unsignedBigInteger('translationable_id')->nullable();
            $table->string('locale')->nullable();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
        });
        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->text('modules')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('employee_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('restaurant_id')->nullable();
            $table->string('name')->nullable();
            $table->text('modules')->nullable();
            $table->timestamps();
        });
        Schema::create('vendor_employees', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('restaurant_id')->nullable();
            $table->unsignedBigInteger('employee_role_id');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('auth_token')->nullable();
            $table->boolean('status')->default(true);
            $table->unsignedInteger('auth_generation')->default(0);
            $table->boolean('two_factor_enabled')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('vendors', function (Blueprint $table): void {
            $table->id();
            $table->boolean('status')->default(true);
            $table->string('password')->nullable();
            $table->unsignedInteger('auth_generation')->default(0);
            $table->boolean('two_factor_enabled')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('restaurants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->boolean('status')->default(true);
            $table->string('restaurant_model')->default('commission');
            $table->timestamps();
        });
        Schema::create('delivery_men', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
        Schema::create('shifts', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('restaurant_id');
            $table->text('order_proof')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        auth('admin')->logout();
        auth('vendor')->logout();
        auth('vendor_employee')->logout();

        File::deleteDirectory($this->viewStubRoot);

        parent::tearDown();
    }

    public function test_admin_sensitive_routes_declare_the_approved_modules(): void
    {
        foreach ($this->adminRouteModules() as [$name, $module]) {
            $middleware = $this->namedRoute($name)->gatherMiddleware();
            $this->assertContains("module:$module", $middleware, $name);
        }

        $customerSettings = $this->namedRoute('admin.customer.update-settings')->gatherMiddleware();
        $this->assertNotContains('module:customerList', $customerSettings);

        $create = file_get_contents(resource_path('views/admin-views/custom-role/create.blade.php'));
        $edit = file_get_contents(resource_path('views/admin-views/custom-role/edit.blade.php'));
        $sidebar = file_get_contents(resource_path('views/layouts/admin/partials/_sidebar.blade.php'));
        $this->assertStringContainsString('value="consolidation"', $create);
        $this->assertStringContainsString('value="consolidation"', $edit);
        $this->assertStringContainsString("module_permission_check('consolidation')", $sidebar);
    }

    public function test_temporary_unrelated_admin_is_denied_by_every_new_gate_and_removed(): void
    {
        [$admin, $role] = $this->seedRestrictedAdmin(['chat']);
        $this->actingAs($admin, 'admin');
        $this->withoutMiddleware(ActivationCheckMiddleware::class);
        DB::table('delivery_men')->insert(['id' => 1]);
        DB::table('shifts')->insert(['id' => 1]);

        try {
            foreach ($this->adminRouteModules() as [$name, $module, $parameters]) {
                $route = $this->namedRoute($name);
                $response = $this->call($route->methods()[0], route($name, $parameters, false));
                $this->assertSame(403, $response->getStatusCode(), $name);
                $response->assertSee($module, false);
            }
        } finally {
            auth('admin')->logout();
            $admin->delete();
            $role->delete();
        }

        $this->assertDatabaseMissing('admins', ['id' => $admin->id]);
        $this->assertDatabaseMissing('admin_roles', ['id' => $role->id]);
    }

    public function test_vendor_app_routes_declare_the_web_equivalent_modules(): void
    {
        foreach ($this->vendorRouteModules() as [$method, $uri, $module]) {
            $route = $this->routeByMethodAndUri($method, $uri);
            $this->assertContains("vmodule:$module", $route->gatherMiddleware(), "$method $uri");
        }
    }

    public function test_temporary_unrelated_vendor_employee_hits_every_target_route_and_gets_403(): void
    {
        [$employee, $role] = $this->seedRestrictedVendorEmployee(['dashboard']);
        $this->withoutMiddleware(ActivationCheckMiddleware::class);
        $this->withHeaders([
            'Authorization' => 'Bearer '.$employee->auth_token,
            'vendorType' => 'employee',
            'Accept' => 'application/json',
        ]);

        try {
            foreach ($this->vendorRouteModules() as [$method, $uri, $module]) {
                $concreteUri = preg_replace('/\{[^}]+\}/', '1', $uri);
                $response = $this->json($method, '/'.$concreteUri);
                $this->assertSame(403, $response->getStatusCode(), "$method $uri");
                $this->assertSame(
                    'permission_denied',
                    $response->json('errors.0.code'),
                    "$method $uri"
                );
            }
        } finally {
            $employee->delete();
            $role->delete();
            DB::table('restaurants')->where('id', 100)->delete();
            DB::table('vendors')->where('id', 10)->delete();
        }

        $this->assertDatabaseMissing('vendor_employees', ['id' => $employee->id]);
        $this->assertDatabaseMissing('employee_roles', ['id' => $role->id]);
    }

    public function test_vendor_a_cannot_download_vendor_b_order_proof_even_with_exact_filename(): void
    {
        $vendorA = $this->vendorActor(10, 100);
        $this->actingAs($vendorA, 'vendor');
        $this->withSession([MerchantTwoFactorController::SESSION_GENERATION => 0]);

        DB::table('orders')->insert([
            [
                'id' => 1001,
                'restaurant_id' => 100,
                'order_proof' => json_encode([['img' => 'vendor-a-proof.png', 'storage' => 'public']]),
            ],
            [
                'id' => 2001,
                'restaurant_id' => 200,
                'order_proof' => json_encode([['img' => 'vendor-b-secret-proof.png', 'storage' => 'public']]),
            ],
        ]);

        $response = $this->get(route('vendor.file-manager.download', [
            2001,
            base64_encode('public/order/vendor-b-secret-proof.png'),
        ], false));
        $response->assertNotFound();
    }

    public function test_vendor_can_still_download_its_own_order_proof(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::fake('local');
        Storage::disk('local')->put('public/order/vendor-a-proof.png', 'proof');

        $vendorA = $this->vendorActor(10, 100);
        $this->actingAs($vendorA, 'vendor');
        $this->withSession([MerchantTwoFactorController::SESSION_GENERATION => 0]);
        DB::table('orders')->insert([
            'id' => 1001,
            'restaurant_id' => 100,
            'order_proof' => json_encode([['img' => 'vendor-a-proof.png', 'storage' => 'public']]),
        ]);

        $response = $this->get(route('vendor.file-manager.download', [
            1001,
            base64_encode('public/order/vendor-a-proof.png'),
        ], false));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_vendor_can_download_owned_s3_and_legacy_public_proofs(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::fake('local');
        Storage::disk('local')->put('order/vendor-a-s3-proof.png', 's3-proof');
        Storage::disk('local')->put('public/order/vendor-a-legacy-proof.png', 'legacy-proof');

        $vendorA = $this->vendorActor(10, 100);
        $this->actingAs($vendorA, 'vendor');
        $this->withSession([MerchantTwoFactorController::SESSION_GENERATION => 0]);
        DB::table('orders')->insert([
            [
                'id' => 1002,
                'restaurant_id' => 100,
                'order_proof' => json_encode([['img' => 'vendor-a-s3-proof.png', 'storage' => 's3']]),
            ],
            [
                'id' => 1003,
                'restaurant_id' => 100,
                'order_proof' => json_encode(['vendor-a-legacy-proof.png']),
            ],
        ]);

        $this->get($this->vendorDownloadUrl(1002, 'order/vendor-a-s3-proof.png'))
            ->assertOk();
        $this->get($this->vendorDownloadUrl(1003, 'public/order/vendor-a-legacy-proof.png'))
            ->assertOk();
    }

    public function test_vendor_employee_session_cannot_download_another_restaurants_proof(): void
    {
        [$employee, $role] = $this->seedRestrictedVendorEmployee(['dashboard']);
        $this->actingAs($employee, 'vendor_employee');
        $this->withSession([MerchantTwoFactorController::SESSION_GENERATION => 0]);
        DB::table('orders')->insert([
            'id' => 2002,
            'restaurant_id' => 200,
            'order_proof' => json_encode([['img' => 'vendor-b-employee-secret.png', 'storage' => 'public']]),
        ]);

        try {
            $this->get($this->vendorDownloadUrl(
                2002,
                'public/order/vendor-b-employee-secret.png'
            ))->assertNotFound();
        } finally {
            auth('vendor_employee')->logout();
            $employee->delete();
            $role->delete();
            DB::table('restaurants')->where('id', 100)->delete();
            DB::table('vendors')->where('id', 10)->delete();
        }

        $this->assertDatabaseMissing('vendor_employees', ['id' => $employee->id]);
        $this->assertDatabaseMissing('employee_roles', ['id' => $role->id]);
    }

    public function test_owned_order_rejects_wrong_proof_storage_and_malformed_paths(): void
    {
        $vendorA = $this->vendorActor(10, 100);
        $this->actingAs($vendorA, 'vendor');
        $this->withSession([MerchantTwoFactorController::SESSION_GENERATION => 0]);
        DB::table('orders')->insert([
            'id' => 1004,
            'restaurant_id' => 100,
            'order_proof' => json_encode([['img' => 'exact-s3-proof.png', 'storage' => 's3']]),
        ]);

        foreach ([
            'public/order/exact-s3-proof.png',
            'order/not-the-proof.png',
            'public/order/../exact-s3-proof.png',
            'public/order/nested/exact-s3-proof.png',
        ] as $path) {
            $this->get($this->vendorDownloadUrl(1004, $path))->assertNotFound();
        }

        $this->get(route('vendor.file-manager.download', [1004, '***'], false))
            ->assertNotFound();
    }

    private function adminRouteModules(): array
    {
        return [
            ['admin.export-account-transaction', 'account', []],
            ['admin.search-account-transaction', 'account', []],
            ['admin.nezha-consolidation.index', 'consolidation', []],
            ['admin.nezha-consolidation.export', 'consolidation', []],
            ['admin.nezha-consolidation.toggle-eligible', 'consolidation', ['id' => 1]],
            ['admin.nezha-consolidation.show', 'consolidation', ['id' => 1]],
            ['admin.nezha-consolidation-rounds.index', 'consolidation', []],
            ['admin.nezha-consolidation-rounds.create', 'consolidation', []],
            ['admin.nezha-consolidation-rounds.store', 'consolidation', []],
            ['admin.nezha-consolidation-rounds.edit', 'consolidation', ['id' => 1]],
            ['admin.nezha-consolidation-rounds.update', 'consolidation', ['id' => 1]],
            ['admin.nezha-consolidation-rounds.open', 'consolidation', ['id' => 1]],
            ['admin.nezha-consolidation-rounds.close', 'consolidation', ['id' => 1]],
            ['admin.nezha-consolidation-rounds.cancel', 'consolidation', ['id' => 1]],
            ['admin.nezha-consolidation-rounds.export', 'consolidation', ['id' => 1]],
            ['admin.nezha-consolidation-rounds.show', 'consolidation', ['id' => 1]],
            ['admin.export-deliveryman-earning', 'provide_dm_earning', []],
            ['admin.search-deliveryman-earning', 'provide_dm_earning', []],
            ['admin.maintenance-mode', 'settings', []],
            ['admin.delivery-man.get_account_data', 'deliveryman', ['deliveryman' => 1]],
            ['admin.customer.select-list', 'customerList', []],
            ['admin.customer.update-settings', 'settings', []],
            ['admin.apple-login.update', 'system_settings', ['service' => 'apple']],
            ['admin.shift.list', 'deliveryman', []],
            ['admin.shift.store', 'deliveryman', []],
            ['admin.shift.edit', 'deliveryman', ['id' => 1]],
            ['admin.shift.update', 'deliveryman', []],
            ['admin.shift.delete', 'deliveryman', ['shift' => 1]],
            ['admin.shift.status', 'deliveryman', ['id' => 1, 'status' => 1]],
        ];
    }

    private function vendorRouteModules(): array
    {
        return [
            ['POST', 'api/v1/vendor/update-active-status', 'restaurant_config'],
            ['PUT', 'api/v1/vendor/update-profile', 'bank_info'],
            ['PUT', 'api/v1/vendor/update-order-status', 'regular_order'],
            ['PUT', 'api/v1/vendor/update-announcment', 'my_restaurant'],
            ['POST', 'api/v1/vendor/make-collected-cash-payment', 'my_wallet'],
            ['POST', 'api/v1/vendor/make-wallet-adjustment', 'my_wallet'],
            ['GET', 'api/v1/vendor/coupon-list', 'coupon'],
            ['GET', 'api/v1/vendor/coupon-view', 'coupon'],
            ['POST', 'api/v1/vendor/coupon-store', 'coupon'],
            ['POST', 'api/v1/vendor/coupon-update', 'coupon'],
            ['POST', 'api/v1/vendor/coupon-status', 'coupon'],
            ['POST', 'api/v1/vendor/coupon-delete', 'coupon'],
            ['POST', 'api/v1/vendor/coupon-search', 'coupon'],
            ['GET', 'api/v1/vendor/coupon/view-without-translate', 'coupon'],
            ['GET', 'api/v1/vendor/advertisement', 'ads_list'],
            ['GET', 'api/v1/vendor/advertisement/details/{id}', 'ads_list'],
            ['DELETE', 'api/v1/vendor/advertisement/delete/{id}', 'ads_list'],
            ['POST', 'api/v1/vendor/advertisement/update/{id}', 'ads_list'],
            ['PUT', 'api/v1/vendor/advertisement/status', 'ads_list'],
            ['POST', 'api/v1/vendor/advertisement/store', 'new_ads'],
            ['POST', 'api/v1/vendor/advertisement/copy-add-post', 'new_ads'],
        ];
    }

    private function namedRoute(string $name): Route
    {
        $route = app('router')->getRoutes()->getByName($name);
        $this->assertNotNull($route, $name);
        return $route;
    }

    private function routeByMethodAndUri(string $method, string $uri): Route
    {
        foreach (app('router')->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }
        $this->fail("Route not found: $method $uri");
    }

    private function seedRestrictedAdmin(array $modules): array
    {
        $role = new AdminRole();
        $role->id = 200;
        $role->exists = false;
        $role->name = 'temporary-unrelated-role';
        $role->modules = json_encode($modules);
        $role->save();

        $admin = new Admin();
        $admin->role_id = $role->id;
        $admin->email = 'temporary-route-gate@example.invalid';
        $admin->password = 'not-used';
        $admin->save();
        $admin->setRelation('role', $role);

        return [$admin, $role];
    }

    private function seedRestrictedVendorEmployee(array $modules): array
    {
        $role = new EmployeeRole();
        $role->name = 'temporary-unrelated-vendor-role';
        $role->modules = json_encode($modules);
        $role->save();

        DB::table('vendors')->insert([
            'id' => 10,
            'status' => true,
        ]);
        DB::table('restaurants')->insert([
            'id' => 100,
            'vendor_id' => 10,
            'status' => true,
            'restaurant_model' => 'commission',
        ]);
        DB::table('business_settings')->insert([
            'key' => 'check_subscription_validity_on',
            'value' => date('Y-m-d'),
        ]);

        $employee = new VendorEmployee();
        $employee->employee_role_id = $role->id;
        $employee->vendor_id = 10;
        $employee->restaurant_id = 100;
        $employee->email = 'temporary-vendor-route-gate@example.invalid';
        $employee->password = 'not-used';
        $employee->auth_token = 'temporary-vendor-route-gate-token';
        $employee->status = true;
        $employee->save();
        $employee->setRelation('role', $role);

        return [$employee, $role];
    }

    private function vendorActor(int $vendorId, int $restaurantId): Vendor
    {
        DB::table('vendors')->updateOrInsert(
            ['id' => $vendorId],
            ['status' => true, 'auth_generation' => 0, 'two_factor_enabled' => false]
        );
        DB::table('restaurants')->updateOrInsert(
            ['id' => $restaurantId],
            [
                'vendor_id' => $vendorId,
                'status' => true,
                'restaurant_model' => 'commission',
            ]
        );

        return Vendor::findOrFail($vendorId);
    }

    private function vendorDownloadUrl(int $orderId, string $path): string
    {
        return route('vendor.file-manager.download', [
            $orderId,
            base64_encode($path),
        ], false);
    }
}
