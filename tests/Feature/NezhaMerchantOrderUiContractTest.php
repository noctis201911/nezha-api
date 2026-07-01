<?php

namespace Tests\Feature;

use Tests\TestCase;

class NezhaMerchantOrderUiContractTest extends TestCase
{
    public function testMerchantOrderListKeepsSimpleDesignAndFastActions(): void
    {
        $blade = file_get_contents(resource_path('views/vendor-views/order/list.blade.php'));

        $this->assertStringContainsString('下一步操作', $blade);
        $this->assertStringContainsString('打印小票', $blade);
        $this->assertStringContainsString('订单详情', $blade);
        $this->assertStringContainsString('Helpers::mask_phone', $blade);
        $this->assertStringContainsString('nz-order-step-form', $blade);
        $this->assertStringContainsString('nzAutoPrintReady', $blade);
        $this->assertStringNotContainsString("translate('messages.delivery_type')</th>", $blade);
        $this->assertStringNotContainsString('text-capitalze opacity-7', $blade);
        $this->assertStringNotContainsString("translate('messages.delivery')}}</span>", $blade);
    }

    public function testMerchantOrderSubStatusPagesHaveCompleteOperatorGuidance(): void
    {
        $blade = file_get_contents(resource_path('views/vendor-views/order/list.blade.php'));

        $this->assertStringContainsString('$nzStatusMeta', $blade);
        foreach ([
            'offline_pending',
            'refund_pending',
            'pending',
            'confirmed',
            'cooking',
            'ready_for_delivery',
            'food_on_the_way',
            'delivered',
            'refunded',
            'refund_requested',
            'scheduled',
            'payment_failed',
            'canceled',
        ] as $status) {
            $this->assertStringContainsString("'{$status}'", $blade);
        }

        $this->assertStringContainsString('nz-status-tabs', $blade);
        $this->assertStringContainsString('nz-status-empty-copy', $blade);
        $this->assertStringContainsString('nz-order-status-cell', $blade);
        $this->assertStringContainsString("route('vendor.order.mark-refunded'", $blade);
        $this->assertStringContainsString('查看详情处理退款申请', $blade);
        $this->assertStringContainsString('订单已关闭', $blade);
    }

    public function testMerchantOrderMobileLayoutIsBuiltForFastOperation(): void
    {
        $blade = file_get_contents(resource_path('views/vendor-views/order/list.blade.php'));

        $this->assertStringContainsString('nz-mobile-toolbar', $blade);
        $this->assertStringContainsString('nz-mobile-print-toggle', $blade);
        $this->assertStringContainsString('nz-order-mobile-actions', $blade);
        $this->assertStringContainsString('nz-order-primary-line', $blade);
        $this->assertStringContainsString('nz-mobile-status-strip', $blade);
        $this->assertStringContainsString('nz-mobile-action-label', $blade);
    }

    public function testMerchantOrderTablePutsDetailsBeforePrintAction(): void
    {
        $blade = file_get_contents(resource_path('views/vendor-views/order/list.blade.php'));

        $detailHeader = strpos($blade, '>订单详情</th>');
        $printHeader = strpos($blade, '>打印小票</th>');
        $detailCell = strpos($blade, '<td class="text-center nz-detail-action-cell"');
        $printCell = strpos($blade, '<td class="text-center nz-print-action-cell"');

        $this->assertNotFalse($detailHeader);
        $this->assertNotFalse($printHeader);
        $this->assertNotFalse($detailCell);
        $this->assertNotFalse($printCell);
        $this->assertLessThan($printHeader, $detailHeader);
        $this->assertLessThan($printCell, $detailCell);
    }

    public function testMerchantOrderTableSupportsOperatorTableControls(): void
    {
        $blade = file_get_contents(resource_path('views/vendor-views/order/list.blade.php'));

        $exportArea = strpos($blade, 'nz-export-area');
        $searchArea = strpos($blade, 'nz-search-area');

        $this->assertNotFalse($exportArea);
        $this->assertNotFalse($searchArea);
        $this->assertLessThan($searchArea, $exportArea);
        $this->assertStringContainsString('.nz-order-toolbar { display: flex; align-items: center; justify-content: flex-start;', $blade);
        $this->assertStringNotContainsString('.nz-search-area { flex: 0 1 360px; margin-left: auto;', $blade);
        $this->assertStringContainsString('nz-resizable-table', $blade);
        $this->assertStringContainsString('nz-col-resizer', $blade);
        $this->assertStringContainsString('nzOrderColumnWidths', $blade);
    }

