<?php

namespace Tests\Feature;

use App\Console\Commands\RecomputeAdAuction;
use App\Http\Controllers\Api\V1\AdvertisementController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 哪吒商家广告「实时竞价」v1 — 死亡测试(全绿才能上线).
 *
 * 🔴 安全墙: tests/bootstrap.php 在 Laravel 启动前强制 sqlite :memory:；生产 config cache 会被直接拒绝。
 * DatabaseTransactions 只负责用例隔离，不能再作为“连接生产库也安全”的理由。
 * 真并发(零超扣/无死锁)单事务回滚测不了 → 另由 nzqa_ad_concurrency.php 提交+清理脚本验, 见交付报告。
 *
 * 覆盖死亡测试清单(docs/PLAN_ad_auction.md §4):
 *  1 首价清算(bid/cap/floor)           5 可信身份防刷
 *  2 原子封顶并发零超扣(顺序版)          6 dedup 去重
 *  3 隔离验证(ad_balance↛deposit/不下线) 7 排名物化(eCPM 序 + 非广告店不误伤 + 开关清空)
 *  8 质量分(难刷信号)                   9 对账(ad_events.charged 合计==ad_click_fee 流水合计)
 *  + 开关默认关零行为变化
 */
class NezhaAdAuctionTest extends TestCase
{
    use DatabaseTransactions;

