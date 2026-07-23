<?php

namespace Tests\Unit;

use App\Console\Commands\NezhaSeedProductOptions20260723;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class NezhaProductFormRedesignContractTest extends TestCase
{
    public function test_chaart_manifest_uses_verified_absolute_prices_and_non_negative_deltas(): void
    {
        require_once dirname(__DIR__, 2).'/app/Console/Commands/NezhaSeedProductOptions20260723.php';

        $manifest = (new ReflectionClass(NezhaSeedProductOptions20260723::class))
            ->getConstant('CHAART_SIZE_PRICES');

        $this->assertCount(33, $manifest);
        $this->assertSame(['M', 'L', 'XL'], array_keys($manifest['波霸奶茶']));
        $this->assertSame(['M', 'L'], array_keys($manifest['浮云鲜牛乳']));
        $this->assertSame(['M', 'L'], array_keys($manifest['美式咖啡']));
        $this->assertSame(['M', 'L'], array_keys($manifest['青沫观音']));

        foreach ($manifest as $foodName => $prices) {
            $this->assertArrayHasKey('M', $prices, "{$foodName} must have an M base");
            foreach ($prices as $size => $absolutePrice) {
                $this->assertGreaterThanOrEqual(
                    $prices['M'],
                    $absolutePrice,
                    "{$foodName}/{$size} would create a negative option_price"
                );
            }
        }
    }

    public function test_add_and_edit_pages_share_one_form_and_four_card_partial(): void
    {
        $root = dirname(__DIR__, 2);
        $add = file_get_contents($root.'/resources/views/vendor-views/product/index.blade.php');
        $edit = file_get_contents($root.'/resources/views/vendor-views/product/edit.blade.php');
        $form = file_get_contents($root.'/resources/views/vendor-views/product/partials/_form-redesign.blade.php');

        $this->assertStringContainsString("product.partials._form-redesign'", $add);
        $this->assertStringContainsString("product.partials._form-redesign'", $edit);
        $this->assertStringContainsString('id="food_form"', $form);
        $this->assertStringContainsString('① 基础信息', $form);
        $this->assertStringContainsString('② 价格与规格', $form);
        $this->assertStringContainsString('③ 加料', $form);
        $this->assertStringContainsString('④ 高级设置', $form);
        $this->assertStringContainsString("'isRequired' => false", $form);
        $this->assertStringNotContainsString('required>简短描述', $form);
        $this->assertStringContainsString('data-variation-template="cup"', $form);
        $this->assertStringContainsString('data-variation-template="portion"', $form);
        $this->assertStringContainsString('data-variation-template="spicy"', $form);
        $this->assertStringContainsString('data-variation-template="custom"', $form);
    }

    public function test_form_script_supports_all_required_variation_actions(): void
    {
        $root = dirname(__DIR__, 2);
        $script = file_get_contents(
            $root.'/resources/views/vendor-views/product/partials/_form-redesign-scripts.blade.php'
        );

        foreach ([
            'nz-remove-variation',
            'nz-remove-option',
            'data-add-option',
            'data-variation-template',
            'nz-choice-mode',
            'removedVariationIDs',
            'removedVariationOptionIDs',
        ] as $contract) {
            $this->assertStringContainsString($contract, $script);
        }
    }
}
