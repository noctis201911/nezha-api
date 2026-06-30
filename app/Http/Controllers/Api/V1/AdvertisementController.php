<?php
namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\Models\Advertisement;
use App\Models\BusinessSetting;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdvertisementController extends Controller
{
    public function get_adds(Request $request)
    {
        Helpers::getZoneIds($request);
        $zone_ids= $request->header('zoneId');
        $zone_ids=  json_decode($zone_ids, true)?? [];

        // 哪吒广告竞价 T4: 竞价开时, 物化赢家(mat_rank)优先服务首页广告位; 关时维持原 priority 排序(零行为变化)。
        $auctionOn = (int) (BusinessSetting::where('key', 'nezha_ad_auction_status')->first()?->value ?? 0);

        $cacheKey = 'advertisements_' . md5(json_encode($zone_ids)) . '_a' . $auctionOn;

        $Advertisement = Cache::remember($cacheKey, now()->addMinutes(20), function () use ($zone_ids, $auctionOn) {
            $query = Advertisement::valid()->with('restaurant')
                ->when(count($zone_ids) > 0, function($query) use($zone_ids) {
                    $query->wherehas('restaurant', function($query) use($zone_ids){
                        $query->whereIn('zone_id',$zone_ids)->active();
                    });
                });

            if ($auctionOn === 1) {
                // 竞价赢家(cpc + 已物化 mat_rank)优先, 其内按 mat_rank 升序; 其余(CPT/未中标)按 priority 兜底。
                $query->orderByRaw('CASE WHEN pricing_model = ? AND mat_rank IS NOT NULL THEN 0 ELSE 1 END ASC', ['cpc'])
                      ->orderByRaw('ISNULL(mat_rank), mat_rank ASC')
                      ->orderByRaw('ISNULL(priority), priority ASC');
            } else {
                $query->orderByRaw('ISNULL(priority), priority ASC');
            }

            $Advertisement = $query->get();

            try {
                $Advertisement->each(function ($advertisement) {
                    $advertisement->reviews_comments_count = (int) $advertisement->restaurant->reviews_comments()->count();
                    $reviewsInfo = $advertisement->restaurant->reviews()->where('reviews.status', 1)
                        ->selectRaw('avg(reviews.rating) as average_rating, count(reviews.id) as total_reviews, food.restaurant_id')
                        ->groupBy('food.restaurant_id')
                        ->first();

                    $advertisement->average_rating = (float)  $reviewsInfo?->average_rating ?? 0;
                });
            } catch (\Exception $e) {
                info($e->getMessage());
            }
            return $Advertisement;
        });

        return response()->json($Advertisement, 200);
    }

    /**
     * 哪吒广告竞价 T4 — 点击计费端点(CPC, 首价, 原子封顶, 资金隔离).
     *
     * 中间件 auth:api(INV-4 计费身份服务端可信; 客户端自报 guest_id 一律不信).
     * 计费前置(任一不满足 → charged=0 只记录, 不扣费, 不泄露原因给客户端):
     *   - 广告正在投放(cpc + approved + 投放期内 + mat_rank 非空=当前物化赢家)
     *   - 身份可信(登录 + 真实下单史 delivered >= nezha_ad_trusted_min_orders)
     * 首价: cost = min(bid_amount, nezha_ad_max_per_click); 广告若在投, bid 已 >= floor(recompute 保证).
     * 去重: dedup_key = sha1('click'|ad|user|窗口桶); 唯一索引保证同窗口同身份只计一次(并发安全).
     * 原子扣费(INV-2/3, 锁序 restaurant_wallets → advertisements → 流水):
     *   1) UPDATE restaurant_wallets SET ad_balance=ad_balance-cost WHERE ad_balance>=cost  (0 行=余额不足)
     *   2) T5 惰性预算重置 + UPDATE advertisements SET spent_today=spent_today+cost WHERE spent_today+cost<=daily_budget  (0 行=封顶)
     *   3) 写 ad_click_fee 流水(只动 ad_balance, balance_after=新 ad_balance) + 回填 ad_events.charged
     *   任一受影响 0 行 → 抛异常整事务回滚(扣款撤销, 零超扣/零透支) → charged=0 记原因。
     * INV-1: 全程只动 ad_balance, 永不碰 deposit_balance → 广告烧空不触发停业闸、不把店买下线。
     */
    public function click(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'advertisement_id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $auctionOn = (int) (BusinessSetting::where('key', 'nezha_ad_auction_status')->first()?->value ?? 0);
        if ($auctionOn !== 1) {
            // 竞价关: 不计费、不记录(避免无意义写库); 客户端无感
            return response()->json(['success' => true, 'charged' => false], 200);
        }

        $user = $request->user();
        if (!$user) {
            return response()->json(['errors' => [['code' => 'auth', 'message' => 'Unauthorized']]], 401);
        }

        $ad = Advertisement::with('restaurant:id,vendor_id')->find($request->advertisement_id);
        if (!$ad) {
            return response()->json(['success' => true, 'charged' => false], 200);
        }

        $today    = Carbon::now('Asia/Yerevan')->toDateString();
        $floor    = (float) (BusinessSetting::where('key', 'nezha_ad_floor_price')->first()?->value ?? 0);
        $maxClick = (float) (BusinessSetting::where('key', 'nezha_ad_max_per_click')->first()?->value ?? 0);
        $minOrders = (int) (BusinessSetting::where('key', 'nezha_ad_trusted_min_orders')->first()?->value ?? 1);
        $windowSec = (int) (BusinessSetting::where('key', 'nezha_ad_dedup_window_sec')->first()?->value ?? 900);
        $windowSec = max(1, $windowSec);

        $slot = $ad->slot ?: ($ad->add_type === 'restaurant_promotion' ? 'home_carousel' : 'list_top');
        $bucket = intdiv(time(), $windowSec);
        $dedupKey = sha1('click|' . $ad->id . '|' . $user->id . '|' . $bucket);
        $ipHash = substr(sha1((string) $request->ip()), 0, 16);
        $uaHash = substr(sha1((string) $request->userAgent()), 0, 16);

        // 是否在投(物化赢家)
        $serving = $ad->pricing_model === 'cpc'
            && $ad->status === 'approved'
            && $ad->mat_rank !== null
            && $ad->start_date <= $today
            && $ad->end_date >= $today;

        // 身份可信(真实下单史). 仅在在投时才查(省一次 count).
        $trusted = $serving && $this->userTrusted((int) $user->id, $minOrders);

        $cost = 0.0;
        if ($serving) {
            $bid = (float) $ad->bid_amount;
            $cost = $maxClick > 0 ? min($bid, $maxClick) : $bid;
            if ($cost < $floor) {
                // 理论上不该在投; 兜底当不可计费
                $serving = false;
            }
        }

        $vendorId = (int) ($ad->restaurant?->vendor_id ?? 0);

        // 不在投 / 不可信 / 无 vendor → 只记录 charged=0(去重幂等), 不进资金路径
        if (!$serving || !$vendorId) {
            $this->recordEvent($ad, $vendorId, $user->id, 'click', $slot, 0, 'not_serving', null, $dedupKey, $ipHash, $uaHash);
            return response()->json(['success' => true, 'charged' => false], 200);
        }
        if (!$trusted) {
            $this->recordEvent($ad, $vendorId, $user->id, 'click', $slot, 0, 'untrusted', null, $dedupKey, $ipHash, $uaHash);
            return response()->json(['success' => true, 'charged' => false], 200);
        }

        // 原子计费
        $reason = 'charged';
        $charged = false;
        try {
            DB::transaction(function () use ($ad, $vendorId, $user, $cost, $today, $slot, $dedupKey, $ipHash, $uaHash) {
                // 去重守卫(放最前: 同窗口同身份重复点 → 唯一键冲突 → 整事务回滚, 不扣费)
                $eventId = DB::table('ad_events')->insertGetId([
                    'advertisement_id' => $ad->id,
                    'restaurant_id'    => $ad->restaurant_id,
                    'vendor_id'        => $vendorId,
                    'user_id'          => $user->id,
                    'event_type'       => 'click',
                    'slot'             => $slot,
                    'charged_amount'   => 0,
                    'charge_reason'    => 'pending',
                    'dedup_key'        => $dedupKey,
                    'ip_hash'          => $ipHash,
                    'ua_hash'          => $uaHash,
                    'created_at'       => now(),
                ]);

                // INV-3 锁序步骤1: 钱包 ad_balance 原子扣(条件 UPDATE, 0 行=余额不足)
                $affW = DB::update(
                    'UPDATE restaurant_wallets SET ad_balance = ad_balance - ?, updated_at = ? WHERE vendor_id = ? AND ad_balance >= ?',
                    [$cost, now(), $vendorId, $cost]
                );
                if ($affW === 0) {
                    throw new \RuntimeException('low_balance');
                }

                // INV-3 锁序步骤2: 广告 spent_today. T5 惰性重置(reset_date<今天先清零), 再原子封顶累加。
                DB::update(
                    'UPDATE advertisements SET spent_today = 0, budget_reset_date = ? WHERE id = ? AND (budget_reset_date IS NULL OR budget_reset_date < ?)',
                    [$today, $ad->id, $today]
                );
                $affA = DB::update(
                    'UPDATE advertisements SET spent_today = spent_today + ? WHERE id = ? AND (daily_budget IS NULL OR spent_today + ? <= daily_budget)',
                    [$cost, $ad->id, $cost]
                );
                if ($affA === 0) {
                    throw new \RuntimeException('budget_capped');
                }

                // INV-3 锁序步骤3: 写流水(ad_click_fee, 只动 ad_balance) + 回填事件
                $newAdBal = (float) DB::table('restaurant_wallets')->where('vendor_id', $vendorId)->value('ad_balance');
                $txnId = DB::table('restaurant_deposit_transactions')->insertGetId([
                    'vendor_id'     => $vendorId,
                    'restaurant_id' => $ad->restaurant_id,
                    'order_id'      => null,
                    'type'          => 'ad_click_fee',
                    'amount'        => -1 * $cost,
                    'commission'    => $cost,
                    'balance_after' => $newAdBal,
                    'note'          => '广告#' . $ad->id . ' CPC点击扣费(ad_balance)',
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                DB::table('ad_events')->where('id', $eventId)->update([
                    'charged_amount'         => $cost,
                    'charge_reason'          => 'charged',
                    'deposit_transaction_id' => $txnId,
                ]);
            });
            $reason = 'charged';
            $charged = true;
        } catch (\Illuminate\Database\QueryException $e) {
            // 唯一键冲突 = 同窗口重复点(去重). 其它 DB 错按 error 记。
            if ((string) $e->getCode() === '23000' && stripos($e->getMessage(), 'dedup') !== false) {
                $reason = 'dedup';
            } else {
                $reason = 'error';
                info('[ad-click] db error ad#' . $ad->id . ': ' . $e->getMessage());
            }
        } catch (\RuntimeException $e) {
            $reason = $e->getMessage(); // low_balance | budget_capped
        }

        // 非扣费结果(余额不足/封顶/error): 事务已回滚, dedup_key 已释放 → 补记 charged=0(去重幂等)
        if (in_array($reason, ['low_balance', 'budget_capped', 'error'], true)) {
            $this->recordEvent($ad, $vendorId, $user->id, 'click', $slot, 0, $reason, null, $dedupKey, $ipHash, $uaHash);
        }

        return response()->json(['success' => true, 'charged' => $charged], 200);
    }

    /**
     * 哪吒广告竞价 T4 — 曝光记录端点(只记录, 永不计费; 可信去重计数, 不进裸 CTR).
     * 游客可记(用 ip_hash 去重); 登录用 user_id 去重。charged_amount 恒 0。
     */
    public function impression(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'advertisement_id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $auctionOn = (int) (BusinessSetting::where('key', 'nezha_ad_auction_status')->first()?->value ?? 0);
        if ($auctionOn !== 1) {
            return response()->json(['success' => true], 200);
        }

        $ad = Advertisement::with('restaurant:id,vendor_id')->find($request->advertisement_id);
        if (!$ad) {
            return response()->json(['success' => true], 200);
        }

        $windowSec = (int) (BusinessSetting::where('key', 'nezha_ad_dedup_window_sec')->first()?->value ?? 900);
        $windowSec = max(1, $windowSec);
        $user = $request->user();
        $userId = $user?->id;
        $ipHash = substr(sha1((string) $request->ip()), 0, 16);
        $uaHash = substr(sha1((string) $request->userAgent()), 0, 16);
        $slot = $ad->slot ?: ($ad->add_type === 'restaurant_promotion' ? 'home_carousel' : 'list_top');
        $bucket = intdiv(time(), $windowSec);
        // 登录按 user 去重, 游客按 ip 去重
        $ident = $userId ? ('u' . $userId) : ('ip' . $ipHash);
        $dedupKey = sha1('imp|' . $ad->id . '|' . $ident . '|' . $bucket);
        $vendorId = (int) ($ad->restaurant?->vendor_id ?? 0);

        $this->recordEvent($ad, $vendorId, $userId, 'impression', $slot, 0, 'impression', null, $dedupKey, $ipHash, $uaHash);
        return response()->json(['success' => true], 200);
    }

    /** 计费身份可信: 登录 + 真实下单史(已送达订单) >= 阈值. INV-4, 防伪造身份刷爆对手预算. */
    protected function userTrusted(int $userId, int $minOrders): bool
    {
        if ($minOrders <= 0) {
            return true;
        }
        $delivered = DB::table('orders')
            ->where('user_id', $userId)
            ->where('order_status', 'delivered')
            ->count();
        return $delivered >= $minOrders;
    }

    /** 去重幂等记录一条 ad_event(charged=0 路径用); 唯一键冲突=已记过, 静默忽略. */
    protected function recordEvent($ad, int $vendorId, $userId, string $type, ?string $slot, float $charged, string $reason, $txnId, string $dedupKey, ?string $ipHash, ?string $uaHash): void
    {
        try {
            DB::table('ad_events')->insertOrIgnore([
                'advertisement_id' => $ad->id,
                'restaurant_id'    => $ad->restaurant_id,
                'vendor_id'        => $vendorId,
                'user_id'          => $userId,
                'event_type'       => $type,
                'slot'             => $slot,
                'charged_amount'   => $charged,
                'charge_reason'    => $reason,
                'deposit_transaction_id' => $txnId,
                'dedup_key'        => $dedupKey,
                'ip_hash'          => $ipHash,
                'ua_hash'          => $uaHash,
                'created_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            info('[ad-event] record failed ad#' . ($ad->id ?? '?') . ': ' . $e->getMessage());
        }
    }
}
