<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 商家端剩余对象级权限面的结构化回归门。
 *
 * 不连接生产数据库；部署前后再用只读/零写入探针验证真实跨店样本。
 */
class NezhaVendorTenantSurfaceSweepTest extends TestCase
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
        $pattern = '/\bfunction\s+'.preg_quote($method, '/').'\s*\(/';
        $this->assertSame(1, preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE), "未找到 {$relativePath}::{$method}()");

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
            $this->assertStringContainsString($needle, $body, "{$file}::{$method}() 缺少边界：{$needle}");
        }
    }

    public function test_banner_and_wallet_mutations_use_the_authenticated_tenant(): void
    {
        $banner = 'app/Http/Controllers/Vendor/BannerController.php';
        $this->assertMethodContains($banner, 'status', [
            'Helpers::get_restaurant_id()',
            'in_array($restaurantId, $restaurantIds',
        ]);
        $this->assertStringNotContainsString('$restaurant_id = $request->status', $this->methodBody($banner, 'status'));

        $wallet = 'app/Http/Controllers/Vendor/WalletController.php';
        $this->assertMethodContains($wallet, 'w_request', [
            "DisbursementWithdrawalMethod::where('restaurant_id', Helpers::get_restaurant_id())",
        ]);
        $this->assertMethodContains($wallet, 'close_request', [
            "WithdrawRequest::where('vendor_id', Helpers::get_vendor_id())",
            '->findOrFail($id)',
        ]);
        $this->assertMethodContains($wallet, 'make_payment', [
            'Rule::in([$restaurantId])',
            'Restaurant::where(\'id\', $restaurantId)->firstOrFail()',
        ]);
    }

    public function test_delivery_man_reads_and_chat_targets_are_restaurant_scoped(): void
    {
        $delivery = 'app/Http/Controllers/Vendor/DeliveryManController.php';
        $this->assertMethodContains($delivery, 'reviews_list', [
            "whereHas('delivery_man'",
            "where('restaurant_id', Helpers::get_restaurant_id())",
        ]);
        $this->assertMethodContains($delivery, 'preview', [
            "where('restaurant_id', Helpers::get_restaurant_id())",
            '->firstOrFail()',
            'DMReview::where(\'delivery_man_id\', $dm->id)',
        ]);
        $this->assertMethodContains($delivery, 'get_account_data', [
            'abort_unless((int) $deliveryman->restaurant_id === (int) Helpers::get_restaurant_id(), 404)',
        ]);
        foreach (['edit', 'status', 'earning', 'update', 'delete'] as $method) {
            $this->assertMethodContains($delivery, $method, [
                "where('restaurant_id'",
                '->findOrFail(',
            ]);
        }

        $this->assertMethodContains('app/Http/Controllers/Vendor/ConversationController.php', 'store', [
            "in_array(\$user_type, ['admin', 'user', 'delivery_man'], true)",
            "DeliveryMan::where('restaurant_id', Helpers::get_restaurant_id())",
            '->findOrFail($user_id)',
        ]);
    }

    public function test_employee_roles_and_pos_customer_data_cannot_cross_restaurants(): void
    {
        $employee = 'app/Http/Controllers/Vendor/EmployeeController.php';
        foreach (['store', 'update'] as $method) {
            $this->assertMethodContains($employee, $method, [
                "Rule::exists('employee_roles', 'id')",
                "where('restaurant_id', Helpers::get_restaurant_id())",
            ]);
        }
        foreach (['edit', 'update'] as $method) {
            $this->assertMethodContains($employee, $method, [
                "where('restaurant_id', Helpers::get_restaurant_id())",
            ]);
        }
        $this->assertMethodContains($employee, 'edit', ['->firstOrFail()']);
        $this->assertMethodContains($employee, 'update', ['->findOrFail($id)']);

        $pos = 'app/Http/Controllers/Vendor/POSController.php';
        $this->assertMethodContains($pos, 'get_customers', [
            "whereHas('orders'",
            'where(\'restaurant_id\', $restaurantId)',
        ]);
        $this->assertMethodContains($pos, 'customer_store', [
            'Session::put(\'customer_id\', $customer->id)',
        ]);
        $this->assertMethodContains($pos, 'addDeliveryInfo', [
            "Session::get('customer_id')",
            'abort_unless($customerId > 0 && (int) Session::get(\'customer_id\') === $customerId, 404)',
            '->where(\'user_id\', $customerId)',
            '->firstOrFail()',
        ]);
    }

    public function test_vendor_reports_scope_transactions_customers_and_campaign_labels(): void
    {
        $report = 'app/Http/Controllers/Vendor/ReportController.php';
        $this->assertMethodContains($report, 'generate_statement', [
            "whereHas('order'",
            "where('restaurant_id', Helpers::get_restaurant_id())",
            '->findOrFail($id)',
        ]);
        $this->assertMethodContains($report, 'customerForCurrentRestaurant', [
            "whereHas('orders'",
            "where('restaurant_id', Helpers::get_restaurant_id())",
            '->firstOrFail()',
        ]);
        foreach (['order_report', 'order_report_export', 'campaign_order_report', 'campaign_report_export'] as $method) {
            $this->assertMethodContains($report, $method, [
                '$this->customerForCurrentRestaurant($customer_id)',
            ]);
        }
        $this->assertMethodContains($report, 'campaign_report_export', [
            'ItemCampaign::where(\'restaurant_id\', $restaurant_id)',
        ]);
        $this->assertStringNotContainsString('Helpers::get_customer_name($customer_id)', $this->methodBody($report, 'order_report_export'));
        $this->assertStringNotContainsString('Helpers::get_customer_name($customer_id)', $this->methodBody($report, 'campaign_report_export'));
    }
}