    public function testMerchantOrderTableShowsCurrencyHintsAndPaymentProofThumbnail(): void
    {
        $blade = file_get_contents(resource_path('views/vendor-views/order/list.blade.php'));

        $this->assertStringContainsString("business_settings", $blade);
        $this->assertStringContainsString("nezha_rate_cny_to_amd", $blade);
        $this->assertStringContainsString("nezha_rate_usd_to_amd", $blade);
        $this->assertStringNotContainsString('Currency::whereIn', $blade);
        $this->assertStringContainsString('nz-order-converted-amounts', $blade);
        $this->assertStringContainsString("'CNY'", $blade);
        $this->assertStringContainsString("'USD'", $blade);
        $this->assertLessThan(strpos($blade, "'USD'"), strpos($blade, "'CNY'"));
        $this->assertStringContainsString('offline_payment_formater', $blade);
        $this->assertStringContainsString('nz-payment-proof-thumb', $blade);
        $this->assertStringContainsString('nzProofModal', $blade);
    }

    public function testMerchantOrderListSupportsCustomerNudgeStatus(): void
    {
        $blade = file_get_contents(resource_path('views/vendor-views/order/list.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Vendor/OrderController.php'));

        $this->assertStringContainsString("'customer_nudged'", $blade);
        $this->assertStringContainsString('客户催促', $blade);
        $this->assertStringContainsString('NezhaCustomerNudge::openOrderIds', $controller);
        $this->assertStringContainsString("'customer_nudged'", $controller);
    }

    public function testMerchantOrderSidebarShowsAllStatusesWithoutMoreFold(): void
    {
        $blade = file_get_contents(resource_path('views/layouts/vendor/partials/_sidebar.blade.php'));

        $this->assertStringNotContainsString('nzSidebarMore', $blade);
        $this->assertStringNotContainsString('更多', $blade);

        foreach ([
            'refunded',
            'refund_requested',
            'scheduled',
            'payment_failed',
            'canceled',
        ] as $status) {
            $this->assertStringContainsString("restaurant-panel/order/list/{$status}", $blade);
            $this->assertStringContainsString("route('vendor.order.list',['{$status}'])", $blade);
        }
    }

    public function testMerchantOrderSidebarKeepsCustomerNudgeAboveOfflinePendingWithAlarmBadge(): void
    {
        $blade = file_get_contents(resource_path('views/layouts/vendor/partials/_sidebar.blade.php'));

        $customerNudge = strpos($blade, "restaurant-panel/order/list/customer_nudged");
        $offlinePending = strpos($blade, "restaurant-panel/order/list/offline_pending");

        $this->assertNotFalse($customerNudge);
        $this->assertNotFalse($offlinePending);
        $this->assertLessThan($offlinePending, $customerNudge);
        $this->assertStringContainsString('客户催促', $blade);
        $this->assertStringContainsString('nz-customer-nudge-alert', $blade);
        $this->assertStringContainsString('nz-customer-nudge-badge', $blade);
        $this->assertStringContainsString('@keyframes nzNudgeBadgePulse', $blade);
        $this->assertStringContainsString('NezhaCustomerNudge::count', $blade);
    }

    public function testRestaurantLoginLandsOnResponsiveOrderList(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/LoginController.php'));

        $this->assertStringContainsString("\$data == 'vendor' && \$request->role == 'vendor'", $controller);
        $this->assertStringContainsString("redirect()->route('vendor.order.list", $controller);
        $this->assertStringContainsString("['all']", $controller);
    }

    public function testMerchantOrderDetailSupportsGuardedAutoPrintAfterStateChange(): void
    {
        $blade = file_get_contents(resource_path('views/vendor-views/order/order-view.blade.php'));

        $this->assertStringContainsString('nzMaybeAutoPrintAfterOrderAction', $blade);
        $this->assertStringContainsString('data-nz-auto-print-invoice', $blade);
    }

    public function testStandardReceiptTemplateProtectsPrivacyAndPrintsAutomaticallyWhenRequested(): void
    {
        $blade = file_get_contents(resource_path('views/new_invoice.blade.php'));

        $this->assertStringContainsString('哪吒标准小票模板', $blade);
        $this->assertStringContainsString('Helpers::mask_phone', $blade);
        $this->assertStringContainsString('nz_auto_print=1', $blade);
        $this->assertStringContainsString('window.print()', $blade);
    }
}
