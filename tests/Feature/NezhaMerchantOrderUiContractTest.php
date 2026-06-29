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
