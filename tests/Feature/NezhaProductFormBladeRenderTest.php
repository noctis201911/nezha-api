<?php

namespace Tests\Feature;

use App\Models\Food;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class NezhaProductFormBladeRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('DOMAIN_POINTED_DIRECTORY')) {
            define('DOMAIN_POINTED_DIRECTORY', 'public');
        }
    }

    public function test_add_form_partial_renders_with_only_the_three_core_fields_required(): void
    {
        config(['currency_symbol' => '֏']);

        $html = view('vendor-views.product.partials._form-redesign', [
            'categories' => new Collection,
            'addons' => new Collection,
            'nutritions' => new Collection,
            'allergies' => new Collection,
            'restaurantId' => 42,
            'productWiseTax' => false,
            'taxVats' => new Collection,
        ])->render();

        $this->assertStringContainsString('id="food_form"', $html);
        $this->assertStringContainsString('name="name[]"', $html);
        $this->assertStringContainsString('name="category_id"', $html);
        $this->assertStringContainsString('name="price"', $html);
        $this->assertStringContainsString('data-variation-template="cup"', $html);
        $this->assertStringContainsString('商品图（选填', $html);
        $this->assertStringContainsString('每日开售（选填', $html);
        $this->assertStringContainsString('每日停售（选填', $html);
        $this->assertStringNotContainsString('name="description[]" required', $html);
        $this->assertStringNotContainsString('name="image" required', $html);
    }

    public function test_rendered_form_script_is_valid_javascript(): void
    {
        config(['currency_symbol' => '֏']);

        $html = view('vendor-views.product.partials._form-redesign-scripts', [
            'isEdit' => false,
        ])->render();
        $javascript = preg_replace('/^\\s*<script>|<\\/script>\\s*$/', '', $html);

        $process = new Process(['node', '--check', '-']);
        $process->setInput($javascript);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            $process->getErrorOutput()
        );
    }

    public function test_edit_form_partial_renders_the_same_structure_with_existing_values(): void
    {
        config(['currency_symbol' => '֏']);

        $product = new Food;
        $product->forceFill([
            'id' => 564,
            'name' => '波霸奶茶',
            'description' => '',
            'category_ids' => json_encode([['id' => 12, 'position' => 1]]),
            'add_ons' => '[]',
            'variations' => '[]',
            'price' => 1500,
            'discount' => 0,
            'discount_type' => 'percent',
            'available_time_starts' => null,
            'available_time_ends' => null,
            'stock_type' => 'unlimited',
            'item_stock' => 0,
            'maximum_cart_quantity' => 0,
            'veg' => 1,
            'is_halal' => 0,
            'status' => 1,
            'image' => null,
        ]);
        $product->syncOriginal();
        foreach ([
            'translations',
            'newVariations',
            'newVariationOptions',
            'nutritions',
            'allergies',
            'tags',
        ] as $relation) {
            $product->setRelation($relation, new Collection);
        }

        $html = view('vendor-views.product.partials._form-redesign', [
            'product' => $product,
            'categories' => collect([(object) ['id' => 12, 'name' => '茶饮甜品']]),
            'addons' => new Collection,
            'nutritions' => new Collection,
            'allergies' => new Collection,
            'restaurantId' => 42,
            'productWiseTax' => false,
            'taxVats' => new Collection,
            'taxVatIds' => [],
        ])->render();

        $this->assertStringContainsString('id="food_form"', $html);
        $this->assertStringContainsString('value="波霸奶茶"', $html);
        $this->assertStringContainsString('value="1500"', $html);
        $this->assertStringContainsString('保存修改', $html);
    }
}
