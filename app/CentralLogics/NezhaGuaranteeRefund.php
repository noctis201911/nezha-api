<?php

namespace App\CentralLogics;

use App\Models\Restaurant;
use App\Models\RestaurantWallet;
use App\Models\RestaurantDepositTransaction;
use App\Models\NezhaTopupRequest;
use App\Models\VendorKycProfile;
use App\Http\Controllers\Admin\NezhaDepositController;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒 中途退回押金 (A3 · S3-B) — 营业中押金退还的 L1-8 状态机核心 (运营核算制 · 方案A)。
 *
 * 与 NezhaOffboard(完整离场全退) 并存互补: 本类只管商家【仍在营业】时退回【部分/超额】押金,
 * 走 nezha_topup_requests(direction=refund·account_type=guarantee)。开关 nezha_topup_refund_status 默认0 dormant。
 *
 * /debate 三路红队(资金安全/合规L1-8/防薅内控)硬化 + 业主批准(2026-07-03·CHANGELOG)。核心教训:
 * offboard 的安全靠"settling全冻结/全额置零/uq_active/离场settleInflight"四前提,中途退场景统统不成立,
 * 故本类【不抄门、抄锁+补前提】。逐门(编号对应 debate 阻断项):
 *   G0 开关+互斥(offboard settling/owing/offboarded 皆挡, 用 is_deposit_credit_frozen 非 is_frozen) [#8]
 *   G1 欠款/敞口门: deposit_balance<0 挡; 抽佣开启须人工核实敞口(manual_exposure_confirmed) [#1]
 *   G2 可退额门: tier=NULL→fail-closed; exempt→引导offboard; floor=tier×汇率(锁内当刻); 0<X≤guarantee−floor [#4]
 *   G3 制裁实时复筛: 每笔都筛(不分金额)·四态fail-closed·approve+pay 各筛一次(新鲜度) [#6]
 *   G4 户名核对(代码强制): normalize(legal_name)==normalize(account_holder_name)·收款账户锁定KYC·KYC申请后变更即挡·无override [#5]
 *   G5 原子放款: approve/pay 全程 request行锁+钱包行锁; pay C4 快照校验防竞态; active_refund_uniq 唯一约束 [#2#3]
 *   G6 频率+异步二次闸: 同店最小间隔/月上限; 高额或单运营超日额→scheduled_pay_at 次日转 [#7#9]
 * 四处对账对称(S3-A 负向): X == |流水amount| == guarantee_balance 冲减量 == 对账中心 guarantee_refund 汇总。
 * 放款主体=平台线下法币转账到 KYC 锁定账户,系统只记录留痕,不接网关/不自动扣款(L1-8① 法币-only)。
 */
class NezhaGuaranteeRefund
{
    /** 高额阈值(≥→次日转), 复用 offboard 同一 L2 键。 */
    public const HIGH_AMOUNT_DEFAULT_AMD = 500000;
    public const HIGH_AMOUNT_DELAY_HOURS = 24;

    protected static function cfg(string $key, $default = null)
    {
        $v = DB::table('business_settings')->where('key', $key)->value('value');
        return ($v === null || $v === '') ? $default : $v;
    }

    /** 中途退款总开关(默认0 dormant, 服务端强制)。 */
    public static function refundEnabled(): bool
    {
        return (int) self::cfg('nezha_topup_refund_status', 0) === 1;
    }

    /** 抽佣是否开启(决定是否需人工核实敞口)。 */
    public static function commissionActive(): bool
    {
        return (int) self::cfg('nezha_deposit_mode_status', 0) === 1;
    }

    protected static function rateCny(): float
    {
        return (float) (self::cfg('nezha_rate_cny_to_amd', 55) ?: 55);
    }

    public static function highAmountThreshold(): float
    {
        return (float) self::cfg('nezha_offboard_high_amount_amd', self::HIGH_AMOUNT_DEFAULT_AMD);
    }

    /** 同店退款最小间隔天数 / 每月上限(L2 可调)。 */
    public static function minIntervalDays(): int
    {
        return (int) self::cfg('nezha_refund_guarantee_min_interval_days', 30);
    }

    public static function monthlyCap(): int
    {
        return (int) self::cfg('nezha_refund_guarantee_monthly_cap', 1);
    }

    /** 单运营单日累计放款额/笔数阈值(超→后续强制次日转 anti-hijack)。 */
    public static function operatorDailyAmd(): float
    {
        return (float) self::cfg('nezha_refund_operator_daily_amd', 500000);
    }

    public static function operatorDailyCount(): int
    {
        return (int) self::cfg('nezha_refund_operator_daily_count', 3);
    }

    /**
     * 最低留存 floor(AMD): tier→CNY档×汇率。
     * 返回 ['ok'=>bool,'floor'=>float,'reason'=>?string,'tier'=>?string]; tier=NULL/exempt 各自 fail-closed / 引导 offboard。
     */
    public static function tierFloorAmd(Restaurant $r): array
    {
        $tier = $r->guarantee_tier;
        if ($tier === null || $tier === '') {
            return ['ok' => false, 'floor' => INF, 'reason' => '押金应缴档未设, 无法核定最低留存(fail-closed)', 'tier' => null];
        }
        if ($tier === 'exempt') {
            return ['ok' => false, 'floor' => INF, 'reason' => '豁免档不走营业中退款, 请走退出结算(offboard)', 'tier' => 'exempt'];
        }
        $cny = NezhaDepositController::GUARANTEE_TIERS_CNY[$tier] ?? null;
        if ($cny === null) {
            return ['ok' => false, 'floor' => INF, 'reason' => '押金档位非法(fail-closed)', 'tier' => $tier];
        }
        return ['ok' => true, 'floor' => round((float) $cny * self::rateCny(), 2), 'reason' => null, 'tier' => $tier];
    }

    /**
     * 只读核算上下文(供审批页展示 + 逐门预判, 不 mutate)。
     * @return array{guarantee:float,deposit:float,tier:?string,floor:float,refundable:float,commission_active:bool,blockers:array<int,string>}
     */
    public static function computeContext(Restaurant $r): array
    {
        $w = RestaurantWallet::where('vendor_id', $r->vendor_id)->first();
        $guarantee = (float) ($w->guarantee_balance ?? 0);
        $deposit   = (float) ($w->deposit_balance ?? 0);
        $blockers  = [];

        if (!self::refundEnabled()) {
            $blockers[] = '中途退款开关未开启(dormant)';
        }
        if (\App\CentralLogics\NezhaOffboard::is_deposit_credit_frozen($r->id)) {
            $blockers[] = '商家处于退出结算/欠款/已退出态, 不可中途退款';
        }
        if ($deposit < 0) {
            $blockers[] = '商家预存佣金为负(欠佣金), 押金为兜底不可退';
        }
        $fl = self::tierFloorAmd($r);
        $floor = $fl['ok'] ? (float) $fl['floor'] : INF;
        if (!$fl['ok']) {
            $blockers[] = $fl['reason'];
        }
        $refundable = $fl['ok'] ? max(0, round($guarantee - $floor, 2)) : 0.0;
        if (self::commissionActive()) {
            $blockers[] = '抽佣已开启: 须运营人工核实真实敞口后勾选确认, 方可放款';
        }

        return [
            'guarantee'         => $guarantee,
            'deposit'           => $deposit,
            'tier'              => $fl['tier'],
            'floor'             => $fl['ok'] ? $floor : 0.0,
            'refundable'        => $refundable,
            'commission_active' => self::commissionActive(),
            'blockers'          => $blockers,
        ];
    }

    /**
     * G3 制裁实时复筛(每笔·四态 fail-closed)。clear→置 sanction_rescreen_at + 留痕; possible/hit/no_kyc/no_name→不置位·转人工。
     * 与 offboard rescreenSanctions 同口径(名单每日刷新·用当前名单·不读旧列)。
     * @return array{ok:bool,status:string,detail:string}
     */
    public static function rescreen(NezhaTopupRequest $req): array
    {
        $profile = VendorKycProfile::where('restaurant_id', $req->restaurant_id)->first();
        if (!$profile) {
            return ['ok' => false, 'status' => 'no_kyc', 'detail' => '无 KYC 资料, 无法制裁核验(fail-closed)'];
        }
        $names = array_values(array_filter(
            [$profile->legal_name, $profile->beneficial_owner_name],
            fn ($n) => trim((string) $n) !== ''
        ));
        if (empty($names)) {
            return ['ok' => false, 'status' => 'no_name', 'detail' => 'KYC 无法人/受益人姓名(fail-closed)'];
        }

        $screen = NezhaKycScreen::screen_names($names);
        $st = $screen['status'] ?? 'possible';
        NezhaKycScreen::apply_to_profile($profile, $screen);

        if ($st === 'clear') {
            $req->sanction_rescreen_at = Carbon::now();
            $req->save();
            return ['ok' => true, 'status' => 'clear', 'detail' => (string) ($screen['detail'] ?? '')];
        }

        // possible / hit → fail-closed(绝不置位), 转人工留痕
        NezhaKycScreen::record_risk($req->restaurant_id, null, $screen, 'guarantee_refund_rescreen');
        return ['ok' => false, 'status' => $st, 'detail' => (string) ($screen['detail'] ?? '')];
    }

    /**
     * G4 户名核对(代码强制, L1-8②): normalize(legal_name)==normalize(account_holder_name),
     * 且 KYC 资料在退款申请提交后【无变更】(变更即挡, 无 override — 防"申请后改成第三方账户")。
     * 收款账户【锁定】为 KYC account_holder_name(运营不得现填, 见暴露层)。
     * 注: "缴纳凭证付款人"第三锚点当前缴纳侧未结构化捕获(original_ref=回执号), 由审批页显示回执供人工交叉核; 缴纳侧补付款人姓名为后续。
     * @return array{ok:bool,detail:string,masked:string}
     */
    public static function verifyHolder(NezhaTopupRequest $req): array
    {
        $profile = VendorKycProfile::where('restaurant_id', $req->restaurant_id)->first();
        if (!$profile) {
            return ['ok' => false, 'detail' => '无 KYC 资料, 无法核对收款账户户名', 'masked' => ''];
        }
        $legal  = trim((string) $profile->legal_name);
        $holder = trim((string) $profile->account_holder_name);
        $bank   = trim((string) $profile->bank_account);
        if ($legal === '' || $holder === '' || $bank === '') {
            return ['ok' => false, 'detail' => 'KYC 法人姓名 / 收款户名 / 收款账户 有缺失, 无法核对', 'masked' => ''];
        }
        // ⚠️ 户名核对【不能】用 NezhaKycScreen::normalize_name —— 它 preg_replace('/[^A-Z0-9 ]+/') 会把中文剥成空串,
        //    致两个不同中文名都归一化成 '' 而"相等", 对中文商户(哪吒主力)完全失效。用 CJK-safe 归一化。
        $nl = self::normHolder($legal);
        $nh = self::normHolder($holder);
        if ($nl === '' || $nh === '' || $nl !== $nh) {
            return ['ok' => false, 'detail' => 'KYC 法人姓名与收款户名不一致, 拒绝放款(防退第三方)', 'masked' => self::maskTail($bank)];
        }
        // KYC 身份/收款账户在申请后有变更 → 挡(无 override): 用【身份指纹】对比(免疫制裁复筛对 screen_* 的写回致 updated_at 误报)
        $curFp = self::kycFingerprint($profile);
        if ($curFp === null) {
            return ['ok' => false, 'detail' => 'KYC 身份信息不完整, 无法核对收款账户', 'masked' => self::maskTail($bank)];
        }
        if (!$req->kyc_apply_fp) {
            return ['ok' => false, 'detail' => '退款申请缺少身份指纹, 请商家重新提交申请', 'masked' => self::maskTail($bank)];
        }
        if (!hash_equals((string) $req->kyc_apply_fp, $curFp)) {
            return ['ok' => false, 'detail' => 'KYC 身份/收款账户在退款申请提交后有变更, 请商家重新提交申请后再核', 'masked' => self::maskTail($bank)];
        }
        return ['ok' => true, 'detail' => '户名一致', 'masked' => self::maskTail($bank)];
    }

    /**
     * KYC 身份指纹 = sha256(normHolder(法人)|normHolder(户名)|去空白账户)。
     * 只哈希身份三字段(不含 screen_ 状态列 / updated_at) → 制裁复筛写回不影响; 供申请当刻捕获 + 放款对比。
     * 身份字段有缺失 → null(fail-closed)。供 Vendor 申请端点调用捕获。
     */
    public static function kycFingerprint(?VendorKycProfile $p): ?string
    {
        if (!$p) {
            return null;
        }
        $legal  = self::normHolder((string) $p->legal_name);
        $holder = self::normHolder((string) $p->account_holder_name);
        $bank   = preg_replace('/\s+/u', '', (string) $p->bank_account);
        if ($legal === '' || $holder === '' || $bank === '') {
            return null;
        }
        return hash('sha256', $legal . '|' . $holder . '|' . $bank);
    }

    /** CJK-safe 户名归一化(保留中文, 只去大小写/空白/标点) —— 区别于制裁 normalize_name(剥中文), 防中文名对比失效。 */
    protected static function normHolder(string $s): string
    {
        $s = mb_strtoupper(trim($s));
        $s = preg_replace('/\s+/u', '', (string) $s);
        $s = preg_replace('/[[:punct:]]+/u', '', (string) $s);
        return (string) $s;
    }

    protected static function maskTail(string $s): string
    {
        $s = preg_replace('/\s+/', '', $s);
        return strlen($s) <= 4 ? str_repeat('*', max(0, strlen($s))) : ('****' . substr($s, -4));
    }

    /** G6 同店频率闸: 最小间隔 + 每月上限。@return array{ok:bool,reason:string} */
    public static function frequencyGate(int $vendorId): array
    {
        $last = NezhaTopupRequest::where('vendor_id', $vendorId)
            ->where('direction', 'refund')->where('account_type', 'guarantee')
            ->whereIn('status', ['approved', 'paid'])
            ->orderByDesc('id')->first();
        if ($last && $last->reviewed_at && Carbon::parse($last->reviewed_at)->gt(Carbon::now()->subDays(self::minIntervalDays()))) {
            return ['ok' => false, 'reason' => '距上次押金退款不足 ' . self::minIntervalDays() . ' 天'];
        }
        $monthCount = NezhaTopupRequest::where('vendor_id', $vendorId)
            ->where('direction', 'refund')->where('account_type', 'guarantee')
            ->whereIn('status', ['approved', 'paid'])
            ->where('created_at', '>=', Carbon::now()->startOfMonth())->count();
        if ($monthCount >= self::monthlyCap()) {
            return ['ok' => false, 'reason' => '本月押金退款已达上限 ' . self::monthlyCap() . ' 笔'];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /** G6 单运营当日累计放款额/笔数是否已超阈(超→本笔强制次日转)。 */
    public static function operatorDailyExceeded(int $adminId, float $amount): bool
    {
        $todayStart = Carbon::now()->startOfDay();
        $q = NezhaTopupRequest::where('operator_id', $adminId)
            ->where('direction', 'refund')->where('account_type', 'guarantee')
            ->where('status', 'paid')->where('reviewed_at', '>=', $todayStart);
        $sum = (float) $q->sum('amount_credited');
        $cnt = (int) $q->count();
        return ($sum + $amount) >= self::operatorDailyAmd() || ($cnt + 1) > self::operatorDailyCount();
    }

    /**
     * 审批(pending→approved): 钱包行锁内跑 G0–G4 + 频率闸, 通过则锁定快照 + 定放款时点(高额/超日额→次日转)。
     * @param bool $manualExposureConfirmed 抽佣开启时运营须已人工核实真实敞口
     * @return array{ok:bool,reason:string,scheduled:bool,pay_at:?string}
     */
    public static function approve(NezhaTopupRequest $request, float $amount, int $adminId, bool $manualExposureConfirmed = false): array
    {
        try {
            return DB::transaction(function () use ($request, $amount, $adminId, $manualExposureConfirmed) {
                $req = NezhaTopupRequest::where('id', $request->id)
                    ->where('direction', 'refund')->where('account_type', 'guarantee')
                    ->lockForUpdate()->first();
                if (!$req || $req->status !== 'pending') {
                    return ['ok' => false, 'reason' => '该退款申请无法审批(可能已处理或不存在)', 'scheduled' => false, 'pay_at' => null];
                }
                $r = Restaurant::where('id', $req->restaurant_id)->first();
                if (!$r) {
                    return ['ok' => false, 'reason' => '商家不存在', 'scheduled' => false, 'pay_at' => null];
                }

                // G0 开关 + 互斥
                if (!self::refundEnabled()) {
                    return ['ok' => false, 'reason' => '中途退款开关未开启', 'scheduled' => false, 'pay_at' => null];
                }
                if (\App\CentralLogics\NezhaOffboard::is_deposit_credit_frozen($r->id)) {
                    return ['ok' => false, 'reason' => '商家处于退出结算/欠款/已退出态, 不可中途退款', 'scheduled' => false, 'pay_at' => null];
                }

                // 钱包行锁(全程串行化同店并发写钱)
                $w = RestaurantWallet::where('vendor_id', $r->vendor_id)->lockForUpdate()->first();
                $guarantee = (float) ($w->guarantee_balance ?? 0);
                $deposit   = (float) ($w->deposit_balance ?? 0);

                // G1 欠款 / 敞口
                if ($deposit < 0) {
                    return ['ok' => false, 'reason' => '商家欠佣金(预存佣金为负), 押金为兜底不可退', 'scheduled' => false, 'pay_at' => null];
                }
                if (self::commissionActive() && !$manualExposureConfirmed) {
                    return ['ok' => false, 'reason' => '抽佣已开启: 须人工核实真实敞口并勾选确认后放款', 'scheduled' => false, 'pay_at' => null];
                }

                // G2 可退额(锁内当刻算 floor/汇率)
                $fl = self::tierFloorAmd($r);
                if (!$fl['ok']) {
                    return ['ok' => false, 'reason' => $fl['reason'], 'scheduled' => false, 'pay_at' => null];
                }
                $floor = (float) $fl['floor'];
                $refundable = round($guarantee - $floor, 2);
                $amount = round($amount, 2);
                if ($amount <= 0) {
                    return ['ok' => false, 'reason' => '退款额须大于 0', 'scheduled' => false, 'pay_at' => null];
                }
                if ($amount > $refundable + 0.005) {
                    return ['ok' => false, 'reason' => '超过可退额(余额 ' . $guarantee . ' − 最低留存 ' . $floor . ' = ' . max(0, $refundable) . ')', 'scheduled' => false, 'pay_at' => null];
                }

                // G3 制裁复筛(每笔)
                $sc = self::rescreen($req);
                if (!$sc['ok']) {
                    return ['ok' => false, 'reason' => '制裁复筛未通过(' . $sc['status'] . '), 转人工 AML', 'scheduled' => false, 'pay_at' => null];
                }

                // G4 户名核对(代码强制)
                $hv = self::verifyHolder($req);
                if (!$hv['ok']) {
                    return ['ok' => false, 'reason' => $hv['detail'], 'scheduled' => false, 'pay_at' => null];
                }
                $req->holder_verified = true;

                // G6 频率闸
                $fq = self::frequencyGate($r->vendor_id);
                if (!$fq['ok']) {
                    return ['ok' => false, 'reason' => $fq['reason'], 'scheduled' => false, 'pay_at' => null];
                }

                // 锁定快照 + 定放款时点
                $highRisk = $amount >= self::highAmountThreshold() || self::operatorDailyExceeded($adminId, $amount);
                $payAt = $highRisk ? Carbon::now()->addHours(self::HIGH_AMOUNT_DELAY_HOURS) : Carbon::now();

                $req->guarantee_snapshot = $guarantee;
                $req->amount_credited    = $amount;
                $req->operator_id        = $adminId;
                $req->approved_at        = Carbon::now();
                $req->scheduled_pay_at   = $payAt;
                $req->status             = 'approved';
                $req->save();

                return ['ok' => true, 'reason' => '', 'scheduled' => $highRisk, 'pay_at' => $payAt->format('Y-m-d H:i')];
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return ['ok' => false, 'reason' => '并发冲突, 请重试', 'scheduled' => false, 'pay_at' => null];
            }
            throw $e;
        }
    }

    /**
     * 放款(approved→paid): 到点校验 + C4 快照校验(防竞态) + G3 复筛(新鲜) + 原子冲减 + 写 guarantee_refund 流水。
     * @return array{status:string,reason:string} status: paid|scheduled|aborted|noop
     */
    public static function pay(NezhaTopupRequest $request): array
    {
        return DB::transaction(function () use ($request) {
            $req = NezhaTopupRequest::where('id', $request->id)
                ->where('direction', 'refund')->where('account_type', 'guarantee')
                ->lockForUpdate()->first();
            if (!$req || $req->status !== 'approved') {
                return ['status' => 'noop', 'reason' => '状态非可放款态'];
            }
            if ($req->scheduled_pay_at && Carbon::now()->lt(Carbon::parse($req->scheduled_pay_at))) {
                return ['status' => 'scheduled', 'reason' => '高额/超日额退款须于 ' . Carbon::parse($req->scheduled_pay_at)->format('Y-m-d H:i') . ' 后放款'];
            }
            $r = Restaurant::where('id', $req->restaurant_id)->first();
            if (!$r) {
                return ['status' => 'noop', 'reason' => '商家不存在'];
            }
            // 放款前仍须互斥(approve 后商家可能进了 offboard)
            if (\App\CentralLogics\NezhaOffboard::is_deposit_credit_frozen($r->id)) {
                return ['status' => 'noop', 'reason' => '商家已进入退出结算/欠款态, 放款中止'];
            }

            $w = RestaurantWallet::where('vendor_id', $req->vendor_id)->lockForUpdate()->first();
            $guarantee = (float) ($w->guarantee_balance ?? 0);
            $X = round((float) $req->amount_credited, 2);

            // C4: 余额 vs 审批快照不一致 → 作废快照回 pending 待重审(防竞态多退)
            if (abs($guarantee - (float) $req->guarantee_snapshot) > 0.005) {
                self::resetToPending($req, '放款时押金余额与审批快照不一致(C4 abort), 已退回待重审');
                return ['status' => 'aborted', 'reason' => '押金余额已变动, 退回重审'];
            }
            // 锁内复查 floor/可退额仍成立
            $fl = self::tierFloorAmd($r);
            if (!$fl['ok'] || $X > round($guarantee - (float) $fl['floor'], 2) + 0.005 || $X <= 0) {
                self::resetToPending($req, '放款时可退额校验不通过(floor/档变动), 已退回待重审');
                return ['status' => 'aborted', 'reason' => '可退额校验不通过, 退回重审'];
            }
            // G3 放款当刻再复筛一次(新鲜度: 名单每日刷新)
            $sc = self::rescreen($req);
            if (!$sc['ok']) {
                self::resetToPending($req, '放款时制裁复筛未通过(' . $sc['status'] . '), 退回转人工');
                return ['status' => 'aborted', 'reason' => '制裁复筛未通过, 退回转人工'];
            }

            // 原子冲减 + 写流水(balance_after=减后真实余额, 非 offboard payLeg 恒0)
            $newBal = round($guarantee - $X, 2);
            $w->guarantee_balance = $newBal;
            $w->save();

            $tx = RestaurantDepositTransaction::create([
                'vendor_id'     => $req->vendor_id,
                'restaurant_id' => $req->restaurant_id,
                'order_id'      => null,
                'type'          => 'guarantee_refund',
                'amount'        => -1 * $X,
                'commission'    => 0,
                'balance_after' => $newBal,
                'note'          => '中途退回押金 申请#' . $req->id . ' 放款 ' . $X . '(退 KYC 本人账户·线下法币)',
                'created_by'    => $req->operator_id,
            ]);

            $req->transaction_id    = $tx->id;
            $req->payout_ref        = 'GRF-' . $req->id . '-' . Carbon::now()->format('YmdHis');
            $req->status            = 'paid';
            $req->active_refund_uniq = null; // 结构墙释放
            $req->reviewed_at       = Carbon::now();
            $req->save();

            return ['status' => 'paid', 'reason' => ''];
        });
    }

    /** C4/校验失败: 作废审批快照, 回 pending 待重审(清制裁/户名标志, 保留 active_refund_uniq 占位)。 */
    protected static function resetToPending(NezhaTopupRequest $req, string $note): void
    {
        $req->status             = 'pending';
        $req->approved_at        = null;
        $req->scheduled_pay_at   = null;
        $req->guarantee_snapshot = null;
        $req->sanction_rescreen_at = null;
        $req->holder_verified    = false;
        $req->reason             = $note;
        $req->save();
    }

    /** 打回(pending/approved→rejected): 释放结构墙。 */
    public static function reject(NezhaTopupRequest $request, string $reason, int $adminId): bool
    {
        return DB::transaction(function () use ($request, $reason, $adminId) {
            $req = NezhaTopupRequest::where('id', $request->id)
                ->where('direction', 'refund')->where('account_type', 'guarantee')
                ->lockForUpdate()->first();
            if (!$req || !in_array($req->status, ['pending', 'approved'], true)) {
                return false;
            }
            $req->status            = 'rejected';
            $req->reason            = $reason;
            $req->operator_id       = $adminId;
            $req->active_refund_uniq = null;
            $req->reviewed_at       = Carbon::now();
            $req->save();
            return true;
        });
    }
}
