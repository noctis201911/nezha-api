<?php

namespace Tests\Feature;

use App\Http\Middleware\PaymentAddressReviewerScopeMiddleware;
use App\Models\Admin;
use App\Models\AdminRole;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

class NezhaPaymentAddressRbacRouteTest extends TestCase
{
    public function test_payment_address_routes_are_split_by_least_privilege_module(): void
    {
        foreach ([
            'admin.restaurant.payment-address-change.store',
            'admin.restaurant.payment-address-change.pause',
            'admin.restaurant.payment-address-change.cancel',
        ] as $name) {
            $middleware = $this->routeMiddleware($name);
            $this->assertContains('module:payment_address_manage', $middleware, $name);
            $this->assertNotContains('module:payment_address_review', $middleware, $name);
            $this->assertNotContains('module:restaurant', $middleware, $name);
            $this->assertContains('reviewer.scope', $middleware, $name);
        }

        foreach ([
            'admin.payment-address-review.pending',
            'admin.restaurant.payment-address-change.show',
            'admin.restaurant.payment-address-change.approve',
        ] as $name) {
            $middleware = $this->routeMiddleware($name);
            $this->assertContains('module:payment_address_review', $middleware, $name);
            $this->assertNotContains('module:payment_address_manage', $middleware, $name);
            $this->assertNotContains('module:restaurant', $middleware, $name);
            $this->assertContains('reviewer.scope', $middleware, $name);
        }

        $this->assertSame(
            'admin/payment-address-review/request/{change}',
            app('router')->getRoutes()
                ->getByName('admin.restaurant.payment-address-change.show')
                ->uri()
        );
    }

    public function test_reviewer_scope_returns_enrollment_and_scope_errors_before_controller_dispatch(): void
    {
        $reviewer = $this->adminWithModules(['payment_address_review']);
        $this->actingAs($reviewer, 'admin');
        $middleware = new PaymentAddressReviewerScopeMiddleware();

        $reviewer->two_factor_enabled = false;
        $enrollment = $middleware->handle(
            $this->jsonRequestForRoute('admin.dashboard'),
            fn () => response('unexpected')
        );
        $this->assertSame(428, $enrollment->getStatusCode());
        $this->assertSame(
            'reviewer_2fa_enrollment_required',
            $enrollment->getData(true)['error']
        );

        $reviewer->two_factor_enabled = true;
        $denied = $middleware->handle(
            $this->jsonRequestForRoute('admin.restaurant.payment-address-change.pause'),
            fn () => response('unexpected')
        );
        $this->assertSame(403, $denied->getStatusCode());
        $this->assertSame(
            'payment_address_reviewer_scope_denied',
            $denied->getData(true)['error']
        );

        $allowed = $middleware->handle(
            $this->jsonRequestForRoute('admin.payment-address-review.pending'),
            fn () => response('allowed', 204)
        );
        $this->assertSame(204, $allowed->getStatusCode());
    }

    public function test_reviewer_scope_is_exclusive_and_enforces_the_2fa_enrollment_allowlist(): void
    {
        $reviewer = $this->adminWithModules(['payment_address_review']);
        $manager = $this->adminWithModules(['payment_address_manage']);
        $combined = $this->adminWithModules(['payment_address_review', 'restaurant']);
        $super = $this->adminWithModules([], 1);

        $this->assertTrue(PaymentAddressReviewerScopeMiddleware::isReviewer($reviewer));
        $this->assertFalse(PaymentAddressReviewerScopeMiddleware::isReviewer($manager));
        $this->assertTrue(PaymentAddressReviewerScopeMiddleware::isReviewer($combined));
        $this->assertFalse(PaymentAddressReviewerScopeMiddleware::isReviewer($super));

        foreach ([
            'admin.two-factor.setup',
            'admin.two-factor.enable',
            'admin.settings-password',
        ] as $routeName) {
            $this->assertTrue(
                PaymentAddressReviewerScopeMiddleware::routeAllowedBeforeEnrollment($routeName),
                $routeName
            );
        }
        foreach ([
            'admin.payment-address-review.pending',
            'admin.restaurant.payment-address-change.approve',
            'admin.restaurant.payment-address-change.store',
            'admin.restaurant.payment-address-change.pause',
            'admin.dashboard',
            'admin.settings',
            'admin.two-factor.disable',
        ] as $routeName) {
            $this->assertFalse(
                PaymentAddressReviewerScopeMiddleware::routeAllowedBeforeEnrollment($routeName),
                $routeName
            );
        }

        foreach ([
            'admin.payment-address-review.pending',
            'admin.restaurant.payment-address-change.show',
            'admin.restaurant.payment-address-change.approve',
            'admin.settings-password',
        ] as $routeName) {
            $this->assertTrue(
                PaymentAddressReviewerScopeMiddleware::routeAllowedAfterEnrollment($routeName),
                $routeName
            );
        }
        foreach ([
            'admin.restaurant.payment-address-change.store',
            'admin.restaurant.payment-address-change.pause',
            'admin.restaurant.payment-address-change.cancel',
            'admin.restaurant.update-payment-info',
            'admin.dashboard',
            'admin.settings',
            'admin.two-factor.disable',
        ] as $routeName) {
            $this->assertFalse(
                PaymentAddressReviewerScopeMiddleware::routeAllowedAfterEnrollment($routeName),
                $routeName
            );
        }
    }

    public function test_reviewer_data_contract_is_pending_only_and_role_composition_is_rejected(): void
    {
        $controller = file_get_contents(app_path(
            'Http/Controllers/Admin/NezhaPaymentAddressChangeController.php'
        ));
        $roleController = file_get_contents(app_path(
            'Http/Controllers/Admin/CustomRoleController.php'
        ));
        $create = file_get_contents(resource_path('views/admin-views/custom-role/create.blade.php'));
        $edit = file_get_contents(resource_path('views/admin-views/custom-role/edit.blade.php'));
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString("where('state', 'pending_distinct_admin')", $controller);
        $this->assertStringContainsString("abort_unless(\$change->state === 'pending_distinct_admin', 404)", $controller);
        $this->assertStringContainsString('payment_address_review must be an exclusive role module', $roleController);
        $this->assertStringContainsString('value="payment_address_review"', $create);
        $this->assertStringContainsString('value="payment_address_manage"', $create);
        $this->assertStringContainsString('value="payment_address_review"', $edit);
        $this->assertStringContainsString('value="payment_address_manage"', $edit);
        $this->assertStringContainsString("'reviewer.scope' => PaymentAddressReviewerScopeMiddleware::class", $bootstrap);
    }

    private function routeMiddleware(string $name): array
    {
        $route = app('router')->getRoutes()->getByName($name);
        $this->assertNotNull($route, $name);
        return $route->gatherMiddleware();
    }

    private function adminWithModules(array $modules, int $roleId = 2): Admin
    {
        $role = new AdminRole();
        $role->modules = json_encode($modules);

        $admin = new Admin();
        $admin->role_id = $roleId;
        $admin->setRelation('role', $role);
        return $admin;
    }

    private function jsonRequestForRoute(string $name): Request
    {
        $request = Request::create('/admin/test', 'GET', server: [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $route = new Route(['GET'], '/admin/test', fn () => null);
        $route->name($name);
        $request->setRouteResolver(fn () => $route);
        return $request;
    }
}
