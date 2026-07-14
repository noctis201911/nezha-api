<?php

declare(strict_types=1);

/**
 * 2026-06 demo 数据收口工具。
 *
 * 安全边界：
 * - 默认只读 PLAN；执行必须回传同一次计划的 SHA-256。
 * - manifest/备份只从显式 evidence-dir 读取，并校验本次上线批次固定哈希。
 * - 全部数据库动作位于一个事务；REHEARSE 在残留断言通过后回滚并复核原计划。
 * - 不改 Banner、开关、用户显示名或文件；这些没有足够的同一份可逆证据。
 */
const NZ_DEMO_EVIDENCE = [
    '_demo_seed_manifest.json' => '68982d4926210e3e394a4b6cdde53e8ffe4defc0a9fe525c8abedc7bc42b3c62',
    '_demo_locallife_v2.json' => 'e6ff14552788c9af4c734aadb78bf7fa4a9fc89cef1e69926c6cfec9f076f0ee',
    '_locallife_v2_backup_20260617212214.json' => '82a6e165127bfec5c1ee43ae24ced39a2fa4fcef4610e2a305aa630b114be5a6',
    '_ll_merchants_manifest.json' => '75a635af0a887aa12e956d75ba3c5578fa669ea6e4e93924b97be77f3fc174bd',
    '_demo_locallife_service.json.archived.20260617140211' => '19be5491c8bbf40e0e72a1f35e3d2cb29a5568a2c4d65b9029b96764eb114f80',
];

const NZ_DEMO_LOCAL_MERCHANT_NAMES = [
    '雅顺移民',
    '王氏移民咨询',
    '环球签证服务',
    '小美沙龙',
    '康宁中医理疗',
    '阿明包车',
    '高加索深度游',
];

function usage(): void
{
    echo <<<'TXT'
用法：
  php nzdemo-cleanup.php --evidence-dir=/secure/path
  php nzdemo-cleanup.php --evidence-dir=/secure/path --export-scope=/secure/rehearsal-scope.json
  php nzdemo-cleanup.php --evidence-dir=/secure/path --scope-file=/secure/scope.json
  php nzdemo-cleanup.php --evidence-dir=/secure/path --apply --rollback --confirm=<plan-sha256>
  php nzdemo-cleanup.php --evidence-dir=/secure/path --apply --confirm=<plan-sha256>

默认只读。自动导出的 scope 是 rehearsal-only；--rollback 用于一次性数据库演练；
真实提交需要另行审定 purpose=production-approved 的 scope 和新的计划哈希。
TXT;
    echo PHP_EOL;
}

function abortWith(string $message, int $code = 2): never
{
    fwrite(STDERR, "拒绝：{$message}".PHP_EOL);
    exit($code);
}

function intIds(mixed $value, string $label): array
{
    if (! is_array($value)) {
        abortWith("{$label} 不是数组");
    }

    $ids = array_values(array_unique(array_map('intval', $value)));
    if (count($ids) !== count($value) || array_filter($ids, fn (int $id): bool => $id <= 0)) {
        abortWith("{$label} 含重复、空或非正整数 ID");
    }

    return $ids;
}

