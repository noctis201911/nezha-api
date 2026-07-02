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
 * step4-4/step5(本次): 退出前置门 eligibilityCheck(§E3) / 功能开关 offboardEnabled / 制裁实时
 *   re-screen rescreenSanctions(§D1·fail-closed) / 审批闸 H 时序 canPayNow(§H)。
 * 暴露层: 商家端 Vendor/NezhaDepositController(申请/撤回) + 超管 Admin/NezhaOffboardController(审批/放款)
 *   + KYC 联动 Admin/NezhaKycController::review。开关 nezha_offboard_status 默认关(服务端强制)。
 * ⚠️ 未部署前生产无 store 会进 settling; staging 下单 harness 作资金正确性唯一验收。
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

    // ==================== step4-4 暴露层前置 + 开关 (DESIGN §E3) ====================

    /** 商家退出功能总开关 nezha_offboard_status(默认 0 关 —— 上线灰度开关, 暴露层服务端强制)。 */
    public static function offboardEnabled(): bool
    {
        return (string) self::cfg('nezha_offboard_status', '0') === '1';
    }

    protected static function cfg(string $key, $default = null)
    {
        $v = \App\Models\BusinessSetting::where('key', $key)->value('value');
        return ($v === null || $v === '') ? $default : $v;
    }

    /** offboard_status 中文标签(商家端/后台展示)。 */
    public static function statusLabel(?string $st): string
    {
        return [
            'active'     => '正常营业',
            'settling'   => '退出结算中',
            'owing'      => '欠款待清缴',
            'offboarded' => '已退出',
        ][$st] ?? ($st ?: '正常营业');
    }

    /**
     * §E3 退出前置门 —— 商家「申请退出」暴露层在 open() 前调用。
     * 只挡「申请」(低风险: 仅起冷静期+冻结, 可 withdraw 撤回)。真实纠纷【不硬挡申请】(防举报武器化,
     * /debate 定): 待核实举报只作 warnings 提示, 其实质判断放到超管「审批放款」时人工把关(§H 红旗)。
     *   门①: 有非终态活跃单(自家订单, 外部无法武器化) → 挡。
     *   门②: 待核实举报/风控 → warning(不挡), 供商家知情。
     *   门③: 冷静期在 open() 锚定、approve() 强制, 此处不重复。
     * @return array{ok:bool, blockers:array<int,string>, warnings:array<int,string>, pending_orders:int, pending_reports:int}
     */
    public static function eligibilityCheck(Restaurant $restaurant): array
    {
        $rid = (int) $restaurant->id;
        $blockers = [];
        $warnings = [];

        $status = $restaurant->offboard_status ?? 'active';
        if ($status !== 'active') {
            $blockers[] = '当前退出状态为「' . self::statusLabel($status) . '」, 无法重复申请';
        }

        $pendingOrders = \App\Models\Order::where('restaurant_id', $rid)
            ->whereIn('order_status', self::BLOCKING_STATES)->count();
        if ($pendingOrders > 0) {
            $blockers[] = "有 {$pendingOrders} 笔进行中订单未完成, 请先处理完所有订单再申请退出";
        }

        $pendingReports = self::pendingDisputeCount($rid);
        if ($pendingReports > 0) {
            $warnings[] = "平台有 {$pendingReports} 条关于本店的待核实反馈, 退出结算前平台会先核实处理";
        }

        return [
            'ok'              => empty($blockers),
            'blockers'        => $blockers,
            'warnings'        => $warnings,
            'pending_orders'  => $pendingOrders,
            'pending_reports' => $pendingReports,
        ];
    }

    /** §E3 门②/§H 红旗: 待核实真实纠纷数 = 待处理举报(status=0) + 风控 review pending。 */
    public static function pendingDisputeCount($restaurantId): int
    {
        $rid = (int) $restaurantId;
        if ($rid <= 0) {
            return 0;
        }
        $reports = \App\Models\RestaurantReport::where('restaurant_id', $rid)
            ->where('status', \App\Models\RestaurantReport::STATUS_PENDING)->count();
        $risk = \App\Models\NezhaRiskRecord::where('restaurant_id', $rid)
            ->where('status', 'pending')->count();
        return (int) ($reports + $risk);
    }

    // ==================== step4-3 净额结算 (DESIGN §F/§C4/§C5/§C6) ====================

    /** C4 快照连续 abort 上限: 超过则熔断转人工(防 DoS 无限重算)。 */
    public const C4_ABORT_LIMIT = 3;

    /** 在途(handover/picked_up 且未 delivered)= 结算首步可推进收尾的单。 */
    protected const INFLIGHT_STATES = ['handover', 'picked_up'];
    /** 非终态活跃单: 存在即挡结算(§E3 门① + 结算 fail-closed 兜底)。public: 暴露层 eligibilityCheck 单一真相源。 */
    public const BLOCKING_STATES = ['pending', 'accepted', 'confirmed', 'processing', 'refund_requested'];

    /**
     * 退款回充冻结判断(§C3): offboard_status != active(settling/owing/offboarded)时,
     * refund_reversal 不自动回充 deposit —— 避免污染结算快照 / 把钱打进已关闭死账户漏损。
     */
    public static function is_deposit_credit_frozen($restaurantId): bool
    {
        if (!$restaurantId) {
            return false;
        }
        $st = Restaurant::where('id', $restaurantId)->value('offboard_status');
        return $st !== null && $st !== 'active';
    }

    /**
     * 退出冻结期 refund_reversal「记 frozen_reversal_owed 非回充」(§C3): 本该回充的佣金记到结算工单
     * 独立字段 frozen_reversal_owed(平台欠商家 —— 与 shortfall_amount[商家欠平台·net<0] 方向相反, 分开记),
     * 待人工核算该笔退款佣金是否退回, 不动 deposit; 审计留痕。无工单时仅留痕。
     */
    public static function recordFrozenReversalOwed($order, float $deducted): void
    {
        $vendorId = (int) ($order->restaurant->vendor->id ?? 0);
        if ($vendorId <= 0 || $deducted <= 0) {
            return;
        }
        $s = self::activeSettlement($vendorId)
            ?: RestaurantOffboardSettlement::where('vendor_id', $vendorId)->orderByDesc('id')->first();
        if ($s) {
            $s->frozen_reversal_owed = (float) $s->frozen_reversal_owed + $deducted;
            $note = trim((string) $s->note);
            $s->note = ($note !== '' ? $note . ' | ' : '')
                . '退出冻结期退款#' . ($order->id ?? '?') . ' 应返还佣金 ' . $deducted . ' 记 frozen_reversal_owed 待人工核算(非自动回充)';
            $s->save();
        }
        self::auditLog($order->restaurant_id ?? null, 'offboard_frozen_reversal', [
            'order_id'      => $order->id ?? null,
            'deducted'      => $deducted,
            'settlement_id' => $s->id ?? null,
        ]);
    }

    /** 审计留痕(复用 logs 表; 失败不影响主流程)。 */
    protected static function auditLog($restaurantId, string $action, array $details): void
    {
        try {
            \App\Models\Log::create([
                'logable_id'     => $restaurantId,
                'logable_type'   => Restaurant::class,
                'action_type'    => $action,
                'model'          => 'Restaurant',
                'model_id'       => $restaurantId,
                'action_details' => json_encode($details),
                'restaurant_id'  => $restaurantId,
            ]);
        } catch (\Throwable $e) {
            info('NezhaOffboard auditLog failed: ' . $e->getMessage());
        }
    }

    /**
     * §C5 结算首步: 把该店在途/漏结的直付单佣金受控收净到 commission_deduction。
     *   1) handover/picked_up 在途单 → settle_delivered(offboard_settle=true) 推 delivered + 收佣(绕 C2 冻结);
     *   2) 已 delivered 但缺 commission_deduction 的直付单(冻结期活线完成漏收 / 历史漏结)补收:
     *        无 order_transaction → create_transaction(offboard_settle=true) 建单 + 收佣;
     *        有 order_transaction(冻结期活线已建单未扣佣)→ 按纯函数 nezha_commissionable_amount 直接补一笔。
     * 非终态活跃单(BLOCKING_STATES)存在 → 抛异常(§E3 前置门应已拦在 applied 前, 此处 fail-closed 兜底)。
     * @return array{pushed:int,swept:int}
     */
    public static function settleInflight(Restaurant $restaurant): array
    {
        $rid = (int) $restaurant->id;

        $blocking = \App\Models\Order::where('restaurant_id', $rid)
            ->whereIn('order_status', self::BLOCKING_STATES)->count();
        if ($blocking > 0) {
            throw new \RuntimeException("offboard settleInflight: 存在 {$blocking} 笔非终态活跃单, 拒绝结算(应先处理完 / 由 §E3 拦截)");
        }

        $commActive = \App\Http\Controllers\Api\V1\OrderController::nezha_commission_active($restaurant);
        $pushed = 0;
        $swept = 0;

        // 1) 在途单 → settle_delivered(bypass 冻结, 收佣)
        $inflightIds = \App\Models\Order::where('restaurant_id', $rid)
            ->whereIn('order_status', self::INFLIGHT_STATES)
            ->whereNull('delivered')->pluck('id');
        foreach ($inflightIds as $oid) {
            $o = \App\Models\Order::find($oid);
            if (!$o) {
                continue;
            }
            $ok = \App\CentralLogics\OrderLogic::settle_delivered($o, 'offboard', null, true);
            if (!$ok) {
                throw new \RuntimeException("offboard settleInflight: 在途单#{$oid} 结算失败, 中止(fail-closed)");
            }
            $pushed++;
        }

        // 2) 已 delivered 直付单缺 commission_deduction → 补收(仅抽佣开启时才计佣)
        if ($commActive) {
            $delivered = \App\Models\Order::where('restaurant_id', $rid)
                ->where('order_status', 'delivered')
                ->where('payment_method', 'offline_payment')->get();
            foreach ($delivered as $o) {
                $hasComm = \App\Models\RestaurantDepositTransaction::where('order_id', $o->id)
                    ->where('type', 'commission_deduction')->exists();
                if ($hasComm) {
                    continue;
                }
                $hasTxn = \App\Models\OrderTransaction::where('order_id', $o->id)->exists();
                if (!$hasTxn) {
                    // 真漏结单: create_transaction 建单 + 收佣(bypass)
                    \App\CentralLogics\OrderLogic::create_transaction($o, 'admin', null, true);
                    $swept++;
                } else {
                    // 冻结期活线已建单未扣佣: 纯函数直接补一笔 commission_deduction(已过滤缺佣单, 幂等)
                    $comm = (float) (\App\CentralLogics\OrderLogic::nezha_commissionable_amount($o)['amount'] ?? 0);
                    if ($comm > 0) {
                        $vid = (int) $o->restaurant->vendor->id;
                        $fresh = (float) (\App\Models\RestaurantWallet::where('vendor_id', $vid)->lockForUpdate()->value('deposit_balance') ?? 0);
                        $after = $fresh - $comm;
                        \App\Models\RestaurantWallet::where('vendor_id', $vid)->update(['deposit_balance' => $after, 'updated_at' => now()]);
                        \App\Models\RestaurantDepositTransaction::insert([
                            'vendor_id'     => $vid,
                            'restaurant_id' => $o->restaurant->id,
                            'order_id'      => $o->id,
                            'type'          => 'commission_deduction',
                            'amount'        => -1 * $comm,
                            'commission'    => $comm,
                            'balance_after' => $after,
                            'note'          => '订单#' . $o->id . ' 退出结算补收佣金(冻结期活线漏收)',
                            'created_at'    => now(),
                            'updated_at'    => now(),
                        ]);
                        $swept++;
                    }
                }
            }
        }
        return ['pushed' => $pushed, 'swept' => $swept];
    }

    /**
     * §B applied → approved: 结算首步收净在途佣金(§C5) → 锁定净额快照(§F: net = 三账户和 − pending_clawback)。
     * 前置门 fail-closed(真实 §D 制裁/户名核对在 step5/暴露层做, 本方法只认标志位, 未置即拒):
     *   status=applied · 冷静期已过 · sanction_rescreen_at 已置(制裁 re-screen 过) · holder_verified(户名核对过)。
     * net<0 → offboard_status=owing(不放款, 人工追缴, shortfall+=|net|); net>=0 → status=approved(待 pay 放款)。
     * @return bool 是否成功推进
     */
    public static function approve(RestaurantOffboardSettlement $settlement, int $adminId): bool
    {
        return DB::transaction(function () use ($settlement, $adminId) {
            $s = RestaurantOffboardSettlement::where('id', $settlement->id)->lockForUpdate()->first();
            if (!$s || $s->status !== 'applied') {
                return false;
            }
            if ($s->cooldown_until && Carbon::now()->lt($s->cooldown_until)) {
                return false; // 冷静期未过
            }
            if ($s->sanction_rescreen_at === null) {
                return false; // §D1 制裁 re-screen 未过(step5 置位)
            }
            if (!$s->holder_verified) {
                return false; // §D3 户名核对未过
            }

            $restaurant = Restaurant::find($s->restaurant_id);
            if (!$restaurant) {
                return false;
            }

            // §C5 收净在途/漏结佣金(非终态活跃单会抛→整个 approve 回滚)
            self::settleInflight($restaurant);

            // §F net = deposit + guarantee + ad − pending_clawback (抵扣只从 deposit; commission 已扣净; ad/guarantee 独立)
            $w = \App\Models\RestaurantWallet::where('vendor_id', $s->vendor_id)->lockForUpdate()->first();
            $deposit   = (float) ($w->deposit_balance ?? 0);
            $ad        = (float) ($w->ad_balance ?? 0);
            $guarantee = (float) ($w->guarantee_balance ?? 0);
            $clawback  = (float) $s->pending_clawback; // 现恒 0(预留垫付追偿钩子)
            $net = $deposit + $guarantee + $ad - $clawback;

            $s->deposit_amt   = $deposit;
            $s->ad_amt        = $ad;
            $s->guarantee_amt = $guarantee;
            $s->net_amount    = $net;
            $s->approved_by   = $adminId;
            $s->approved_at   = Carbon::now();
            $s->status        = 'approved';

            if ($net < 0) {
                $s->shortfall_amount = (float) $s->shortfall_amount + abs($net);
                $s->save();
                $restaurant->offboard_status = 'owing';
                $restaurant->save();
                self::auditLog($s->restaurant_id, 'offboard_approved_owing', ['net' => $net, 'settlement_id' => $s->id]);
            } else {
                $s->save();
                self::auditLog($s->restaurant_id, 'offboard_approved', ['net' => $net, 'settlement_id' => $s->id]);
            }
            return true;
        });
    }

    /**
     * §C4/§C6 approved → paying → paid/partial: 快照拒付(C4) + 三腿原子置零 + 逐腿幂等。
     * C4: 首次(approved 且无腿已付)锁 wallet 重读三余额 vs approved 快照; 一致才放款;
     *   不一致→abort(重跑 settleInflight 重算 net 重快照, 回 approved 待重试; 连续超 C4_ABORT_LIMIT 熔断 failed)。
     * 三腿: deposit_refund/ad_refund/guarantee_refund 各置零本账户(INV-1 不跨户), 写 balance_after=0 流水, 标 leg_*_paid。
     * 幂等: 已 paid 腿跳过; settlement 行 lockForUpdate 串行化并发防双付。net<0 → owing 不放款。
     * @return string paid|partial|owing|aborted|failed|noop
     */
    public static function pay(RestaurantOffboardSettlement $settlement): string
    {
        return DB::transaction(function () use ($settlement) {
            $s = RestaurantOffboardSettlement::where('id', $settlement->id)->lockForUpdate()->first();
            if (!$s || !in_array($s->status, ['approved', 'paying', 'partial'], true)) {
                return 'noop';
            }
            if ((float) $s->net_amount < 0) {
                $r = Restaurant::find($s->restaurant_id);
                if ($r && $r->offboard_status !== 'owing') {
                    $r->offboard_status = 'owing';
                    $r->save();
                }
                return 'owing';
            }

            $w = \App\Models\RestaurantWallet::where('vendor_id', $s->vendor_id)->lockForUpdate()->first();
            if (!$w) {
                return 'noop';
            }

            // C4: 仅首次(approved 且三腿皆未付)校验三余额 vs approved 快照
            if ($s->status === 'approved' && !$s->leg_deposit_paid && !$s->leg_ad_paid && !$s->leg_guarantee_paid) {
                $mismatch = abs((float) $w->deposit_balance - (float) $s->deposit_amt) > 0.005
                    || abs((float) $w->ad_balance - (float) $s->ad_amt) > 0.005
                    || abs((float) $w->guarantee_balance - (float) $s->guarantee_amt) > 0.005;
                if ($mismatch) {
                    return self::handleC4Abort($s);
                }
                $s->status = 'paying';
                $s->save();
            }

            // 三腿(逐腿幂等; INV-1: 每腿只动本账户列)
            if (!$s->leg_deposit_paid) {
                self::payLeg($w, 'deposit_balance', 'deposit_refund', $s);
                $s->leg_deposit_paid = true;
            }
            if (!$s->leg_ad_paid) {
                self::payLeg($w, 'ad_balance', 'ad_refund', $s);
                $s->leg_ad_paid = true;
            }
            if (!$s->leg_guarantee_paid) {
                self::payLeg($w, 'guarantee_balance', 'guarantee_refund', $s);
                $s->leg_guarantee_paid = true;
            }

            if ($s->leg_deposit_paid && $s->leg_ad_paid && $s->leg_guarantee_paid) {
                $s->status = 'paid';
                $s->active_uniq = null;
                $s->payout_ref = 'OFB-' . $s->id . '-' . Carbon::now()->format('YmdHis');
                $s->save();
                $r = Restaurant::find($s->restaurant_id);
                if ($r) {
                    $r->offboard_status = 'offboarded';
                    $r->save();
                }
                self::auditLog($s->restaurant_id, 'offboard_paid', [
                    'net' => (float) $s->net_amount, 'settlement_id' => $s->id, 'payout_ref' => $s->payout_ref,
                ]);
                return 'paid';
            }

            $s->status = 'partial';
            $s->save();
            return 'partial';
        });
    }

    /** 单腿置零 + 写 *_refund 流水(balance_after=0)。INV-1: 仅动 $balanceCol 一个账户列。 */
    protected static function payLeg(\App\Models\RestaurantWallet $w, string $balanceCol, string $refundType, RestaurantOffboardSettlement $s): void
    {
        $bal = (float) $w->{$balanceCol};
        $delta = -1 * $bal; // 置零 delta: bal>0→负(退给商家); bal<0(deposit 欠佣)→正(抹平)
        $w->{$balanceCol} = 0;
        $w->save();
        \App\Models\RestaurantDepositTransaction::insert([
            'vendor_id'     => $s->vendor_id,
            'restaurant_id' => $s->restaurant_id,
            'order_id'      => null,
            'type'          => $refundType,
            'amount'        => $delta,
            'commission'    => 0,
            'balance_after' => 0,
            'note'          => '退出结算 ' . $refundType . ' 置零(工单#' . $s->id . ', 原额 ' . $bal . ')',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    /** C4 快照不一致: 重跑 settleInflight 重算 net 重快照, 回 approved 待重试; 连续超限熔断 failed。 */
    protected static function handleC4Abort(RestaurantOffboardSettlement $s): string
    {
        $marker = '[C4-abort]';
        $count = substr_count((string) $s->note, $marker) + 1;
        if ($count > self::C4_ABORT_LIMIT) {
            $s->status = 'failed';
            $s->note = trim((string) $s->note . ' ' . $marker . '#' . $count . ' 熔断转人工');
            $s->save();
            self::auditLog($s->restaurant_id, 'offboard_c4_circuit_break', ['aborts' => $count, 'settlement_id' => $s->id]);
            return 'failed';
        }
        $restaurant = Restaurant::find($s->restaurant_id);
        if ($restaurant) {
            self::settleInflight($restaurant); // 收可能新增的在途佣金
        }
        $w = \App\Models\RestaurantWallet::where('vendor_id', $s->vendor_id)->lockForUpdate()->first();
        $deposit   = (float) ($w->deposit_balance ?? 0);
        $ad        = (float) ($w->ad_balance ?? 0);
        $guarantee = (float) ($w->guarantee_balance ?? 0);
        $net = $deposit + $guarantee + $ad - (float) $s->pending_clawback;
        $s->deposit_amt = $deposit;
        $s->ad_amt = $ad;
        $s->guarantee_amt = $guarantee;
        $s->net_amount = $net;
        $s->status = 'approved'; // 回 approved 待重试(作废旧快照, 新快照已写)
        $s->note = trim((string) $s->note . ' ' . $marker . '#' . $count . '@' . Carbon::now()->format('H:i:s'));
        $s->save();
        self::auditLog($s->restaurant_id, 'offboard_c4_abort', ['aborts' => $count, 'net' => $net, 'settlement_id' => $s->id]);
        return 'aborted';
    }

    // ==================== step5 制裁实时 re-screen(§D1) + 审批闸 H 时序(§H) ====================

    /** 高额退款阈值(净额≥此值→审批闸 H 强制次日转)。L2 可调 business_setting nezha_offboard_high_amount_amd。 */
    public const H_HIGH_AMOUNT_DEFAULT_AMD = 500000;
    /** 高额放款须与审批间隔小时数(单超管「双人复核」等价替身之次日转, §H/§D3)。 */
    public const H_HIGH_AMOUNT_DELAY_HOURS = 24;

    public static function highAmountThreshold(): float
    {
        return (float) self::cfg('nezha_offboard_high_amount_amd', self::H_HIGH_AMOUNT_DEFAULT_AMD);
    }

    /**
     * §D1 制裁实时 re-screen(step5): 审批放款前用【当前】OFAC SDN 名单实时 RE-run screen_names,
     * 【不读入驻旧 screen_status 列】(名单每日刷新、旧列会空转; L1-6 / INVARIANTS L1-8③)。
     *   clear    → 置 sanction_rescreen_at(供 approve() 放行) + 写回 profile.screen_*, ok=true。
     *   possible → 不置标志位(fail-closed), 转人工 AML(record_risk review/pending), ok=false。
     *   hit      → 不置标志位; 工单 rejected + 恢复营业(不给退款) + record_risk(reject/auto), ok=false。
     *   无 KYC / 无姓名 → fail-closed(不置位), ok=false。
     * @return array{ok:bool, status:string, detail:string}
     */
    public static function rescreenSanctions(RestaurantOffboardSettlement $settlement): array
    {
        $profile = VendorKycProfile::where('restaurant_id', $settlement->restaurant_id)->first();
        if (!$profile) {
            return ['ok' => false, 'status' => 'no_kyc', 'detail' => '无 KYC 资料, 无法制裁核验(fail-closed)'];
        }
        $names = array_values(array_filter(
            [$profile->legal_name, $profile->beneficial_owner_name],
            fn ($n) => trim((string) $n) !== ''
        ));
        if (empty($names)) {
            return ['ok' => false, 'status' => 'no_name', 'detail' => 'KYC 无法人/受益人姓名, 无法制裁核验(fail-closed)'];
        }

        $screen = \App\CentralLogics\NezhaKycScreen::screen_names($names);
        $st = $screen['status'] ?? 'possible';
        \App\CentralLogics\NezhaKycScreen::apply_to_profile($profile, $screen); // 留痕(复用 screen_* 列)

        if ($st === 'clear') {
            DB::transaction(function () use ($settlement) {
                $s = RestaurantOffboardSettlement::where('id', $settlement->id)->lockForUpdate()->first();
                if ($s) {
                    $s->sanction_rescreen_at = Carbon::now();
                    $s->save();
                }
            });
            self::auditLog($settlement->restaurant_id, 'offboard_sanction_rescreen_clear', [
                'settlement_id' => $settlement->id,
            ]);
            return ['ok' => true, 'status' => 'clear', 'detail' => (string) ($screen['detail'] ?? '')];
        }

        // possible / hit → fail-closed(绝不置 sanction_rescreen_at → approve() 拒放行)
        \App\CentralLogics\NezhaKycScreen::record_risk($settlement->restaurant_id, null, $screen, 'offboard_rescreen');
        if ($st === 'hit') {
            DB::transaction(function () use ($settlement) {
                $s = RestaurantOffboardSettlement::where('id', $settlement->id)->lockForUpdate()->first();
                if ($s && in_array($s->status, ['applied', 'kyc_pending'], true)) {
                    $s->status = 'rejected';
                    $s->active_uniq = null;
                    $s->note = trim((string) $s->note . ' | 制裁名单命中(L1-6): 拒绝退出放款, 转人工 AML');
                    $s->save();
                    $r = Restaurant::find($s->restaurant_id);
                    if ($r) {
                        $r->offboard_status = 'active'; // 恢复营业(不给退款; 受制裁主体的经营处置走单独 AML 流程)
                        $r->save();
                    }
                }
            });
        }
        self::auditLog($settlement->restaurant_id, 'offboard_sanction_' . $st, [
            'settlement_id' => $settlement->id,
            'detail'        => mb_substr((string) ($screen['detail'] ?? ''), 0, 500),
        ]);
        return ['ok' => false, 'status' => $st, 'detail' => (string) ($screen['detail'] ?? '')];
    }

    /**
     * §H 审批闸时序: 放款前校验。高额(net≥阈值)强制 approved 后满 H_HIGH_AMOUNT_DELAY_HOURS(次日转)
     * 才可放款 —— 单超管无第二人时以「异步二次确认 + 时间窗」当双人复核等价替身。
     * partial 续付不再卡时序(首腿放款时已过闸)。
     * @return array{ok:bool, reason:string}
     */
    public static function canPayNow(RestaurantOffboardSettlement $s): array
    {
        if (!in_array($s->status, ['approved', 'paying', 'partial'], true)) {
            return ['ok' => false, 'reason' => '工单状态非可放款态'];
        }
        if ($s->status === 'approved' && (float) $s->net_amount >= self::highAmountThreshold()) {
            $approvedAt = $s->approved_at ? Carbon::parse($s->approved_at) : null;
            $readyAt = $approvedAt ? $approvedAt->copy()->addHours(self::H_HIGH_AMOUNT_DELAY_HOURS) : null;
            if ($readyAt && Carbon::now()->lt($readyAt)) {
                return ['ok' => false, 'reason' => '高额退款(净额≥' . (int) self::highAmountThreshold() . '֏)须审批满 '
                    . self::H_HIGH_AMOUNT_DELAY_HOURS . ' 小时后放款; 可放款时间 ' . $readyAt->format('Y-m-d H:i')];
            }
        }
        return ['ok' => true, 'reason' => ''];
    }
}
