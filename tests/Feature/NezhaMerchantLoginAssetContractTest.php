<?php

namespace Tests\Feature;

use Tests\TestCase;

class NezhaMerchantLoginAssetContractTest extends TestCase
{
    public function testMerchantLoginFaviconUsesThePublicStorageUrl(): void
    {
        $blade = file_get_contents(resource_path('views/auth/login.blade.php'));

        $this->assertStringContainsString(
            "dynamicStorage('storage/app/public/business/'.\$icon)",
            $blade
        );
        $this->assertStringNotContainsString(
            "asset(\$icon ? 'storage/app/public/business/'",
            $blade
        );
    }
}

