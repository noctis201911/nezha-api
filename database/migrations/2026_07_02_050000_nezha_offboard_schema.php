<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒[商家退出结算/押金账户 · DESIGN_merchant_offboard §A · step1 schema] — 全 additive 迁移(可回滚)。
 *
 * A1 restaurant_wallets.guarantee_balance —— 押金独立可退子余额。
 * A2 restaurant_deposit_transactions: currency / original_amount / original_ref —— 法币缴纳记录+回执留痕。
 *    🔴 L1-8⑤ 留存: 本表为资金流水, 全库 purge 任务均不触及(2026-07-02 grep app/Console 零命中已核实),
 *       押金流水/结算记录须留存≥5年、免 PII 清除(同 L1-4)。original_ref 走模型 encrypted cast。
 * A3 restaurants: onboard_source / offboard_status / guarantee_tier —— 来路溯源 + 退出状态机 + 应缴档。
 *    (存量店 ADD COLUMN default 自动回填: onboard_source='unknown' / offboard_status='active' / guarantee_tier=NULL)
 * A4 vendor_kyc_profiles: account_holder_name(模型 encrypted, 户名 enforce) / id_doc_fingerprint(HMAC hex 明文可索引)。
 * A5 restaurant_offboard_settlements(新表): 退出结算工单; uq_active(vendor_id, active_uniq) 5.7 部分唯一=幂等根,
 *    同 vendor 至多一条 active(active_uniq=1), 关闭态置 NULL 可并存(可重申)。🔴 L1-8⑤ 同留存, 无 purge 触及。
 *
 * 定级: L3 结构(实装 L1-8 已批准机制的载体, 本迁移**不激活**任何资金/合规行为——纯 schema)。5.7 additive 可回滚 greenfield。
 */
return new class extends Migration
{
    public function up(): void
    {
        // A1
        if (Schema::hasTable('restaurant_wallets') && !Schema::hasColumn('restaurant_wallets', 'guarantee_balance')) {
            Schema::table('restaurant_wallets', function (Blueprint $t) {
                $t->decimal('guarantee_balance', 24, 2)->default(0)->after('deposit_balance');
            });
        }

        // A2 (retention-exempt ledger)
        Schema::table('restaurant_deposit_transactions', function (Blueprint $t) {
            if (!Schema::hasColumn('restaurant_deposit_transactions', 'currency')) {
                $t->string('currency', 8)->default('AMD')->after('balance_after');
            }
            if (!Schema::hasColumn('restaurant_deposit_transactions', 'original_amount')) {
                $t->decimal('original_amount', 24, 2)->nullable()->after('currency');
            }
            if (!Schema::hasColumn('restaurant_deposit_transactions', 'original_ref')) {
                $t->text('original_ref')->nullable()->after('original_amount');
            }
        });

        // A3
        Schema::table('restaurants', function (Blueprint $t) {
            if (!Schema::hasColumn('restaurants', 'onboard_source')) {
                $t->enum('onboard_source', ['self_register', 'admin_create', 'unknown'])->default('unknown')->after('status');
            }
            if (!Schema::hasColumn('restaurants', 'offboard_status')) {
                $t->enum('offboard_status', ['active', 'settling', 'owing', 'offboarded'])->default('active')->after('onboard_source');
            }
            if (!Schema::hasColumn('restaurants', 'guarantee_tier')) {
                $t->enum('guarantee_tier', ['exempt', '500', '1000', '5000'])->nullable()->after('offboard_status');
            }
        });

        // A4
        if (Schema::hasTable('vendor_kyc_profiles')) {
            Schema::table('vendor_kyc_profiles', function (Blueprint $t) {
                if (!Schema::hasColumn('vendor_kyc_profiles', 'account_holder_name')) {
                    $t->text('account_holder_name')->nullable()->after('bank_account');
                }
                if (!Schema::hasColumn('vendor_kyc_profiles', 'id_doc_fingerprint')) {
                    $t->string('id_doc_fingerprint', 64)->nullable()->index()->after('account_holder_name');
                }
            });
        }

        // A5 (new table, retention-exempt)
        if (!Schema::hasTable('restaurant_offboard_settlements')) {
            Schema::create('restaurant_offboard_settlements', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('vendor_id')->index();
                $t->unsignedBigInteger('restaurant_id')->index();
                $t->tinyInteger('active_uniq')->nullable();
                $t->unique(['vendor_id', 'active_uniq'], 'uq_active');
                $t->enum('status', ['applied', 'kyc_pending', 'approved', 'rejected', 'withdrawn', 'paying', 'paid', 'partial', 'failed'])->default('applied');
                $t->timestamp('applied_at')->nullable();
                $t->timestamp('cooldown_until')->nullable();
                $t->boolean('kyc_gate_passed')->default(false);
                $t->timestamp('sanction_rescreen_at')->nullable();
                $t->boolean('holder_verified')->default(false);
                $t->decimal('guarantee_amt', 24, 2)->nullable();
                $t->decimal('deposit_amt', 24, 2)->nullable();
                $t->decimal('ad_amt', 24, 2)->nullable();
                $t->decimal('net_amount', 24, 2)->nullable();
                $t->decimal('shortfall_amount', 24, 2)->nullable();
                $t->decimal('pending_clawback', 24, 2)->default(0);
                $t->boolean('leg_deposit_paid')->default(false);
                $t->boolean('leg_ad_paid')->default(false);
                $t->boolean('leg_guarantee_paid')->default(false);
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->string('payout_ref')->nullable();
                $t->text('note')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_offboard_settlements');

        if (Schema::hasTable('vendor_kyc_profiles')) {
            Schema::table('vendor_kyc_profiles', function (Blueprint $t) {
                foreach (['id_doc_fingerprint', 'account_holder_name'] as $c) {
                    if (Schema::hasColumn('vendor_kyc_profiles', $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }

        Schema::table('restaurants', function (Blueprint $t) {
            foreach (['guarantee_tier', 'offboard_status', 'onboard_source'] as $c) {
                if (Schema::hasColumn('restaurants', $c)) {
                    $t->dropColumn($c);
                }
            }
        });

        Schema::table('restaurant_deposit_transactions', function (Blueprint $t) {
            foreach (['original_ref', 'original_amount', 'currency'] as $c) {
                if (Schema::hasColumn('restaurant_deposit_transactions', $c)) {
                    $t->dropColumn($c);
                }
            }
        });

        if (Schema::hasTable('restaurant_wallets') && Schema::hasColumn('restaurant_wallets', 'guarantee_balance')) {
            Schema::table('restaurant_wallets', function (Blueprint $t) {
                $t->dropColumn('guarantee_balance');
            });
        }
    }
};
