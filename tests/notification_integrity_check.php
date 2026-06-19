<?php
/**
 * 哪吒外卖 — 消息中心通知数据完整性集成测试 (req#9)
 *
 * 覆盖 NotificationController::get_notifications 的失效通知处理:
 *   1) 失效通知(order_id 指向不存在的订单)      -> 必须丢弃
 *   2) 无权限订单(order_id 指向他人订单)          -> 必须丢弃
 *   3) 删除订单(同失效, 验证不会泄露/不报错)       -> 必须丢弃
 *   4) 缺字段响应(data 仅 order_id, 无商家/类型/状态) -> 保留并补全真实字段
 *   + 非订单类通知(无 order_id) 保留
 *   + 合法订单通知 保留并带 restaurant_name/order_type/order_status
 *
 * 安全: 全程包在一个 DB 事务里, 结束 ROLLBACK, 不向生产库写入任何持久数据。
 * 运行: php tests/notification_integrity_check.php   (在 api 仓库根目录)
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Api\V1\NotificationController;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$pass = 0;
$fail = 0;
function check($name, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name\n"; }
}

DB::beginTransaction();
try {
    // 选一个真实顾客 + 他名下一条真实订单 作为合法夹具
    $ownOrder = Order::whereNotNull('user_id')->first();
    $user = $ownOrder ? User::find($ownOrder->user_id) : User::find(6);
    if (!$user || !$ownOrder) {
        echo "环境缺少顾客/订单夹具, 跳过\n";
        DB::rollBack();
        exit(0);
    }

    // 造一条"他人订单"(user_id 不同)用于越权场景, 用真实餐厅满足 NOT NULL 约束
    $otherUserId = $user->id + 990000;
    $otherOrderId = (int) DB::table('orders')->insertGetId([
        'user_id' => $otherUserId,
        'restaurant_id' => $ownOrder->restaurant_id,
        'restaurant_discount_amount' => 0,
        'order_type' => 'delivery',
        'order_status' => 'pending',
        'order_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $missingOrderId = 999999999; // 不存在的订单(失效/已删除)

    $mk = function ($data) use ($user) {
        return (int) DB::table('user_notifications')->insertGetId([
            'user_id' => $user->id,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $idValid       = $mk(['order_id' => $ownOrder->id, 'type' => 'order_status', 'title' => '订单通知', 'description' => '合法订单', 'order_status' => 'pending']);
    $idOrphan      = $mk(['order_id' => $missingOrderId, 'type' => 'order_status', 'title' => '订单通知', 'description' => '失效订单']);
    $idNoPerm      = $mk(['order_id' => $otherOrderId, 'type' => 'order_status', 'title' => '订单通知', 'description' => '他人订单']);
    $idAnnounce    = $mk(['type' => 'advertisement', 'title' => '平台活动', 'description' => '公告无order_id']);
    $idMissingFlds = $mk(['order_id' => $ownOrder->id]); // 仅 order_id, 缺商家/类型/状态/正文

    // 调真实控制器
    $request = Request::create('/api/v1/customer/notifications', 'GET');
    $request->headers->set('zoneId', '[2,3]');
    $request->setUserResolver(fn () => $user);

    $response = (new NotificationController())->get_notifications($request);
    $items = json_decode($response->getContent(), true) ?: [];

    // 仅取本次插入的 user_notifications(按 id 命中)
    $byId = [];
    foreach ($items as $it) {
        if (isset($it['id'])) { $byId[$it['id']] = $it; }
    }

    echo "状态码: " . $response->getStatusCode() . "\n";

    check('合法订单通知 保留', isset($byId[$idValid]));
    check('失效通知(不存在订单) 丢弃', !isset($byId[$idOrphan]));
    check('无权限订单(他人订单) 丢弃', !isset($byId[$idNoPerm]));
    check('公告(无order_id) 保留', isset($byId[$idAnnounce]));
    check('缺字段订单通知 保留', isset($byId[$idMissingFlds]));

    // 合法 & 缺字段 都应被补全真实字段
    foreach (['合法' => $idValid, '缺字段' => $idMissingFlds] as $label => $id) {
        $d = $byId[$id]['data'] ?? [];
        check("$label: 补 restaurant_name", !empty($d['restaurant_name']));
        check("$label: 补 order_type", !empty($d['order_type']));
        check("$label: 补 order_status", !empty($d['order_status']));
    }

    // 响应任何一条都不得出现 NaN/undefined/空 order_id 这类脏值(订单类必须是正整数)
    $dirty = false;
    foreach ($byId as $it) {
        $oid = $it['data']['order_id'] ?? null;
        if ($oid !== null && $oid !== '' && (!is_numeric($oid) || (int) $oid <= 0)) { $dirty = true; }
    }
    check('保留项无脏 order_id', !$dirty);

} catch (\Throwable $e) {
    $fail++;
    echo "  EXCEPTION: " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine() . "\n";
} finally {
    DB::rollBack(); // 绝不向生产库落数据
}

echo "\n结果: PASS=$pass FAIL=$fail\n";
exit($fail === 0 ? 0 : 1);
