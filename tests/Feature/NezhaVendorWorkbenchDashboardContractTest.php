<?php

namespace Tests\Feature;

use Tests\TestCase;

class NezhaVendorWorkbenchDashboardContractTest extends TestCase
{
    public function testWorkbenchDisplaysCnyAndUsdConversionHints(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Vendor/WorkbenchController.php'));
        $body = file_get_contents(resource_path('views/vendor-views/workbench/_body.blade.php'));

        $this->assertStringContainsString("'amount_usd'", $controller);
        $this->assertStringContainsString("'refund_usd'", $controller);
        $this->assertStringContainsString('$rateUsd', $controller);

        $this->assertStringContainsString('$r[\'amount_usd\']', $body);
        $this->assertStringContainsString('$r[\'refund_usd\']', $body);
        $this->assertStringContainsString('$todayUsd', $body);
        $this->assertStringContainsString('/ $', $body);
    }

    public function testVendorDashboardPromoCardIsRemovedFromMainDashboard(): void
    {
        $blade = file_get_contents(resource_path('views/vendor-views/dashboard.blade.php'));

        $this->assertStringNotContainsString('Want_to_get_highlighted?', $blade);
        $this->assertStringNotContainsString('Create_ads_to_get_highlighted_on_the_app_and_web_browser', $blade);
        $this->assertStringNotContainsString("route('vendor.advertisement.create')", $blade);
    }

    public function testPreorderTimeIsVisibleBeforeMerchantConfirmsPayment(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Vendor/WorkbenchController.php'));
        $workbench = file_get_contents(resource_path('views/vendor-views/workbench/_body.blade.php'));
        $list = file_get_contents(resource_path('views/vendor-views/order/list.blade.php'));
        $detail = file_get_contents(resource_path('views/vendor-views/order/order-view.blade.php'));
        $detailModes = file_get_contents(resource_path('views/vendor-views/order/partials/_detail_modes.blade.php'));

        $this->assertStringContainsString("'schedule_label'", $controller);
        $this->assertStringContainsString('$r[\'schedule_label\']', $workbench);
        $this->assertStringContainsString('预约送达 · {{ $__scheduleLabel }}', $list);
        $this->assertStringContainsString('不要按即时单立刻出餐', $detail);
        $this->assertStringContainsString('本单预约送达：', $detail);
        $this->assertStringContainsString('data-nz-confirm-msg', $detailModes);
        $this->assertStringContainsString('$nzScheduleLabel', $detailModes);
        $this->assertStringNotContainsString("data-nz-auto-print-action=\"{{ \$nzOffPending ? '1' : '0' }}\" @if (\$nzPrimary['confirm']) onsubmit=", $detailModes);
    }
}
