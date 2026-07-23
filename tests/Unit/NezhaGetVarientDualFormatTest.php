<?php

namespace Tests\Unit;

use App\CentralLogics\Helpers;
use PHPUnit\Framework\TestCase;

/**
 * get_varient 双格式规格定价回归 —— 挂 pre-push 门禁的「墙」。
 *
 * 背景 (0723 · commit 9fb25ce1 · release 20260723-044550-9fb25ce · L3):
 * get_varient 原只认移动端旧版 variation.values 结构 ['label' => [...]]; 哪吒 H5 新版
 * [{label, optionPrice, isSelected}, ...] 会走到 $variation['values']['label'], 旧代码
 * `in_array($option['label'], $variation['values']['label'])` 因 'label' 键不存在拿到 null,
 * 触发 TypeError ("Undefined array key label" → in_array(str, null)) 抛 500, 同时打断
 * nezha-quote 预览与 H5 规格菜下单(存量缺陷, 非当次引入)。修法新增
 * Helpers::nezha_selected_variation_labels() 归一化两格式。
 *
 * 审计员 0723 标注: 既有 pre-push 门禁(NezhaTieredCouponParityTest 等)全部用 variations=[],
 * 完全不覆盖 get_varient 的改动分支 —— 这条 money 路径此前无回归保护。本套件补上。
 *
 * get_varient / nezha_selected_variation_labels 是纯静态函数(只用 data_get / filter_var /
 * 原生数组函数, 不碰 DB / 模型 / facade), 故走 tests/Unit 纯单测: plain
 * PHPUnit\Framework\TestCase, 不 boot Laravel, 无需 DB / RefreshDatabase。
 *
 * 🔴 定价主权在服务端: 价格一律按第 1 参 $product_variations(服务端 DB option)的 optionPrice
 *    累加; 第 2 参 $variations(客户端提交)里的 optionPrice 永不参与计价(L1 平台不碰钱 / 服务端定价)。
 *
 * 🛡️ setUp 守卫: 归一化辅助函数缺失(工作树尚未合入 9fb25ce1)时 markTestSkipped, 避免误伤
 *    落后于 origin/main 的共享工作树上"无关分支"的 push —— 该门禁装在共享 git-common hooks
 *    目录, 对所有 worktree 生效。origin/main(生产真相源)已含该函数, 门禁在其上完整运行。
 */
class NezhaGetVarientDualFormatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! method_exists(Helpers::class, 'nezha_selected_variation_labels')) {
            $this->markTestSkipped(
                'nezha_selected_variation_labels 缺失(工作树落后于含 9fb25ce1 的 origin/main); '
                . '跳过以免误伤陈旧共享工作树的无关 push。生产源 origin/main 已含此函数, 门禁在其上完整运行。'
            );
        }
    }

    /**
     * 服务端菜品规格定义($product_variations / 第 1 参)。
     * 结构与 Helpers::product_data_formatting 下发一致: name + values[]{label, optionPrice, option_id}。
     *   份量: 标准份(+0, id=101) / 大份(+500, id=102)
     *   加料: 荷包蛋(+300, id=201)
     */
    private function productVariations(): array
    {
        return [
            [
                'name'   => '份量',
                'type'   => 'single',
                'values' => [
                    ['label' => '标准份', 'optionPrice' => 0,   'option_id' => 101],
                    ['label' => '大份',   'optionPrice' => 500, 'option_id' => 102],
                ],
            ],
            [
                'name'   => '加料',
                'type'   => 'multiple',
                'values' => [
                    ['label' => '荷包蛋', 'optionPrice' => 300, 'option_id' => 201],
                ],
            ],
        ];
    }

    /** ① H5 新格式: 按 isSelected 只累加被选中项的服务端 optionPrice(选加价项真加价 / 不选不加)。 */
    public function test_h5_new_format_sums_only_selected_option_prices(): void
    {
        // 选「大份」(+500), 不选「标准份」; 不选「荷包蛋」。
        $variations = [
            [
                'name'   => '份量',
                'values' => [
                    ['label' => '标准份', 'optionPrice' => 0,   'isSelected' => false],
                    ['label' => '大份',   'optionPrice' => 500, 'isSelected' => true],
                ],
            ],
            [
                'name'   => '加料',
                'values' => [
                    ['label' => '荷包蛋', 'optionPrice' => 300, 'isSelected' => false],
                ],
            ],
        ];

        $out = Helpers::get_varient($this->productVariations(), $variations);

        $this->assertSame(500, (int) $out['price'], '仅「大份」被选 → 只加 500');
        $this->assertSame([102], $out['optionIds'], '只回选中项的 option_id');
        $this->assertCount(1, $out['variations'][0]['values'], '命中的份量组只保留「大份」一项');
        $this->assertSame('大份', $out['variations'][0]['values'][0]['label']);

        // 反向: 改选「标准份」(+0), 不选「大份」→ 价格 0(不加价项不加价)。
        $variations[0]['values'][0]['isSelected'] = true;
        $variations[0]['values'][1]['isSelected'] = false;
        $out = Helpers::get_varient($this->productVariations(), $variations);
        $this->assertSame(0, (int) $out['price'], '仅「标准份」(+0)被选 → 不加价');
        $this->assertSame([101], $out['optionIds']);

        // 跨组累加: 「大份」(+500) + 「荷包蛋」(+300) → 800。
        $variations[0]['values'][0]['isSelected'] = false;
        $variations[0]['values'][1]['isSelected'] = true;
        $variations[1]['values'][0]['isSelected'] = true;
        $out = Helpers::get_varient($this->productVariations(), $variations);
        $this->assertSame(800, (int) $out['price'], '大份 500 + 荷包蛋 300 = 800');
        $this->assertSame([102, 201], $out['optionIds']);
    }

    /** ② 移动端旧格式 values.label: 与 9fb25ce1 修复前对同一输入字节级等价(选中项 + 服务端价累加)。 */
    public function test_legacy_mobile_format_label_list_is_byte_equivalent(): void
    {
        // 旧结构: values => ['label' => [选中的 label...]]。
        $variations = [
            ['name' => '份量', 'values' => ['label' => ['大份']]],
            ['name' => '加料', 'values' => ['label' => ['荷包蛋']]],
        ];

        $out = Helpers::get_varient($this->productVariations(), $variations);

        // 修复前: in_array($option['label'], $variation['values']['label']) 命中「大份」「荷包蛋」
        //         → 500 + 300 = 800, optionIds = [102, 201]。归一化后对旧格式输出完全一致。
        $this->assertSame(800, (int) $out['price']);
        $this->assertSame([102, 201], $out['optionIds']);
        $this->assertSame('大份',  $out['variations'][0]['values'][0]['label']);
        $this->assertSame('荷包蛋', $out['variations'][1]['values'][0]['label']);

        // 只选「标准份」(+0)。
        $out = Helpers::get_varient($this->productVariations(), [
            ['name' => '份量', 'values' => ['label' => ['标准份']]],
        ]);
        $this->assertSame(0, (int) $out['price']);
        $this->assertSame([101], $out['optionIds']);
    }

    /** ③ 缺 label / 畸形项一律跳过, 不抛错(H5 新格式的防御)。 */
    public function test_malformed_or_missing_label_items_are_skipped_without_error(): void
    {
        $variations = [
            [
                'name'   => '份量',
                'values' => [
                    ['optionPrice' => 500, 'isSelected' => true],       // 缺 label → 跳过
                    'garbage-scalar',                                    // 非数组 → 跳过
                    null,                                                // null → 跳过
                    ['label' => ['嵌套非标量'], 'isSelected' => true],    // label 非标量 → 跳过
                    ['label' => '大份', 'isSelected' => true],            // 唯一有效选中项 → 命中 +500
                ],
            ],
        ];

        $out = Helpers::get_varient($this->productVariations(), $variations);

        $this->assertSame(500, (int) $out['price'], '畸形项跳过, 只「大份」命中');
        $this->assertSame([102], $out['optionIds']);
    }

    /** ④ 客户端谎报 optionPrice 被忽略, 只用服务端 DB option 价(L1 服务端定价)。 */
    public function test_client_reported_option_price_is_ignored_server_price_wins(): void
    {
        // 客户端把「大份」谎报成 optionPrice=0(想白嫖加价), 「标准份」谎报成 99999。
        $variations = [
            [
                'name'   => '份量',
                'values' => [
                    ['label' => '标准份', 'optionPrice' => 99999, 'isSelected' => false],
                    ['label' => '大份',   'optionPrice' => 0,     'isSelected' => true],
                ],
            ],
        ];

        $out = Helpers::get_varient($this->productVariations(), $variations);

        // 服务端「大份」= 500; 客户端谎报的 0 被忽略。回填的 option 也是服务端那一份。
        $this->assertSame(500, (int) $out['price'], '价按服务端 DB(500), 客户端谎报的 0 被忽略');
        $this->assertSame([102], $out['optionIds']);
        $this->assertSame(500, (int) $out['variations'][0]['values'][0]['optionPrice'], '回填 option 用服务端价');

        // 旧格式同理: 客户端只发 label, 价永远来自服务端。
        $out = Helpers::get_varient($this->productVariations(), [
            ['name' => '份量', 'values' => ['label' => ['大份']]],
        ]);
        $this->assertSame(500, (int) $out['price']);
    }
}
