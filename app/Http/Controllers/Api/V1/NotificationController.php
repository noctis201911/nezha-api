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

        // 对齐前端 limit(原前端要 limit=20 但后端无视·全返15天=列表可能很长·业主0708治本)。默认30·硬顶50·两来源各限。
        $limit = min(max((int) $request->input('limit', 30), 1), 50);
        $zone_id= json_decode($request->header('zoneId'), true);
        try {
            $notifications = Notification::active()->where('tergat', 'customer')->where(function($q)use($zone_id){
                $q->whereNull('zone_id')->orWhereIn('zone_id', $zone_id);
            })->where('updated_at', '>=', \Carbon\Carbon::today()->subDays(15))->latest('updated_at')->limit($limit)->get();
            $notifications->append('data');

            $userId = $request?->user()?->id;
            $user_notifications = UserNotification::where('user_id', $userId)
                ->where('created_at', '>=', \Carbon\Carbon::today()->subDays(15))
                ->orderByDesc('id')
                ->limit($limit)
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

    // 哪吒(系统通知红点 06-22): 个人系统通知(订单更新)未读数 = created_at > system_notif_seen_at,
    // 且与展示一致地过滤孤儿(指向已删/非本人订单的通知)。只数 UserNotification, 不数平台公告(防发公告即骚扰所有人)。
    // 窗口与前端展示窗一致(5天·业主0708): 徽标只数看得到的·防"红点8但只显3条"假红点。
    public function unread_count(Request $request)
    {
        try {
            $userId = $request?->user()?->id;
            $seenAt = $request->user()?->system_notif_seen_at;
            $rows = UserNotification::where('user_id', $userId)
                ->where('created_at', '>=', \Carbon\Carbon::today()->subDays(5))
                ->when($seenAt, function ($q) use ($seenAt) {
                    $q->where('created_at', '>', $seenAt);
                })
                ->get();
            $orderIds = $rows->map(function ($n) {
                $oid = is_array($n->data) ? ($n->data['order_id'] ?? null) : null;
                return ($oid === null || $oid === '') ? null : (int) $oid;
            })->filter()->unique()->values();
            $orders = $orderIds->isNotEmpty()
                ? Order::where('user_id', $userId)->whereIn('id', $orderIds->all())->pluck('id')->flip()
                : collect();
            $count = $rows->filter(function ($n) use ($orders) {
                $data = is_array($n->data) ? $n->data : [];
                $oid = $data['order_id'] ?? null;
                if ($oid === null || $oid === '') {
                    return true;
                }
                return $orders->has((int) $oid);
            })->count();
            return response()->json(['count' => $count], 200);
        } catch (\Exception $e) {
            return response()->json(['count' => 0], 200);
        }
    }

    // 哪吒: 顾客查看了"系统通知"tab -> 记 system_notif_seen_at=now, 清未读(在场感知: 看了才清)。
    public function mark_seen(Request $request)
    {
        try {
            \Illuminate\Support\Facades\DB::table('users')
                ->where('id', $request?->user()?->id)
                ->update(['system_notif_seen_at' => now()]);
            return response()->json(['message' => 'ok'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'ok'], 200);
        }
    }

}
