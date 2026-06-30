<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒商家广告「实时竞价」v1 — T1 数据层(后端核心,定级 L2).
 *
 * 落地七不变量(INVARIANTS, 见 docs/PLAN_ad_auction.md §2):
 * - INV-1 资金隔离: restaurant_wallets 加 ad_balance, 与 deposit_balance 物理分列.
 *         广告费只扣 ad_balance, 永不碰 deposit_balance, 从结构上根除「买广告把自己店买下线」.
 * - INV-2 原子封顶: advertisements 加 spent_today / daily_budget. 扣费走原子条件 UPDATE
 *         WHERE ad_balance>=cost / spent_today+cost<=daily_budget, 受影响 0 行 = 到顶拒扣.
 * - INV-3 锁序: restaurant_wallets → advertisements → 流水 三路径同序.
 * - INV-4 计费身份: click 端点 auth:api + 真实下单史阈值(nezha_ad_trusted_min_orders).
 * - INV-5 不打广告标(业主 2026-07-01 拍板, 已数构成要件: 亚美尼亚广告法实质性反误导, 无强制标识).
 * - INV-6 L2, ad_balance 走商家 B2B 预付, 不碰顾客钱.
 * - INV-7 首价 + floor>0 + daily_budget 硬上限 + max_per_click 上限.
 *
 * 物化方式(业主拍板): advertisements 加 mat_boost/mat_rank/mat_at 三字段(不单独建赢家表).
 * 重算命令 nezha:recompute-ad-auction 每 nezha_ad_recompute_min 分钟跑(bootstrap withSchedule).
 *
 * 与既有 CPT 计费(nezha_ad_billing_status, 已上线但当前 0=关) 并行解耦:
 * - pricing_model='cpt' 老广告全部默认值, 行为零回归.
 * - pricing_model='cpc' 新增, 仅 nezha_ad_auction_status=1 时生效.
 *
 * 全 additive/nullable/可回滚: down() 删字段/删表/删 settings 行, 老数据零损失.
 */
