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
                $table->string('image')->nullable();
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
                $table->boolean('status')->default(true);
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
                $table->string('key');
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
