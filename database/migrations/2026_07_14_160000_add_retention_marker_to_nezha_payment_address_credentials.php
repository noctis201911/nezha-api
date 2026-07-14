<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks unconsumed credential rows whose address snapshot and secret hash were
 * cleared after the fixed 30-day retention window. Binding, fingerprint,
 * lifecycle timestamps and consumed order evidence remain intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nezha_payment_address_credentials')
            || Schema::hasColumn('nezha_payment_address_credentials', 'redacted_at')) {
            return;
        }

        Schema::table('nezha_payment_address_credentials', function (Blueprint $table): void {
            $table->timestamp('redacted_at')->nullable()->index()->after('revoked_reason');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('nezha_payment_address_credentials')
            || ! Schema::hasColumn('nezha_payment_address_credentials', 'redacted_at')) {
            return;
        }
        if (DB::table('nezha_payment_address_credentials')->whereNotNull('redacted_at')->exists()) {
            throw new \RuntimeException(
                'Refusing to drop credential retention evidence after redaction has occurred.'
            );
        }

        Schema::table('nezha_payment_address_credentials', function (Blueprint $table): void {
            $table->dropColumn('redacted_at');
        });
    }
};
