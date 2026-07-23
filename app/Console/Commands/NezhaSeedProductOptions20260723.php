<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * 2026-07-23 商品规格补录。
 *
 * 数据来源：
 * - ChaArt 原始菜单照片（逐页复核 M/L/XL 绝对价）
 * - 武大卤原始菜单（另加卤蛋 +500）
 *
 * 闽南菜单只有“自选/预定/大小份”等展示文案，未给出可以安全合并现有
 * food 行的规格契约，本命令故意不猜测、不改写。
 */
class NezhaSeedProductOptions20260723 extends Command
{
    private const PRODUCTION_CONFIRMATION = 'NEZHA_PRODUCT_OPTIONS_20260723';

    protected $signature = 'nezha:seed-product-options-20260723
        {--apply : 执行写入；缺省只做可审计 dry-run}
        {--scope=all : all、chaart 或 wudalu}
        {--confirm= : production 写入的二次确认字串}';

    protected $description = '幂等补录 ChaArt 杯型规格与武大卤卤蛋加料（默认 dry-run）';

    /**
     * 绝对价来自菜单；落库时转换为 option_price 差价。
     *
     * @var array<string, array<string, int>>
     */
    private const CHAART_SIZE_PRICES = [
        '波霸奶茶' => ['M' => 1500, 'L' => 1850, 'XL' => 2050],
        '茉莉奶绿' => ['M' => 1500, 'L' => 1850, 'XL' => 2050],
        '烧仙草奶茶' => ['M' => 1700, 'L' => 2150, 'XL' => 2350],
        '黑糖珍珠奶茶' => ['M' => 1700, 'L' => 2150, 'XL' => 2350],
        '烤奶奶茶' => ['M' => 1700, 'L' => 2150, 'XL' => 2350],
        '宇治抹茶' => ['M' => 1750, 'L' => 2250, 'XL' => 2450],
        '香芋西米奶茶' => ['M' => 1750, 'L' => 2250, 'XL' => 2450],
        '美式巧克力奶茶' => ['M' => 1750, 'L' => 2250, 'XL' => 2450],
        '浮云鲜牛乳' => ['M' => 1500, 'L' => 1850],
        '蓬巴杜粉莓' => ['M' => 1500, 'L' => 1850],
        '手打柠檬红茶' => ['M' => 1450, 'L' => 1750, 'XL' => 1950],
        '手打柠檬绿茶' => ['M' => 1450, 'L' => 1750, 'XL' => 1950],
        '鸭屎香柠檬茶' => ['M' => 1450, 'L' => 1750, 'XL' => 1950],
        '芭乐柠檬茶' => ['M' => 1700, 'L' => 2150, 'XL' => 2350],
        '西瓜啵啵冰' => ['M' => 1650, 'L' => 2050, 'XL' => 2250],
        '百香果双重奏' => ['M' => 1650, 'L' => 2050, 'XL' => 2250],
        '多肉葡萄' => ['M' => 1850, 'L' => 2450, 'XL' => 2650],
        '满杯西柚' => ['M' => 1850, 'L' => 2450, 'XL' => 2650],
        '杨枝甘露' => ['M' => 2050, 'L' => 2650, 'XL' => 2850],
        '美式咖啡' => ['M' => 850, 'L' => 1200],
        '拿铁' => ['M' => 850, 'L' => 1200],
        '卡布奇诺' => ['M' => 850, 'L' => 1200],
        '生椰拿铁' => ['M' => 1250, 'L' => 1900],
        '冒险家拿铁' => ['M' => 1250, 'L' => 1600],
        '焦糖布丁拿铁' => ['M' => 1250, 'L' => 1600],
        '芝士茉莉绿茶' => ['M' => 1500, 'L' => 1850, 'XL' => 2050],
        '芝士幽兰红茶' => ['M' => 1500, 'L' => 1850, 'XL' => 2050],
        '芝士白桃乌龙' => ['M' => 1650, 'L' => 2050, 'XL' => 2250],
        '幽兰拿铁' => ['M' => 1650, 'L' => 1850],
        '茉海花开' => ['M' => 1650, 'L' => 1850],
        '声声乌龙' => ['M' => 1800, 'L' => 2050],
        '鸭屎知' => ['M' => 1800, 'L' => 2050],
        '青沫观音' => ['M' => 1800, 'L' => 2050],
    ];

