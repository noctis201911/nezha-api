<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaPromotionalBanner;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Models\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NezhaPromotionalBannerAdminTest extends TestCase
{
    protected function setUp(): void
    {
        if (! defined('DOMAIN_POINTED_DIRECTORY')) {
            define('DOMAIN_POINTED_DIRECTORY', 'public');
        }

        parent::setUp();

        if (! Schema::hasTable('data_settings')) {
            Schema::create('data_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key');
                $table->string('type');
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('react_promotional_banners')) {
            Schema::create('react_promotional_banners', function (Blueprint $table): void {
                $table->id();
                $table->string('title', 100)->nullable();
                $table->text('description')->nullable();
                $table->string('image', 100)->nullable();
                $table->boolean('status')->default(true);
                $table->timestamps();
            });
        }

        Storage::fake('public');
        $this->withoutMiddleware([
            AdminMiddleware::class,
            ActivationCheckMiddleware::class,
        ]);
        $this->actingAs(Admin::query()->findOrFail(1), 'admin');
    }

    public function test_admin_cannot_enable_the_seed_banner(): void
    {
        Storage::disk('public')->put('banner/seed-banner.png', 'not-a-real-image');
        $this->seedBanner('Demo Banner', 'seed-banner.png', '0');

        $response = $this->from(route('admin.banner.promotional_banner'))
            ->post(route('admin.banner.promotional_banner_update'), [
                'promotional_banner_status' => '1',
                'promotional_banner_title' => ['Demo Banner'],
                'lang' => ['default'],
            ]);

        $response->assertRedirect(route('admin.banner.promotional_banner'));
        $response->assertSessionHasErrors('promotional_banner_title.0');
        $this->assertSame('0', $this->statusValue());
    }

    public function test_integer_one_cannot_bypass_the_enable_checks(): void
    {
        Storage::disk('public')->put('banner/seed-banner.png', 'not-a-real-image');
        $this->seedBanner('Demo Banner', 'seed-banner.png', '0');

        $response = $this->from(route('admin.banner.promotional_banner'))
            ->post(route('admin.banner.promotional_banner_update'), [
                'promotional_banner_status' => 1,
                'promotional_banner_title' => ['Demo Banner'],
                'lang' => ['default'],
            ]);

        $response->assertRedirect(route('admin.banner.promotional_banner'));
        $response->assertSessionHasErrors('promotional_banner_title.0');
        $this->assertSame('0', $this->statusValue());
    }

    public function test_status_zero_closes_immediately_without_content_and_preserves_existing_rows(): void
    {
        $this->seedBanner('Demo Banner', 'missing-banner.png', '1');
        $before = DB::table('data_settings')
            ->where('type', 'promotional_banner')
            ->whereIn('key', ['promotional_banner_title', 'promotional_banner_image'])
            ->orderBy('id')
            ->get(['key', 'value'])
            ->all();

        $response = $this->post(route('admin.banner.promotional_banner_update'), [
            'promotional_banner_status' => 0,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame('0', $this->statusValue());
        $this->assertEquals(
            $before,
            DB::table('data_settings')
                ->where('type', 'promotional_banner')
                ->whereIn('key', ['promotional_banner_title', 'promotional_banner_image'])
                ->orderBy('id')
                ->get(['key', 'value'])
                ->all()
        );
        $this->assertNull(NezhaPromotionalBanner::configuration());
    }

    public function test_admin_cannot_enable_a_banner_whose_image_file_is_missing(): void
    {
        $this->seedBanner('Approved summer offer', 'missing-banner.png', '0');

        $response = $this->from(route('admin.banner.promotional_banner'))
            ->post(route('admin.banner.promotional_banner_update'), [
                'promotional_banner_status' => '1',
                'promotional_banner_title' => ['Approved summer offer'],
                'lang' => ['default'],
            ]);

        $response->assertRedirect(route('admin.banner.promotional_banner'));
        $response->assertSessionHasErrors('promotional_banner_image');
        $this->assertSame('0', $this->statusValue());
    }

    public function test_admin_can_enable_a_complete_non_seed_banner(): void
    {
        Storage::disk('public')->put('banner/real-banner.png', 'not-a-real-image');
        $this->seedBanner('Approved summer offer', 'real-banner.png', '0');

        $response = $this->post(route('admin.banner.promotional_banner_update'), [
            'promotional_banner_status' => '1',
            'promotional_banner_title' => ['Approved summer offer'],
            'lang' => ['default'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame('1', $this->statusValue());
    }

    public function test_admin_close_is_immediate_and_only_forgets_the_exact_legacy_cache_keys(): void
    {
        Storage::disk('public')->put('banner/real-banner.png', 'not-a-real-image');
        $this->seedBanner('Approved summer offer', 'real-banner.png', '1');
        DB::table('react_promotional_banners')->insert([
            'title' => 'Independent React banner',
            'description' => 'must stay unchanged',
            'image' => 'react.png',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reactBefore = DB::table('react_promotional_banners')->first();
        Cache::forever('data_settings_promotional_banner', ['poisoned' => true]);
        Cache::forever('data_settings_promotional_banner_storage', 's3');
        Cache::forever('unrelated_cache_key', 'must-survive');

        $response = $this->post(route('admin.banner.promotional_banner_update'), [
            'promotional_banner_status' => '0',
            'promotional_banner_title' => ['Approved summer offer'],
            'lang' => ['default'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame('0', $this->statusValue());
        $this->assertNull(NezhaPromotionalBanner::configuration());
        $this->assertFalse(Cache::has('data_settings_promotional_banner'));
        $this->assertFalse(Cache::has('data_settings_promotional_banner_storage'));
        $this->assertSame('must-survive', Cache::get('unrelated_cache_key'));
        $this->assertEquals($reactBefore, DB::table('react_promotional_banners')->first());
    }

    public function test_admin_close_updates_every_duplicate_status_row_without_deduplicating(): void
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

        $response = $this->post(route('admin.banner.promotional_banner_update'), [
            'promotional_banner_status' => '0',
            'promotional_banner_title' => ['Approved summer offer'],
            'lang' => ['default'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(
            ['0', '0'],
            DB::table('data_settings')
                ->where('type', 'promotional_banner')
                ->where('key', 'promotional_banner_status')
                ->orderBy('id')
                ->pluck('value')
                ->all()
        );
        $this->assertNull(NezhaPromotionalBanner::configuration());
    }

    public function test_repeated_close_creates_at_most_one_missing_status_row(): void
    {
        $this->seedBanner('Demo Banner', 'missing-banner.png', '1');
        DB::table('data_settings')
            ->where('type', 'promotional_banner')
            ->where('key', 'promotional_banner_status')
            ->delete();

        $first = $this->post(route('admin.banner.promotional_banner_update'), [
            'promotional_banner_status' => '0',
        ]);
        $second = $this->post(route('admin.banner.promotional_banner_update'), [
            'promotional_banner_status' => '0',
        ]);

        $first->assertRedirect()->assertSessionHasNoErrors();
        $second->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(
            ['0'],
            DB::table('data_settings')
                ->where('type', 'promotional_banner')
                ->where('key', 'promotional_banner_status')
                ->pluck('value')
                ->all()
        );
    }

    public function test_admin_enable_updates_every_duplicate_title_row_but_public_api_stays_closed(): void
    {
        Storage::disk('public')->put('banner/real-banner.png', 'not-a-real-image');
        $this->seedBanner('First old title', 'real-banner.png', '0');
        DB::table('data_settings')->insert([
            'type' => 'promotional_banner',
            'key' => 'promotional_banner_title',
            'value' => 'Second old title',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->post(route('admin.banner.promotional_banner_update'), [
            'promotional_banner_status' => '1',
            'promotional_banner_title' => ['Updated approved offer'],
            'lang' => ['default'],
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(
            ['Updated approved offer', 'Updated approved offer'],
            DB::table('data_settings')
                ->where('type', 'promotional_banner')
                ->where('key', 'promotional_banner_title')
                ->orderBy('id')
                ->pluck('value')
                ->all()
        );
        $this->assertSame('1', $this->statusValue());
        $this->assertNull(NezhaPromotionalBanner::configuration());
    }

    public function test_database_cache_save_only_forgets_the_two_exact_legacy_banner_keys(): void
    {
        $cache = app('cache');
        $originalDriver = $cache->getDefaultDriver();
        $originalPrefix = config('cache.prefix');

        Storage::disk('public')->put('banner/real-banner.png', 'not-a-real-image');
        $this->seedBanner('Approved summer offer', 'real-banner.png', '1');

        try {
            config([
                'cache.prefix' => strtolower(str_replace('=', '', (string) env('APP_NAME').'_cache')),
            ]);
            $cache->setDefaultDriver('database');
            $cache->forgetDriver('database');
            Cache::forever('data_settings_promotional_banner', ['poisoned' => true]);
            Cache::forever('data_settings_promotional_banner_storage', 'public');
            Cache::forever('data_settings_invoice_settings', 'must-survive');
            Cache::forever('react_promotional_banner_status', 'must-also-survive');

            $response = $this->post(route('admin.banner.promotional_banner_update'), [
                'promotional_banner_status' => '1',
                'promotional_banner_title' => ['Updated approved offer'],
                'lang' => ['default'],
            ]);

            $response->assertRedirect();
            $response->assertSessionHasNoErrors();
            $this->assertFalse(Cache::has('data_settings_promotional_banner'));
            $this->assertFalse(Cache::has('data_settings_promotional_banner_storage'));
            $this->assertSame('must-survive', Cache::get('data_settings_invoice_settings'));
            $this->assertSame('must-also-survive', Cache::get('react_promotional_banner_status'));
            $this->assertSame(
                'Updated approved offer',
                DB::table('data_settings')
                    ->where('type', 'promotional_banner')
                    ->where('key', 'promotional_banner_title')
                    ->value('value')
            );
        } finally {
            $cache->forgetDriver('database');
            $cache->setDefaultDriver($originalDriver);
            config(['cache.prefix' => $originalPrefix]);
        }
    }

    public function test_admin_page_shows_the_persisted_publish_status(): void
    {
        Storage::disk('public')->put('banner/real-banner.png', 'not-a-real-image');
        $this->seedBanner('Approved summer offer', 'real-banner.png', '1');

        $view = app(BannerController::class)->promotional_banner();
        $data = $view->getData();

        $this->assertArrayHasKey('banner_status', $data);
        $this->assertSame('1', $data['banner_status']->getRawOriginal('value'));

        $template = file_get_contents(resource_path('views/admin-views/banner/promotional_banner.blade.php'));
        $this->assertStringContainsString('name="promotional_banner_status"', $template);
        $this->assertStringContainsString('$banner_status', $template);
    }

    public function test_admin_without_banner_permission_gets_403_and_cannot_change_data_or_cache(): void
    {
        Storage::disk('public')->put('banner/real-banner.png', 'not-a-real-image');
        $this->seedBanner('Approved summer offer', 'real-banner.png', '0');
        Cache::forever('data_settings_promotional_banner', ['must' => 'stay']);
        Cache::forever('data_settings_promotional_banner_storage', 'public');
        DB::table('admin_roles')->insert([
            'id' => 2,
            'name' => 'No banner access',
            'modules' => json_encode(['settings']),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('admins')->insert([
            'id' => 2,
            'f_name' => 'Restricted',
            'l_name' => 'Admin',
            'email' => 'restricted-admin@example.test',
            'password' => 'not-used',
            'role_id' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs(Admin::query()->findOrFail(2), 'admin');
        app('view')->getFinder()->prependLocation(base_path('tests/Fixtures/views'));

        $response = $this->post(route('admin.banner.promotional_banner_update'), [
            'promotional_banner_status' => '1',
            'promotional_banner_title' => ['Unauthorized title'],
            'lang' => ['default'],
        ]);

        $response->assertForbidden();
        $this->assertSame('0', $this->statusValue());
        $this->assertSame(
            'Approved summer offer',
            DB::table('data_settings')
                ->where('type', 'promotional_banner')
                ->where('key', 'promotional_banner_title')
                ->value('value')
        );
        $this->assertSame(['must' => 'stay'], Cache::get('data_settings_promotional_banner'));
        $this->assertSame('public', Cache::get('data_settings_promotional_banner_storage'));
    }

    private function seedBanner(string $title, string $image, string $status): void
    {
        foreach ([
            'promotional_banner_title' => $title,
            'promotional_banner_image' => $image,
            'promotional_banner_status' => $status,
        ] as $key => $value) {
            DB::table('data_settings')->updateOrInsert(
                ['type' => 'promotional_banner', 'key' => $key],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
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

    private function statusValue(): ?string
    {
        return DB::table('data_settings')
            ->where('type', 'promotional_banner')
            ->where('key', 'promotional_banner_status')
            ->value('value');
    }
}