function canonicalJson(array $value): string
{
    $sort = function (&$item) use (&$sort): void {
        if (! is_array($item)) {
            return;
        }
        foreach ($item as &$child) {
            $sort($child);
        }
        unset($child);
        if (array_keys($item) !== range(0, count($item) - 1)) {
            ksort($item);
        }
    };
    $sort($value);

    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function loadEvidence(string $dir, string $name): array
{
    $expected = NZ_DEMO_EVIDENCE[$name] ?? null;
    if ($expected === null) {
        abortWith("未登记 evidence：{$name}");
    }

    $path = $dir.DIRECTORY_SEPARATOR.$name;
    $real = realpath($path);
    if ($real === false || dirname($real) !== $dir) {
        abortWith("evidence 缺失或越界：{$name}");
    }

    $actual = hash_file('sha256', $real);
    if (! hash_equals($expected, $actual)) {
        abortWith("evidence 哈希不符：{$name} expected={$expected} actual={$actual}");
    }

    $decoded = json_decode((string) file_get_contents($real), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        abortWith("evidence 不是 JSON 对象/数组：{$name}");
    }

    return $decoded;
}

function currentIds(string $table, array $ids): array
{
    if ($ids === []) {
        return [];
    }

    return Illuminate\Support\Facades\DB::table($table)
        ->whereIn('id', $ids)
        ->orderBy('id')
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();
}

function countUnplanned(string $table, string $relationColumn, array $relationIds, array $plannedIds = []): int
{
    if ($relationIds === []) {
        return 0;
    }

    $query = Illuminate\Support\Facades\DB::table($table)->whereIn($relationColumn, $relationIds);
    if ($plannedIds !== []) {
        $query->whereNotIn('id', $plannedIds);
    }

    return (int) $query->count();
}

function queryFingerprint($query, array $orderColumns = ['id']): string
{
    foreach ($orderColumns as $column) {
        $query->orderBy($column);
    }
    $rows = $query->get()->map(fn ($row): array => (array) $row)->all();

    return hash('sha256', canonicalJson($rows));
}

function buildContext(string $evidenceDir): array
{
    $main = loadEvidence($evidenceDir, '_demo_seed_manifest.json');
    $localV2 = loadEvidence($evidenceDir, '_demo_locallife_v2.json');
    $localBackup = loadEvidence($evidenceDir, '_locallife_v2_backup_20260617212214.json');
    $merchantManifest = loadEvidence($evidenceDir, '_ll_merchants_manifest.json');
    $serviceManifest = loadEvidence($evidenceDir, '_demo_locallife_service.json.archived.20260617140211');

    $tables = $main['tables'] ?? null;
    if (! is_array($tables)) {
        abortWith('_demo_seed_manifest.json 缺 tables');
    }

    $ctx = [
        'tables' => [],
        'local_v2_inserted' => intIds($localV2['inserted_ids'] ?? null, 'local_v2.inserted_ids'),
        'local_v2_restore' => intIds($localV2['deleted_old'] ?? null, 'local_v2.deleted_old'),
        'local_backup_rows' => $localBackup,
        'local_service_inserted' => intIds($serviceManifest['inserted_ids'] ?? null, 'local_service.inserted_ids'),
        'local_merchant_ids' => intIds($merchantManifest['ids'] ?? null, 'local_merchants.ids'),
    ];

    foreach (['vendors', 'restaurant_wallets', 'restaurants', 'restaurant_schedule', 'restaurant_configs', 'categories', 'food', 'coupons', 'campaigns', 'item_campaigns', 'translations', 'banners'] as $table) {
        $ctx['tables'][$table] = intIds($tables[$table] ?? null, "tables.{$table}");
    }

    $backupById = [];
    foreach ($ctx['local_backup_rows'] as $row) {
        if (! is_array($row) || ! isset($row['id'])) {
            abortWith('local-life backup 含无 id 行');
        }
        $backupById[(int) $row['id']] = $row;
    }
    if (array_keys($backupById) !== $ctx['local_v2_restore']) {
        abortWith('local-life backup IDs 与 manifest deleted_old 不一致');
    }
    $ctx['local_backup_by_id'] = $backupById;

    return $ctx;
}

function loadScope(?string $scopeFile): ?array
{
    if ($scopeFile === null || $scopeFile === '') {
        return null;
    }
    $real = realpath($scopeFile);
    if ($real === false || ! is_file($real)) {
        abortWith('scope-file 不存在');
    }
    $scope = json_decode((string) file_get_contents($real), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($scope)) {
        abortWith('scope-file 不是 JSON 对象');
    }
    $scope['_sha256'] = hash_file('sha256', $real);

    return $scope;
}

function exportScope(array $ctx, string $path): void
{
    $db = Illuminate\Support\Facades\DB::class;
    $demoRestaurantIds = $ctx['tables']['restaurants'];
    $relatedOrderIds = $db::table('orders')
        ->whereIn('restaurant_id', $demoRestaurantIds)
        ->where(function ($query): void {
            $query->whereNull('order_note')->orWhere('order_note', '!=', '_demo_socialproof_seed');
        })
        ->orderBy('id')
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();
    $merchants = $db::table('local_life_merchants')
        ->whereIn('id', $ctx['local_merchant_ids'])
        ->orderBy('id')
        ->get(['id', 'name'])
        ->map(fn ($row): array => ['id' => (int) $row->id, 'expected_name' => (string) $row->name])
        ->all();
    $reviewIds = $db::table('reviews')
        ->whereIn('restaurant_id', $demoRestaurantIds)
        ->orderBy('id')
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();
    $addOnIds = $db::table('add_ons')
        ->whereIn('restaurant_id', $demoRestaurantIds)
        ->orderBy('id')
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();

    $payload = [
        'purpose' => 'rehearsal-only',
        'source_database' => (string) $db::connection()->getDatabaseName(),
        'generated_at' => gmdate('c'),
        'related_order_ids' => $relatedOrderIds,
        'review_ids' => $reviewIds,
        'add_on_ids' => $addOnIds,
        'local_life_merchants' => $merchants,
    ];
    $dir = dirname($path);
    if (! is_dir($dir) || ! is_writable($dir)) {
        abortWith('export-scope 目录不存在或不可写');
    }
    if (file_exists($path) || is_link($path)) {
        abortWith('export-scope 目标已存在，拒绝覆盖既有证据');
    }
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
    echo 'SCOPE_EXPORTED='.$path.PHP_EOL;
    echo 'SCOPE_SHA256='.hash_file('sha256', $path).PHP_EOL;
}

function buildPlan(array $ctx, ?array $scope): array
{
    $db = Illuminate\Support\Facades\DB::class;
    $blockers = [];
    $demoRestaurantIds = $ctx['tables']['restaurants'];
    $demoVendorIds = $ctx['tables']['vendors'];

    $vendors = $db::table('vendors')->whereIn('id', $demoVendorIds)->get(['id', 'email']);
    foreach ($vendors as $vendor) {
        if (! preg_match('/^demo_seed_[0-9]+@nezha\.am$/', (string) $vendor->email)) {
            $blockers[] = "vendor#{$vendor->id} 不再带 demo_seed 邮箱标记";
        }
    }

    $restaurants = $db::table('restaurants')->whereIn('id', $demoRestaurantIds)->get(['id', 'additional_data']);
    foreach ($restaurants as $restaurant) {
        $data = json_decode((string) $restaurant->additional_data, true);
        if (($data['demo_seed'] ?? false) !== true) {
            $blockers[] = "restaurant#{$restaurant->id} 不再带 demo_seed=true 标记";
        }
    }

    $foreignFoods = $db::table('food')
        ->whereIn('id', $ctx['tables']['food'])
        ->whereNotIn('restaurant_id', $demoRestaurantIds)
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();
    if ($foreignFoods !== []) {
        $blockers[] = 'manifest food 已被重挂到非 demo 店：'.implode(',', $foreignFoods);
    }

    $socialOrderIds = $db::table('orders')
        ->where('order_note', '_demo_socialproof_seed')
        ->orderBy('id')
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();
    $otherDemoStoreOrderIds = $db::table('orders')
        ->whereIn('restaurant_id', $demoRestaurantIds)
        ->where(function ($query): void {
            $query->whereNull('order_note')->orWhere('order_note', '!=', '_demo_socialproof_seed');
        })
        ->orderBy('id')
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();
    $scopedOrderIds = $scope === null ? [] : intIds($scope['related_order_ids'] ?? null, 'scope.related_order_ids');
    if ($otherDemoStoreOrderIds !== $scopedOrderIds) {
        $blockers[] = 'demo 店关联非 socialproof 订单尚未被同一份精确 scope 覆盖：'.implode(',', $otherDemoStoreOrderIds);
    }
    $allPlannedOrderIds = array_values(array_unique(array_merge($socialOrderIds, $scopedOrderIds)));

    $currentReviewIds = $db::table('reviews')
        ->whereIn('restaurant_id', $demoRestaurantIds)
        ->orderBy('id')
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();
    $scopedReviewIds = $scope === null ? [] : intIds($scope['review_ids'] ?? null, 'scope.review_ids');
    if ($currentReviewIds !== $scopedReviewIds) {
        $blockers[] = 'demo 店评价尚未被同一份精确 scope 覆盖：'.implode(',', $currentReviewIds);
    }

    $currentAddOnIds = $db::table('add_ons')
        ->whereIn('restaurant_id', $demoRestaurantIds)
        ->orderBy('id')
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();
    $scopedAddOnIds = $scope === null ? [] : intIds($scope['add_on_ids'] ?? null, 'scope.add_on_ids');
    if ($currentAddOnIds !== $scopedAddOnIds) {
        $blockers[] = 'demo 店加料项尚未被同一份精确 scope 覆盖：'.implode(',', $currentAddOnIds);
    }

    $postIds = array_values(array_unique(array_merge($ctx['local_service_inserted'], $ctx['local_v2_inserted'])));
    $unsafePostIds = $db::table('local_life_posts')
        ->whereIn('id', $postIds)
        ->where(function ($query): void {
            $query->whereNull('contact_info')->orWhere('contact_info', 'not like', '%nezha_demo%');
        })
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();
    if ($unsafePostIds !== []) {
        $blockers[] = 'manifest 本地生活帖已不再带 nezha_demo 标记：'.implode(',', $unsafePostIds);
    }

    foreach ($ctx['local_v2_restore'] as $id) {
        $current = $db::table('local_life_posts')->where('id', $id)->first();
        if ($current !== null && (array) $current !== $ctx['local_backup_by_id'][$id]) {
            $blockers[] = "待还原旧帖 id={$id} 已被其它内容占用";
        }
    }

    $merchantRows = $db::table('local_life_merchants')
        ->whereIn('id', $ctx['local_merchant_ids'])
        ->get(['id', 'name'])
        ->keyBy('id');
    $scopedMerchants = [];
    if ($scope !== null) {
        foreach (($scope['local_life_merchants'] ?? []) as $row) {
            if (! is_array($row) || ! isset($row['id'], $row['expected_name'])) {
                abortWith('scope.local_life_merchants 格式错误');
            }
            $scopedMerchants[(int) $row['id']] = (string) $row['expected_name'];
        }
    }
    foreach ($ctx['local_merchant_ids'] as $index => $id) {
        $row = $merchantRows->get($id);
        $expectedName = $scopedMerchants[$id] ?? NZ_DEMO_LOCAL_MERCHANT_NAMES[$index];
        if ($row !== null && (string) $row->name !== $expectedName) {
            $blockers[] = "local_life_merchant#{$id} 名称已不是原 demo 值";
        }
    }
    if ($scope !== null && array_diff(array_keys($scopedMerchants), $ctx['local_merchant_ids']) !== []) {
        $blockers[] = 'scope 含 manifest 外的 local-life merchant ID';
    }

    // manifest 没有覆盖的关联记录绝不跟随 demo 门店/商户自动删除。
    // 这些计数只用于 fail-closed 证据；必须由相应数据 owner 逐类裁决。
    $allDemoFoodIds = $db::table('food')
        ->whereIn('restaurant_id', $demoRestaurantIds)
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();
    $relationCounts = [
        'food.outside_manifest' => countUnplanned('food', 'restaurant_id', $demoRestaurantIds, $ctx['tables']['food']),
        'food.category_id_outside_manifest' => countUnplanned('food', 'category_id', $ctx['tables']['categories'], $ctx['tables']['food']),
        'item_campaigns.outside_manifest' => countUnplanned('item_campaigns', 'restaurant_id', $demoRestaurantIds, $ctx['tables']['item_campaigns']),
        'item_campaigns.category_id_outside_manifest' => countUnplanned('item_campaigns', 'category_id', $ctx['tables']['categories'], $ctx['tables']['item_campaigns']),
        'restaurant_configs.outside_manifest' => countUnplanned('restaurant_configs', 'restaurant_id', $demoRestaurantIds, $ctx['tables']['restaurant_configs']),
        'restaurant_schedule.outside_manifest' => countUnplanned('restaurant_schedule', 'restaurant_id', $demoRestaurantIds, $ctx['tables']['restaurant_schedule']),
        'restaurant_wallets.outside_manifest' => countUnplanned('restaurant_wallets', 'vendor_id', $demoVendorIds, $ctx['tables']['restaurant_wallets']),
        'campaign_restaurant.outside_manifest_campaigns' => (int) $db::table('campaign_restaurant')
            ->whereIn('restaurant_id', $demoRestaurantIds)
            ->whereNotIn('campaign_id', $ctx['tables']['campaigns'])
            ->count(),
        'order_details.outside_scoped_orders' => (int) $db::table('order_details')
            ->whereIn('food_id', $allDemoFoodIds)
            ->when($allPlannedOrderIds !== [], fn ($query) => $query->whereNotIn('order_id', $allPlannedOrderIds))
            ->count(),
        'order_details.category_id_outside_scoped_orders' => (int) $db::table('order_details')
            ->whereIn('category_id', $ctx['tables']['categories'])
            ->when($allPlannedOrderIds !== [], fn ($query) => $query->whereNotIn('order_id', $allPlannedOrderIds))
            ->count(),
        'reviews.by_food_outside_scoped_reviews' => (int) $db::table('reviews')
            ->whereIn('food_id', $allDemoFoodIds)
            ->when($scopedReviewIds !== [], fn ($query) => $query->whereNotIn('id', $scopedReviewIds))
            ->count(),
        'reviews.by_order_outside_scoped_reviews' => (int) $db::table('reviews')
            ->whereIn('order_id', $allPlannedOrderIds)
            ->when($scopedReviewIds !== [], fn ($query) => $query->whereNotIn('id', $scopedReviewIds))
            ->count(),
        'carts.restaurant_id' => countUnplanned('carts', 'restaurant_id', $demoRestaurantIds),
        'coupon_claims.coupon_id' => countUnplanned('coupon_claims', 'coupon_id', $ctx['tables']['coupons']),
        'cuisine_restaurant.restaurant_id' => countUnplanned('cuisine_restaurant', 'restaurant_id', $demoRestaurantIds),
        'local_life_merchant_accounts.merchant_id' => countUnplanned('local_life_merchant_accounts', 'merchant_id', $ctx['local_merchant_ids']),
        'logs.restaurant_id' => countUnplanned('logs', 'restaurant_id', $demoRestaurantIds),
        'messages.order_id' => countUnplanned('messages', 'order_id', $allPlannedOrderIds),
        'nezha_cart_events.restaurant_id' => countUnplanned('nezha_cart_events', 'restaurant_id', $demoRestaurantIds),
        'nezha_consolidation_surveys.restaurant_or_vendor' => (int) $db::table('nezha_consolidation_surveys')
            ->where(fn ($query) => $query->whereIn('restaurant_id', $demoRestaurantIds)->orWhereIn('vendor_id', $demoVendorIds))
            ->count(),
        'nezha_cs_tickets.order_or_vendor' => (int) $db::table('nezha_cs_tickets')
            ->where(fn ($query) => $query->whereIn('order_id', $allPlannedOrderIds)->orWhereIn('vendor_id', $demoVendorIds))
            ->count(),
        'nezha_order_timeout_events.order_id' => countUnplanned('nezha_order_timeout_events', 'order_id', $allPlannedOrderIds),
        'nezha_refund_records.restaurant_or_order' => (int) $db::table('nezha_refund_records')
            ->where(fn ($query) => $query->whereIn('restaurant_id', $demoRestaurantIds)->orWhereIn('order_id', $allPlannedOrderIds))
            ->count(),
        'nezha_review_reports.review_id' => countUnplanned('nezha_review_reports', 'review_id', $scopedReviewIds),
        'nezha_risk_records.restaurant_or_order' => (int) $db::table('nezha_risk_records')
            ->where(fn ($query) => $query->whereIn('restaurant_id', $demoRestaurantIds)->orWhereIn('order_id', $allPlannedOrderIds))
            ->count(),
        'offline_payments.order_id' => countUnplanned('offline_payments', 'order_id', $allPlannedOrderIds),
        'order_transactions.order_or_vendor' => (int) $db::table('order_transactions')
            ->where(fn ($query) => $query->whereIn('order_id', $allPlannedOrderIds)->orWhereIn('vendor_id', $demoVendorIds))
            ->count(),
        'restaurant_deposit_transactions.restaurant_or_vendor' => (int) $db::table('restaurant_deposit_transactions')
            ->where(fn ($query) => $query->whereIn('restaurant_id', $demoRestaurantIds)->orWhereIn('vendor_id', $demoVendorIds))
            ->count(),
        'restaurant_notification_settings.restaurant_id' => countUnplanned('restaurant_notification_settings', 'restaurant_id', $demoRestaurantIds),
        'restaurant_reports.restaurant_or_vendor' => (int) $db::table('restaurant_reports')
            ->where(fn ($query) => $query->whereIn('restaurant_id', $demoRestaurantIds)->orWhereIn('vendor_id', $demoVendorIds))
            ->count(),
        'user_infos.vendor_id' => countUnplanned('user_infos', 'vendor_id', $demoVendorIds),
        'user_notifications.vendor_id' => countUnplanned('user_notifications', 'vendor_id', $demoVendorIds),
        'vendor_feedback.restaurant_or_vendor' => (int) $db::table('vendor_feedback')
            ->where(fn ($query) => $query->whereIn('restaurant_id', $demoRestaurantIds)->orWhereIn('vendor_id', $demoVendorIds))
            ->count(),
        'wishlists.restaurant_or_food' => (int) $db::table('wishlists')
            ->where(fn ($query) => $query->whereIn('restaurant_id', $demoRestaurantIds)->orWhereIn('food_id', $allDemoFoodIds))
            ->count(),
    ];
    $relationCounts = array_filter($relationCounts, fn (int $count): bool => $count > 0);
    foreach ($relationCounts as $relation => $count) {
        $blockers[] = "manifest 外关联仍存在：{$relation}={$count}";
    }

    $targets = [];
    foreach ($ctx['tables'] as $table => $ids) {
        $targets[$table] = currentIds($table, $ids);
    }
    $targets['add_ons'] = $scopedAddOnIds;
    $targets['reviews'] = $scopedReviewIds;
    $targets['socialproof_orders'] = $socialOrderIds;
    $targets['scoped_related_orders'] = $scopedOrderIds;
    $targets['socialproof_users'] = $db::table('users')->where('email', 'like', '_demo_sp_user_%')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    $targets['local_life_posts'] = currentIds('local_life_posts', $postIds);
    $targets['local_life_merchants'] = currentIds('local_life_merchants', $ctx['local_merchant_ids']);
    $targets['local_life_restore'] = array_values(array_filter($ctx['local_v2_restore'], fn (int $id): bool => ! $db::table('local_life_posts')->where('id', $id)->exists()));

    $fingerprints = [];
    foreach ($ctx['tables'] as $table => $ids) {
        $fingerprints[$table] = queryFingerprint($db::table($table)->whereIn('id', $targets[$table]));
    }
    $fingerprints['add_ons'] = queryFingerprint($db::table('add_ons')->whereIn('id', $scopedAddOnIds));
    $fingerprints['reviews'] = queryFingerprint($db::table('reviews')->whereIn('id', $scopedReviewIds));
    $fingerprints['orders'] = queryFingerprint($db::table('orders')->whereIn('id', $allPlannedOrderIds));
    $fingerprints['order_details'] = queryFingerprint($db::table('order_details')->whereIn('order_id', $allPlannedOrderIds));
    $fingerprints['socialproof_users'] = queryFingerprint($db::table('users')->whereIn('id', $targets['socialproof_users']));
    $fingerprints['local_life_posts'] = queryFingerprint($db::table('local_life_posts')->whereIn('id', $targets['local_life_posts']));
    $fingerprints['local_life_merchants'] = queryFingerprint($db::table('local_life_merchants')->whereIn('id', $targets['local_life_merchants']));
    $fingerprints['campaign_restaurant'] = queryFingerprint(
        $db::table('campaign_restaurant')->whereIn('campaign_id', $ctx['tables']['campaigns']),
        ['campaign_id', 'restaurant_id']
    );

    ksort($targets);
    ksort($fingerprints);
    sort($blockers);

    return [
        'database' => (string) $db::connection()->getDatabaseName(),
        'evidence_sha256' => NZ_DEMO_EVIDENCE,
        'scope' => $scope === null ? null : [
            'purpose' => (string) ($scope['purpose'] ?? ''),
            'sha256' => (string) ($scope['_sha256'] ?? ''),
            'source_database' => (string) ($scope['source_database'] ?? ''),
            'related_order_ids' => $scopedOrderIds,
            'review_ids' => $scopedReviewIds,
            'add_on_ids' => $scopedAddOnIds,
            'local_life_merchants' => $scopedMerchants,
        ],
        'targets' => $targets,
        'target_row_sha256' => $fingerprints,
        'manifest_outside_relation_counts' => $relationCounts,
        'blockers' => $blockers,
        'excluded_without_reversible_evidence' => [
            'promotional_banner_title',
            'demo image files',
            'user display-name rewrites from _demo_review_names_rollback.php',
        ],
    ];
}

function assertResidualZero(array $ctx): void
{
    $db = Illuminate\Support\Facades\DB::class;
    $checks = [
        'demo vendors' => $db::table('vendors')->where('email', 'like', 'demo_seed_%@nezha.am')->count(),
        'demo restaurants' => $db::table('restaurants')->where('additional_data', 'like', '%"demo_seed":true%')->count(),
        'demo add_ons' => $db::table('add_ons')->whereIn('restaurant_id', $ctx['tables']['restaurants'])->count(),
        'demo reviews' => $db::table('reviews')->whereIn('restaurant_id', $ctx['tables']['restaurants'])->count(),
        'socialproof orders' => $db::table('orders')->where('order_note', '_demo_socialproof_seed')->count(),
        'other demo-store orders' => $db::table('orders')->whereIn('restaurant_id', $ctx['tables']['restaurants'])->count(),
        'socialproof users' => $db::table('users')->where('email', 'like', '_demo_sp_user_%')->count(),
        'local-life posts' => $db::table('local_life_posts')->where('contact_info', 'like', '%nezha_demo%')->count(),
        'local-life merchants' => $db::table('local_life_merchants')->whereIn('id', $ctx['local_merchant_ids'])->count(),
    ];

    $failed = array_filter($checks, fn ($count): bool => (int) $count !== 0);
    if ($failed !== []) {
        abortWith('执行后残留不为 0：'.canonicalJson($failed), 5);
    }

    $missingRestores = Illuminate\Support\Facades\DB::table('local_life_posts')
        ->whereIn('id', $ctx['local_v2_restore'])
        ->count();
    if ((int) $missingRestores !== count($ctx['local_v2_restore'])) {
        abortWith('旧本地生活帖未完整还原', 5);
    }
}

function executeCleanup(array $ctx, array $plan): void
{
    $db = Illuminate\Support\Facades\DB::class;
    $demoRestaurantIds = $ctx['tables']['restaurants'];
    $socialOrderIds = $db::table('orders')->where('order_note', '_demo_socialproof_seed')->pluck('id')->all();
    $orderIds = array_values(array_unique(array_merge($socialOrderIds, $plan['targets']['scoped_related_orders'])));

    if ($orderIds !== []) {
        $db::table('order_details')->whereIn('order_id', $orderIds)->delete();
        $db::table('orders')->whereIn('id', $orderIds)->delete();
    }
    $db::table('users')->where('email', 'like', '_demo_sp_user_%')->delete();

    $db::table('reviews')->whereIn('id', $plan['targets']['reviews'])->delete();
    $db::table('add_ons')->whereIn('id', $plan['targets']['add_ons'])->delete();

    $postIds = array_values(array_unique(array_merge($ctx['local_service_inserted'], $ctx['local_v2_inserted'])));
    $db::table('local_life_posts')->whereIn('id', $postIds)->delete();
    foreach ($ctx['local_backup_by_id'] as $id => $row) {
        if (! $db::table('local_life_posts')->where('id', $id)->exists()) {
            $db::table('local_life_posts')->insert($row);
        }
    }
    $db::table('local_life_merchants')->whereIn('id', $ctx['local_merchant_ids'])->delete();

    if ($ctx['tables']['campaigns'] !== []) {
        $db::table('campaign_restaurant')->whereIn('campaign_id', $ctx['tables']['campaigns'])->delete();
    }
    foreach (['translations', 'banners', 'item_campaigns', 'campaigns', 'coupons', 'food', 'restaurant_schedule', 'restaurant_configs', 'restaurants', 'restaurant_wallets', 'categories', 'vendors'] as $table) {
        $db::table($table)->whereIn('id', $ctx['tables'][$table])->delete();
    }
}

$options = getopt('', ['help', 'evidence-dir:', 'scope-file:', 'export-scope:', 'apply', 'rollback', 'confirm:']);
if (isset($options['help'])) {
    usage();
    exit(0);
}

$evidenceDir = realpath((string) ($options['evidence-dir'] ?? ''));
if ($evidenceDir === false || ! is_dir($evidenceDir)) {
    abortWith('必须提供存在的 --evidence-dir');
}

$base = __DIR__;
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ctx = buildContext($evidenceDir);
$scope = loadScope(isset($options['scope-file']) ? (string) $options['scope-file'] : null);
if (isset($options['export-scope'])) {
    exportScope($ctx, (string) $options['export-scope']);
    exit(0);
}
$plan = buildPlan($ctx, $scope);
$planHash = hash('sha256', canonicalJson($plan));

echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
echo "PLAN_SHA256={$planHash}".PHP_EOL;

if (! isset($options['apply'])) {
    exit($plan['blockers'] === [] ? 0 : 4);
}
if ($plan['blockers'] !== []) {
    abortWith('计划仍有阻断，禁止执行', 4);
}
if (isset($options['rollback'])) {
    if (! str_starts_with($plan['database'], 'nezha_qa_')) {
        abortWith('REHEARSE 只允许连接名为 nezha_qa_* 的一次性数据库', 4);
    }
    if (($plan['scope']['purpose'] ?? null) !== 'rehearsal-only') {
        abortWith('REHEARSE 必须使用 purpose=rehearsal-only 的 scope', 4);
    }
} else {
    if (($plan['scope']['purpose'] ?? null) !== 'production-approved') {
        abortWith('提交必须使用 purpose=production-approved 的独立 scope', 4);
    }
    if (getenv('NZ_DEMO_ALLOW_COMMIT') !== 'YES') {
        abortWith('提交必须同时设置 NZ_DEMO_ALLOW_COMMIT=YES', 4);
    }
}
if (($plan['scope']['source_database'] ?? null) !== $plan['database']) {
    abortWith('scope.source_database 与实际数据库不一致', 4);
}

$confirm = (string) ($options['confirm'] ?? '');
if (! hash_equals($planHash, $confirm)) {
    abortWith("计划哈希不匹配 expected={$planHash}", 3);
}

Illuminate\Support\Facades\DB::beginTransaction();
try {
    executeCleanup($ctx, $plan);
    assertResidualZero($ctx);

    if (isset($options['rollback'])) {
        Illuminate\Support\Facades\DB::rollBack();
        $restoredPlan = buildPlan($ctx, $scope);
        $restoredHash = hash('sha256', canonicalJson($restoredPlan));
        if (! hash_equals($planHash, $restoredHash)) {
            abortWith("事务回滚后计划不一致 expected={$planHash} actual={$restoredHash}", 6);
        }
        echo "REHEARSAL_ROLLED_BACK plan_sha256={$restoredHash}".PHP_EOL;
        exit(0);
    }

    Illuminate\Support\Facades\DB::commit();
    echo "CLEANUP_COMMITTED plan_sha256={$planHash}".PHP_EOL;
} catch (Throwable $e) {
    if (Illuminate\Support\Facades\DB::transactionLevel() > 0) {
        Illuminate\Support\Facades\DB::rollBack();
    }
    abortWith('执行失败且已回滚：'.$e->getMessage(), 5);
}
