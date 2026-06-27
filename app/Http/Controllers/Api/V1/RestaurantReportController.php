<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * 顾客举报商家(餐厅)。
 * POST /api/v1/customer/restaurant/{restaurant_id}/report  (middleware: apiGuestCheck)
 *
 * 作用域/鉴权(复刻 OrderController::nudge_merchant 的 apiGuestCheck/user_id 模式):
 *   - 举报人身份服务端取定: 登录 = $request->user->id 写 user_id; 游客 = $request->guest_id 写 guest_id。
 *     【绝不信任 body 里的 user_id / vendor_id】→ 防越权伪造他人身份(IDOR)。
 *   - restaurant_id 必须是真实存在的餐厅, 否则 404; vendor_id 由餐厅服务端派生。
 *
 * 防刷(参考 nudge_merchant 的 Cache + locallife reportPost 的每日上限/去重):
 *   1. 待处理去重: 同举报人对同店已有 pending → 友好 200, 不重复落库。
 *   2. 短时限频: 同举报人 + 同店 10 分钟内只受理一次(Cache)。
 *   3. 每人每日上限(默认 10): 超过 → 429。
 *   4. 每 IP 每日上限(默认 30, Cache 不落库 IP): 钝化游客 guest_id 轮换刷量。
 *
 * L1-1: 全程不含任何资金字段, 平台不碰钱。
 * L1-7: description 视为 PII, 随表加密 + 到期清除(nezha:purge-restaurant-reports)。
 */
class RestaurantReportController extends Controller
{
    /** 举报理由白名单 —— 必须与前端 ReportRestaurantDrawer.jsx 的 REPORT_REASONS 严格一致 */
    public const REASONS = [
        '食品安全问题',
        '卫生环境差',
        '虚假宣传 / 与实际不符',
        '服务态度恶劣',
        '价格欺诈 / 乱收费',
        '其他',
    ];
    public const REASON_OTHER = '其他';

    public function store(Request $request, $restaurant_id)
    {
        // ── 入参校验 ──
        $validator = Validator::make($request->all(), [
            'reason'      => ['required', 'string', 'in:' . implode(',', self::REASONS)],
            'description' => 'nullable|string|max:500',
            'guest_id'    => $request->user ? 'nullable' : 'required',
        ], [
            'reason.required'   => '请选择举报理由',
            'reason.in'         => '举报理由不在允许范围',
            'description.max'   => '说明最多 500 字',
            'guest_id.required' => '缺少访客标识，请刷新后重试',
        ]);
        $validator->after(function ($v) use ($request) {
            if ($request->input('reason') === self::REASON_OTHER && !trim((string) $request->input('description'))) {
                $v->errors()->add('description', '选择"其他"时请填写具体说明');
            }
        });
        if ($validator->fails()) {
            $errs = [];
            foreach ($validator->errors()->getMessages() as $field => $msgs) {
                $errs[] = ['code' => $field, 'message' => $msgs[0]];
            }
            return response()->json(['errors' => $errs], 422);
        }

        // ── 目标餐厅必须真实存在(防 IDOR / 乱填 id) ──
        $restaurant = Restaurant::find($restaurant_id);
        if (!$restaurant) {
            return response()->json(['errors' => [['code' => 'restaurant', 'message' => '餐厅不存在或已下架']]], 404);
        }

        // ── 举报人身份: 服务端取定, 绝不信 body ──
        $isGuest     = !$request->user;
        $userId      = $isGuest ? null : $request->user->id;
        $guestId     = $isGuest ? (string) $request->guest_id : null;
        $reporterKey = $isGuest ? ('g:' . $guestId) : ('u:' . $userId);

        // ── 防刷 4 ── 每 IP 每日上限(Cache, 不落库 IP)
        $ipKey = 'nezha_rr_ip_' . md5((string) $request->ip()) . '_' . now()->toDateString();
        $ipCap = (int) $this->setting('nezha_restaurant_report_ip_daily_limit', 30);
        $ipCap = $ipCap > 0 ? $ipCap : 30;
        if ((int) Cache::get($ipKey, 0) >= $ipCap) {
            return response()->json(['errors' => [['code' => 'limit', 'message' => '提交过于频繁，请稍后再试']]], 429);
        }

        // ── 防刷 3 ── 每人每日上限
        $dailyCap = (int) $this->setting('nezha_restaurant_report_daily_limit', 10);
        $dailyCap = $dailyCap > 0 ? $dailyCap : 10;
        $todayCount = RestaurantReport::where('created_at', '>=', now()->startOfDay())
            ->when($isGuest, fn ($q) => $q->where('guest_id', $guestId)->whereNull('user_id'))
            ->when(!$isGuest, fn ($q) => $q->where('user_id', $userId))
            ->count();
        if ($todayCount >= $dailyCap) {
            return response()->json(['errors' => [['code' => 'limit', 'message' => '今日举报已达上限，请明天再试']]], 429);
        }

        // ── 防刷 1 ── 待处理去重(同举报人 + 同店已有 pending)
        $exists = RestaurantReport::where('restaurant_id', $restaurant->id)
            ->where('status', RestaurantReport::STATUS_PENDING)
            ->when($isGuest, fn ($q) => $q->where('guest_id', $guestId)->whereNull('user_id'))
            ->when(!$isGuest, fn ($q) => $q->where('user_id', $userId))
            ->exists();
        if ($exists) {
            return response()->json(['message' => '你已举报过该商家，我们会尽快核实处理'], 200);
        }

        // ── 防刷 2 ── 短时限频(同举报人 + 同店 10 分钟)
        $freshKey = 'nezha_restaurant_report_' . $reporterKey . '_' . $restaurant->id;
        if (Cache::has($freshKey)) {
            return response()->json(['message' => '你已举报过该商家，我们会尽快核实处理'], 200);
        }

        // ── 落库 ──
        RestaurantReport::create([
            'restaurant_id' => $restaurant->id,
            'vendor_id'     => $restaurant->vendor_id,   // 服务端派生, 不信 body
            'user_id'       => $userId,
            'guest_id'      => $guestId,
            'reason'        => $request->reason,
            'description'   => $request->input('description') ?: null,
            'status'        => RestaurantReport::STATUS_PENDING,
        ]);

        Cache::put($freshKey, now()->toDateTimeString(), now()->addMinutes(10));
        Cache::put($ipKey, ((int) Cache::get($ipKey, 0)) + 1, now()->endOfDay());

        return response()->json(['message' => '已收到举报，感谢反馈。平台会尽快核实处理'], 200);
    }

    private function setting(string $key, $default)
    {
        $v = DB::table('business_settings')->where('key', $key)->value('value');
        return $v === null ? $default : $v;
    }
}
