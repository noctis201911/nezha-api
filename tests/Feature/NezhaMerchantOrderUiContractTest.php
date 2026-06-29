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
