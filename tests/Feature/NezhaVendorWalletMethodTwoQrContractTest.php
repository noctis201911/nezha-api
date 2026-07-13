<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class NezhaVendorWalletMethodTwoQrContractTest extends TestCase
{
    public function testMerchantPaymentPageRendersEachConfiguredUsdtNetworkIndependently(): void
    {
        $blade = file_get_contents(dirname(__DIR__, 2) . '/resources/views/vendor-views/wallet-method/index.blade.php');

        $this->assertStringContainsString('$hasTrc20 = !empty($restaurant?->usdt_address)', $blade);
        $this->assertStringContainsString('$hasBep20 = !empty($restaurant?->usdt_bep20_address)', $blade);
        $this->assertStringContainsString('$hasUsdt = $hasTrc20 || $hasBep20', $blade);
        $this->assertStringContainsString('@if ($hasTrc20)', $blade);
        $this->assertStringContainsString('@if ($hasBep20)', $blade);
    }

    public function testEachUsdtNetworkOwnsItsAddressAndQrCode(): void
    {
        $blade = file_get_contents(dirname(__DIR__, 2) . '/resources/views/vendor-views/wallet-method/index.blade.php');

        $this->assertSame(1, substr_count($blade, 'data-usdt-network="TRC20"'));
        $this->assertSame(1, substr_count($blade, 'data-usdt-network="BEP20"'));
        $this->assertStringContainsString('{{ $restaurant?->usdt_address }}', $blade);
        $this->assertStringContainsString('{{ $restaurant?->usdt_bep20_address }}', $blade);
        $this->assertSame(1, substr_count($blade, 'generate($restaurant->usdt_address)'));
        $this->assertSame(1, substr_count($blade, 'generate($restaurant->usdt_bep20_address)'));
    }
}
