<?php

declare(strict_types=1);

// 仅用于可回滚的 demo 公告。默认预览；GO 才写入，并先保存原值 manifest。

$go = ($argv[1] ?? '') === 'GO';
$base = __DIR__;
$evidenceDir = getenv('NZ_DEMO_EVIDENCE_DIR') ?: $base;
$manifest = rtrim($evidenceDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'_demo_announcement_manifest.json';
$message = '本店每日11:00-22:00营业，高峰期出餐约需40分钟，请提前下单。对口味有要求可在备注说明～';

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$restaurant = App\Models\Restaurant::find(6);
$metadata = json_decode((string) ($restaurant?->additional_data ?? ''), true);
if ($restaurant === null || ($metadata['demo_seed'] ?? false) !== true) {
    fwrite(STDERR, "拒绝：restaurant#6 不存在或已不是 demo_seed 店。\n");
    exit(2);
}
if (is_file($manifest)) {
    fwrite(STDERR, "拒绝：公告 manifest 已存在，请先核对或回退。\n");
    exit(3);
}

echo "将把 demo restaurant#6 公告改为：{$message}\n";
if (! $go) {
    echo "预览模式；执行需：php _demo_announcement_seed.php GO\n";
    exit(0);
}

$payload = [
    'restaurant_id' => 6,
    'before' => [
        'announcement' => (int) $restaurant->announcement,
        'announcement_message' => $restaurant->announcement_message,
    ],
    'after' => [
        'announcement' => 1,
        'announcement_message' => $message,
    ],
];
file_put_contents($manifest, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
chmod($manifest, 0600);

$restaurant->announcement = 1;
$restaurant->announcement_message = $message;
$restaurant->save();
echo "demo announcement committed; rollback manifest={$manifest}\n";
