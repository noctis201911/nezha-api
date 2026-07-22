<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NezhaImageProxyRetirementTest extends TestCase
{
    public function test_public_image_proxy_route_is_not_registered(): void
    {
        $registered = collect(Route::getRoutes())->contains(
            fn ($route) => trim($route->uri(), '/') === 'image-proxy'
        );

        $this->assertFalse($registered);
        $this->get('/image-proxy?url=https%3A%2F%2Fexample.com%2Fimage.png')
            ->assertNotFound();
    }
}
