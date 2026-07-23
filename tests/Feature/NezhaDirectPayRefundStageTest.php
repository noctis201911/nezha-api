<?php

namespace Tests\Feature;

use App\Models\NezhaRefundRecord;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NezhaDirectPayRefundStageTest extends TestCase
{
    private bool $createdTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('nezha_refund_records')) {
            Schema::create('nezha_refund_records', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('restaurant_id')->nullable()->index();
                $table->string('status', 40)->default('recorded');
                $table->string('payment_channel', 20)->default('other');
                $table->decimal('refund_amount', 24, 2)->default(0);
                $table->boolean('customer_confirmed')->default(false);
                $table->timestamp('customer_confirmed_at')->nullable();
                $table->timestamp('merchant_refunded_at')->nullable();
                $table->string('merchant_refund_note')->nullable();
                $table->string('refund_tx_hash', 120)->nullable();
                $table->char('refund_tx_fingerprint', 64)->nullable()->unique();
                $table->string('chain_verify_status', 20)->default('na');
                $table->json('chain_verify_detail')->nullable();
                $table->string('locked_to_address', 120)->nullable();
                $table->decimal('refund_asset_amount_atomic', 65, 0)->nullable();
                $table->timestamp('reconfirmed_at')->nullable();
                $table->timestamps();
            });
            $this->createdTable = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdTable) {
            Schema::dropIfExists('nezha_refund_records');
        }
        parent::tearDown();
    }

    public function test_pending_stage_projects_as_not_refunded(): void
    {
        $record = NezhaRefundRecord::forceCreate([
            'order_id' => 501,
            'restaurant_id' => 10,
            'status' => 'pending_merchant_refund',
            'payment_channel' => 'rmb',
            'refund_amount' => 88.50,
        ]);

        $projection = $record->customerProjection();

        $this->assertSame('pending_merchant_refund', $projection['status']);
        $this->assertFalse($projection['refunded']);
        $this->assertNull($projection['merchant_refunded_at']);
    }

    public function test_only_first_pending_to_refunded_transition_wins(): void
    {
        $pending = NezhaRefundRecord::forceCreate([
            'order_id' => 502,
            'restaurant_id' => 10,
            'status' => 'pending_merchant_refund',
            'payment_channel' => 'rmb',
            'refund_amount' => 25,
        ]);

        $first = NezhaRefundRecord::transitionPendingToMerchantRefunded($pending->id, [
            'merchant_refund_note' => 'merchant confirmed',
        ], 10);
        $repeat = NezhaRefundRecord::transitionPendingToMerchantRefunded($pending->id, [], 10);

        $this->assertNotNull($first);
        $this->assertSame('merchant_refunded', $first->status);
        $this->assertNotNull($first->merchant_refunded_at);
        $this->assertTrue($first->customerProjection()['refunded']);
        $this->assertNull($repeat, 'A repeated action must not win the transition or become eligible to notify again.');
    }

    public function test_usdt_cannot_transition_until_reconfirmed_and_chain_verified(): void
    {
        $pending = NezhaRefundRecord::forceCreate([
            'order_id' => 506,
            'restaurant_id' => 10,
            'status' => 'pending_merchant_refund',
            'payment_channel' => 'usdt',
            'refund_amount' => 25,
            'locked_to_address' => '0x1111111111111111111111111111111111111111',
            'refund_asset_amount_atomic' => '25000000000000000000',
        ]);

        $blocked = NezhaRefundRecord::transitionPendingToMerchantRefunded($pending->id, [
            'refund_tx_hash' => str_repeat('a', 64),
            'chain_verify_status' => 'verified',
        ], 10);
        $this->assertNull($blocked);

        $pending->reconfirmed_at = now();
        $pending->save();
        $completed = NezhaRefundRecord::transitionPendingToMerchantRefunded($pending->id, [
            'refund_tx_hash' => str_repeat('a', 64),
            'refund_tx_fingerprint' => hash('sha256', 'bsc|'.str_repeat('a', 64)),
            'chain_verify_status' => 'verified',
            'chain_verify_detail' => ['amount_atomic' => '25000000000000000000'],
        ], 10);

        $this->assertNotNull($completed);
        $this->assertSame('merchant_refunded', $completed->status);
    }

    public function test_tenant_mismatch_cannot_transition_record(): void
    {
        $pending = NezhaRefundRecord::forceCreate([
            'order_id' => 503,
            'restaurant_id' => 10,
            'status' => 'pending_merchant_refund',
        ]);

        $result = NezhaRefundRecord::transitionPendingToMerchantRefunded($pending->id, [], 99);

        $this->assertNull($result);
        $this->assertSame('pending_merchant_refund', $pending->fresh()->status);
    }

    public function test_latest_customer_projection_ignores_non_customer_audit_rows(): void
    {
        NezhaRefundRecord::forceCreate(['order_id' => 504, 'status' => 'pending_merchant_refund']);
        NezhaRefundRecord::forceCreate(['order_id' => 504, 'status' => 'recorded']);
        NezhaRefundRecord::forceCreate(['order_id' => 505, 'status' => 'closed_no_payment']);

        $records = NezhaRefundRecord::latestCustomerVisibleByOrderIds([504, 505]);

        $this->assertSame('pending_merchant_refund', $records->get(504)?->status);
        $this->assertFalse($records->has(505));
    }

    public function test_notification_and_controller_contracts_keep_stage_owner_explicit(): void
    {
        $admin = file_get_contents(app_path('Http/Controllers/Admin/OrderController.php'));
        $vendor = file_get_contents(app_path('Http/Controllers/Vendor/OrderController.php'));
        $vendorApi = file_get_contents(app_path('Http/Controllers/Api/V1/Vendor/VendorController.php'));
        $vendorDashboard = file_get_contents(app_path('Http/Controllers/Vendor/DashboardController.php'));
        $operations = file_get_contents(app_path('Http/Controllers/Admin/NezhaRefundController.php'));
        $helpers = file_get_contents(app_path('CentralLogics/Helpers.php'));

        $this->assertStringContainsString("Cache::lock('nezha:admin-order-status:'", $admin);
        $this->assertStringContainsString('notify_customer_direct_pay_refund_pending', $admin);
        $this->assertStringContainsString('markDirectPayRefundPendingNotified', $admin);
        $this->assertStringContainsString('transitionPendingToMerchantRefunded', $vendor);
        $this->assertStringContainsString("notify_customer_direct_pay_refund_completed(\$order, \$record, 'merchant')", $vendor);
        $this->assertStringContainsString('attachRefundStages($completedItems)', $vendorApi);
        $this->assertStringContainsString('latestCustomerVisibleByOrderIds', $vendorApi);
        $this->assertStringContainsString("whereIn('status', \\App\\Models\\NezhaRefundRecord::STATUS_UNRESOLVED)", $vendorDashboard);
        $this->assertStringContainsString('transitionPendingToMerchantRefunded', $operations);
        $this->assertStringContainsString("notify_customer_direct_pay_refund_completed(\$order, \$rec, 'admin')", $operations);
        $this->assertStringContainsString('nezhaDirectPayRefundPendingNotified', $helpers);
    }
}
