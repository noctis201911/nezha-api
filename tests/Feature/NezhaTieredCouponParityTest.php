<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\NezhaOrderQuoteController;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 🔴🔴 多级满减 PARITY 套件 —— Fable 满减触点终稿决议 rule10 · 四条件之①(挂 pre-push hook 的「墙」)。
 *
 * 目的：锁定 NezhaOrderQuoteController::quote() 的「满减档位阶梯 + 券取优」与 OrderController::place_order
 * 券取优段【等价】。两处是 parity 锁定的平行实现(见双向哨兵注释)；改任一侧取优口径而不同步对侧,
 * 本套件红 → pre-push 推不上去(把"人肉记得跑"变墙)。golden 期望值 = place_order 取优逻辑手推：
 *   券基数 = 商品 + 加料(不含满减)  ·  满减基数 = 商品(不含加料)  ·  取优比较: coupon_if_win > tiered ? 券胜 : 满减胜(平局归满减)。
 *
 * 🔴 本仓 APP_ENV=testing 仍连生产库(sql_api_nezha_am): 全程 DatabaseTransactions 回滚 + 只调只读 quote()(不落库) → 零残留。
 * 灰度门穿透: get_business_settings 双层缓存(Cache('business_settings_all_data') + Config('nezha_tiered_discount_status_conf')) 都写。
 * tier 精度: 借用餐厅6现有菜品, 事务内把单价钉成 1000(回滚复原), 令 quantity 精确命中 3000/5000/8000 门槛。
 */
class NezhaTieredCouponParityTest extends TestCase
{
    use DatabaseTransactions;

    private const RID = 6;   // 川味轩(演示店·min_order=0)
    private const UNIT = 1000; // 事务内钉死单价, quantity 直接映射购物额

    private int $foodId;
    private int $discId;
    private $user;   // 券只对登录态有效(前端 coupon 入口仅登录·后端 coupon_check 需 user), 故券取优用真实用户

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = \App\Models\User::orderBy('id')->first();
        if (!$this->user) {
            $this->markTestSkipped('无顾客账号, 券取优 parity 无法构造');
        }

        // 借餐厅6一个在售菜品并把单价钉成 1000(事务回滚复原真实价), 归零 food 折扣/规格保证 product_discount 全部来自满减。
        $food = DB::table('food')->where('restaurant_id', self::RID)->where('status', 1)->orderBy('id')->first();
        if (!$food) {
            $this->markTestSkipped('餐厅6无在售菜品, 满减 parity 无法构造(演示店 fixture 缺失)');
        }
        $this->foodId = (int) $food->id;
        DB::table('food')->where('id', $this->foodId)->update(['price' => self::UNIT, 'discount' => 0, 'discount_type' => 'percent', 'variations' => '[]']);

        // 灰度开(穿透 get_business_settings 双层缓存)
        DB::table('business_settings')->updateOrInsert(['key' => 'nezha_tiered_discount_status'], ['value' => '1']);
        Cache::forget('business_settings_all_data');
        Config::set('nezha_tiered_discount_status_conf', ['value' => '1']);