    private int $createdVariations = 0;

    private int $createdOptions = 0;

    private int $createdAddons = 0;

    private int $attachedFoods = 0;

    private int $unchanged = 0;

    public function handle(): int
    {
        $scope = strtolower((string) $this->option('scope'));
        if (! in_array($scope, ['all', 'chaart', 'wudalu'], true)) {
            $this->error('--scope 只接受 all、chaart 或 wudalu');

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');
        if (
            $apply
            && app()->environment('production')
            && $this->option('confirm') !== self::PRODUCTION_CONFIRMATION
        ) {
            $this->error('production 写入已阻断；先 dry-run 审核，再显式提供 --confirm='.self::PRODUCTION_CONFIRMATION);

            return self::FAILURE;
        }

        DB::beginTransaction();

        try {
            if ($scope === 'all' || $scope === 'chaart') {
                $this->seedChaart();
            }
            if ($scope === 'all' || $scope === 'wudalu') {
                $this->seedWudalu();
            }

            if ($apply) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->error('ABORT：'.$exception->getMessage());

            return self::FAILURE;
        }

        $prefix = $apply ? '[APPLIED]' : '[DRY-RUN]';
        $this->info(sprintf(
            '%s variations +%d, options +%d, add-ons +%d, food attachments +%d, unchanged %d',
            $prefix,
            $this->createdVariations,
            $this->createdOptions,
            $this->createdAddons,
            $this->attachedFoods,
            $this->unchanged
        ));
        $this->line('闽南：未改。原菜单缺少可安全合并现有 food 行的规格契约，等待业务逐项确认。');

        return self::SUCCESS;
    }

    private function seedChaart(): void
    {
        $this->assertRestaurant(42, 'ChaArt 珍珠奶茶');

        $foods = DB::table('food')
            ->where('restaurant_id', 42)
            ->whereBetween('id', [564, 599])
            ->lockForUpdate()
            ->get(['id', 'name', 'price'])
            ->keyBy('name');

        if ($foods->count() !== 36) {
            throw new RuntimeException("ChaArt food 边界漂移：期望 restaurant=42 / id=564..599 共 36 行，实得 {$foods->count()} 行");
        }

        foreach (self::CHAART_SIZE_PRICES as $foodName => $absolutePrices) {
            $food = $foods->get($foodName);
            if (! $food) {
                throw new RuntimeException("ChaArt 缺少菜品：{$foodName}");
            }

            $basePrice = (float) $food->price;
            if (abs($basePrice - (float) $absolutePrices['M']) > 0.001) {
                throw new RuntimeException("{$foodName} 主价漂移：DB={$basePrice}，菜单 M={$absolutePrices['M']}");
            }

            $expectedOptions = [];
            foreach ($absolutePrices as $size => $absolutePrice) {
                $delta = $absolutePrice - $absolutePrices['M'];
                if ($delta < 0) {
                    throw new RuntimeException("{$foodName} {$size} 差价为负，拒绝写入");
                }
                $expectedOptions[$size] = (float) $delta;
            }

            $this->upsertExactSingleVariation((int) $food->id, '杯型', $expectedOptions);
        }

        $waffleNames = ['原味鸡蛋仔', '抹茶鸡蛋仔', '巧克力鸡蛋仔'];
        foreach ($waffleNames as $waffleName) {
            if (! $foods->has($waffleName)) {
                throw new RuntimeException("ChaArt 缺少无规格菜品：{$waffleName}");
            }
        }
    }

    /**
     * @param  array<string, float>  $expectedOptions
     */
    private function upsertExactSingleVariation(int $foodId, string $name, array $expectedOptions): void
    {
        $variations = DB::table('variations')
            ->where('food_id', $foodId)
            ->where('name', $name)
            ->lockForUpdate()
            ->get();

        if ($variations->count() > 1) {
            throw new RuntimeException("food={$foodId} 存在重复规格组 {$name}");
        }

        $variation = $variations->first();
        if (! $variation) {
            $variationId = DB::table('variations')->insertGetId([
                'food_id' => $foodId,
                'name' => $name,
                'type' => 'single',
                'min' => 1,
                'max' => 1,
                'is_required' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->createdVariations++;
        } else {
            if (
                $variation->type !== 'single'
                || (int) $variation->min !== 1
                || (int) $variation->max !== 1
                || (int) $variation->is_required !== 1
            ) {
                throw new RuntimeException("food={$foodId} 已有 {$name}，但选择规则与本批不一致");
            }
            $variationId = (int) $variation->id;
        }

        $existingOptions = DB::table('variation_options')
            ->where('variation_id', $variationId)
            ->lockForUpdate()
            ->get(['option_name', 'option_price', 'stock_type', 'total_stock'])
            ->keyBy('option_name');

        if ($existingOptions->isNotEmpty()) {
            if ($existingOptions->count() !== count($expectedOptions)) {
                throw new RuntimeException("food={$foodId} {$name} 已有选项数与菜单不一致");
            }
            foreach ($expectedOptions as $optionName => $optionPrice) {
                $existing = $existingOptions->get($optionName);
                if (
                    ! $existing
                    || abs((float) $existing->option_price - $optionPrice) > 0.001
                    || $existing->stock_type !== 'unlimited'
                    || (int) $existing->total_stock !== 0
                ) {
                    throw new RuntimeException("food={$foodId} {$name}/{$optionName} 与菜单不一致");
                }
            }
            $this->unchanged++;

            return;
        }

        foreach ($expectedOptions as $optionName => $optionPrice) {
            DB::table('variation_options')->insert([
                'food_id' => $foodId,
                'variation_id' => $variationId,
                'option_name' => $optionName,
                'option_price' => $optionPrice,
                'total_stock' => 0,
                'stock_type' => 'unlimited',
                'sell_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->createdOptions++;
        }
    }

    private function seedWudalu(): void
    {
        $this->assertRestaurant(40, '武大卤');

        $foods = DB::table('food')
            ->where('restaurant_id', 40)
            ->whereBetween('id', [513, 522])
            ->lockForUpdate()
            ->get(['id', 'add_ons']);

        if ($foods->count() !== 10) {
            throw new RuntimeException("武大卤 food 边界漂移：期望 restaurant=40 / id=513..522 共 10 行，实得 {$foods->count()} 行");
        }

        $addons = DB::table('add_ons')
            ->where('restaurant_id', 40)
            ->where('name', '卤蛋')
            ->lockForUpdate()
            ->get();

        if ($addons->count() > 1) {
            throw new RuntimeException('武大卤存在重复“卤蛋”加料');
        }

        $addon = $addons->first();
        if (! $addon) {
            $addonId = DB::table('add_ons')->insertGetId([
                'name' => '卤蛋',
                'price' => 500,
                'restaurant_id' => 40,
                'status' => 1,
                'stock_type' => 'unlimited',
                'addon_stock' => 0,
                'sell_count' => 0,
                'addon_category_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->createdAddons++;
        } else {
            if (abs((float) $addon->price - 500.0) > 0.001 || (int) $addon->status !== 1) {
                throw new RuntimeException('武大卤已有“卤蛋”加料，但价格或状态不是 +500/启用');
            }
            $addonId = (int) $addon->id;
        }

        foreach ($foods as $food) {
            $addonIds = json_decode((string) ($food->add_ons ?? '[]'), true);
            if (! is_array($addonIds)) {
                throw new RuntimeException("food={$food->id} add_ons 不是合法 JSON 数组");
            }
            $addonIds = array_values(array_unique(array_map('intval', $addonIds)));
            if (in_array($addonId, $addonIds, true)) {
                $this->unchanged++;

                continue;
            }

            $addonIds[] = $addonId;
            sort($addonIds);
            DB::table('food')->where('id', $food->id)->update([
                'add_ons' => json_encode($addonIds),
                'updated_at' => now(),
            ]);
            $this->attachedFoods++;
        }
    }

    private function assertRestaurant(int $restaurantId, string $expectedName): void
    {
        $restaurant = DB::table('restaurants')
            ->where('id', $restaurantId)
            ->lockForUpdate()
            ->first(['id', 'name']);

        if (! $restaurant || $restaurant->name !== $expectedName) {
            $actual = $restaurant?->name ?? 'missing';
            throw new RuntimeException("restaurant={$restaurantId} 身份不匹配：期望 {$expectedName}，实得 {$actual}");
        }
    }
}
