<?php

namespace App\CentralLogics;

use App\Models\Restaurant;
use App\Models\RestaurantOffboardSettlement;
use App\Models\VendorKycProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒 商家退出结算 — 状态机核心 (DESIGN_merchant_offboard §B · step4-1 入口半)。
 *
 * 只管 RestaurantOffboardSettlement 生命周期的「入口段 + 回流边」:
 *   active ──open──► applied | kyc_pending
 *   applied/kyc_pending ──withdraw──► active (status=withdrawn)          [回流边① 治误点永久停业]
 *   kyc_pending ──onKycApproved──► applied                               [回流边②]
 *   kyc_pending ──onKycRejected──► active (status=rejected, 恢复营业)     [回流边③ 治KYC拒卡死]
 *
 * 幂等根: uq_active(vendor_id, active_uniq) —— 同 vendor 至多一条 active(active_uniq=1),
 *   关闭态置 NULL 可并存(可重申)。并发撞 1062 当幂等、勿 500。
 *
 * ⚠️ 后续子片: 冻结(§C nezha_offboard_frozen 全建单入口) / 净额结算放款(§F) / 审批闸(§H)
 *    / 制裁 re-screen(§D1) / 入口暴露(商家申请+超管审批 UI) / 退出前置门(§E3 订单终态·无纠纷·冷静期)。
 * ⚠️ 本服务**尚未接线上任何路径、未暴露入口** —— 生产无 store 会进 settling; 由 staging harness 直接驱动验证。
 */
class NezhaOffboard
{
    /** 冷静期天数(applied 当刻锚定, 撤回重提不重置)。 */
    public const COOLDOWN_DAYS = 20;

    /** 当前活跃退出工单(至多一条, active_uniq=1); 无则 null。 */
    public static function activeSettlement(int $vendorId): ?RestaurantOffboardSettlement
    {
        return RestaurantOffboardSettlement::where('vendor_id', $vendorId)
            ->where('active_uniq', 1)->first();
    }

    /**
     * 是否处于退出冻结态(settling) —— 停一切新单/扣佣/退费(DESIGN §C)。
     * 兼容数组/对象入参; 字段缺失(部分 select)按未冻结, 避免误伤线上下单。
     */
    public static function is_frozen($restaurant): bool
    {
        if (!$restaurant) {
            return false;
        }
        $status = is_array($restaurant)
            ? ($restaurant['offboard_status'] ?? null)
            : ($restaurant->offboard_status ?? null);
        return $status === 'settling';
    }

    /** 按 id 显式 fresh 查询是否冻结(扣佣门用, 避免调用点 lazy relation 读到 stale 'active')。 */
    public static function is_frozen_id($restaurantId): bool
    {
        if (!$restaurantId) {
            return false;
        }
        return Restaurant::where('id', $restaurantId)->value('offboard_status') === 'settling';
    }

    /** 该店 KYC 是否已通过(决定 open 落 applied 还是 kyc_pending)。 */
    protected static function isKycApproved(int $restaurantId): bool
    {
        return VendorKycProfile::where('restaurant_id', $restaurantId)->value('kyc_status') === 'approved';
    }

    /**
     * 商家申请退出: 建活跃工单 + 冷静期锚定 + offboard_status=settling(退出即冻结)。
     * KYC 未通过 → kyc_pending(前置身份核验); 已通过 → applied。
     * 幂等: 已有活跃工单原样返回; 并发撞 uq_active(1062) 亦当幂等返回既有。
     * ⚠️ 退出前置门(§E3 订单终态/无纠纷/冷静期)由暴露层在调用本方法前把关, 本方法只做状态迁移。
     */
    public static function open(Restaurant $restaurant): RestaurantOffboardSettlement
    {
        $vendorId = (int) $restaurant->vendor_id;
        if ($existing = self::activeSettlement($vendorId)) {
            return $existing;
        }

        $status = self::isKycApproved((int) $restaurant->id) ? 'applied' : 'kyc_pending';
        $now = Carbon::now();

        try {
            return DB::transaction(function () use ($restaurant, $vendorId, $status, $now) {
                $s = new RestaurantOffboardSettlement();
                $s->vendor_id      = $vendorId;
                $s->restaurant_id  = (int) $restaurant->id;
                $s->active_uniq    = 1;
                $s->status         = $status;
                $s->applied_at     = $now;
                $s->cooldown_until = $now->copy()->addDays(self::COOLDOWN_DAYS);
                $s->kyc_gate_passed = ($status === 'applied'); // KYC 已通过则入口门已过
                $s->save();

                // Restaurant fillable 很严(['food_section','status']), 直接赋属性绕过 mass-assign
                $restaurant->offboard_status = 'settling';
                $restaurant->save();
                return $s;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) { // uq_active 并发冲突 → 幂等
                if ($existing = self::activeSettlement($vendorId)) {
                    return $existing;
                }
            }
            throw $e;
        }
    }

    /**
     * 撤回退出(商家反悔 / 超管取消, 仅 approved 前): 工单 withdrawn + 解冻回 active。
     * 治"误点=永久停业"(回流边①)。无活跃工单或已进 approved 及之后 → false(不动)。
     */
    public static function withdraw(Restaurant $restaurant): bool
    {
        return DB::transaction(function () use ($restaurant) {
            $s = RestaurantOffboardSettlement::where('vendor_id', $restaurant->vendor_id)
                ->where('active_uniq', 1)->lockForUpdate()->first();
            if (!$s || !in_array($s->status, ['applied', 'kyc_pending'], true)) {
                return false;
            }
            $s->status = 'withdrawn';
            $s->active_uniq = null;
            $s->save();

            $restaurant->offboard_status = 'active';
            $restaurant->save();
            return true;
        });
    }

    /** KYC 复核通过: kyc_pending → applied(回流边②); 非该态 → false。 */
    public static function onKycApproved(Restaurant $restaurant): bool
    {
        return DB::transaction(function () use ($restaurant) {
            $s = RestaurantOffboardSettlement::where('vendor_id', $restaurant->vendor_id)
                ->where('active_uniq', 1)->lockForUpdate()->first();
            if (!$s || $s->status !== 'kyc_pending') {
                return false;
            }
            $s->status = 'applied';
            $s->kyc_gate_passed = true;
            $s->save();
            return true;
        });
    }

    /** KYC 复核拒绝: kyc_pending → active(status=rejected, 恢复营业)(回流边③); 非该态 → false。 */
    public static function onKycRejected(Restaurant $restaurant): bool
    {
        return DB::transaction(function () use ($restaurant) {
            $s = RestaurantOffboardSettlement::where('vendor_id', $restaurant->vendor_id)
                ->where('active_uniq', 1)->lockForUpdate()->first();
            if (!$s || $s->status !== 'kyc_pending') {
                return false;
            }
            $s->status = 'rejected';
            $s->active_uniq = null;
            $s->save();

            $restaurant->offboard_status = 'active';
            $restaurant->save();
            return true;
        });
    }
}