return new class extends Migration {

    public function up(): void
    {
        // INV-1: ad_balance 资金隔离
        Schema::table('restaurant_wallets', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurant_wallets', 'ad_balance')) {
                $table->decimal('ad_balance', 24, 2)->default(0)
                    ->comment('哪吒广告 CPC 子余额 ֏; 与 deposit_balance 物理分列, 广告费只动这列(INV-1)');
            }
        });

        // INV-2/3/7: 竞价 + 物化 字段
        Schema::table('advertisements', function (Blueprint $table) {
            if (!Schema::hasColumn('advertisements', 'bid_amount')) {
                $table->decimal('bid_amount', 10, 2)->nullable()
                    ->comment('CPC 每次点击最高愿付价 ֏; NULL=不竞价');
            }
            if (!Schema::hasColumn('advertisements', 'pricing_model')) {
                $table->string('pricing_model', 8)->default('cpt')
                    ->comment("'cpt'(按天包时段)|'cpc'(按点击) 与老广告并存, 老广告默认 cpt 零回归");
            }
            if (!Schema::hasColumn('advertisements', 'daily_budget')) {
                $table->decimal('daily_budget', 10, 2)->nullable()
                    ->comment('CPC 日预算硬封顶 ֏(INV-7); NULL=不限(仅在 nezha_ad_max_daily_budget 内)');
            }
            if (!Schema::hasColumn('advertisements', 'spent_today')) {
                $table->decimal('spent_today', 10, 2)->default(0)
                    ->comment('CPC 当日累计扣费 ֏; 原子 UPDATE 防超扣(INV-2); 跨日由惰性重置归零');
            }
            if (!Schema::hasColumn('advertisements', 'budget_reset_date')) {
                $table->date('budget_reset_date')->nullable()
                    ->comment('CPC spent_today 上次重置日期(Asia/Yerevan); 扣费时若<今天先归零再判封顶');
            }
            if (!Schema::hasColumn('advertisements', 'slot')) {
                $table->string('slot', 16)->nullable()
                    ->comment("广告位: 'home_carousel'|'list_top'|...; NULL=按 add_type 推断");
            }
            if (!Schema::hasColumn('advertisements', 'quality_score')) {
                $table->decimal('quality_score', 4, 3)->default(1.000)
                    ->comment('质量分 [0.000,2.000] 只用难刷信号(完单率/差评率/出餐时长); 由 recompute 命令计算');
            }
            if (!Schema::hasColumn('advertisements', 'mat_boost')) {
                $table->decimal('mat_boost', 5, 3)->default(0.000)
                    ->comment('物化: RestaurantLogic 综合分加成(0.000-1.000); recompute 写入');
            }
            if (!Schema::hasColumn('advertisements', 'mat_rank')) {
                $table->smallInteger('mat_rank')->nullable()
                    ->comment('物化: 当前 slot 内排名(1=赢家); recompute 写入; NULL=未上架/失效');
            }
            if (!Schema::hasColumn('advertisements', 'mat_at')) {
                $table->timestamp('mat_at')->nullable()
                    ->comment('物化时间戳; 排序/读 click 时可用于陈旧判断');
            }
        });

        // 添加索引(独立 closure 以避免 MySQL 同一 ALTER 内 index 与 column 顺序问题)
        try {
            Schema::table('advertisements', function (Blueprint $table) {
                $idxes = collect(DB::select("SHOW INDEX FROM advertisements"))->pluck('Key_name')->toArray();
                if (!in_array('idx_ad_cpc_active', $idxes)) {
                    $table->index(['pricing_model', 'status', 'start_date', 'end_date'], 'idx_ad_cpc_active');
                }
                if (!in_array('idx_ad_mat', $idxes)) {
                    $table->index(['slot', 'mat_rank'], 'idx_ad_mat');
                }
            });
        } catch (\Throwable $e) {
            // 索引重复或其它 best-effort 失败不阻断迁移; 由部署后 SHOW INDEX 复核
            info('[nezha_ad_auction_v1] index add best-effort: '.$e->getMessage());
        }

        // 事件流表(对账源头,dedup 唯一索引)
        if (!Schema::hasTable('ad_events')) {
            Schema::create('ad_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('advertisement_id')->index();
                $table->unsignedBigInteger('restaurant_id')->index();
                $table->unsignedBigInteger('vendor_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->comment('登录用户 id; NULL=游客(charged 必为 0)');
                $table->enum('event_type', ['click', 'impression']);
                $table->string('slot', 16)->nullable();
                $table->decimal('charged_amount', 10, 2)->default(0)
                    ->comment('实扣 ֏; 0=不可信/dedup/封顶/余额0; 对账与 advertisement_fee 流水合计相等');
                $table->string('charge_reason', 32)->nullable()
                    ->comment("'charged'|'untrusted'|'dedup'|'budget_capped'|'low_balance'|'floor_violation'|'impression'");
                $table->unsignedBigInteger('deposit_transaction_id')->nullable()
                    ->comment('实扣时关联 restaurant_deposit_transactions.id');
                $table->string('dedup_key', 64)->unique('uniq_dedup')
                    ->comment('sha1(ad_id|user_id|window_bucket) 重复 INSERT 冲突→charged=0; 同窗口同身份只计一次');
                $table->string('ip_hash', 16)->nullable()->comment('sha1(ip) 前16位; 风控分析用, 非 PII');
                $table->string('ua_hash', 16)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['advertisement_id', 'created_at'], 'idx_ad_time');
                $table->index(['vendor_id', 'created_at'], 'idx_vendor_time');
                $table->index(['user_id', 'advertisement_id', 'created_at'], 'idx_user_ad_time');
            });
        }

        // business_settings 9 键(默认全部「保守关闭/低风险」, 业主拍板)
        $settings = [
            ['nezha_ad_auction_status',         '0',     'CPC 总开关; 默认关; 关时排序退化到现行 CPT EXISTS 路径'],
            ['nezha_ad_floor_price',            '50',    '单次点击保底价 ֏; bid<floor 拒投放'],
            ['nezha_ad_max_daily_budget',       '50000', '商家日预算硬上限 ֏; daily_budget 不得超过此值'],
            ['nezha_ad_max_per_click',          '500',   '单次点击最高扣费 ֏; 防超大 bid 烧空'],
            ['nezha_ad_dedup_window_sec',       '900',   '同用户同广告去重窗口(秒); 默认 15min 内重复点只计一次'],
            ['nezha_ad_natural_reserved_slots', '3',     '前 N 自然位保留,广告不挤掉前 3 真实排名(防完全广告化)'],
            ['nezha_ad_max_share_per_store',    '3',     '同广告位单店最多占 N 位(防垄断)'],
            ['nezha_ad_recompute_min',          '5',     '物化重算间隔(分钟); recompute 命令调度间隔'],
            ['nezha_ad_trusted_min_orders',     '1',     '计费身份阈值: 登录+真实下单史>=N 才计费(INV-4)'],
        ];
        foreach ($settings as [$key, $value, $comment]) {
            DB::table('business_settings')->insertOrIgnore([
                'key'        => $key,
                'value'      => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // 反向: 先删 ad_events(无外键依赖), 再删 advertisements 字段, 最后删 ad_balance + settings
        Schema::dropIfExists('ad_events');

        Schema::table('advertisements', function (Blueprint $table) {
            try {
                $table->dropIndex('idx_ad_cpc_active');
            } catch (\Throwable $e) {}
            try {
                $table->dropIndex('idx_ad_mat');
            } catch (\Throwable $e) {}
        });

        Schema::table('advertisements', function (Blueprint $table) {
            foreach ([
                'bid_amount', 'pricing_model', 'daily_budget', 'spent_today',
                'budget_reset_date', 'slot', 'quality_score',
                'mat_boost', 'mat_rank', 'mat_at',
            ] as $c) {
                if (Schema::hasColumn('advertisements', $c)) {
                    $table->dropColumn($c);
                }
            }
        });

        Schema::table('restaurant_wallets', function (Blueprint $table) {
            if (Schema::hasColumn('restaurant_wallets', 'ad_balance')) {
                $table->dropColumn('ad_balance');
            }
        });

        DB::table('business_settings')->whereIn('key', [
            'nezha_ad_auction_status',
            'nezha_ad_floor_price',
            'nezha_ad_max_daily_budget',
            'nezha_ad_max_per_click',
            'nezha_ad_dedup_window_sec',
            'nezha_ad_natural_reserved_slots',
            'nezha_ad_max_share_per_store',
            'nezha_ad_recompute_min',
            'nezha_ad_trusted_min_orders',
        ])->delete();
    }
};
