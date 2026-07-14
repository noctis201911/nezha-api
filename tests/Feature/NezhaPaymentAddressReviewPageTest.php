<?php

namespace Tests\Feature;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaPaymentAddressReviewQueue;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\NezhaPaymentAddressChange;
use App\Models\Restaurant;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class NezhaPaymentAddressReviewPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('DOMAIN_POINTED_DIRECTORY')) {
            define('DOMAIN_POINTED_DIRECTORY', 'public');
        }
        View::share('errors', new ViewErrorBag());

        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Schema::create('storages', function (Blueprint $table): void {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
        Schema::create('nezha_payment_address_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->nullable();
            $table->unsignedBigInteger('restaurant_id')->nullable();
            $table->string('network', 8)->nullable();
            $table->string('state');
            $table->unsignedBigInteger('requested_by_admin_id')->nullable();
            $table->timestamp('expires_at')->nullable();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_type')->nullable();
            $table->string('order_status')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('schedule_at')->nullable();
            $table->boolean('scheduled')->default(false);
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
        });
        Schema::create('offline_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
        });
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->unsignedInteger('unread_message_count')->default(0);
        });
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sender_id')->nullable();
        });
        DB::table('business_settings')->insert([
            ['key' => 'logo', 'value' => 'nezha-logo-v1.png'],
            ['key' => 'icon', 'value' => 'nezha-logo-sq.png'],
            ['key' => 'business_name', 'value' => '哪吒外卖'],
        ]);
    }

    public function test_queue_provider_caps_and_orders_the_same_snapshot_used_by_the_badge(): void
    {
        $base = Carbon::parse('2026-07-14 12:00:00');
        for ($id = 1; $id <= 102; $id++) {
            DB::table('nezha_payment_address_changes')->insert([
                'id' => $id,
                'public_id' => 'change-'.$id,
                'state' => 'pending_distinct_admin',
                'expires_at' => $base->copy()->addMinutes(103 - $id),
            ]);
        }
        DB::table('nezha_payment_address_changes')->insert([
            'id' => 103,
            'public_id' => 'not-pending',
            'state' => 'rejected',
            'expires_at' => $base->copy()->subDay(),
        ]);

        $this->assertSame(100, NezhaPaymentAddressReviewQueue::count());
        $ids = NezhaPaymentAddressReviewQueue::query()->pluck('id');
        $this->assertCount(100, $ids);
        $this->assertSame(102, $ids->first());
        $this->assertSame(3, $ids->last());
    }

    public function test_full_formal_view_renders_reviewer_shell_and_v3_page_without_aliases(): void
    {
        $reviewer = $this->reviewer(77);
        $reviewer->role->modules = json_encode([
            'payment_address_review',
            'restaurant',
            'settings',
            'report',
            'deposit',
        ]);
        $this->actingAs($reviewer, 'admin');
        $change = $this->change(18, 41, Carbon::now()->addMinutes(90));
        DB::table('nezha_payment_address_changes')->insert([
            'id' => 18,
            'public_id' => $change->public_id,
            'restaurant_id' => 12,
            'network' => 'TRC20',
            'state' => 'pending_distinct_admin',
            'requested_by_admin_id' => 41,
            'expires_at' => $change->expires_at,
        ]);

        $html = view('admin-views.nezha-payment-address-review', [
            'changes' => collect([$change]),
            'currentAdminId' => 77,
            'reviewError' => null,
        ])->render();

        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('data-payment-address-review="reviewer-v3"', $html);
        $this->assertStringContainsString('亚美零食槟榔', $html);
        $this->assertStringContainsString('TSynDemoOldAddr1111111111111111111', $html);
        $this->assertStringContainsString('TSynDemoNewAddr2222222222222222222', $html);
        $this->assertStringContainsString('name="new_fingerprint" value="'.str_repeat('b', 64).'"', $html);
        $this->assertStringContainsString('btn btn-success', $html);
        $this->assertStringContainsString('btn btn-danger', $html);
        $this->assertStringContainsString('收款地址复核', $html);
        $this->assertStringNotContainsString('平台集运申报</span>', $html);
        $this->assertStringNotContainsString('佣金充值管理</span>', $html);
        $this->assertStringNotContainsString('商家管理</span>', $html);
        $this->assertStringNotContainsString('id="modalOpener"', $html);
        $this->assertTrue(Helpers::isExclusivePaymentAddressReviewer());
    }

    public function test_full_formal_view_renders_timeout_self_and_inline_error_states(): void
    {
        $reviewer = $this->reviewer(77);
        $this->actingAs($reviewer, 'admin');
        $change = $this->change(19, 77, Carbon::now()->subMinute());
        DB::table('nezha_payment_address_changes')->insert([
            'id' => 19,
            'public_id' => $change->public_id,
            'restaurant_id' => 12,
            'network' => 'TRC20',
            'state' => 'pending_distinct_admin',
            'requested_by_admin_id' => 77,
            'expires_at' => $change->expires_at,
        ]);

        $html = view('admin-views.nezha-payment-address-review', [
            'changes' => collect([$change]),
            'currentAdminId' => 77,
            'reviewError' => [
                'change_id' => $change->public_id,
                'code' => 'address_change_totp_invalid',
                'message' => 'TOTP 验证码无效，地址未变更。',
                'status' => 401,
            ],
        ])->render();

        $this->assertStringContainsString('nzpar-expired', $html);
        $this->assertStringContainsString('申请状态已变化，请刷新页面后重新核对。', $html);
        $this->assertStringContainsString('申请人不能自批，必须由另一名管理员复核。', $html);
        $this->assertStringContainsString('data-can-act="0"', $html);
        $this->assertStringContainsString('data-reopen-change="'.$change->public_id.'"', $html);
    }

    public function test_full_formal_view_renders_the_zero_queue_empty_state(): void
    {
        $this->actingAs($this->reviewer(77), 'admin');

        $html = view('admin-views.nezha-payment-address-review', [
            'changes' => collect(),
            'currentAdminId' => 77,
            'reviewError' => null,
        ])->render();

        $this->assertStringContainsString('当前没有待复核的地址变更', $html);
        $this->assertStringNotContainsString('badge-soft-info badge-pill ml-1', $html);
        $this->assertStringContainsString('>0</span><span class="nzpar-stat-k">待复核', $html);
    }

    public function test_formal_view_emits_all_isolated_browser_source_states_when_requested(): void
    {
        $changes = collect([
            $this->change(5, 42, Carbon::now()->subMinutes(20)),
            $this->change(1, 41, Carbon::now()->addMinutes(38)),
            $this->change(2, 41, Carbon::now()->addMinutes(132)),
            $this->change(3, 42, Carbon::now()->addMinutes(326)),
            $this->change(4, 77, Carbon::now()->addMinutes(485)),
        ]);
        foreach ($changes as $change) {
            DB::table('nezha_payment_address_changes')->insert([
                'id' => $change->id,
                'public_id' => $change->public_id,
                'restaurant_id' => $change->restaurant_id,
                'network' => $change->network,
                'state' => 'pending_distinct_admin',
                'requested_by_admin_id' => $change->requested_by_admin_id,
                'expires_at' => $change->expires_at,
            ]);
        }

        $reviewer = $this->reviewer(77);
        $this->actingAs($reviewer, 'admin');
        $queue = $this->renderReview($changes);
        $failure = $this->renderReview($changes, [
            'change_id' => (string) $changes[1]->public_id,
            'code' => 'address_change_totp_invalid',
            'message' => 'TOTP 验证码无效，地址未变更。',
            'status' => 401,
        ]);

        Cache::put('nezha_admin_order_counts', [
            'total' => 0, 'dine_in' => 0, 'delivered' => 0, 'canceled' => 0, 'failed' => 0,
            'refunded' => 0, 'refund_requested' => 0, 'processing' => 0, 'scheduled' => 0,
            'pending' => 0, 'picked_up' => 0, 'ongoing' => 0, 'searching_dm' => 0, 'accepted' => 0,
            'offline_payments' => 0, 'grp_pending' => 0, 'grp_ongoing' => 0, 'grp_done' => 0,
            'grp_aftersale' => 0, 'grp_closed' => 0,
        ], 60);
        $this->actingAs($this->superAdmin(1), 'admin');
        $admin = $this->renderReview($changes);

        DB::table('nezha_payment_address_changes')->delete();
        $this->actingAs($reviewer, 'admin');
        $empty = $this->renderReview(collect());

        $remainingChanges = $changes->reject(fn ($change) => $change->id === 1)->values();
        foreach ($remainingChanges as $change) {
            DB::table('nezha_payment_address_changes')->insert([
                'id' => $change->id,
                'public_id' => $change->public_id,
                'restaurant_id' => $change->restaurant_id,
                'network' => $change->network,
                'state' => 'pending_distinct_admin',
                'requested_by_admin_id' => $change->requested_by_admin_id,
                'expires_at' => $change->expires_at,
            ]);
        }
        Toastr::success('独立复核已通过，新地址已用于新付款；已签发的旧地址凭据只保留到各自到期。');
        $success = $this->renderReview($remainingChanges);

        $this->assertStringContainsString('data-payment-address-review="reviewer-v3"', $queue);
        $this->assertStringContainsString('TOTP 验证码无效，地址未变更。', $failure);
        $this->assertStringContainsString('当前没有待复核的地址变更', $empty);
        $this->assertStringContainsString('独立复核已通过，新地址已用于新付款', $success);
        $this->assertStringContainsString('id="modalOpener"', $admin);

        $directory = getenv('NEZHA_REVIEW_UI_PREVIEW_DIR');
        if (is_string($directory) && $directory !== '') {
            if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
                $this->fail('Unable to create preview directory');
            }
            foreach ([
                'reviewer-queue.html' => $queue,
                'reviewer-failure.html' => $failure,
                'reviewer-empty.html' => $empty,
                'reviewer-success.html' => $success,
                'admin-queue.html' => $admin,
            ] as $name => $html) {
                $previewHtml = str_replace('http://localhost', '', $html);
                $previewHtml = str_replace(
                    'href="/storage/business"',
                    'href="/assets/admin/img/logo.png"',
                    $previewHtml
                );
                file_put_contents($directory.'/'.$name, $previewHtml);
            }
        }
    }

    private function reviewer(int $id): Admin
    {
        $role = new AdminRole();
        $role->modules = json_encode(['payment_address_review']);

        $admin = new Admin();
        $admin->forceFill([
            'id' => $id,
            'role_id' => 2,
            'f_name' => '林',
            'l_name' => '复核员',
            'email' => 'reviewer@example.test',
            'two_factor_enabled' => true,
            'image' => null,
        ]);
        $admin->setRelation('role', $role);
        $admin->setRelation('storage', collect());

        return $admin;
    }

    private function superAdmin(int $id): Admin
    {
        $role = new AdminRole();
        $role->modules = json_encode([]);

        $admin = $this->reviewer($id);
        $admin->role_id = 1;
        $admin->f_name = 'Zhuang';
        $admin->l_name = 'Zengfeng';
        $admin->email = 'owner@example.test';
        $admin->setRelation('role', $role);

        return $admin;
    }

    private function renderReview($changes, ?array $reviewError = null): string
    {
        return view('admin-views.nezha-payment-address-review', [
            'changes' => $changes,
            'currentAdminId' => (int) auth('admin')->id(),
            'reviewError' => $reviewError,
        ])->render();
    }

    private function change(int $id, int $requesterId, Carbon $expiresAt): NezhaPaymentAddressChange
    {
        $restaurant = new Restaurant();
        $restaurant->forceFill(['id' => 12, 'name' => '亚美零食槟榔']);
        $restaurant->setRelation('translations', collect());
        $requester = new Admin();
        $requester->forceFill([
            'id' => $requesterId,
            'f_name' => '配置',
            'l_name' => '管理员',
            'email' => 'manager@example.test',
        ]);

        $change = new NezhaPaymentAddressChange();
        $change->forceFill([
            'id' => $id,
            'public_id' => '24071401-8000-4000-8000-'.str_pad((string) $id, 12, '0', STR_PAD_LEFT),
            'restaurant_id' => 12,
            'network' => 'TRC20',
            'state' => 'pending_distinct_admin',
            'old_address' => 'TSynDemoOldAddr1111111111111111111',
            'new_address' => 'TSynDemoNewAddr2222222222222222222',
            'old_fingerprint' => str_repeat('a', 64),
            'new_fingerprint' => str_repeat('b', 64),
            'requested_by_admin_id' => $requesterId,
            'reason' => '更换收款钱包（合成演示）',
            'merchant_confirmed_at' => Carbon::now()->subHour(),
            'expires_at' => $expiresAt,
        ]);
        $change->setRelation('restaurant', $restaurant);
        $change->setRelation('requestedByAdmin', $requester);

        return $change;
    }
}
