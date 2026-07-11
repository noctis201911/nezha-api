<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaPreorder;
use App\Http\Controllers\Controller;
use App\Models\NezhaDeliveryWindow;
use App\Models\Order;
use App\Models\RestaurantSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * 哪吒 预约下单 M5 —— 商家「配送时段窗口」CRUD(表 nezha_delivery_windows·M1 建)。
 * 顾客结算(M6)只能从这里 active=1 的窗口里选;作业台(M7)按窗口分组。全程收在总闸 nezha_preorder_status(默认关)下。
 *
 * 🔴 IDOR(SECURITY §A 对象级鉴权):所有单条改动一律 where restaurant_id = session 商家, 绝不凭请求里的 id 单独定位。
 * 校验:①总闸门 ②day/start/end 格式 + end>start ③净新增「窗口 ⊆ 营业时段」(债辩 §4.2·mockup02 状态B) ④去重。
 * capacity:Phase 2 才开放业务容量, 此处一律写 null(不限)。合规 L2/L3, 不碰钱/退款/L1。
 */
class NezhaDeliveryWindowController extends Controller
{
    /** 总闸门:功能未开放时统一 403(防御纵深, UI 关时也不该到这)。 */
    private function gateClosed()
    {
        if (!NezhaPreorder::enabled()) {
            return response()->json(['errors' => [['code' => 'preorder', 'message' => '预约下单功能尚未开放']]], 403);
        }
        return null;
    }

    /**
     * 配送时段配置页(mockup 02·浅白专业 DS §19)。渲染本店各 day 的窗口 + 营业时段(供「时段须在营业时间内」提示与校验)。
     * 页面本身渲染不 gate(便于隔离预览截图·纯读无副作用), mutations 端点各自 gated 403;真实入口链另按总闸 nezha_preorder_status 显隐。
     */
    public function index(Request $request)
    {
        $rid = Helpers::get_restaurant_id();
        $windowsByDay = NezhaDeliveryWindow::where('restaurant_id', $rid)
            ->orderBy('start_time')->get()->groupBy('day');
        $hoursByDay = RestaurantSchedule::where('restaurant_id', $rid)
            ->orderBy('opening_time')->get()->groupBy('day');
        $preorderOn = NezhaPreorder::enabled();
        return view('vendor-views.business-settings.nezha-delivery-windows', compact('windowsByDay', 'hoursByDay', 'preorderOn'));
    }

    /** 加窗口。 */
    public function store(Request $request)
    {
        if ($resp = $this->gateClosed()) {
            return $resp;
        }

        $validator = Validator::make($request->all(), [
            'day'        => 'required|integer|between:0,6',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i|after:start_time',
        ], [
            'end_time.after' => '结束时间必须晚于开始时间',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 422);
        }

        $restaurantId = Helpers::get_restaurant_id();

        // ⊆ 营业时段(债辩 §4.2:配窗口时即校验冲突早暴露, 否则 order_validation_check 会按 schedule_at 查营业直接拒单)。
        // 取该 day 所有营业块, 窗口须整段落在某一块内(不得跨闭店缝)。该 day 无营业块(当天不营业)→ 天然拒。
        $blocks = RestaurantSchedule::where('restaurant_id', $restaurantId)
            ->where('day', $request->day)
            ->get(['opening_time', 'closing_time']);
        if (!NezhaPreorder::rangeWithinAnyBlock($request->start_time, $request->end_time, $blocks)) {
            return response()->json(['errors' => [['code' => 'time', 'message' => '时段须落在当天营业时间内']]], 422);
        }

        // 去重(同 day + 同起止不重复建)。
        $dup = NezhaDeliveryWindow::where('restaurant_id', $restaurantId)
            ->where('day', $request->day)
            ->where('start_time', $request->start_time)
            ->where('end_time', $request->end_time)
            ->exists();
        if ($dup) {
            return response()->json(['errors' => [['code' => 'time', 'message' => '该时段已存在']]], 422);
        }

        $window = NezhaDeliveryWindow::create([
            'restaurant_id' => $restaurantId,
            'day'           => (int) $request->day,
            'start_time'    => $request->start_time,
            'end_time'      => $request->end_time,
            'capacity'      => null,   // Phase 2 才开放业务容量, Phase 1 一律不限
            'active'        => 1,
        ]);

        return response()->json(['message' => '配送时段已添加', 'id' => $window->id]);
    }

