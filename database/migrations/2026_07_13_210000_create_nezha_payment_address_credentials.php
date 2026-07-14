<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 顾客付款地址凭据账本。
 *
 * 只记录平台向已登录顾客展示过的 USDT 地址版本，不创建订单、不冻结金额、
 * 不触碰资金。地址与交易哈希由模型 encrypted cast 做应用层加密；表空间再尽力
 * 启用 MySQL ENCRYPTION='Y'。功能开关默认 0，迁移本身不改变现行付款链路。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nezha_payment_address_credentials')) {
            Schema::create('nezha_payment_address_credentials', function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->char('secret_hash', 64);
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('restaurant_id')->index();
                $table->unsignedBigInteger('method_id')->index();
                $table->string('network', 8);
                $table->text('address_snapshot');
                $table->char('address_fingerprint', 64);
                $table->timestamp('issued_at');
                $table->timestamp('expires_at')->index();
                $table->timestamp('consumed_at')->nullable();
                $table->unsignedBigInteger('consumed_order_id')->nullable()->unique();
                $table->text('submitted_tx_hash')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->text('revoked_reason')->nullable();
                $table->timestamps();

                $table->index(
                    ['user_id', 'restaurant_id', 'issued_at'],
                    'nz_addr_cred_user_rest_issued_idx'
                );
                $table->index(
                    ['restaurant_id', 'network', 'address_fingerprint'],
                    'nz_addr_cred_rest_net_fp_idx'
                );
            });

            try {
                DB::statement("ALTER TABLE `nezha_payment_address_credentials` ENCRYPTION='Y'");
            } catch (\Throwable $e) {
                // SQLite/无 keyring 环境不阻断迁移；生产部署门会单独核验表空间加密。
            }
        }

        $this->ensureSetting('nezha_payment_address_credential_status', '0');
    }

    public function down(): void
    {
        if (Schema::hasTable('nezha_payment_address_credentials')
            && DB::table('nezha_payment_address_credentials')->exists()) {
            throw new \RuntimeException(
                'Refusing to drop non-empty payment address credential evidence; use an approved retention procedure.'
            );
        }
        if (DB::table('business_settings')
            ->where('key', 'nezha_payment_address_credential_status')
            ->where('value', '1')
            ->exists()) {
            throw new \RuntimeException('Refusing rollback while payment address credentials are enabled.');
        }
        Schema::dropIfExists('nezha_payment_address_credentials');
        // Preserve the disabled switch row. business_settings.key is not unique
        // in production, so a down migration cannot prove which matching row it
        // originally inserted without risking deletion of operator-owned data.
    }

    private function ensureSetting(string $key, string $defaultValue): void
    {
        $count = (int) DB::table('business_settings')->where('key', $key)->count();

        if ($count > 1) {
            throw new \RuntimeException("Refusing migration with duplicate business setting: {$key}");
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
};