        // canonical 三档: 满3000减300 / 满5000减700 / 满8000享9折(封顶1000)
        $this->discId = DB::table('discounts')->insertGetId([
            'restaurant_id' => self::RID, 'discount_type' => 'amount', 'discount' => 0, 'min_purchase' => 0, 'max_discount' => 0,
            'start_date' => null, 'end_date' => null, 'start_time' => null, 'end_time' => null, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('discount_tiers')->insert([
            ['discount_id' => $this->discId, 'min_purchase' => 3000, 'discount_type' => 'amount',  'discount' => 300, 'max_discount' => 0,    'sort' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['discount_id' => $this->discId, 'min_purchase' => 5000, 'discount_type' => 'amount',  'discount' => 700, 'max_discount' => 0,    'sort' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['discount_id' => $this->discId, 'min_purchase' => 8000, 'discount_type' => 'percent', 'discount' => 10,  'max_discount' => 1000, 'sort' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        // Config 不随事务回滚, 复位防污染其它测试类
        Config::set('nezha_tiered_discount_status_conf', null);
        Cache::forget('business_settings_all_data');
        parent::tearDown();
    }

    private function mkCoupon(string $code, string $type, float $discount, float $minPurchase, float $maxDiscount = 0): void
    {
        DB::table('coupons')->insert([
            'title' => 'PARITY ' . $code, 'code' => $code, 'start_date' => now()->subDay()->toDateString(),
            'expire_date' => now()->addYear()->toDateString(), 'min_purchase' => $minPurchase, 'max_discount' => $maxDiscount,
            'discount' => $discount, 'discount_type' => $type, 'coupon_type' => 'default', 'limit' => null,
            'status' => 1, 'data' => null, 'total_uses' => 0, 'created_by' => 'admin', 'customer_id' => json_encode(['all']),
            'slug' => '', 'restaurant_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function quote(int $qty, ?string $coupon = null, bool $loggedIn = false): array
    {
        // 每次前把券 total_uses 归零, 抵消 coupon_check 可能的自增(quote 只读语义靠此保持幂等)
        DB::table('coupons')->where('code', 'like', 'PARITY %')->update(['total_uses' => 0]);
        $params = [
            'restaurant_id' => self::RID, 'guest_id' => 990000123,
            'cart' => [[
                'item_id' => $this->foodId, 'item_type' => 'App\\Models\\Food',
                'quantity' => $qty, 'add_on_ids' => [], 'add_on_qtys' => [], 'variations' => [],
            ]],
        ];
        if ($coupon) {
            $params['coupon_code'] = $coupon;
        }
        $req = Request::create('/api/v1/customer/order/nezha-quote', 'POST', $params);
        // 券取优走登录态(apiGuestCheck 就是把登录用户塞进 $request->user; coupon_check 据此判归属/限领)
        if ($loggedIn) {
            $req->merge(['user' => $this->user]);
        }
        return (new NezhaOrderQuoteController())->quote($req)->getData(true);
    }

    /** 满减档位阶梯(无券): current(已减) / next(还差/共减) / all_reached / percent total_off=null / is_current 唯一。 */
    public function test_ladder_tiers_no_coupon(): void
    {
        // 未达任何档(2000): 无 current, winner=none, 下一档=满3000共减300
        $d = $this->quote(2);
        $this->assertTrue($d['has_tiered']);
        $this->assertSame(2000, (int) $d['product_price']);
        $this->assertNull($d['current_tier'], '2000 未达 3000 应无 current');
        $this->assertSame('none', $d['winner']);
        $this->assertSame(3000, (int) $d['next_tier']['min_purchase']);
        $this->assertSame(1000, (int) $d['next_tier']['shortfall']);
        $this->assertSame(300, (int) $d['next_tier']['total_off']);
        $this->assertFalse((bool) $d['all_reached']);

        // 满3000减300
        $d = $this->quote(3);
        $this->assertSame(300, (int) $d['current_tier']['applied_amount']);
        $this->assertSame(3000, (int) $d['current_tier']['min_purchase']);
        $this->assertSame('tiered', $d['winner']);
        $this->assertSame(300, (int) $d['tiered']['amount']);
        $this->assertSame(5000, (int) $d['next_tier']['min_purchase']);
        $this->assertSame(700, (int) $d['next_tier']['total_off']);
        $this->assertFalse((bool) $d['all_reached']);
        $this->assertSame(1, count(array_filter($d['ladder'], fn ($t) => $t['is_current'])), 'is_current 至多一枚(绿章唯一)');

        // 满5000减700
        $d = $this->quote(5);
        $this->assertSame(700, (int) $d['current_tier']['applied_amount']);
        $this->assertSame(5000, (int) $d['current_tier']['min_purchase']);
        $this->assertSame(8000, (int) $d['next_tier']['min_purchase']);

        // 满8000享9折(封顶1000): 8000×10%=800 未触顶
        $d = $this->quote(8);
        $this->assertSame(800, (int) $d['current_tier']['applied_amount']);
        $this->assertSame(8000, (int) $d['current_tier']['min_purchase']);
        $this->assertTrue((bool) $d['all_reached']);
        $this->assertNull($d['next_tier']);
        $t8000 = collect($d['ladder'])->firstWhere('min_purchase', 8000);
        $this->assertNull($t8000['total_off'], 'percent 档 total_off 必须 null(前端不冻结 Y)');

        // 满8000享9折触顶: 12000×10%=1200 → 封顶 1000
        $d = $this->quote(12);
        $this->assertSame(1000, (int) $d['current_tier']['applied_amount']);
        $this->assertTrue((bool) $d['all_reached']);
    }

    /** 券 vs 满减「取更优」——与 place_order 券取优段等价(平行实现 parity)。 */
    public function test_coupon_vs_tiered_takes_better(): void
    {
        $this->mkCoupon('PARITY_AMT600', 'amount', 600, 3000);   // 满3000减600
        $this->mkCoupon('PARITY_PCT15', 'percent', 15, 2000);    // 满2000减15%
        $this->mkCoupon('PARITY_AMT300', 'amount', 300, 3000);   // 满3000减300(与档持平, 测平局)

        // 3000: 满减300 vs 券600(达门槛) → 券胜
        $d = $this->quote(3, 'PARITY_AMT600', true);
        $this->assertSame('coupon', $d['winner']);
        $this->assertSame(0, (int) $d['product_discount']);
        $this->assertSame(600, (int) $d['coupon_discount']);
        $this->assertTrue((bool) $d['coupon']['won']);
        $this->assertFalse((bool) $d['tiered']['won']);

        // 5000: 满减700 vs 券600 → 满减胜(券让位)
        $d = $this->quote(5, 'PARITY_AMT600', true);
        $this->assertSame('tiered', $d['winner']);
        $this->assertSame(700, (int) $d['product_discount']);
        $this->assertSame(0, (int) $d['coupon_discount']);

        // 3000: 满减300 vs 券15%=450 → 券胜
        $d = $this->quote(3, 'PARITY_PCT15', true);
        $this->assertSame('coupon', $d['winner']);
        $this->assertSame(450, (int) $d['coupon_discount']);

        // 8000: 满减800(percent档) vs 券600 → 满减胜(percent 档也参与取优)
        $d = $this->quote(8, 'PARITY_AMT600', true);
        $this->assertSame('tiered', $d['winner']);
        $this->assertSame(800, (int) $d['product_discount']);

        // 8000: 满减800 vs 券15%=1200 → 券胜
        $d = $this->quote(8, 'PARITY_PCT15', true);
        $this->assertSame('coupon', $d['winner']);
        $this->assertSame(1200, (int) $d['coupon_discount']);

        // 平局(满减300 == 券300) → 归满减(place_order: coupon_if_win > tiered 为 false 时满减胜)
        $d = $this->quote(3, 'PARITY_AMT300', true);
        $this->assertSame('tiered', $d['winner'], '平局必须归满减(与 place_order 一致)');
        $this->assertSame(300, (int) $d['product_discount']);
        $this->assertSame(0, (int) $d['coupon_discount']);
    }

    /** 券不达门槛: 软标记 below_min, 不 403, winner 回落满减/none。 */
    public function test_coupon_below_min_soft_flag(): void
    {
        $this->mkCoupon('PARITY_AMT600', 'amount', 600, 3000);
        // 2000: 无满减 + 券门槛3000未达 → below_min, winner=none, 不减
        $d = $this->quote(2, 'PARITY_AMT600', true);
        $this->assertSame('none', $d['winner']);
        $this->assertSame(0, (int) $d['coupon_discount']);
        $this->assertTrue((bool) $d['coupon']['below_min']);
    }

    /** 空车: 仍返回 has_tiered + ladder(供触点判定), product_price=0, current=null。 */
    public function test_empty_cart_returns_ladder(): void
    {
        $params = ['restaurant_id' => self::RID, 'guest_id' => 990000123, 'cart' => []];
        $req = Request::create('/api/v1/customer/order/nezha-quote', 'POST', $params);
        $d = (new NezhaOrderQuoteController())->quote($req)->getData(true);
        // 空 body cart 回落服务端车(该 guest 无车) → product_price=0
        $this->assertTrue($d['has_tiered']);
        $this->assertSame(0, (int) $d['product_price']);
        $this->assertNull($d['current_tier']);
        $this->assertSame(3, count($d['ladder']));
    }
}