    /** 启停窗口(暂停后顾客选不到此时段·已有订单不受影响)。 */
    public function toggle(Request $request, $id)
    {
        if ($resp = $this->gateClosed()) {
            return $resp;
        }
        $window = NezhaDeliveryWindow::where('id', $id)
            ->where('restaurant_id', Helpers::get_restaurant_id())   // 🔴 IDOR 作用域
            ->first();
        if (!$window) {
            return response()->json(['errors' => [['code' => 'window', 'message' => '时段不存在']]], 404);
        }
        $window->active = $window->active ? 0 : 1;
        $window->save();
        return response()->json([
            'message' => $window->active ? '时段已启用' : '时段已暂停',
            'active'  => (bool) $window->active,
        ]);
    }

    /** 删除窗口。有订单引用则拒删(防孤儿·引导改暂停), 保护作业台分组稳定性(PLAN §4.1)。 */
    public function destroy(Request $request, $id)
    {
        if ($resp = $this->gateClosed()) {
            return $resp;
        }
        $window = NezhaDeliveryWindow::where('id', $id)
            ->where('restaurant_id', Helpers::get_restaurant_id())   // 🔴 IDOR 作用域
            ->first();
        if (!$window) {
            return response()->json(['errors' => [['code' => 'window', 'message' => '时段不存在']]], 404);
        }
        // 有订单挂在此窗口 → 拒删(删了作业台分组会孤儿), 引导商家改「暂停」。
        if (Order::where('nezha_delivery_window_id', $window->id)->exists()) {
            return response()->json(['errors' => [['code' => 'window', 'message' => '该时段已有订单，无法删除，请改为「暂停」']]], 422);
        }
        $window->delete();
        return response()->json(['message' => '配送时段已删除']);
    }

    /**
     * M7 批量「标出餐」: 把 order_ids 中属本店 + scheduled + confirmed 的单批量 confirmed→handover(出餐待叫车·跳过备餐中)。
     * 业主 2026-07-11 定映射(见 memory)。逐单包 DB::transaction + lockForUpdate + 锁内 canBatchReady(fresh) 复核(照 M3 范式), 输竞态/不合格→跳过不覆盖。
     * 🔴「转入配送」不批量翻 picked_up(不告诉顾客批量配送), 走逐单 Yandex——故本端点只到 handover。通知在锁外发(不在事务内外呼)。上限 100 防滥用。
     */
    public function batchMarkReady(Request $request)
    {
        if ($resp = $this->gateClosed()) {
            return $resp;
        }
        $ids = $request->input('order_ids', []);
        if (!is_array($ids) || !count($ids)) {
            return response()->json(['errors' => [['code' => 'order', 'message' => '未选择订单']]], 422);
        }
        $rid = Helpers::get_restaurant_id();
        $readied = [];
        foreach (array_slice($ids, 0, 100) as $oid) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($oid, $rid, &$readied) {
                $o = Order::where('id', $oid)->where('restaurant_id', $rid)->lockForUpdate()->first();  // 🔴 IDOR + 行锁
                if (!$o || !NezhaPreorder::canBatchReady((int) $o->scheduled, (string) $o->order_status)) {
                    return; // 非本店/非预约/非 confirmed(已被改走)→跳过, 不覆盖
                }
                $o->order_status = 'handover';
                $o->handover = now();
                $o->save();
                $readied[] = $o->id;
            });
        }
        // 通知锁外发(避免事务内外呼): 逐单知会顾客"出餐待叫车"。send 失败不阻断批量结果。
        foreach ($readied as $oid) {
            try {
                $o = Order::find($oid);
                if ($o) {
                    Helpers::send_order_notification($o);
                }
            } catch (\Throwable $e) {
                info('nezha batch-ready notify failed #' . $oid . ': ' . $e->getMessage());
            }
        }
        return response()->json(['message' => '已标出餐 ' . count($readied) . ' 单', 'done' => count($readied)]);
    }
}
