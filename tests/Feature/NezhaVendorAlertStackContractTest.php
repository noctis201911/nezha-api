<?php

namespace Tests\Feature;

use Tests\TestCase;

class NezhaVendorAlertStackContractTest extends TestCase
{
    public function testVendorOrderAlertsShareOneNonOverlappingStack(): void
    {
        $blade = file_get_contents(resource_path('views/layouts/vendor/app.blade.php'));

        $this->assertStringContainsString('id="nz-alert-stack"', $blade);
        $this->assertStringContainsString('#nz-alert-stack', $blade);
        $this->assertStringContainsString('flex-direction:column-reverse', preg_replace('/\s+/', '', $blade));
        $this->assertStringContainsString('#nz-alert-stack > #nz-new-order-toast', $blade);
        $this->assertStringContainsString('#nz-alert-stack > #nz-timeout-toast', $blade);

        $newOrderToast = $this->extractToastMarkup($blade, 'nz-new-order-toast');
        $timeoutToast = $this->extractToastMarkup($blade, 'nz-timeout-toast');

        $this->assertStringNotContainsString('position:fixed', $newOrderToast);
        $this->assertStringNotContainsString('bottom:', $newOrderToast);
        $this->assertStringNotContainsString('position:fixed', $timeoutToast);
        $this->assertStringNotContainsString('bottom:', $timeoutToast);
    }

    private function extractToastMarkup(string $blade, string $id): string
    {
        $pattern = '/<div id="' . preg_quote($id, '/') . '"[^>]*>/';
        $this->assertMatchesRegularExpression($pattern, $blade);
        preg_match($pattern, $blade, $matches);

        return $matches[0] ?? '';
    }
}
