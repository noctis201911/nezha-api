<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\LocalLifeController;
use App\Models\LocalLifeMerchant;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class LocalLifeServiceSummaryTest extends TestCase
{
    public function test_it_returns_a_plain_first_sentence_without_repeating_the_merchant_name(): void
    {
        $merchant = new LocalLifeMerchant();
        $merchant->forceFill([
            'name' => '雅顺移民',
            'intro' => '<strong>雅顺移民</strong>提供亚美尼亚签证、居留、公司注册及材料翻译服务。后续详情不进入列表。',
        ]);

        $this->assertSame(
            '提供亚美尼亚签证、居留、公司注册及材料翻译服务',
            $this->summaryFor($merchant)
        );
    }

    public function test_it_returns_null_for_an_empty_intro(): void
    {
        $merchant = new LocalLifeMerchant();
        $merchant->forceFill(['name' => '空简介商家', 'intro' => '   ']);

        $this->assertNull($this->summaryFor($merchant));
    }

    private function summaryFor(LocalLifeMerchant $merchant): ?string
    {
        $method = new ReflectionMethod(LocalLifeController::class, 'serviceSummary');
        $method->setAccessible(true);

        return $method->invoke(new LocalLifeController(), $merchant);
    }
}
