<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nezha B-plan: customer "cancel request after merchant accepts" + merchant reject flow.
 *
 * These columns track a cancellation REQUEST lifecycle WITHOUT changing order_status:
 * the order keeps fulfilling (confirmed/processing) until the merchant decides.
 *   nezha_cancel_request: null | requested | approved | rejected
 * On approve the order goes through the shared cancellation finalizer (canceled + refund
 * record for paid direct-pay orders); on reject the order resumes its original status.
 * Compliance: platform never touches money (L1-1); paid orders only get a
 * pending_merchant_refund ledger record + "contact merchant" notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'nezha_cancel_request')) {
                $table->string('nezha_cancel_request', 20)->nullable()->after('refund_request_canceled');
            }
            if (!Schema::hasColumn('orders', 'nezha_cancel_request_reason')) {
                $table->string('nezha_cancel_request_reason', 500)->nullable()->after('nezha_cancel_request');
            }
            if (!Schema::hasColumn('orders', 'nezha_cancel_requested_at')) {
                $table->timestamp('nezha_cancel_requested_at')->nullable()->after('nezha_cancel_request_reason');
            }
            if (!Schema::hasColumn('orders', 'nezha_cancel_response_note')) {
                $table->string('nezha_cancel_response_note', 500)->nullable()->after('nezha_cancel_requested_at');
            }
            if (!Schema::hasColumn('orders', 'nezha_cancel_responded_at')) {
                $table->timestamp('nezha_cancel_responded_at')->nullable()->after('nezha_cancel_response_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach ([
                'nezha_cancel_request',
                'nezha_cancel_request_reason',
                'nezha_cancel_requested_at',
                'nezha_cancel_response_note',
                'nezha_cancel_responded_at',
            ] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
