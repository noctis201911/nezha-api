<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * USDT 顾客退款地址凭据与订单级退款快照。
 *
 * 顾客地址在付款凭证提交前签发，并与商家收款地址凭据在同一事务消费。
 * tx.from 只保留为来源证据，任何模式都不能成为退款目标。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nezha_payment_address_credentials')
            && ! Schema::hasColumn(
                'nezha_payment_address_credentials',
                'submitted_tx_fingerprint'
            )) {
            Schema::table('nezha_payment_address_credentials', function (Blueprint $table): void {
                $table->char('submitted_tx_fingerprint', 64)
                    ->nullable()
                    ->after('submitted_tx_hash');
                $table->unique(
                    'submitted_tx_fingerprint',
                    'nz_payment_tx_fingerprint_uq'
                );
            });
        }

        if (! Schema::hasTable('nezha_customer_refund_address_credentials')) {
            Schema::create('nezha_customer_refund_address_credentials', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->char('secret_hash', 64);
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('restaurant_id')->index();
                $table->unsignedBigInteger('method_id')->index();
                $table->string('network', 8);
                $table->text('address_snapshot');
                $table->char('address_fingerprint', 64);
                $table->string('verification_status', 32)->default('customer_attested');
                $table->string('route_policy_version', 32)->default('refund-bound-v2');

                // MVP 不执行钱包签名；相关列只为未来经批准的 control_verified 档保留。
                $table->char('control_challenge_hash', 64)->nullable();
                $table->text('control_evidence')->nullable();
                $table->string('control_method', 32)->nullable();
                $table->timestamp('control_verified_at')->nullable();

                $table->timestamp('issued_at');
                $table->timestamp('expires_at')->index();
                $table->timestamp('consumed_at')->nullable();
                $table->unsignedBigInteger('consumed_order_id')->nullable()->unique();

                // 付款时冻结的只读证据；不得由后续资料、请求参数或商家输入改写。
                $table->text('payment_tx_hash')->nullable();
                $table->text('payment_from_address')->nullable();
                $table->string('asset_contract', 120)->nullable();
                $table->unsignedTinyInteger('asset_decimals')->nullable();
                $table->decimal('paid_asset_amount_atomic', 65, 0)->nullable();
                $table->decimal('refundable_amd_snapshot', 24, 2)->nullable();
                $table->string('order_currency_snapshot', 8)->nullable();

                $table->timestamp('revoked_at')->nullable();
                $table->text('revoked_reason')->nullable();
                $table->timestamp('redacted_at')->nullable()->index();
                $table->timestamps();

                // 审计凭据须在用户、餐厅、支付方式或订单软/硬删除后仍可保留。
                // 因此只保存不可变 ID + 索引，不建立会级联删除或阻断父记录清理的外键。
                $table->index(
                    ['user_id', 'restaurant_id', 'method_id', 'network'],
                    'nz_refund_cred_binding_idx'
                );
                $table->index(
                    ['network', 'address_fingerprint'],
                    'nz_refund_cred_net_fp_idx'
                );
            });
        }

        $this->assertTablespaceEncrypted('nezha_customer_refund_address_credentials');

        if (Schema::hasTable('nezha_refund_records')) {
            Schema::table('nezha_refund_records', function (Blueprint $table): void {
                if (! Schema::hasColumn('nezha_refund_records', 'refund_address_credential_id')) {
                    $table->unsignedBigInteger('refund_address_credential_id')->nullable()->index();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'route_policy_version')) {
                    $table->string('route_policy_version', 32)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'destination_source')) {
                    $table->string('destination_source', 32)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'destination_verification_method')) {
                    $table->string('destination_verification_method', 32)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'verification_status')) {
                    $table->string('verification_status', 32)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'address_fingerprint')) {
                    $table->char('address_fingerprint', 64)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'payment_from_address')) {
                    $table->string('payment_from_address', 120)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'asset_network')) {
                    $table->string('asset_network', 8)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'refund_asset')) {
                    $table->string('refund_asset', 16)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'asset_contract')) {
                    $table->string('asset_contract', 120)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'asset_decimals')) {
                    $table->unsignedTinyInteger('asset_decimals')->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'paid_asset_amount_atomic')) {
                    $table->decimal('paid_asset_amount_atomic', 65, 0)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'refund_asset_amount_atomic')) {
                    $table->decimal('refund_asset_amount_atomic', 65, 0)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'refund_amount_order_currency')) {
                    $table->decimal('refund_amount_order_currency', 24, 2)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'refundable_amd_snapshot')) {
                    $table->decimal('refundable_amd_snapshot', 24, 2)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'order_currency_snapshot')) {
                    $table->string('order_currency_snapshot', 8)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'refund_amount_policy')) {
                    $table->string('refund_amount_policy', 32)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'hold_reason')) {
                    $table->string('hold_reason', 64)->nullable()->index();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'reconfirm_challenge_hash')) {
                    $table->char('reconfirm_challenge_hash', 64)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'reconfirm_initial_token_id')) {
                    $table->string('reconfirm_initial_token_id', 100)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'reconfirm_expires_at')) {
                    $table->timestamp('reconfirm_expires_at')->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'reconfirmed_at')) {
                    $table->timestamp('reconfirmed_at')->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'reconfirm_auth_method')) {
                    $table->string('reconfirm_auth_method', 32)->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'reconfirm_consumed_at')) {
                    $table->timestamp('reconfirm_consumed_at')->nullable();
                }
                if (! Schema::hasColumn('nezha_refund_records', 'refund_tx_fingerprint')) {
                    $table->char('refund_tx_fingerprint', 64)->nullable()->unique();
                }
            });
            $this->assertTablespaceEncrypted('nezha_refund_records');
        }

        $this->ensureSetting('nezha_usdt_refund_binding_mode', 'drain');
        $this->ensureSetting('nezha_usdt_refund_legal_gate', 'pending');
        $this->ensureSetting('nezha_refund_reconfirm_ttl_seconds', '300');
        $this->ensureSetting('nezha_refund_bsc_finality_blocks', '12');
        $this->ensureSetting('nezha_refund_tron_finality_blocks', '20');
        $this->ensureSetting('nezha_refund_sanction_max_sync_age_hours', '48');
    }

    public function down(): void
    {
        if (Schema::hasTable('nezha_customer_refund_address_credentials')
            && DB::table('nezha_customer_refund_address_credentials')->exists()) {
            throw new RuntimeException(
                'Refusing to drop consumed or retained customer refund address evidence.'
            );
        }
        if (DB::table('business_settings')
            ->where('key', 'nezha_usdt_refund_binding_mode')
            ->whereIn('value', ['enforce', 'drain'])
            ->exists()) {
            throw new RuntimeException('Refusing rollback while the v2 refund route is active or draining.');
        }

        if (Schema::hasTable('nezha_refund_records')) {
            $hasV2Evidence = DB::table('nezha_refund_records')
                ->whereNotNull('route_policy_version')
                ->exists();
            if ($hasV2Evidence) {
                throw new RuntimeException('Refusing to drop v2 refund snapshots or reconfirmation evidence.');
            }
        }

        Schema::dropIfExists('nezha_customer_refund_address_credentials');
        // Existing refund columns and disabled setting rows are deliberately retained:
        // MySQL DDL auto-commits and dropping audit columns is not a safe rollback.
    }

    private function ensureSetting(string $key, string $defaultValue): void
    {
        $count = (int) DB::table('business_settings')->where('key', $key)->count();
        if ($count > 1) {
            throw new RuntimeException("Refusing migration with duplicate business setting: {$key}");
        }
        if ($count === 0) {
            DB::table('business_settings')->insert([
                'key' => $key,
                'value' => $defaultValue,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function assertTablespaceEncrypted(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ENCRYPTION='Y'");
        $row = DB::selectOne(
            'SELECT CREATE_OPTIONS AS create_options FROM information_schema.TABLES '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
            [DB::connection()->getDatabaseName(), $table]
        );
        $options = strtoupper((string) ($row->create_options ?? ''));
        if (strpos($options, 'ENCRYPTION="Y"') === false
            && strpos($options, "ENCRYPTION='Y'") === false) {
            throw new RuntimeException("MySQL tablespace encryption verification failed: {$table}");
        }
    }
};
