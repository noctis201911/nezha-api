<?php

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Schema\Blueprint;

/**
 * Minimal schema used by redline tests under the mandatory in-memory SQLite DB.
 *
 * This is intentionally not an application migration mirror: only tables read or
 * transactionally mutated by the redline suite belong here.
 */
final class IsolatedDatabaseFixtures
{
    public static function ensure(Application $app): void
    {
        $database = $app['db']->connection();
        $schema = $database->getSchemaBuilder();

        if (! $schema->hasTable('business_settings')) {
            $schema->create('business_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('cache')) {
            $schema->create('cache', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (! $schema->hasTable('offline_payments')) {
            $schema->create('offline_payments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->text('payment_info')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('users')) {
            $schema->create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('f_name')->nullable();
                $table->string('l_name')->nullable();
                $table->string('phone')->nullable();
                $table->string('ref_code')->nullable();
                $table->string('image')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('vendors')) {
            $schema->create('vendors', function (Blueprint $table): void {
                $table->id();
                $table->string('f_name');
                $table->string('l_name')->nullable();
                $table->string('phone')->unique();
                $table->string('email')->unique();
                $table->string('password');
                $table->rememberToken();
                $table->text('two_factor_secret')->nullable();
                $table->boolean('two_factor_enabled')->default(false);
                $table->text('two_factor_recovery_codes')->nullable();
                $table->timestamp('two_factor_required_at')->nullable();
                $table->timestamp('two_factor_enrolled_at')->nullable();
                $table->unsignedBigInteger('two_factor_last_counter')->nullable();
                $table->unsignedBigInteger('auth_generation')->default(0);
                $table->boolean('two_factor_grace_pending')->default(false);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('vendor_employees')) {
            $schema->create('vendor_employees', function (Blueprint $table): void {
                $table->id();
                $table->string('f_name')->nullable();
                $table->string('l_name')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->unique();
                $table->unsignedBigInteger('employee_role_id');
                $table->unsignedBigInteger('vendor_id');
                $table->unsignedBigInteger('restaurant_id');
                $table->string('password');
                $table->boolean('status')->default(true);
                $table->rememberToken();
                $table->text('two_factor_secret')->nullable();
                $table->boolean('two_factor_enabled')->default(false);
                $table->text('two_factor_recovery_codes')->nullable();
                $table->timestamp('two_factor_required_at')->nullable();
                $table->timestamp('two_factor_enrolled_at')->nullable();
                $table->unsignedBigInteger('two_factor_last_counter')->nullable();
                $table->unsignedBigInteger('auth_generation')->default(0);
                $table->boolean('two_factor_grace_pending')->default(false);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('merchant_two_factor_challenges')) {
            $schema->create('merchant_two_factor_challenges', function (Blueprint $table): void {
                $table->id();
                $table->char('token_hash', 64)->unique();
                $table->string('actor_type', 16);
                $table->unsignedBigInteger('actor_id');
                $table->string('purpose', 16);
                $table->text('pending_secret')->nullable();
                $table->unsignedBigInteger('auth_generation');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->char('ip_hash', 64)->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamp('consumed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('merchant_two_factor_events')) {
            $schema->create('merchant_two_factor_events', function (Blueprint $table): void {
                $table->id();
                $table->string('actor_type', 16);
                $table->unsignedBigInteger('actor_id');
                $table->string('event_type', 64);
                $table->unsignedBigInteger('auth_generation')->default(0);
                $table->string('initiator_type', 24)->nullable();
                $table->unsignedBigInteger('initiator_id')->nullable();
                $table->unsignedBigInteger('approver_one_id')->nullable();
                $table->unsignedBigInteger('approver_two_id')->nullable();
                $table->string('reason', 500)->nullable();
                $table->char('ip_hash', 64)->nullable();
                $table->char('user_agent_hash', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('local_life_merchants')) {
            $schema->create('local_life_merchants', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('category', 60)->index();
                $table->string('logo', 191)->nullable();
                $table->json('images')->nullable();
                $table->string('cover_image', 191)->nullable();
                $table->string('wechat_qr', 191)->nullable();
                $table->json('contacts')->nullable();
                $table->decimal('rating', 2, 1)->default(5.0);
                $table->decimal('google_rating', 2, 1)->nullable();
                $table->string('google_rating_url', 255)->nullable();
                $table->unsignedInteger('google_rating_count')->nullable();
                $table->string('area', 60)->nullable()->index();
                $table->string('address', 255)->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->json('open_days')->nullable();
                $table->string('open_time', 5)->nullable();
                $table->string('close_time', 5)->nullable();
                $table->string('hours_note', 120)->nullable();
                $table->text('intro')->nullable();
                $table->json('services')->nullable();
                $table->json('video_links')->nullable();
                $table->boolean('has_offer')->default(false);
                $table->string('offer_text', 120)->nullable();
                $table->boolean('is_sensitive')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedInteger('views')->default(0);
                $table->boolean('status')->default(true);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('local_life_merchant_accounts')) {
            $schema->create('local_life_merchant_accounts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('merchant_id')->unique();
                $table->string('email', 191)->unique();
                $table->string('password')->nullable();
                $table->string('contact_name', 120)->nullable();
                $table->boolean('status')->default(true);
                $table->timestamp('last_login_at')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('local_life_merchant_changes')) {
            $schema->create('local_life_merchant_changes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('merchant_id')->index();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->json('payload');
                $table->json('base_snapshot')->nullable();
                $table->tinyInteger('status')->default(0)->index();
                $table->string('review_note', 255)->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('submit_ip', 45)->nullable();
                $table->string('submit_ua', 255)->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('local_life_merchant_password_resets')) {
            $schema->create('local_life_merchant_password_resets', function (Blueprint $table): void {
                $table->string('email', 191)->index();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! $schema->hasTable('admin_roles')) {
            $schema->create('admin_roles', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 30);
                $table->string('modules')->nullable();
                $table->boolean('status')->default(true);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('admins')) {
            $schema->create('admins', function (Blueprint $table): void {
                $table->id();
                $table->string('f_name')->nullable();
                $table->string('l_name')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->unique();
                $table->string('password');
                $table->unsignedBigInteger('role_id')->default(1);
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('restaurant_configs')) {
            $schema->create('restaurant_configs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('restaurant_id')->index();
                $table->boolean('halal_tag_status')->default(false);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('restaurant_subscriptions')) {
            $schema->create('restaurant_subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('restaurant_id')->index();
                $table->boolean('status')->default(true);
                $table->string('max_order')->default('unlimited');
                $table->date('expiry_date')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('restaurants')) {
            $schema->create('restaurants', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->string('slug')->nullable();
                $table->string('logo')->nullable();
                $table->string('cover_photo')->nullable();
                $table->string('meta_image')->nullable();
                $table->string('rmb_qr_image')->nullable();
                $table->unsignedBigInteger('vendor_id')->default(1);
                $table->unsignedBigInteger('zone_id')->nullable();
                $table->boolean('status')->default(true);
                $table->string('restaurant_model')->default('commission');
                $table->decimal('minimum_order', 24, 2)->default(0);
                $table->decimal('delivery_charge', 24, 2)->default(0);
                $table->boolean('schedule_order')->default(false);
                $table->boolean('free_delivery')->default(false);
                $table->boolean('self_delivery_system')->default(false);
                $table->string('delivery_time')->default('10-20');
                $table->time('opening_time')->nullable();
                $table->time('closeing_time')->nullable();
                $table->text('gst')->nullable();
                $table->text('free_delivery_distance')->nullable();
                $table->boolean('nezha_order_suspended')->default(false);
                $table->boolean('nezha_auto_offline')->default(false);
                $table->string('nezha_auto_offline_reason')->nullable();
                $table->timestamp('nezha_auto_offline_at')->nullable();
                $table->boolean('nezha_commission_enabled')->default(false);
                $table->string('nezha_notify_email')->nullable();
                $table->string('telegram_chat_id')->nullable();
                $table->boolean('timeout_notify_telegram')->default(true);
                $table->boolean('new_order_repeat_enabled')->default(false);
                $table->unsignedSmallInteger('new_order_repeat_interval_sec')->default(20);
                $table->unsignedSmallInteger('new_order_repeat_max_minutes')->default(5);
                $table->boolean('new_order_repeat_scope_accept')->default(true);
                $table->boolean('new_order_repeat_scope_payment')->default(false);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('restaurant_wallets')) {
            $schema->create('restaurant_wallets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('vendor_id')->unique();
                $table->decimal('total_earning', 24, 2)->default(0);
                $table->decimal('total_withdrawn', 24, 2)->default(0);
                $table->decimal('pending_withdraw', 24, 2)->default(0);
                $table->decimal('collected_cash', 24, 2)->default(0);
                $table->decimal('deposit_balance', 24, 2)->default(0);
                $table->decimal('ad_balance', 24, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('orders')) {
            $schema->create('orders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('restaurant_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('subscription_id')->nullable()->index();
                $table->decimal('restaurant_discount_amount', 24, 2)->default(0);
                $table->string('order_status')->default('pending');
                $table->string('payment_method')->nullable();
                $table->string('order_type')->default('delivery');
                $table->boolean('checked')->default(false);
                $table->boolean('scheduled')->default(false);
                $table->timestamp('schedule_at')->nullable();
                $table->timestamp('confirmed')->nullable();
                $table->timestamp('accepted')->nullable();
                $table->timestamp('processing')->nullable();
                $table->timestamp('handover')->nullable();
                $table->timestamp('picked_up')->nullable();
                $table->timestamp('delivered')->nullable();
                $table->timestamp('canceled')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('subscriptions')) {
            $schema->create('subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->string('status')->nullable();
                $table->date('start_at')->nullable();
                $table->date('end_at')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('subscription_schedules')) {
            $schema->create('subscription_schedules', function (Blueprint $table): void {
                $table->unsignedBigInteger('subscription_id')->index();
                $table->string('type')->nullable();
                $table->integer('day')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('subscription_pauses')) {
            $schema->create('subscription_pauses', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('subscription_id')->index();
                $table->date('from')->nullable();
                $table->date('to')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('subscription_logs')) {
            $schema->create('subscription_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('subscription_id')->nullable();
                $table->unsignedBigInteger('delivery_man_id')->nullable();
                $table->string('order_status')->nullable();
                $table->timestamp('schedule_at')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('advertisements')) {
            $schema->create('advertisements', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('restaurant_id')->index();
                $table->string('add_type')->default('restaurant_promotion');
                $table->string('title')->nullable();
                $table->text('description')->nullable();
                $table->date('start_date');
                $table->date('end_date');
                $table->unsignedBigInteger('created_by_id');
                $table->string('created_by_type');
                $table->string('status')->default('pending');
                $table->boolean('is_paid')->default(false);
                $table->decimal('price', 24, 2)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('deposit_transaction_id')->nullable();
                $table->decimal('bid_amount', 10, 2)->nullable();
                $table->string('pricing_model', 8)->default('cpt');
                $table->decimal('daily_budget', 10, 2)->nullable();
                $table->decimal('spent_today', 10, 2)->default(0);
                $table->date('budget_reset_date')->nullable();
                $table->string('slot', 16)->nullable();
                $table->decimal('quality_score', 4, 3)->default(1);
                $table->decimal('mat_boost', 5, 3)->default(0);
                $table->smallInteger('mat_rank')->nullable();
                $table->timestamp('mat_at')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('restaurant_deposit_transactions')) {
            $schema->create('restaurant_deposit_transactions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('vendor_id')->index();
                $table->unsignedBigInteger('restaurant_id')->nullable()->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->string('type', 30);
                $table->decimal('amount', 24, 2)->default(0);
                $table->decimal('commission', 24, 2)->default(0);
                $table->decimal('balance_after', 24, 2)->default(0);
                $table->string('note')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('ad_events')) {
            $schema->create('ad_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('advertisement_id')->index();
                $table->unsignedBigInteger('restaurant_id')->index();
                $table->unsignedBigInteger('vendor_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('event_type', 16);
                $table->string('slot', 16)->nullable();
                $table->decimal('charged_amount', 10, 2)->default(0);
                $table->string('charge_reason', 32)->nullable();
                $table->unsignedBigInteger('deposit_transaction_id')->nullable();
                $table->string('dedup_key', 64)->unique();
                $table->string('ip_hash', 16)->nullable();
                $table->string('ua_hash', 16)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! $schema->hasTable('nezha_order_timeout_events')) {
            $schema->create('nezha_order_timeout_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->string('action', 64);
                $table->timestamp('fired_at')->nullable();
                $table->text('detail')->nullable();
                $table->timestamps();
                $table->unique(['order_id', 'action'], 'nezha_oto_order_action_uq');
            });
        }

        if (! $schema->hasTable('nezha_notification_log')) {
            $schema->create('nezha_notification_log', function (Blueprint $table): void {
                $table->id();
                $table->string('channel', 16);
                $table->string('target', 16);
                $table->string('event_type', 40);
                $table->string('outcome', 16);
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->unsignedBigInteger('restaurant_id')->nullable()->index();
                $table->string('detail', 255)->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('nezha_auto_offline_events')) {
            $schema->create('nezha_auto_offline_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('restaurant_id')->index();
                $table->string('action', 32);
                $table->string('detail')->nullable();
                $table->timestamp('fired_at')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('categories')) {
            $schema->create('categories', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->unsignedBigInteger('parent_id')->default(0);
                $table->boolean('status')->default(true);
                $table->string('image')->nullable();
                $table->string('meta_image')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('food')) {
            $schema->create('food', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->string('image')->nullable();
                $table->unsignedBigInteger('category_id')->nullable();
                $table->text('category_ids')->nullable();
                $table->text('variations')->nullable();
                $table->text('add_ons')->nullable();
                $table->decimal('price', 24, 2)->default(0);
                $table->decimal('discount', 24, 2)->default(0);
                $table->string('discount_type')->default('percent');
                $table->boolean('status')->default(true);
                $table->unsignedBigInteger('restaurant_id')->index();
                $table->string('stock_type')->default('unlimited');
                $table->integer('stock')->default(0);
                $table->integer('daily_stock')->default(0);
                $table->integer('recommended')->default(0);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('add_ons')) {
            $schema->create('add_ons', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->decimal('price', 24, 2)->default(0);
                $table->unsignedBigInteger('restaurant_id')->index();
                $table->boolean('status')->default(true);
                $table->string('stock_type')->default('unlimited');
                $table->integer('addon_stock')->default(0);
                $table->integer('sell_count')->default(0);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('variations')) {
            $schema->create('variations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('food_id')->index();
                $table->string('name')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('variation_options')) {
            $schema->create('variation_options', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('food_id')->index();
                $table->unsignedBigInteger('variation_id')->index();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('carts')) {
            $schema->create('carts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('item_id');
                $table->unsignedBigInteger('restaurant_id')->nullable();
                $table->boolean('is_guest')->default(false);
                $table->text('add_on_ids')->nullable();
                $table->text('add_on_qtys')->nullable();
                $table->string('item_type');
                $table->decimal('price', 24, 3)->default(0);
                $table->integer('quantity')->default(1);
                $table->text('variations')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('discounts')) {
            $schema->create('discounts', function (Blueprint $table): void {
                $table->id();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->time('start_time')->nullable();
                $table->time('end_time')->nullable();
                $table->decimal('min_purchase', 24, 2)->default(0);
                $table->decimal('max_discount', 24, 2)->default(0);
                $table->decimal('discount', 24, 2)->default(0);
                $table->string('discount_type')->default('amount');
                $table->unsignedBigInteger('restaurant_id')->index();
                $table->boolean('status')->default(true);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('discount_tiers')) {
            $schema->create('discount_tiers', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('discount_id')->index();
                $table->decimal('min_purchase', 24, 2)->default(0);
                $table->string('discount_type')->default('amount');
                $table->decimal('discount', 24, 2)->default(0);
                $table->decimal('max_discount', 24, 2)->default(0);
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('coupons')) {
            $schema->create('coupons', function (Blueprint $table): void {
                $table->id();
                $table->string('title')->nullable();
                $table->string('code')->nullable()->index();
                $table->date('start_date')->nullable();
                $table->date('expire_date')->nullable();
                $table->decimal('min_purchase', 24, 2)->default(0);
                $table->decimal('max_discount', 24, 2)->default(0);
                $table->decimal('discount', 24, 2)->default(0);
                $table->string('discount_type')->default('amount');
                $table->string('coupon_type')->default('default');
                $table->integer('limit')->nullable();
                $table->boolean('status')->default(true);
                $table->text('data')->nullable();
                $table->integer('total_uses')->default(0);
                $table->string('created_by')->default('admin');
                $table->text('customer_id')->nullable();
                $table->string('slug')->nullable();
                $table->unsignedBigInteger('restaurant_id')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('storages')) {
            $schema->create('storages', function (Blueprint $table): void {
                $table->id();
                $table->string('data_type');
                $table->unsignedBigInteger('data_id');
                $table->string('key')->nullable();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('translations')) {
            $schema->create('translations', function (Blueprint $table): void {
                $table->id();
                $table->string('translationable_type');
                $table->unsignedBigInteger('translationable_id')->index();
                $table->string('locale')->index();
                $table->string('key')->nullable();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('reviews')) {
            $schema->create('reviews', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('food_id')->index();
                $table->unsignedBigInteger('restaurant_id')->nullable()->index();
                $table->unsignedTinyInteger('rating')->default(0);
                $table->text('comment')->nullable();
                $table->boolean('status')->default(true);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('nutritions')) {
            $schema->create('nutritions', function (Blueprint $table): void {
                $table->id();
                $table->string('nutrition')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('food_nutrition')) {
            $schema->create('food_nutrition', function (Blueprint $table): void {
                $table->unsignedBigInteger('food_id')->index();
                $table->unsignedBigInteger('nutrition_id')->index();
            });
        }

        if (! $schema->hasTable('allergies')) {
            $schema->create('allergies', function (Blueprint $table): void {
                $table->id();
                $table->string('allergy')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('allergy_food')) {
            $schema->create('allergy_food', function (Blueprint $table): void {
                $table->unsignedBigInteger('food_id')->index();
                $table->unsignedBigInteger('allergy_id')->index();
            });
        }

        if (! $schema->hasTable('cuisines')) {
            $schema->create('cuisines', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->string('image')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('cuisine_restaurant')) {
            $schema->create('cuisine_restaurant', function (Blueprint $table): void {
                $table->unsignedBigInteger('cuisine_id')->index();
                $table->unsignedBigInteger('restaurant_id')->index();
            });
        }

        if (! $schema->hasTable('taxables')) {
            $schema->create('taxables', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tax_id')->nullable();
                $table->string('taxable_type');
                $table->unsignedBigInteger('taxable_id')->index();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('taxes')) {
            $schema->create('taxes', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->decimal('tax_rate', 24, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('nezha_sanction_addresses')) {
            $schema->create('nezha_sanction_addresses', function (Blueprint $table): void {
                $table->id();
                $table->string('addr_kind', 16);
                $table->string('address')->index();
                $table->string('source')->nullable();
                $table->string('sdn_uid')->nullable();
                $table->string('currency_type')->nullable();
                $table->timestamps();
            });
        }

        $database->table('business_settings')->updateOrInsert(
            ['key' => 'check_daily_subscription_validity_check'],
            ['value' => date('Y-m-d'), 'created_at' => now(), 'updated_at' => now()]
        );
        $database->table('users')->updateOrInsert(
            ['id' => 1],
            ['f_name' => 'Fixture', 'l_name' => 'Customer', 'created_at' => now(), 'updated_at' => now()]
        );
        $database->table('vendors')->updateOrInsert(
            ['id' => 1],
            [
                'f_name' => 'Fixture',
                'l_name' => 'Vendor',
                'phone' => 'fixture-vendor-1',
                'email' => 'fixture-vendor@example.test',
                'password' => 'not-used',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $database->table('admins')->updateOrInsert(
            ['id' => 1],
            [
                'f_name' => 'Fixture',
                'l_name' => 'Admin',
                'email' => 'fixture-admin@example.test',
                'password' => 'not-used',
                'role_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $database->table('admin_roles')->updateOrInsert(
            ['id' => 1],
            [
                'name' => 'Super Admin',
                'modules' => null,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $database->table('local_life_merchants')->updateOrInsert(
            ['id' => 1],
            [
                'name' => 'Fixture Local Merchant',
                'category' => '本地服务',
                'intro' => 'Fixture introduction',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $database->table('restaurants')->updateOrInsert(
            ['id' => 6],
            [
                'name' => 'Fixture Restaurant',
                'slug' => 'fixture-restaurant',
                'vendor_id' => 1,
                'status' => 1,
                'restaurant_model' => 'commission',
                'delivery_time' => '10-20',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $database->table('categories')->updateOrInsert(
            ['id' => 1],
            ['name' => 'Fixture Category', 'parent_id' => 0, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
        $database->table('food')->updateOrInsert(
            ['id' => 1],
            [
                'name' => 'Fixture Food',
                'category_id' => 1,
                'category_ids' => '[{"id":"1","position":1}]',
                'variations' => '[]',
                'add_ons' => '[]',
                'price' => 1000,
                'discount' => 0,
                'discount_type' => 'percent',
                'status' => 1,
                'restaurant_id' => 6,
                'stock_type' => 'unlimited',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
