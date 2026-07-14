<?php

namespace Tests\Feature;

use Tests\TestCase;

class NezhaMerchantOrderUiContractTest extends TestCase
{
    public function testMerchantOrderListKeepsSimpleDesignAndFastActions(): void
    {
        $blade = file_get_contents(resource_path('views/vendor-views/order/list.blade.php'));
        $contactVisibility = file_get_contents(app_path('CentralLogics/NezhaContactVisibility.php'));

        $this->assertStringContainsString('下一步操作', $blade);
        $this->assertStringContainsString('打印小票', $blade);
        $this->assertStringContainsString('订单详情', $blade);
        $this->assertStringContainsString('NezhaContactVisibility::phone', $blade);
        $this->assertStringContainsString('Helpers::mask_phone', $contactVisibility);
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
        $this->assertStringContainsString('td[data-label="订单"] { display: block; width: 100% !important;', $blade);
    }

    public function testMerchantOrderTableKeepsDetailsBeforePrintInTheMoreMenu(): void
    {
        $blade = file_get_contents(resource_path('views/vendor-views/order/list.blade.php'));

        $moreHeader = strpos($blade, '>更多</th>');
        $moreCell = strpos($blade, '<td class="text-center nz-row-more-cell"');
        $detailAction = strpos($blade, 'id="nzMenuDetail"');
        $printAction = strpos($blade, 'id="nzMenuInvoice"');

        $this->assertNotFalse($moreHeader);
        $this->assertNotFalse($moreCell);
        $this->assertNotFalse($detailAction);
        $this->assertNotFalse($printAction);
        $this->assertLessThan($printAction, $detailAction);
        $this->assertStringContainsString("route('vendor.order.details'", $blade);
        $this->assertStringContainsString("route('vendor.order.generate-invoice'", $blade);
    }

    public function testSingleMerchantInvoiceReturnsNotFoundForAnotherRestaurantsOrder(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Vendor/OrderController.php'));
        $methodStart = strpos($controller, 'public function generate_invoice($id)');
        $batchMethodStart = strpos($controller, 'public function generate_invoice_batch(', $methodStart);

        $this->assertNotFalse($methodStart);
        $this->assertNotFalse($batchMethodStart);

        $method = substr($controller, $methodStart, $batchMethodStart - $methodStart);
        $guard = strpos($method, 'abort_unless($order, 404);');
        $invoiceSettings = strpos($method, '$invoiceSettings = DataSetting::invoiceSettings();');

        $this->assertStringContainsString("'restaurant_id' => Helpers::get_restaurant_id()", $method);
        $this->assertNotFalse($guard);
        $this->assertNotFalse($invoiceSettings);
        $this->assertTrue($guard < $invoiceSettings);
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
        $this->assertStringContainsString('nz-payment-proof-list--status', $blade);
        $this->assertStringContainsString('nzProofModal', $blade);
        $this->assertStringContainsString('window.nzOpenPaymentProof', $blade);
        $this->assertStringContainsString("window.jQuery(modal).modal('show')", $blade);
        $this->assertStringNotContainsString("$('#nzProofModal').modal('show')", $blade);

        $statusCell = strpos($blade, '<td class="text-capitalize text-center nz-order-status-cell"');
        $proofList = strpos($blade, '<div class="nz-payment-proof-list nz-payment-proof-list--status">');
        $statusBranch = strpos($blade, "@if(\$order['order_status']=='pending')", $statusCell);

        $this->assertNotFalse($statusCell);
        $this->assertNotFalse($proofList);
        $this->assertNotFalse($statusBranch);
        $this->assertLessThan($proofList, $statusCell);
        $this->assertLessThan($statusBranch, $proofList);
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

    public function testMerchantOrderSidebarUsesOneActionCountedOrderEntry(): void
    {
        $blade = file_get_contents(resource_path('views/layouts/vendor/partials/_sidebar.blade.php'));

        $this->assertStringNotContainsString('nzSidebarMore', $blade);
        $this->assertStringContainsString('NezhaOrderCounts::forRestaurant', $blade);
        $this->assertStringContainsString("route('vendor.order.list', ['grp_action'])", $blade);
        $this->assertStringContainsString('nz-order-action-badge', $blade);

        foreach ([
            'refunded',
            'refund_requested',
            'scheduled',
            'payment_failed',
            'canceled',
        ] as $status) {
            $this->assertStringNotContainsString("restaurant-panel/order/list/{$status}", $blade);
            $this->assertStringNotContainsString("route('vendor.order.list',['{$status}'])", $blade);
        }
    }

    public function testMerchantOrderPageKeepsCustomerNudgeInTheActionGroup(): void
    {
        $list = file_get_contents(resource_path('views/vendor-views/order/list.blade.php'));
        $sidebar = file_get_contents(resource_path('views/layouts/vendor/partials/_sidebar.blade.php'));

        $this->assertStringContainsString("'chips' => ['offline_pending', 'customer_nudged', 'timeout'", $list);
        $this->assertStringContainsString("'customer_nudged' => 'grp_action'", $list);
        $this->assertStringContainsString('客户催促', $list);
        $this->assertStringContainsString('NezhaOrderCounts::forRestaurant', $sidebar);
        $this->assertStringNotContainsString('restaurant-panel/order/list/customer_nudged', $sidebar);
    }

    public function testRestaurantLoginReturnsToVendorDashboardByDefault(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/LoginController.php'));

        $this->assertStringNotContainsString("\$data == 'vendor' && \$request->role == 'vendor'", $controller);
        $this->assertStringNotContainsString("redirect()->route('vendor.order.list", $controller);
        $this->assertStringContainsString("if(\$data == 'vendor' )", $controller);
        $this->assertStringContainsString("return redirect()->route('vendor.dashboard');", $controller);
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
        $receiptBody = file_get_contents(resource_path('views/nz_receipt_body.blade.php'));

        $this->assertStringContainsString('哪吒标准小票模板', $blade);
        $this->assertStringContainsString("@include('nz_receipt_body'", $blade);
        $this->assertStringContainsString('Helpers::mask_phone', $receiptBody);
        $this->assertStringContainsString('nz_auto_print=1', $blade);
        $this->assertStringContainsString('window.print()', $blade);
    }
}
