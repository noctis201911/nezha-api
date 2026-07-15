<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaBackofficeEmailBoundary;
use App\Rules\UniqueBackofficeEmail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 后台身份邮箱隔离回归门。
 *
 * 只覆盖 admins / vendors / vendor_employees；顾客 users 不属于后台身份边界。
 * 测试强制运行在 sqlite :memory:，不会读写生产账号。
 */
class NezhaBackofficeEmailBoundaryTest extends TestCase
{
    use DatabaseTransactions;

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

    public function test_existing_admin_vendor_and_vendor_employee_emails_are_rejected_case_insensitively(): void
    {
        DB::table('admins')->insert([
            'f_name' => 'Boundary',
            'email' => 'Admin-Boundary@Example.com',
            'password' => 'not-used',
            'role_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('vendors')->insert([
            'f_name' => 'Boundary',
            'phone' => '+4915700000101',
            'email' => 'Vendor-Boundary@Example.com',
            'password' => 'not-used',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('vendor_employees')->insert([
            'f_name' => 'Boundary',
            'email' => 'Employee-Boundary@Example.com',
            'employee_role_id' => 1,
            'vendor_id' => 1,
            'restaurant_id' => 1,
            'password' => 'not-used',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            ' admin-boundary@example.COM ',
            ' vendor-boundary@example.COM ',
            ' employee-boundary@example.COM ',
        ] as $email) {
            $this->assertTrue(NezhaBackofficeEmailBoundary::conflicts($email));

            $validator = Validator::make(['email' => $email], [
                'email' => ['required', 'email', new UniqueBackofficeEmail()],
            ]);
            $this->assertTrue($validator->fails(), "后台身份邮箱应被隔离：{$email}");
        }
    }

    public function test_update_can_keep_its_own_email_but_cannot_take_another_role_email(): void
    {
        $adminId = DB::table('admins')->insertGetId([
            'f_name' => 'Boundary',
            'email' => 'keep-own@example.com',
            'password' => 'not-used',
            'role_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(NezhaBackofficeEmailBoundary::conflicts(' KEEP-OWN@EXAMPLE.COM ', 'admins', $adminId));

        DB::table('vendors')->insert([
            'f_name' => 'Boundary',
            'phone' => '+4915700000102',
            'email' => 'keep-own@example.com',
            'password' => 'not-used',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue(NezhaBackofficeEmailBoundary::conflicts('keep-own@example.com', 'admins', $adminId));
    }

    public function test_new_email_is_allowed_and_error_message_explains_the_boundary(): void
    {
        $this->assertSame('fresh-backoffice@example.com', NezhaBackofficeEmailBoundary::normalize(' Fresh-Backoffice@Example.COM '));

        $validator = Validator::make(['email' => 'fresh-backoffice@example.com'], [
            'email' => ['required', 'email', new UniqueBackofficeEmail()],
        ]);

        $this->assertFalse($validator->fails());
        $this->assertStringContainsString('管理员、商家或商家员工', (new UniqueBackofficeEmail())->message());
    }

    public function test_all_operational_account_write_paths_use_the_shared_boundary(): void
    {
        $rulePaths = [
            ['app/Http/Controllers/Admin/VendorController.php', 'store'],
            ['app/Http/Controllers/Admin/VendorController.php', 'update'],
            ['app/Http/Controllers/Admin/EmployeeController.php', 'store'],
            ['app/Http/Controllers/Admin/EmployeeController.php', 'update'],
            ['app/Http/Controllers/Admin/SystemController.php', 'settings_update'],
            ['app/Http/Controllers/Vendor/EmployeeController.php', 'store'],
            ['app/Http/Controllers/Vendor/EmployeeController.php', 'update'],
            ['app/Http/Controllers/VendorController.php', 'store'],
            ['app/Http/Controllers/Api/V1/Auth/VendorLoginController.php', 'register'],
        ];

        foreach ($rulePaths as [$file, $method]) {
            $this->assertStringContainsString(
                'new UniqueBackofficeEmail(',
                $this->methodBody($file, $method),
                "{$file}::{$method}() 未接统一后台身份邮箱隔离规则"
            );
        }

        $this->assertStringContainsString(
            'NezhaBackofficeEmailBoundary::conflicts(',
            $this->methodBody('app/Http/Controllers/Admin/VendorController.php', 'bulk_import_data'),
            '商家批量导入未接统一后台身份邮箱隔离规则'
        );
    }

    public function test_public_and_app_self_registration_stay_closed_before_validation(): void
    {
        foreach ([
            ['app/Http/Controllers/VendorController.php', 'store'],
            ['app/Http/Controllers/Api/V1/Auth/VendorLoginController.php', 'register'],
        ] as [$file, $method]) {
            $body = $this->methodBody($file, $method);
            $togglePosition = strpos($body, "toggle_restaurant_registration");
            $validatorPosition = strpos($body, 'Validator::make');

            $this->assertNotFalse($togglePosition, "{$file}::{$method}() 缺少商家自助注册关闭开关");
            $this->assertNotFalse($validatorPosition, "{$file}::{$method}() 缺少请求校验");
            $this->assertLessThan($validatorPosition, $togglePosition, "{$file}::{$method}() 必须先拒绝关闭的自助注册，再处理注册数据");
        }
    }
}
