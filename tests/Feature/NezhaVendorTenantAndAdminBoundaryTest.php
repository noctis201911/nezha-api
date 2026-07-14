<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 哪吒商家租户边界与管理员登录入口的结构化回归门。
 *
 * 这些断言刻意不连数据库：生产测试配置没有隔离测试库，安全门不能冒险写生产数据。
 * 真实跨店 404 / 同店可用性在部署前后另用只读探针核对。
 */
class NezhaVendorTenantAndAdminBoundaryTest extends TestCase
{
    private function source(string $relativePath): string
    {
        $source = file_get_contents(base_path($relativePath));
        $this->assertNotFalse($source, "无法读取 {$relativePath}");

        return $source;
    }

    private function methodBody(string $relativePath, string $method): string
    {
        $source = $this->source($relativePath);
        $startPattern = '/\bfunction\s+'.preg_quote($method, '/').'\s*\(/';
        $this->assertSame(1, preg_match($startPattern, $source, $match, PREG_OFFSET_CAPTURE), "未找到 {$relativePath}::{$method}()");

        $start = $match[0][1];
        $tail = substr($source, $start + strlen($match[0][0]));
        if (preg_match('/\b(?:public|protected|private)?\s*function\s+\w+\s*\(/', $tail, $next, PREG_OFFSET_CAPTURE)) {
            return substr($source, $start, strlen($match[0][0]) + $next[0][1]);
        }

        return substr($source, $start);
    }

