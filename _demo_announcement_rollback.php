<?php
// _demo_announcement_rollback: 清演示店6公告(上线日清演示数据清单·第7项)
require '/www/wwwroot/api.nezha.am/vendor/autoload.php';
$app = require '/www/wwwroot/api.nezha.am/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Restaurant;
$r = Restaurant::find(6);
$r->announcement = 0; $r->announcement_message = null; $r->save();
echo "demo store6 announcement cleared\n";
