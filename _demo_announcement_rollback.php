<?php

declare(strict_types=1);

// 配套 _demo_announcement_seed.php。默认预览；只在当前值仍等于 manifest.after 时允许 GO。

$go = ($argv[1] ?? '') === 'GO';
$base = __DIR__;
$evidenceDir = getenv('NZ_DEMO_EVIDENCE_DIR') ?: $base;
$manifest = rtrim($evidenceDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'_demo_announcement_manifest.json';

if (! is_file($manifest)) {
    fwrite(STDERR, "拒绝：缺 _demo_announcement_manifest.json，不能猜测原值。\n");
    exit(2);
}
$payload = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$restaurant = App\Models\Restaurant::find((int) ($payload['restaurant_id'] ?? 0));
$current = $restaurant === null ? null : [
    'announcement' => (int) $restaurant->announcement,
    'announcement_message' => $restaurant->announcement_message,
];
if ($restaurant === null || $current !== ($payload['after'] ?? null)) {
    fwrite(STDERR, "拒绝：公告当前值已偏离 seed 后快照，不能覆盖他人变更。\n");
    exit(3);
}

echo '将还原 restaurant#'.$restaurant->id.' 公告到 manifest.before'.PHP_EOL;
if (! $go) {
    echo "预览模式；执行需：php _demo_announcement_rollback.php GO\n";
    exit(0);
}

$restaurant->announcement = (int) $payload['before']['announcement'];
$restaurant->announcement_message = $payload['before']['announcement_message'];
$restaurant->save();
rename($manifest, $manifest.'.done.'.date('YmdHis'));
echo "demo announcement restored from manifest\n";