    private int $rnd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rnd = random_int(100000000, 999999999);
        // 已知设置(测完随事务回滚)
        $this->setSetting('nezha_ad_auction_status', '1');
        $this->setSetting('nezha_ad_floor_price', '50');
        $this->setSetting('nezha_ad_max_per_click', '500');
        $this->setSetting('nezha_ad_max_daily_budget', '50000');
        $this->setSetting('nezha_ad_dedup_window_sec', '900');
        $this->setSetting('nezha_ad_trusted_min_orders', '1');
        $this->setSetting('nezha_ad_boost_weight', '0.5');
        $this->setSetting('nezha_ad_max_share_per_store', '3');
        $this->setSetting('nezha_ad_recompute_min', '5');
        $this->setSetting('nezha_ad_natural_reserved_slots', '3');
        // 默认关掉保证金扣佣闸(隔离测试里再单独打开)
        $this->setSetting('nezha_deposit_mode_status', '0');
        $this->setSetting('nezha_min_deposit_threshold', '0');
    }

    private function setSetting(string $key, $value): void
    {
        if (DB::table('business_settings')->where('key', $key)->exists()) {
            DB::table('business_settings')->where('key', $key)->update(['value' => $value]);
        } else {
            DB::table('business_settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** 建 vendor + restaurant + wallet; 返回 [vendorId, restaurantId]. */
    private function mkStore(float $adBalance, float $depositBalance = 0, int $commissionEnabled = 0): array
    {
        $this->rnd++;
        $vendorId = DB::table('vendors')->insertGetId([
            'f_name' => 'NZQA', 'phone' => 'nzqa' . $this->rnd, 'email' => 'nzqa' . $this->rnd . '@t.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $restId = DB::table('restaurants')->insertGetId([
            'name' => 'NZQA Store ' . $this->rnd, 'phone' => 'r' . $this->rnd, 'vendor_id' => $vendorId,
            'nezha_commission_enabled' => $commissionEnabled, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('restaurant_wallets')->insert([
            'vendor_id' => $vendorId, 'ad_balance' => $adBalance, 'deposit_balance' => $depositBalance,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$vendorId, $restId];
    }

    /** 建广告(默认 cpc/approved/在投/已物化 rank1). */
    private function mkAd(int $restId, float $bid, ?float $dailyBudget = null, float $spentToday = 0, ?int $matRank = 1, string $pricing = 'cpc', string $status = 'approved', string $slot = 'home_carousel'): int
    {
        return DB::table('advertisements')->insertGetId([
            'restaurant_id' => $restId,
            'add_type' => 'restaurant_promotion',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'created_by_id' => 1, 'created_by_type' => 'App\\Models\\Vendor',
            'status' => $status, 'is_paid' => 0,
            'pricing_model' => $pricing, 'bid_amount' => $bid,
            'daily_budget' => $dailyBudget, 'spent_today' => $spentToday,
            'budget_reset_date' => now('Asia/Yerevan')->toDateString(),
            'slot' => $slot, 'quality_score' => 1.0, 'mat_rank' => $matRank, 'mat_boost' => 0.5,
            'mat_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 建顾客 + N 条已送达订单(可信身份). */
    private function mkUser(int $deliveredOrders, int $restId): User
    {
        $this->rnd++;
        $uid = DB::table('users')->insertGetId([
            'phone' => 'u' . $this->rnd, 'ref_code' => 'REF' . $this->rnd,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($i = 0; $i < $deliveredOrders; $i++) {
            DB::table('orders')->insert([
                'user_id' => $uid, 'restaurant_id' => $restId, 'order_status' => 'delivered',
                'restaurant_discount_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return User::find($uid);
    }

    /** 直接调控制器 click(绕过路由中间件, 测资金逻辑本身); 返回 ['charged'=>bool]. */
    private function click(int $adId, User $user): array
    {
        $req = Request::create('/api/v1/advertisement/click', 'POST', ['advertisement_id' => $adId]);
        $req->setUserResolver(fn () => $user);
        $resp = (new AdvertisementController())->click($req);
        return json_decode($resp->getContent(), true);
    }

    private function adBalance(int $vendorId): float
    {
        return (float) DB::table('restaurant_wallets')->where('vendor_id', $vendorId)->value('ad_balance');
    }
    private function spentToday(int $adId): float
    {
        return (float) DB::table('advertisements')->where('id', $adId)->value('spent_today');
    }

    // ───────────────────────── 1 首价清算 ─────────────────────────
    public function test_1_first_price_charges_bid_capped_by_max_per_click(): void
    {
        // bid 300 < cap 500 → 扣 300(出多少扣多少, 首价)
        [$v, $r] = $this->mkStore(adBalance: 10000);
        $ad = $this->mkAd($r, bid: 300);
        $u = $this->mkUser(1, $r);
        $res = $this->click($ad, $u);
        $this->assertTrue($res['charged'], '首价: 合格点击应计费');
        $this->assertEqualsWithDelta(9700, $this->adBalance($v), 0.01, '首价: ad_balance 应减 bid=300');
        $this->assertEqualsWithDelta(300, $this->spentToday($ad), 0.01);

        // bid 800 > cap 500 → 封顶扣 500(不超 max_per_click)
        [$v2, $r2] = $this->mkStore(adBalance: 10000);
        $ad2 = $this->mkAd($r2, bid: 800);
        $u2 = $this->mkUser(1, $r2);
        $this->click($ad2, $u2);
        $this->assertEqualsWithDelta(9500, $this->adBalance($v2), 0.01, '首价封顶: 扣 cap=500 而非 bid=800');
    }

    // ───────────────────────── 2 原子封顶零超扣(顺序) ─────────────────────────
    public function test_2_atomic_budget_cap_no_overspend(): void
    {
        // daily_budget=1000, 每次 cost=300; 4 个不同可信用户点同广告 → 前3扣(900), 第4封顶 charged=0; spent 永不超 1000
        [$v, $r] = $this->mkStore(adBalance: 100000);
        $ad = $this->mkAd($r, bid: 300, dailyBudget: 1000);
        $charged = 0;
        for ($i = 0; $i < 4; $i++) {
            $u = $this->mkUser(1, $r);
            $res = $this->click($ad, $u);
            if ($res['charged']) $charged++;
        }
        $this->assertSame(3, $charged, '应恰好 3 次计费(900<=1000<1200)');
        $this->assertEqualsWithDelta(900, $this->spentToday($ad), 0.01, 'spent_today 封顶 900, 零超扣');
        $this->assertLessThanOrEqual(1000.0, $this->spentToday($ad), '绝不超 daily_budget');
        $this->assertEqualsWithDelta(99100, $this->adBalance($v), 0.01, 'ad_balance 只减真实扣费 900');
        $capped = DB::table('ad_events')->where('advertisement_id', $ad)->where('charge_reason', 'budget_capped')->count();
        $this->assertSame(1, $capped, '第4次应记 budget_capped');
    }

    // ───────────────────────── 3 隔离验证(直接证伪自残) ─────────────────────────
    public function test_3_ad_balance_isolated_from_deposit_no_pause(): void
    {
        // 打开保证金扣佣闸 + 阈值4500 + deposit=5000(初始不下线); 点击烧光 ad_balance 600。
        // 若隔离破(广告误扣 deposit): 5000-600=4400<=4500 → 下线=TRUE → 本测失败。正确: deposit 不动、不下线。
        $this->setSetting('nezha_deposit_mode_status', '1');
        $this->setSetting('nezha_min_deposit_threshold', '4500');
        $this->setSetting('nezha_ad_max_per_click', '1000'); // 让单次能扣 600
        [$v, $r] = $this->mkStore(adBalance: 600, depositBalance: 5000, commissionEnabled: 1);
        $ad = $this->mkAd($r, bid: 600);
        $u = $this->mkUser(1, $r);

        $rest = Restaurant::find($r);
        $this->assertFalse(OrderController::nezha_store_paused($rest), '前置: 初始不应下线');

        $res = $this->click($ad, $u);
        $this->assertTrue($res['charged']);
        $this->assertEqualsWithDelta(0, $this->adBalance($v), 0.01, 'ad_balance 烧光到 0');
        $this->assertEqualsWithDelta(5000, (float) DB::table('restaurant_wallets')->where('vendor_id', $v)->value('deposit_balance'), 0.01, 'deposit_balance 必须纹丝不动(INV-1)');
        $rest2 = Restaurant::find($r);
        $this->assertFalse(OrderController::nezha_store_paused($rest2), 'ad_balance 烧光不得触发停业闸(不把店买下线)');
    }

    // ───────────────────────── 4 可信身份防刷 ─────────────────────────
    public function test_4_untrusted_user_not_charged(): void
    {
        // 无下单史游客(min_orders=1) → charged=0 untrusted, 对手预算/余额不动
        [$v, $r] = $this->mkStore(adBalance: 10000);
        $ad = $this->mkAd($r, bid: 300);
        $u = $this->mkUser(0, $r); // 0 单
        $res = $this->click($ad, $u);
        $this->assertFalse($res['charged'], '无下单史不可信 → 不计费');
        $this->assertEqualsWithDelta(10000, $this->adBalance($v), 0.01, '不可信点击不动余额');
        $this->assertEqualsWithDelta(0, $this->spentToday($ad), 0.01);
        $reason = DB::table('ad_events')->where('advertisement_id', $ad)->value('charge_reason');
        $this->assertSame('untrusted', $reason);
    }

    // ───────────────────────── 5/6 dedup 去重 ─────────────────────────
    public function test_5_dedup_same_user_window_charged_once(): void
    {
        [$v, $r] = $this->mkStore(adBalance: 10000);
        $ad = $this->mkAd($r, bid: 300);
        $u = $this->mkUser(1, $r);
        $r1 = $this->click($ad, $u);
        $r2 = $this->click($ad, $u);
        $this->assertTrue($r1['charged'], '首次应计费');
        $this->assertFalse($r2['charged'], '同窗口重复点 → 去重不计费');
        $this->assertEqualsWithDelta(9700, $this->adBalance($v), 0.01, '只扣一次 300');
        $this->assertEqualsWithDelta(300, $this->spentToday($ad), 0.01);
        // 去重靠 dedup_key 唯一索引: 同窗口同身份只存 1 行(首次 charged 那条占住 key), 重复点被唯一键挡回滚、不另立行也不再扣。
        $rows = DB::table('ad_events')->where('advertisement_id', $ad)->where('user_id', $u->id)->count();
        $this->assertSame(1, $rows, '同窗口同身份只 1 条事件(去重生效)');
        $this->assertSame('charged', DB::table('ad_events')->where('advertisement_id', $ad)->value('charge_reason'));
    }

    // ───────────────────────── 7 排名物化 ─────────────────────────
    public function test_7_recompute_ranks_by_ecpm_and_off_clears(): void
    {
        // 同 slot 两 cpc 广告: bid 高者 rank1(质量分均1.0 → eCPM=bid); 非 cpc 广告不被物化误伤。
        [$vA, $rA] = $this->mkStore(adBalance: 10000);
        [$vB, $rB] = $this->mkStore(adBalance: 10000);
        [$vC, $rC] = $this->mkStore(adBalance: 10000);
        $low  = $this->mkAd($rA, bid: 100, matRank: null);
        $high = $this->mkAd($rB, bid: 900, matRank: null);
        $cpt  = $this->mkAd($rC, bid: 0, matRank: null, pricing: 'cpt'); // 非竞价, 不应被物化

        Artisan::call('nezha:recompute-ad-auction');

        $this->assertSame(1, (int) DB::table('advertisements')->where('id', $high)->value('mat_rank'), '高出价 → rank1');
        $this->assertSame(2, (int) DB::table('advertisements')->where('id', $low)->value('mat_rank'), '低出价 → rank2');
        $this->assertNull(DB::table('advertisements')->where('id', $cpt)->value('mat_rank'), 'CPT 广告不被物化(非广告店不误伤)');
        $this->assertGreaterThan(
            (float) DB::table('advertisements')->where('id', $low)->value('mat_boost'),
            (float) DB::table('advertisements')->where('id', $high)->value('mat_boost'),
            'rank1 boost 应 > rank2'
        );

        // 关开关 + 重算 → 物化清空(排序退化, 零残留)
        $this->setSetting('nezha_ad_auction_status', '0');
        Artisan::call('nezha:recompute-ad-auction');
        $this->assertNull(DB::table('advertisements')->where('id', $high)->value('mat_rank'), '关开关后 mat_rank 清空');
        $this->assertEqualsWithDelta(0, (float) DB::table('advertisements')->where('id', $high)->value('mat_boost'), 0.001, '关开关后 mat_boost 清零');
    }

    // ───────────────────────── 8 质量分(难刷信号) ─────────────────────────
    public function test_8_quality_score_uses_unfakeable_signals(): void
    {
        [$v, $r] = $this->mkStore(adBalance: 0);
        // 9 送达 1 取消 → 完单率 0.9; 无评价(中性0.7); 无出餐时长(中性0.5)
        for ($i = 0; $i < 9; $i++) {
            DB::table('orders')->insert(['restaurant_id' => $r, 'order_status' => 'delivered', 'restaurant_discount_amount' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('orders')->insert(['restaurant_id' => $r, 'order_status' => 'canceled', 'restaurant_discount_amount' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $q = (new RecomputeAdAuction())->computeQualityScores([$r]);
        $expected = 0.5 + (0.4 * 0.9 + 0.4 * 0.7 + 0.2 * 0.5); // = 0.5 + 0.74 = 1.24
        $this->assertEqualsWithDelta($expected, $q[$r], 0.01, '质量分=完单率/好评率/出餐速度合成, 映射[0.5,1.5]');
        $this->assertGreaterThanOrEqual(0.5, $q[$r]);
        $this->assertLessThanOrEqual(1.5, $q[$r]);
    }

    // ───────────────────────── 9 对账 ─────────────────────────
    public function test_9_reconciliation_events_equal_ledger(): void
    {
        // 多次计费后: ad_events.charged 合计 == ad_click_fee 流水扣款合计 == ad_balance 减少额
        [$v, $r] = $this->mkStore(adBalance: 10000);
        $ad = $this->mkAd($r, bid: 300, dailyBudget: 5000);
        $before = $this->adBalance($v);
        for ($i = 0; $i < 5; $i++) {
            $u = $this->mkUser(1, $r);
            $this->click($ad, $u);
        }
        $after = $this->adBalance($v);
        $eventsSum = (float) DB::table('ad_events')->where('advertisement_id', $ad)->where('charge_reason', 'charged')->sum('charged_amount');
        $ledgerSum = (float) DB::table('restaurant_deposit_transactions')->where('vendor_id', $v)->where('type', 'ad_click_fee')->sum('amount');
        $this->assertEqualsWithDelta(1500, $eventsSum, 0.01, '5×300=1500 计费事件合计');
        $this->assertEqualsWithDelta(-1500, $ledgerSum, 0.01, '流水扣款合计 -1500');
        $this->assertEqualsWithDelta($eventsSum, -$ledgerSum, 0.01, '对账: 事件==流水');
        $this->assertEqualsWithDelta($before - $after, $eventsSum, 0.01, '对账: 余额减少额==计费合计');
    }

    // ───────────────────────── 开关默认关 零行为变化 ─────────────────────────
    public function test_10_switch_off_no_charge_no_event(): void
    {
        $this->setSetting('nezha_ad_auction_status', '0');
        [$v, $r] = $this->mkStore(adBalance: 10000);
        $ad = $this->mkAd($r, bid: 300);
        $u = $this->mkUser(1, $r);
        $res = $this->click($ad, $u);
        $this->assertFalse($res['charged'], '总开关关 → 不计费');
        $this->assertEqualsWithDelta(10000, $this->adBalance($v), 0.01, '关时余额不动');
        $this->assertSame(0, DB::table('ad_events')->where('advertisement_id', $ad)->count(), '关时不写事件(零行为变化)');
    }
}