    private function assertMethodContains(string $file, string $method, array $needles): void
    {
        $body = $this->methodBody($file, $method);
        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $body, "{$file}::{$method}() 缺少租户边界：{$needle}");
        }
    }

    public function test_subscription_and_wallet_paths_are_scoped_to_the_current_restaurant(): void
    {
        $file = 'app/Http/Controllers/Vendor/SubscriptionController.php';

        $this->assertMethodContains($file, 'switchToCommission', [
            'Helpers::get_restaurant_id()',
            "->where('id', \$restaurantId)",
            '->firstOrFail()',
        ]);
        $this->assertMethodContains($file, 'packageView', [
            'Helpers::get_restaurant_id()',
            "Restaurant::where('id', \$restaurantId)->firstOrFail()",
        ]);
        $this->assertMethodContains($file, 'packageBuy', [
            'Rule::in([$restaurantId])',
            "Restaurant::where('id', \$restaurantId)->firstOrFail(['id', 'vendor_id'])",
        ]);
        $this->assertMethodContains($file, 'invoice', [
            "->where('restaurant_id', Helpers::get_restaurant_id())",
            '->findOrFail($id)',
        ]);
    }

    public function test_order_settings_campaign_and_subscription_details_are_tenant_scoped(): void
    {
        $this->assertMethodContains('app/Http/Controllers/Vendor/OrderController.php', 'add_delivery_man', [
            '$restaurantId = Helpers::get_restaurant_id()',
            "->where('restaurant_id', \$restaurantId)",
            '->findOrFail($order_id)',
            '->findOrFail($delivery_man_id)',
        ]);
        $this->assertMethodContains('app/Http/Controllers/Vendor/OrderController.php', 'status', [
            "'restaurant_id' => Helpers::get_restaurant_id()",
            '->firstOrFail()',
        ]);
        foreach (['add_dine_in_table_number', 'update', 'update_shipping'] as $method) {
            $this->assertMethodContains('app/Http/Controllers/Vendor/OrderController.php', $method, [
                "abort_unless((int) \$order->restaurant_id === (int) Helpers::get_restaurant_id(), 404)",
            ]);
        }
        $this->assertMethodContains('app/Http/Controllers/Vendor/OrderController.php', 'edit', [
            "Order::where('restaurant_id', Helpers::get_restaurant_id())",
            '->firstOrFail()',
        ]);
        $this->assertMethodContains('app/Http/Controllers/Vendor/BusinessSettingsController.php', 'restaurant_status', [
            'Helpers::get_restaurant_id()',
            "abort_unless((int) \$restaurant->id === (int) \$restaurantId, 404)",
            '$allowedMenus',
            "abort_unless(in_array((string) \$request->menu, \$allowedMenus, true), 404)",
        ]);
        $this->assertMethodContains('app/Http/Controllers/Vendor/BusinessSettingsController.php', 'restaurant_setup', [
            "abort_unless((int) \$restaurant->id === (int) Helpers::get_restaurant_id(), 404)",
        ]);
        $this->assertMethodContains('app/Http/Controllers/Vendor/BusinessSettingsController.php', 'updateOpeningClosingStatus', [
            "abort_unless((int) \$id === (int) \$restaurant->id, 404)",
            "RestaurantConfig::firstOrNew(['restaurant_id' => \$restaurant->id])",
        ]);

        $campaign = 'app/Http/Controllers/Vendor/CampaignController.php';
        $this->assertMethodContains($campaign, 'list', [
            'Helpers::get_restaurant_id()',
            "->where('restaurants.id', \$restaurantId)",
        ]);
        foreach (['remove_restaurant', 'addrestaurant'] as $method) {
            $this->assertMethodContains($campaign, $method, [
                'Helpers::get_restaurant_id()',
                "abort_unless((int) \$restaurant === (int) \$restaurantId, 404)",
                "Restaurant::where('id', \$restaurantId)",
            ]);
        }
        foreach (['view', 'status'] as $method) {
            $this->assertMethodContains($campaign, $method, [
                "where('restaurant_id', Helpers::get_restaurant_id())",
            ]);
        }

        $this->assertMethodContains('app/Http/Controllers/Vendor/OrderSubscriptionController.php', 'show', [
            'Helpers::get_restaurant_id()',
            "abort_unless((int) \$subscription->restaurant_id === (int) \$restaurantId, 404)",
        ]);
    }

    public function test_admin_password_submit_has_a_basic_auth_covered_route_and_role_allowlists(): void
    {
        $routeSource = $this->source('routes/web.php');
        $this->assertStringContainsString("Route::post('login/admin-submit', [LoginController::class, 'submitAdmin'])->name('admin.login_post')", $routeSource);

        $route = app('router')->getRoutes()->getByName('admin.login_post');
        $this->assertNotNull($route, '管理员独立 POST 登录路由未注册');
        $this->assertSame('login/admin-submit', $route->uri());
        $this->assertSame(['POST'], $route->methods());

        $controller = 'app/Http/Controllers/LoginController.php';
        $this->assertMethodContains($controller, 'submit', [
            "['vendor', 'vendor_employee']",
            'submitForRoles',
        ]);
        $this->assertMethodContains($controller, 'submitAdmin', [
            "['admin', 'admin_employee']",
            'submitForRoles',
        ]);
        $this->assertMethodContains($controller, 'submitForRoles', [
            "'role' => ['required', Rule::in(\$allowedRoles)]",
        ]);

        $this->assertStringContainsString("route('admin.login_post')", $this->source('resources/views/auth/admin-login.blade.php'));
        $this->assertStringContainsString("['admin', 'admin_employee']", $this->source('resources/views/auth/login.blade.php'));
        $this->assertStringContainsString("route('admin.login_post')", $this->source('resources/views/auth/login.blade.php'));
    }

    public function test_public_vendor_submit_rejects_admin_roles_before_password_verification(): void
    {
        $this->withoutMiddleware();

        foreach (['admin', 'admin_employee'] as $role) {
            $response = $this->from('/login/restaurant')->post('/login_submit', [
                'email' => 'boundary-test@example.com',
                'password' => 'not-a-real-password',
                'role' => $role,
            ]);

            $response->assertRedirect('/login/restaurant');
            $response->assertSessionHasErrors('role');
        }
    }

    public function test_admin_submit_rejects_vendor_roles_before_password_verification(): void
    {
        $this->withoutMiddleware();

        foreach (['vendor', 'vendor_employee'] as $role) {
            $response = $this->from('/login/admin')->post('/login/admin-submit', [
                'email' => 'boundary-test@example.com',
                'password' => 'not-a-real-password',
                'role' => $role,
            ]);

            $response->assertRedirect('/login/admin');
            $response->assertSessionHasErrors('role');
        }
    }
}
