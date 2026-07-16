<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NezhaPromotionalBannerConfigTest extends TestCase
{
    protected function setUp(): void
    {
        if (! defined('DOMAIN_POINTED_DIRECTORY')) {
            define('DOMAIN_POINTED_DIRECTORY', 'public');
        }

        parent::setUp();

        $this->ensureConfigurationSchema();
        $this->seedRequiredBusinessSettings();
        Storage::fake('public');
        Cache::flush();
    }

    public function test_missing_status_keeps_seed_banner_out_of_public_configuration(): void
    {
        Storage::disk('public')->put('banner/seed-banner.png', 'not-a-real-image');
        $this->seedBanner('Demo Banner', 'seed-banner.png');

        $response = $this->withHeader('X-localization', 'en')
            ->getJson('/api/v1/config');

        $response->assertOk();
        $response->assertJsonPath('banner_data', null);
        $this->assertStringNotContainsString('Demo Banner', $response->getContent());
        $this->assertStringNotContainsString('seed-banner.png', $response->getContent());
    }

    public function test_duplicate_enabled_status_rows_fail_closed(): void
    {
        Storage::disk('public')->put('banner/real-banner.png', 'not-a-real-image');
        $this->seedBanner('Approved summer offer', 'real-banner.png', '1');
        DB::table('data_settings')->insert([
            'type' => 'promotional_banner',
            'key' => 'promotional_banner_status',
            'value' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('X-localization', 'en')
            ->getJson('/api/v1/config');

        $response->assertOk();
        $response->assertJsonPath('banner_data', null);
        $this->assertStringNotContainsString('Approved summer offer', $response->getContent());
        $this->assertStringNotContainsString('real-banner.png', $response->getContent());
    }

    public function test_duplicate_title_rows_fail_closed(): void
    {
        Storage::disk('public')->put('banner/real-banner.png', 'not-a-real-image');
        $this->seedBanner('Approved summer offer', 'real-banner.png', '1');
        DB::table('data_settings')->insert([
            'type' => 'promotional_banner',
            'key' => 'promotional_banner_title',
            'value' => 'Approved summer offer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('X-localization', 'en')
            ->getJson('/api/v1/config');

        $response->assertOk();
        $response->assertJsonPath('banner_data', null);
        $this->assertStringNotContainsString('Approved summer offer', $response->getContent());
    }

    public function test_duplicate_image_rows_fail_closed(): void
    {
        Storage::disk('public')->put('banner/real-banner.png', 'not-a-real-image');
        $this->seedBanner('Approved summer offer', 'real-banner.png', '1');
        DB::table('data_settings')->insert([
            'type' => 'promotional_banner',
            'key' => 'promotional_banner_image',
            'value' => 'real-banner.png',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('X-localization', 'en')
            ->getJson('/api/v1/config');

        $response->assertOk();
        $response->assertJsonPath('banner_data', null);
        $this->assertStringNotContainsString('real-banner.png', $response->getContent());
    }

    public function test_missing_title_row_fails_closed(): void
    {
        Storage::disk('public')->put('banner/real-banner.png', 'not-a-real-image');
        $this->seedBanner('Approved summer offer', 'real-banner.png', '1');
        DB::table('data_settings')
            ->where('type', 'promotional_banner')
            ->where('key', 'promotional_banner_title')
            ->delete();

        $response = $this->withHeader('X-localization', 'en')
            ->getJson('/api/v1/config');

        $response->assertOk();
        $response->assertJsonPath('banner_data', null);
        $this->assertStringNotContainsString('real-banner.png', $response->getContent());
    }

    public function test_missing_image_row_fails_closed(): void
    {
        Storage::disk('public')->put('banner/real-banner.png', 'not-a-real-image');
        $this->seedBanner('Approved summer offer', 'real-banner.png', '1');
        DB::table('data_settings')
            ->where('type', 'promotional_banner')
            ->where('key', 'promotional_banner_image')
            ->delete();

        $response = $this->withHeader('X-localization', 'en')
            ->getJson('/api/v1/config');

        $response->assertOk();
        $response->assertJsonPath('banner_data', null);
        $this->assertStringNotContainsString('Approved summer offer', $response->getContent());
    }

    public function test_enabled_seed_title_is_still_fail_closed(): void
    {
        Storage::disk('public')->put('banner/seed-banner.png', 'not-a-real-image');
        $this->seedBanner('  dEmO bAnNeR  ', 'seed-banner.png', '1');

        $response = $this->withHeader('X-localization', 'en')
            ->getJson('/api/v1/config');

        $response->assertOk();
        $response->assertJsonPath('banner_data', null);
        $this->assertStringNotContainsString('dEmO bAnNeR', $response->getContent());
        $this->assertStringNotContainsString('seed-banner.png', $response->getContent());
    }

    public function test_enabled_banner_without_a_title_is_fail_closed(): void
    {
        Storage::disk('public')->put('banner/real-banner.png', 'not-a-real-image');
        $this->seedBanner(" \t ", 'real-banner.png', '1');

        $response = $this->withHeader('X-localization', 'en')
            ->getJson('/api/v1/config');

        $response->assertOk();
        $response->assertJsonPath('banner_data', null);
        $this->assertStringNotContainsString('real-banner.png', $response->getContent());
    }

    public function test_enabled_banner_with_a_missing_image_file_is_fail_closed(): void
    {
        $this->seedBanner('Approved summer offer', 'missing-banner.png', '1');

        $response = $this->withHeader('X-localization', 'en')
            ->getJson('/api/v1/config');

        $response->assertOk();
        $response->assertJsonPath('banner_data', null);
        $this->assertStringNotContainsString('Approved summer offer', $response->getContent());
        $this->assertStringNotContainsString('missing-banner.png', $response->getContent());
    }

    public function test_enabled_complete_banner_returns_only_the_legacy_public_dto(): void
    {
        Storage::disk('public')->put('banner/real-banner.png', 'not-a-real-image');
        $this->seedBanner('  Approved summer offer  ', 'real-banner.png', '1');

        $response = $this->withHeader('X-localization', 'en')
            ->getJson('/api/v1/config');

        $response->assertOk();
        $banner = $response->json('banner_data');

        $this->assertSame(
            [
                'promotional_banner_title',
                'promotional_banner_image',
                'promotional_banner_image_full_url',
            ],
            array_keys($banner)
        );
        $this->assertSame('Approved summer offer', $banner['promotional_banner_title']);
        $this->assertSame('real-banner.png', $banner['promotional_banner_image']);
        $this->assertStringContainsString('/storage/banner/real-banner.png', $banner['promotional_banner_image_full_url']);
        $this->assertArrayNotHasKey('promotional_banner_status', $banner);
    }

    public function test_only_the_exact_status_value_one_can_publish_the_banner(): void
    {
        Storage::disk('public')->put('banner/real-banner.png', 'not-a-real-image');
        $this->seedBanner('Approved summer offer', 'real-banner.png');

        foreach ([null, '', '0', '01', 'true', 'yes', '-1'] as $status) {
            DB::table('data_settings')
                ->where('type', 'promotional_banner')
                ->where('key', 'promotional_banner_status')
                ->delete();
            DB::table('data_settings')->insert([
                'type' => 'promotional_banner',
                'key' => 'promotional_banner_status',
                'value' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $response = $this->withHeader('X-localization', 'en')
                ->getJson('/api/v1/config');

            $response->assertOk();
            $response->assertJsonPath('banner_data', null);
            $this->assertStringNotContainsString('Approved summer offer', $response->getContent());
            $this->assertStringNotContainsString('real-banner.png', $response->getContent());
        }
    }

    public function test_locale_changes_do_not_reuse_a_warm_banner_value_from_another_language(): void
    {
        Storage::disk('public')->put('banner/real-banner.png', 'not-a-real-image');
        $this->seedBanner('Default approved offer', 'real-banner.png', '1');
        $titleId = DB::table('data_settings')
            ->where('type', 'promotional_banner')
            ->where('key', 'promotional_banner_title')
            ->value('id');
        DB::table('translations')->insert([
            [
                'translationable_type' => 'App\\Models\\DataSetting',
                'translationable_id' => $titleId,
                'locale' => 'en',
                'key' => 'promotional_banner_title',
                'value' => 'English approved offer',
            ],
            [
                'translationable_type' => 'App\\Models\\DataSetting',
                'translationable_id' => $titleId,
                'locale' => 'zh',
                'key' => 'promotional_banner_title',
                'value' => '中文已批准优惠',
            ],
        ]);
        Cache::forever('data_settings_promotional_banner', [
            'promotional_banner_title' => 'Poisoned old locale cache',
            'promotional_banner_image' => 'wrong.png',
        ]);

        $english = $this->withHeader('X-localization', 'en')
            ->getJson('/api/v1/config');
        $chinese = $this->withHeader('X-localization', 'zh')
            ->getJson('/api/v1/config');

        $english->assertOk()->assertJsonPath(
            'banner_data.promotional_banner_title',
            'English approved offer'
        );
        $chinese->assertOk()->assertJsonPath(
            'banner_data.promotional_banner_title',
            '中文已批准优惠'
        );
        $this->assertStringNotContainsString('Poisoned old locale cache', $english->getContent());
        $this->assertStringNotContainsString('Poisoned old locale cache', $chinese->getContent());

        DB::table('data_settings')
            ->where('type', 'promotional_banner')
            ->where('key', 'promotional_banner_status')
            ->update(['value' => '0', 'updated_at' => now()]);

        $this->withHeader('X-localization', 'zh')
            ->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('banner_data', null);
        $this->withHeader('X-localization', 'en')
            ->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('banner_data', null);
    }

    public function test_next_public_request_is_null_immediately_after_the_banner_is_closed(): void
    {
        Storage::disk('public')->put('banner/real-banner.png', 'not-a-real-image');
        $this->seedBanner('Approved summer offer', 'real-banner.png', '1');
        Cache::forever('data_settings_promotional_banner', [
            'promotional_banner_title' => 'Stale approved offer',
            'promotional_banner_image' => 'stale.png',
        ]);
        Cache::forever('data_settings_promotional_banner_storage', 'public');

        $warm = $this->withHeader('X-localization', 'en')->getJson('/api/v1/config');
        $warm->assertOk()->assertJsonPath(
            'banner_data.promotional_banner_title',
            'Approved summer offer'
        );

        DB::table('data_settings')
            ->where('type', 'promotional_banner')
            ->where('key', 'promotional_banner_status')
            ->update(['value' => '0', 'updated_at' => now()]);

        $closed = $this->withHeader('X-localization', 'zh')->getJson('/api/v1/config');
        $closed->assertOk()->assertJsonPath('banner_data', null);
        $this->assertStringNotContainsString('Approved summer offer', $closed->getContent());
        $this->assertStringNotContainsString('Stale approved offer', $closed->getContent());
    }

    private function seedBanner(string $title, string $image, ?string $status = null): void
    {
        foreach ([
            'promotional_banner_title' => $title,
            'promotional_banner_image' => $image,
        ] as $key => $value) {
            DB::table('data_settings')->updateOrInsert(
                ['type' => 'promotional_banner', 'key' => $key],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        if ($status !== null) {
            DB::table('data_settings')->updateOrInsert(
                ['type' => 'promotional_banner', 'key' => 'promotional_banner_status'],
                ['value' => $status, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $imageId = DB::table('data_settings')
            ->where('type', 'promotional_banner')
            ->where('key', 'promotional_banner_image')
            ->value('id');

        DB::table('storages')->updateOrInsert(
            ['data_type' => 'App\\Models\\DataSetting', 'data_id' => $imageId],
            ['value' => 'public', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function ensureConfigurationSchema(): void
    {
        if (! Schema::hasTable('data_settings')) {
            Schema::create('data_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key');
                $table->string('type');
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('currencies')) {
            Schema::create('currencies', function (Blueprint $table): void {
                $table->id();
                $table->string('currency_code')->unique();
                $table->string('currency_symbol');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('social_media')) {
            Schema::create('social_media', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->string('link')->nullable();
                $table->boolean('status')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->nullable();
                $table->string('settings_type')->nullable();
                $table->json('live_values')->nullable();
                $table->json('test_values')->nullable();
                $table->boolean('is_active')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('addon_settings')) {
            Schema::create('addon_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key_name')->nullable();
                $table->string('settings_type')->nullable();
                $table->json('live_values')->nullable();
                $table->json('test_values')->nullable();
                $table->string('mode')->nullable();
                $table->boolean('is_active')->default(false);
                $table->json('additional_data')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('system_tax_setups')) {
            Schema::create('system_tax_setups', function (Blueprint $table): void {
                $table->id();
                $table->string('tax_type')->nullable();
                $table->boolean('is_active')->default(false);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_included')->default(false);
                $table->timestamps();
            });
        }
    }

    private function seedRequiredBusinessSettings(): void
    {
        $settings = [
            'cash_on_delivery' => '{"status":0}',
            'digital_payment' => '{"status":0}',
            'business_model' => '{"commission":1,"subscription":0}',
            'business_name' => 'Nezha Test',
            'logo' => '',
            'address' => 'Test address',
            'phone' => '000',
            'email_address' => 'test@example.test',
            'country' => 'AM',
            'currency' => 'AMD',
            'currency_symbol_position' => 'right',
            'app_minimum_version_android' => '0',
            'app_url_android' => '',
            'app_minimum_version_ios' => '0',
            'app_url_ios' => '',
            'customer_verification' => '0',
            'schedule_order' => '0',
            'order_delivery_verification' => '0',
            'popular_food' => '0',
            'popular_restaurant' => '0',
            'new_restaurant' => '0',
            'most_reviewed_foods' => '0',
            'show_dm_earning' => '0',
            'canceled_by_deliveryman' => '0',
            'canceled_by_restaurant' => '0',
            'timeformat' => '24',
            'language' => '["en","zh"]',
            'toggle_veg_non_veg' => '0',
            'toggle_dm_registration' => '0',
            'toggle_restaurant_registration' => '0',
            'schedule_order_slot_duration' => '30',
            'theme' => '1',
            'footer_text' => '',
            'icon' => '',
            'partial_payment_method' => '[]',
            'subscription_free_trial_type' => 'day',
        ];

        foreach ($settings as $key => $value) {
            DB::table('business_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        DB::table('currencies')->updateOrInsert(
            ['currency_code' => 'AMD'],
            ['currency_symbol' => '֏', 'created_at' => now(), 'updated_at' => now()]
        );
    }
}
