<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive storage for the dormant USDT address-change state machine.
 *
 * Running this migration does not initialize network states, change an address,
 * or enable either feature switch. Address/reason/context values are encrypted
 * by model casts; MySQL tablespace encryption is mandatory and verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nezha_payment_network_states')) {
            Schema::create('nezha_payment_network_states', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('restaurant_id');
                $table->string('network', 8);
                $table->string('state', 16)->default('active');
                $table->char('active_address_fingerprint', 64);
                $table->unsignedInteger('active_version')->default(1);
                $table->unsignedBigInteger('pending_change_id')->nullable();
                $table->timestamp('drain_until')->nullable()->index();
                $table->timestamp('paused_at')->nullable();
                $table->unsignedBigInteger('paused_by_admin_id')->nullable();
                $table->text('pause_reason')->nullable();
                $table->timestamps();

                $table->unique(['restaurant_id', 'network'], 'nz_pay_net_rest_network_uq');
                $table->index(['state', 'drain_until'], 'nz_pay_net_state_drain_idx');
            });
        }
        $this->assertTablespaceEncrypted('nezha_payment_network_states');

        if (! Schema::hasTable('nezha_payment_address_changes')) {
            Schema::create('nezha_payment_address_changes', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->unsignedBigInteger('restaurant_id');
                $table->string('network', 8);
                $table->string('source_state', 16);
                $table->text('old_address');
                $table->text('new_address');
                $table->char('old_fingerprint', 64);
                $table->char('new_fingerprint', 64);
                $table->unsignedInteger('expected_version');
                $table->string('state', 32);
                $table->unsignedBigInteger('requested_by_admin_id');
                $table->char('idempotency_hash', 64);
                $table->text('reason');
                $table->unsignedBigInteger('merchant_confirmed_by_vendor_id')->nullable();
                $table->timestamp('merchant_confirmed_at')->nullable();
                $table->unsignedBigInteger('approved_by_admin_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('drain_until')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->timestamp('canceled_at')->nullable();
                $table->timestamp('expired_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->string('failure_code', 64)->nullable();
                $table->timestamps();

                $table->unique(
                    ['requested_by_admin_id', 'idempotency_hash'],
                    'nz_pay_chg_admin_idem_uq'
                );
                $table->index(
                    ['restaurant_id', 'network', 'state'],
                    'nz_pay_chg_rest_net_state_idx'
                );
                $table->index(['state', 'expires_at'], 'nz_pay_chg_state_exp_idx');
                $table->index(['state', 'drain_until'], 'nz_pay_chg_state_drain_idx');
            });
        }
        $this->assertTablespaceEncrypted('nezha_payment_address_changes');

        if (! Schema::hasTable('nezha_payment_address_change_events')) {
            Schema::create('nezha_payment_address_change_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('change_id')->nullable();
                $table->unsignedBigInteger('network_state_id');
                $table->string('event_type', 32);
                $table->string('state_from', 32)->nullable();
                $table->string('state_to', 32);
                $table->string('actor_type', 16);
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->unsignedBigInteger('totp_counter')->nullable();
                $table->text('context')->nullable();
                $table->timestamp('created_at')->useCurrent();

                // NULL counters (vendor/system events) may repeat; admin step counters may not.
                $table->unique(
                    ['actor_type', 'actor_id', 'totp_counter'],
                    'nz_pay_evt_actor_totp_uq'
                );
                $table->index(['change_id', 'id'], 'nz_pay_evt_change_id_idx');
                $table->index(['network_state_id', 'id'], 'nz_pay_evt_network_id_idx');
            });
        }
        $this->assertTablespaceEncrypted('nezha_payment_address_change_events');

        $this->ensureSetting('nezha_payment_address_change_status', '0');
        $this->ensureSetting('nezha_payment_address_change_approval_ttl_min', '1440');
    }

    public function down(): void
    {
        if (DB::table('business_settings')
            ->where('key', 'nezha_payment_address_change_status')
            ->where('value', '1')
            ->exists()) {
            throw new \RuntimeException('Refusing rollback while payment address changes are enabled.');
        }
        foreach ([
            'nezha_payment_address_change_events',
            'nezha_payment_address_changes',
            'nezha_payment_network_states',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new \RuntimeException(
                    'Refusing to drop non-empty payment address security tables; preserve audit evidence.'
                );
            }
        }
        Schema::dropIfExists('nezha_payment_address_change_events');
        Schema::dropIfExists('nezha_payment_address_changes');
        Schema::dropIfExists('nezha_payment_network_states');

        // Preserve setting rows. business_settings.key is not unique in
        // production, so down() cannot safely identify migration-owned rows.
    }

    private function assertTablespaceEncrypted(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Run this for both new and pre-existing tables. MySQL DDL auto-commits,
        // so a failed ALTER after CREATE must be repaired and re-verified when
        // the migration is retried.
        DB::statement("ALTER TABLE `{$table}` ENCRYPTION='Y'");

        $row = DB::selectOne(
            'SELECT CREATE_OPTIONS AS create_options FROM information_schema.TABLES '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
            [DB::connection()->getDatabaseName(), $table]
        );
        $options = strtoupper((string) ($row->create_options ?? ''));

        if (strpos($options, 'ENCRYPTION="Y"') === false
            && strpos($options, "ENCRYPTION='Y'") === false) {
            throw new \RuntimeException("MySQL tablespace encryption verification failed: {$table}");
        }
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
