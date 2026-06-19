<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Order;
use App\Models\UserNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function get_notifications(Request $request){

        Helpers::getZoneIds($request);

        $zone_id= json_decode($request->header('zoneId'), true);
        try {
            $notifications = Notification::active()->where('tergat', 'customer')->where(function($q)use($zone_id){
                $q->whereNull('zone_id')->orWhereIn('zone_id', $zone_id);
            })->where('updated_at', '>=', \Carbon\Carbon::today()->subDays(15))->get();
            $notifications->append('data');

            $userId = $request?->user()?->id;
            $user_notifications = UserNotification::where('user_id', $userId)
                ->where('created_at', '>=', \Carbon\Carbon::today()->subDays(15))
                ->orderByDesc('id')
                ->get();

            // 哪吒(数据完整性 req#2/#3): 通知 data.order_id 可能指向已删除/不属于本人的订单
            // (历史测试单清理后留下孤儿通知 -> 点开后订单详情接口 404 -> 前端 NaN/undefined/持续重试)。
            // 读取时:
            //   1) 丢弃指向"不存在或不属于本人"订单的订单类通知(隐藏失效通知, req#2);
            //   2) 给保留下来的订单类通知补真实订单字段(商家名/履约类型/最新状态)供前端结构化展示(req#3)。
            //   非订单类通知(无 order_id, 如平台公告)原样保留。
            $orderIds = $user_notifications
                ->map(function ($n) {
                    $oid = is_array($n->data) ? ($n->data['order_id'] ?? null) : null;
                    return ($oid === null || $oid === '') ? null : (int) $oid;
                })
                ->filter()
                ->unique()
                ->values();

            $orders = $orderIds->isNotEmpty()
                ? Order::with('restaurant:id,name')
                    ->where('user_id', $userId)
                    ->whereIn('id', $orderIds->all())
                    ->get(['id', 'restaurant_id', 'order_type', 'order_status'])
                    ->keyBy('id')
                : collect();

            $user_notifications = $user_notifications
                ->filter(function ($n) use ($orders) {
                    $data = is_array($n->data) ? $n->data : [];
                    $oid = $data['order_id'] ?? null;
                    if ($oid === null || $oid === '') {
                        return true; // 非订单类通知保留
                    }
                    return $orders->has((int) $oid); // 订单类: 必须命中本人真实订单
                })
                ->map(function ($n) use ($orders) {
                    $data = is_array($n->data) ? $n->data : [];
                    $oid = $data['order_id'] ?? null;
                    if ($oid !== null && $oid !== '' && $orders->has((int) $oid)) {
                        $order = $orders->get((int) $oid);
                        $data['restaurant_name'] = $order->restaurant?->name ?? ($data['restaurant_name'] ?? '');
                        $data['order_type'] = $order->order_type;       // delivery / take_away / dine_in
                        $data['order_status'] = $order->order_status;   // 用最新真实状态, 防陈旧
                    }
                    // data 是 json_decode accessor: 覆盖时必须写回 JSON 字符串, 否则 accessor 再次 decode 数组会抛错
                    $n->setAttribute('data', json_encode($data, JSON_UNESCAPED_UNICODE));
                    return $n;
                })
                ->values();

            $notifications =  $notifications->merge($user_notifications);
            return response()->json($notifications, 200);
        } catch (\Exception $e) {
            info(['Notification api issue_____',$e->getMessage()]);
            return response()->json([], 200);
        }
    }

}
