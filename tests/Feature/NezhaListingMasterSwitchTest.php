<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaListing;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 哪吒[外卖TG化 Phase1·挂牌态] 总闸契约墙。
 *
 * 守两件事：
 *  ① 行为：总闸关 = 逐店开关一律失效（回到功能上线前）；键缺失 = 按关处理。
 *  ② 结构：全后端只许经 NezhaListing 判断「是不是挂牌态」。
 *     漏一处就是前后端分叉 —— 前端按「不是挂牌店」渲染出加购/结算入口，后端 403 硬闸却仍按挂牌店拒单，
 *     顾客加得进购物车、点下单被拒、页面上又没有 TG 联系入口 = 死胡同。
 *     故这里把「允许出现 nezha_listing_only 字面量的文件」钉成白名单：新增读取点会让本测试红，
 *     逼作者要么改走 NezhaListing，要么显式登记进白名单并说明理由。
 */
class NezhaListingMasterSwitchTest extends TestCase
{
    /**
     * 允许直接出现 nezha_listing_only 字面量的文件（相对 app/）。
     * 每一条都要说明「为什么它可以不经 NezhaListing」。
     */
    private const RAW_COLUMN_ALLOWLIST = [
        // 判定单点本体：唯一允许读原始列值的地方
        'CentralLogics/NezhaListing.php',
        // 读取点：读的是 NezhaListing::isListingOnly() 算好的有效值再回写给前端 payload
        'CentralLogics/Helpers.php',
        // 读取点：SQL 层的直链放行，已由 NezhaListing::enabled() 包住
        'CentralLogics/RestaurantLogic.php',
        // 模型 cast 声明
        'Models/Restaurant.php',
        // Food::scopeActive($allowListed) —— 放宽与否由调用方传入（调用方已走 NezhaListing）
        'Models/Food.php',
        // 后台写入路径：运营翻逐店开关，本就该读写原始列（总闸另有其表现）
        'Http/Controllers/Admin/NezhaListingController.php',
    ];

    /** 这些文件必须引用 NezhaListing —— 它们是把挂牌态透出给顾客/拦下单的关键路径 */
    private const MUST_USE_HELPER = [
        'CentralLogics/Helpers.php',
        'CentralLogics/RestaurantLogic.php',
        'CentralLogics/ProductLogic.php',
        'Http/Controllers/Api/V1/OrderController.php',
        'Http/Controllers/Api/V1/RestaurantController.php',
    ];

    /** 去掉 // # /* 注释，只留真代码（注释里写「nezha_listing_only」不该被判成读取点） */
    private function stripPhpComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    private function setSwitch(?string $value): void
    {
        if (! Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->string('key');
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        DB::table('business_settings')->where('key', NezhaListing::SWITCH_KEY)->delete();
        if ($value !== null) {
            DB::table('business_settings')->insert(['key' => NezhaListing::SWITCH_KEY, 'value' => $value]);
        }

        NezhaListing::flushCache();
    }

    public function test_master_switch_off_neutralizes_per_store_flag(): void
    {
        $listedStore = (object) ['nezha_listing_only' => 1];

        $this->setSwitch('0');
        $this->assertFalse(NezhaListing::enabled(), '总闸=0 应判关');
        $this->assertFalse(
            NezhaListing::isListingOnly($listedStore),
            '总闸关时逐店开关必须失效，否则会出现「前端放行下单、后端 403 拒单」的死胡同'
        );

        $this->setSwitch('1');
        $this->assertTrue(NezhaListing::enabled(), '总闸=1 应判开');
        $this->assertTrue(NezhaListing::isListingOnly($listedStore), '总闸开 + 逐店开 = 挂牌态');
    }

    public function test_missing_switch_row_defaults_to_off(): void
    {
        $this->setSwitch(null);

        $this->assertFalse(NezhaListing::enabled(), '键缺失（迁移未跑）必须按关处理，降级方向安全');
        $this->assertFalse(NezhaListing::isListingOnly((object) ['nezha_listing_only' => 1]));
    }

    public function test_accepts_array_null_and_absent_column(): void
    {
        $this->setSwitch('1');

        $this->assertTrue(NezhaListing::isListingOnly(['nezha_listing_only' => 1]));
        $this->assertFalse(NezhaListing::isListingOnly(['nezha_listing_only' => 0]));
        $this->assertFalse(NezhaListing::isListingOnly([]), '列不存在（迁移未跑）不得 fatal，按非挂牌处理');
        $this->assertFalse(NezhaListing::isListingOnly(null));
    }

    public function test_no_unregistered_raw_column_read_sites(): void
    {
        $found = [];
        $appDir = base_path('app');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appDir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            // 只看真代码：注释里提到列名（本次几处补丁的说明注释就会提到）不算读取点
            $contents = $this->stripPhpComments(file_get_contents($file->getPathname()));
            if (str_contains($contents, 'nezha_listing_only')) {
                $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($appDir) + 1));
            }
        }

        sort($found);
        $allowed = self::RAW_COLUMN_ALLOWLIST;
        sort($allowed);

        $this->assertSame(
            $allowed,
            $found,
            "出现了未登记的 nezha_listing_only 直读点。挂牌态判断必须走 NezhaListing::isListingOnly()，"
            . "否则总闸会漏管这一处，产生前后端分叉的死胡同。确有理由直读的，把文件加进 RAW_COLUMN_ALLOWLIST 并写明原因。"
        );
    }

    public function test_customer_facing_paths_go_through_helper(): void
    {
        foreach (self::MUST_USE_HELPER as $relative) {
            $path = base_path('app/' . $relative);
            $this->assertFileExists($path);
            $this->assertStringContainsString(
                'NezhaListing',
                file_get_contents($path),
                "{$relative} 是挂牌态的顾客侧关键路径，必须经 NezhaListing 判断（总闸才管得住它）"
            );
        }
    }
}
