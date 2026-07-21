<?php

namespace App\CentralLogics;

use Illuminate\Support\Facades\DB;

/**
 * 哪吒[外卖TG化 Phase1·挂牌态] 总闸 + 「有效挂牌态」单点收口。
 *
 * 背景（2026-07-21 补齐 CHANGELOG 2026-07-20 登记的「已知缺口」）：
 * 挂牌态原本只有逐店 DB 字段 `restaurants.nezha_listing_only`，没有总开关——要全关只能 revert 代码 + 重新部署。
 *
 * 🔴 本类是**唯一**允许判断「这家店此刻是不是挂牌态」的地方。全后端读 `nezha_listing_only` 的点
 *    必须走 self::isListingOnly()，一处漏掉就会出现两半分叉：
 *    典型死胡同 = 前端拿到 listing_only=false 渲染出加购/结算入口，后端 403 硬闸却仍按挂牌店拒单
 *    （顾客能加购、点了下单被拒、且页面上没有任何 TG 联系入口可走）。
 *    契约由 tests/Feature/NezhaListingMasterSwitchTest.php 的白名单断言看守，新增读取点会让测试红。
 *
 * 当前全部读取点（改动时同步本清单与测试白名单）：
 *   1. RestaurantLogic::get_restaurant_details()  直链详情放行（status=0 也可达）—— 挂牌店能被打开的唯一原因
 *   2. Helpers::restaurant_data_formatting()      单店：菜单 foods 放宽 + price_starts_from + 回写给前端的有效值
 *   3. Helpers::restaurant_data_formatting()      列表：回写给前端的有效值
 *   4. ProductLogic::get_latest_products()        店内菜单分页放宽（恒按 restaurant_id 收窄，不外溢）
 *   5. Api\V1\OrderController::order_validation_check()  下单 403 硬闸
 *   6. Api\V1\RestaurantController::get_details()        顾客侧公开联系方式透出
 *
 * 关闸语义（= 回到功能上线前）：
 *   - 挂牌店多为 status=0 的预建店 → 直链详情不再放行 → 前端 getStaticProps 拿不到店 → 404（ISR 最长 ~60 秒）。
 *     **不是**「变成可下单的正常店」，故不产生死胡同。
 *   - 若运营给一家 status=1 的正常营业店开了挂牌，关闸后它回到可下单——此时前端(不渲染挂牌形态)与
 *     后端(不拒单)同样一致。
 *
 * L1：本类只控展示与下单入口，不碰资金/退款/结算，属 L3 实现层。
 */
class NezhaListing
{
    /** business_settings 键；默认缺省=关（迁移会写入 '0'） */
    public const SWITCH_KEY = 'nezha_listing_status';

    /** 请求内缓存：null=本请求还没读过 */
    private static ?bool $enabled = null;

    /**
     * 挂牌态总闸。
     *
     * 🔴 刻意直读 business_settings 表、只在请求内 static 缓存，**不走** Helpers::get_business_settings()：
     *    后者带进程内 static + business_settings_all_data 缓存，翻闸后必须 cache:clear + kill -USR2 FPM 才生效，
     *    运营在后台点一下开关却要等人去命令行刷 worker = 必然踩坑（见 INVARIANTS 中多个开关的 ops_note）。
     *    这里每请求一条主键查询，代价可忽略，翻闸下一个请求即生效。
     * 读不到（迁移未跑/表异常）→ false = 按关处理：挂牌态是新增能力，关掉即回到功能上线前，降级方向安全。
     */
    public static function enabled(): bool
    {
        if (self::$enabled === null) {
            try {
                self::$enabled = (string) DB::table('business_settings')
                    ->where('key', self::SWITCH_KEY)
                    ->value('value') === '1';
            } catch (\Throwable $e) {
                self::$enabled = false;
            }
        }

        return self::$enabled;
    }

    /**
     * 这家店此刻是不是挂牌态 = 总闸开 && 逐店开关开。
     * 兼容 Eloquent 模型 / 数组 / stdClass / null（列不存在也不 fatal）。
     */
    public static function isListingOnly($restaurant): bool
    {
        if ($restaurant === null || !self::enabled()) {
            return false;
        }

        $raw = is_array($restaurant)
            ? ($restaurant['nezha_listing_only'] ?? null)
            : ($restaurant->nezha_listing_only ?? null);

        return (bool) $raw;
    }

    /** 仅供测试：清掉请求内缓存（生产路径不调用） */
    public static function flushCache(): void
    {
        self::$enabled = null;
    }
}
