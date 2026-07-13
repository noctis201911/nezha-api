<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\BusinessSettingsController;
use App\Http\Controllers\Api\V1\ConfigController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

class GoogleMapsProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('business_settings');
        parent::tearDown();
    }

    public function test_blank_keys_return_service_configuration_error_without_calling_google(): void
    {
        $controller = $this->controllerWithKeys(" \t ", null);

        $responses = [
            $controller->place_api_autocomplete($this->request(['search_text' => 'Yerevan'])),
            $controller->place_api_details($this->request(['placeid' => 'test-place'])),
            $controller->geocode_api($this->request(['lat' => 40.1772, 'lng' => 44.5035])),
            $controller->distance_api($this->request([
                'origin_lat' => 40.1772,
                'origin_lng' => 44.5035,
                'destination_lat' => 40.1872,
                'destination_lng' => 44.5135,
            ])),
        ];

        foreach ($responses as $response) {
            $this->assertSame(503, $response->getStatusCode());
            $this->assertSame('google_maps_not_configured', $response->getData(true)['errors'][0]['code']);
        }

        Http::assertNothingSent();
    }

    public function test_autocomplete_success_uses_staging_referer_and_returns_suggestions(): void
    {
        $key = 'fake-places-key';
        Http::fake([
            'https://places.googleapis.com/v1/places:autocomplete' => Http::response([
                'suggestions' => [[
                    'placePrediction' => [
                        'placeId' => 'test-place',
                        'text' => ['text' => 'Yerevan, Armenia'],
                    ],
                ]],
            ]),
        ]);

        $response = $this->controllerWithKeys($key, 'fake-server-key')->place_api_autocomplete(
            $this->request(['search_text' => 'Yerevan'], 'https://staging.nezha.am')
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $response->getData(true)['suggestions']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://places.googleapis.com/v1/places:autocomplete'
            && $request->hasHeader('X-Goog-Api-Key', $key)
            && $request->hasHeader('Referer', 'https://staging.nezha.am/')
        );
    }

    public function test_place_details_success_uses_production_referer(): void
    {
        $key = 'fake-places-key';
        Http::fake([
            'https://places.googleapis.com/v1/places/test-place' => Http::response([
                'id' => 'test-place',
                'formattedAddress' => 'Yerevan, Armenia',
                'location' => ['latitude' => 40.1772, 'longitude' => 44.5035],
            ]),
        ]);

        $response = $this->controllerWithKeys($key, 'fake-server-key')->place_api_details(
            $this->request(['placeid' => 'test-place'], 'https://nezha.am')
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('test-place', $response->getData(true)['id']);
        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Goog-Api-Key', $key)
            && $request->hasHeader('Referer', 'https://nezha.am/')
            && $request->hasHeader('X-Goog-FieldMask', 'id,displayName,formattedAddress,location')
        );
    }

    public function test_places_upstream_rejection_returns_bad_gateway_without_leaking_key(): void
    {
        $placesKey = 'fake-places-secret-not-for-responses';
        $serverKey = 'fake-server-secret-not-for-responses';
        Http::fake([
            'https://places.googleapis.com/*' => Http::response([
                'error' => ['code' => 403, 'message' => 'Rejected '.$placesKey],
            ], 403),
        ]);

        $controller = $this->controllerWithKeys($placesKey, $serverKey);
        $responses = [
            $controller->place_api_autocomplete($this->request(['search_text' => 'Yerevan'])),
            $controller->place_api_details($this->request(['placeid' => 'test-place'])),
        ];

        foreach ($responses as $response) {
            $content = $response->getContent();
            $this->assertSame(502, $response->getStatusCode());
            $this->assertSame('google_maps_upstream_error', $response->getData(true)['errors'][0]['code']);
            $this->assertStringNotContainsString($placesKey, $content);
            $this->assertStringNotContainsString($serverKey, $content);
            $this->assertStringNotContainsString('Rejected', $content);
        }
    }

    public function test_geocode_success_returns_google_results(): void
    {
        $key = 'fake-server-key';
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [['formatted_address' => 'Yerevan, Armenia']],
            ]),
        ]);

        $response = $this->controllerWithKeys('fake-places-key', $key)->geocode_api(
            $this->request(['lat' => 40.1772, 'lng' => 44.5035])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getData(true)['status']);
        $this->assertCount(1, $response->getData(true)['results']);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/geocode/json?')
            && str_contains($request->url(), 'key='.$key)
        );
    }

    public function test_geocode_upstream_rejection_returns_bad_gateway_without_leaking_key(): void
    {
        $placesKey = 'fake-places-secret-not-for-responses';
        $serverKey = 'fake-server-secret-not-for-responses';
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'REQUEST_DENIED',
                'error_message' => 'Rejected '.$serverKey,
            ]),
        ]);

        $response = $this->controllerWithKeys($placesKey, $serverKey)->geocode_api(
            $this->request(['lat' => 40.1772, 'lng' => 44.5035])
        );

        $content = $response->getContent();
        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame('google_maps_upstream_error', $response->getData(true)['errors'][0]['code']);
        $this->assertStringNotContainsString($placesKey, $content);
        $this->assertStringNotContainsString($serverKey, $content);
        $this->assertStringNotContainsString('Rejected', $content);
    }

    public function test_key_loading_skips_blank_duplicates_deterministically(): void
    {
        $this->createBusinessSettingsTable();
        DB::table('business_settings')->insert([
            ['key' => 'map_api_key', 'value' => '   '],
            ['key' => 'map_api_key', 'value' => 'first-non-blank-key'],
            ['key' => 'map_api_key', 'value' => 'later-key'],
            ['key' => 'map_api_key_server', 'value' => 'server-key'],
        ]);
        Http::fake([
            'https://places.googleapis.com/v1/places:autocomplete' => Http::response(['suggestions' => []]),
        ]);

        $response = (new ConfigController)->place_api_autocomplete(
            $this->request(['search_text' => 'Yerevan'])
        );

        $this->assertSame(200, $response->getStatusCode());
        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Goog-Api-Key', 'first-non-blank-key'));
    }

    public function test_config_update_keeps_all_duplicate_map_key_rows_consistent(): void
    {
        $this->createBusinessSettingsTable();
        DB::table('business_settings')->insert([
            ['key' => 'map_api_key', 'value' => null],
            ['key' => 'map_api_key', 'value' => 'old-a'],
            ['key' => 'map_api_key', 'value' => 'old-b'],
            ['key' => 'map_api_key_server', 'value' => null],
        ]);

        $request = Request::create('/admin/business-settings/config-update', 'POST', [
            'map_api_key' => '  new-places-key  ',
            'map_api_key_server' => '  new-server-key  ',
        ], [], [], ['HTTP_REFERER' => 'https://admin.example.test/config']);
        $request->setLaravelSession($this->app['session.store']);
        $this->app->instance('request', $request);

        (new BusinessSettingsController)->config_update($request);

        $this->assertSame(
            ['new-places-key'],
            DB::table('business_settings')->where('key', 'map_api_key')->pluck('value')->unique()->values()->all()
        );
        $this->assertSame(
            ['new-server-key'],
            DB::table('business_settings')->where('key', 'map_api_key_server')->pluck('value')->unique()->values()->all()
        );
        $this->assertSame(3, DB::table('business_settings')->where('key', 'map_api_key')->count());
    }

    private function controllerWithKeys(?string $placesKey, ?string $serverKey): ConfigController
    {
        $reflection = new ReflectionClass(ConfigController::class);
        /** @var ConfigController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();

        foreach (['places_key' => $placesKey, 'map_api_key' => $serverKey] as $property => $value) {
            $reflectedProperty = $reflection->getProperty($property);
            $reflectedProperty->setValue($controller, $value);
        }

        return $controller;
    }

    private function request(array $parameters, ?string $origin = null): Request
    {
        $server = $origin === null ? [] : ['HTTP_ORIGIN' => $origin];

        return Request::create('/api/v1/config/google-test', 'GET', $parameters, [], [], $server);
    }

    private function createBusinessSettingsTable(): void
    {
        Schema::dropIfExists('business_settings');
        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }
}
