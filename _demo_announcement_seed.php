<?php
// _demo_announcement_seed: 给演示店6设运营类公告(展示#8公告条)。仅demo店,上线日跑rollback清。
require '/www/wwwroot/api.nezha.am/vendor/autoload.php';
$app = require '/www/wwwroot/api.nezha.am/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Restaurant;
$r = Restaurant::find(6);
$r->announcement = 1;
$r->announcement_message = '本店每日11:00-22:00营业，高峰期出餐约需40分钟，请提前下单。对口味有要求可在备注说明～';
$r->save();
echo "demo store6 announcement set: {$r->announcement_message}\n";
