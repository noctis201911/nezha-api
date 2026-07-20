<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive V2 late-payment case fields on the existing refund aggregate.
 *
 * This deliberately does not create a second refund, credential, or network
 * ledger. The feature switch is seeded disabled and the migration performs no
 * payment, provider, address, notification, or order-status action.
 *
 * Funds migration 150000 must already own event_key. Ordinary refunds keep
 * event_key=order:{id}:refund; V2 rows use source_domain + case_key and leave
 * event_key null, so one ordinary refund and one late case may coexist for an
 * order. Once V2 evidence exists, down() is intentionally refused and schema
 * evolution is forward-only.
 */
return new class extends Migration
{
    private const SWITCH_KEY = 'nezha_direct_payment_late_v2_status';

    /** @var list<string> */
    private const CASE_COLUMNS = [
        'source_domain',
        'case_public_id',
        'case_key',
        'state_version',
        'credential_id',
        'method_id',
        'wallet_type',
        'network',
        'token_contract',
        'token_decimals',
        'late_payment_tx_hash',
        'late_payment_claim_key',
        'late_payment_event_index',
        'received_amount_atomic',
        'refund_amount_atomic',
        'late_refund_destination',
        'late_refund_destination_fingerprint',
        'refund_destination_source',
        'late_refund_tx_hash',
        'late_refund_claim_key',
        'late_refund_event_index',
        'evidence_authority',
        'reported_at',
        'payment_attributed_at',
        'refund_submitted_at',
        'closed_at',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('nezha_refund_records')) {
            throw new RuntimeException('nezha_refund_records must exist before the V2 late-payment migration.');
        }
        if (! Schema::hasColumn('nezha_refund_records', 'event_key')) {
            throw new RuntimeException(
                'Funds refund event identity migration must run before the V2 late-payment migration.'
            );
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE nezha_refund_records MODIFY status VARCHAR(64) NOT NULL DEFAULT 'recorded'");
        }

        Schema::table('nezha_refund_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('nezha_refund_records', 'source_domain')) {
                $table->string('source_domain', 40)->nullable()->index();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'case_public_id')) {
                $table->uuid('case_public_id')->nullable()->unique();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'case_key')) {
                $table->char('case_key', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'state_version')) {
                $table->unsignedInteger('state_version')->default(0);
            }
            if (! Schema::hasColumn('nezha_refund_records', 'credential_id')) {
                $table->unsignedBigInteger('credential_id')->nullable()->index();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'method_id')) {
                $table->unsignedBigInteger('method_id')->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'wallet_type')) {
                $table->string('wallet_type', 32)->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'network')) {
                $table->string('network', 8)->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'token_contract')) {
                $table->string('token_contract', 120)->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'token_decimals')) {
                $table->unsignedTinyInteger('token_decimals')->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'late_payment_tx_hash')) {
                $table->text('late_payment_tx_hash')->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'late_payment_claim_key')) {
                $table->char('late_payment_claim_key', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'late_payment_event_index')) {
                $table->unsignedBigInteger('late_payment_event_index')->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'received_amount_atomic')) {
                $table->string('received_amount_atomic', 78)->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'refund_amount_atomic')) {
                $table->string('refund_amount_atomic', 78)->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'late_refund_destination')) {
                $table->text('late_refund_destination')->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'late_refund_destination_fingerprint')) {
                $table->char('late_refund_destination_fingerprint', 64)->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'refund_destination_source')) {
                $table->string('refund_destination_source', 64)->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'late_refund_tx_hash')) {
                $table->text('late_refund_tx_hash')->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'late_refund_claim_key')) {
                $table->char('late_refund_claim_key', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'late_refund_event_index')) {
                $table->unsignedBigInteger('late_refund_event_index')->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'evidence_authority')) {
                $table->string('evidence_authority', 40)->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'reported_at')) {
                $table->timestamp('reported_at')->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'payment_attributed_at')) {
                $table->timestamp('payment_attributed_at')->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'refund_submitted_at')) {
                $table->timestamp('refund_submitted_at')->nullable();
            }
            if (! Schema::hasColumn('nezha_refund_records', 'closed_at')) {
                $table->timestamp('closed_at')->nullable();
            }
        });

        if (! Schema::hasTable('nezha_refund_record_events')) {
            Schema::create('nezha_refund_record_events', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->uuid('public_id')->unique();
                $table->unsignedBigInteger('refund_record_id');
                $table->unsignedInteger('sequence');
                $table->string('event_type', 64);
                $table->string('state_from', 64)->nullable();
                $table->string('state_to', 64);
                $table->string('actor_type', 32);
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('evidence_authority', 40)->nullable();
                $table->text('payload')->nullable();
                $table->char('payload_hash', 64);
                $table->timestamp('recorded_at');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['refund_record_id', 'sequence'], 'nz_ref_evt_record_sequence_uq');
                $table->index(['refund_record_id', 'id'], 'nz_ref_evt_record_id_idx');
                $table->index(['event_type', 'recorded_at'], 'nz_ref_evt_type_time_idx');
                $table->foreign('refund_record_id', 'nz_ref_evt_record_fk')
                    ->references('id')->on('nezha_refund_records')->onDelete('restrict');
            });
        }

        $this->assertTablespaceEncrypted('nezha_refund_record_events');
        $this->createAppendOnlyTriggers();
        $this->ensureSetting(self::SWITCH_KEY, '0');
    }

    public function down(): void
    {
        if (DB::table('business_settings')
            ->where('key', self::SWITCH_KEY)
            ->where('value', '1')
            ->exists()) {
            throw new RuntimeException('Refusing rollback while direct-payment late V2 is enabled.');
        }
        if ((Schema::hasTable('nezha_refund_record_events')
                && DB::table('nezha_refund_record_events')->exists())
            || DB::table('nezha_refund_records')
                ->where('source_domain', 'direct_payment_late_v2')
                ->exists()) {
            throw new RuntimeException('Refusing to drop non-empty V2 late-payment evidence.');
        }

        $this->dropAppendOnlyTriggers();
        Schema::dropIfExists('nezha_refund_record_events');
        Schema::table('nezha_refund_records', function (Blueprint $table): void {
            foreach (self::CASE_COLUMNS as $column) {
                if (Schema::hasColumn('nezha_refund_records', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE nezha_refund_records MODIFY status VARCHAR(40) NOT NULL DEFAULT 'recorded'");
        }
        // Preserve the disabled setting row because business_settings.key is not unique.
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
        if (! str_contains($options, 'ENCRYPTION="Y"')
            && ! str_contains($options, "ENCRYPTION='Y'")) {
            throw new RuntimeException("MySQL tablespace encryption verification failed: {$table}");
        }
    }

    private function createAppendOnlyTriggers(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            $this->dropAppendOnlyTriggers();
            DB::unprepared("CREATE TRIGGER nz_ref_evt_no_update BEFORE UPDATE ON nezha_refund_record_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refund events are append-only'");
            DB::unprepared("CREATE TRIGGER nz_ref_evt_no_delete BEFORE DELETE ON nezha_refund_record_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refund events are append-only'");
        } elseif ($driver === 'sqlite') {
            $this->dropAppendOnlyTriggers();
            DB::unprepared("CREATE TRIGGER nz_ref_evt_no_update BEFORE UPDATE ON nezha_refund_record_events BEGIN SELECT RAISE(ABORT, 'refund events are append-only'); END");
            DB::unprepared("CREATE TRIGGER nz_ref_evt_no_delete BEFORE DELETE ON nezha_refund_record_events BEGIN SELECT RAISE(ABORT, 'refund events are append-only'); END");
        }
    }

    private function dropAppendOnlyTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS nz_ref_evt_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS nz_ref_evt_no_delete');
    }
};
