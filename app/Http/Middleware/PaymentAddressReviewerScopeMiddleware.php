<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;

class PaymentAddressReviewerScopeMiddleware
{
    private const REVIEW_MODULE = 'payment_address_review';

    private const BEFORE_ENROLLMENT_ROUTES = [
        'admin.lang',
        'admin.settings',
        'admin.two-factor.setup',
        'admin.two-factor.enable',
        'admin.settings-password',
    ];

    private const AFTER_ENROLLMENT_ROUTES = [
        'admin.lang',
        'admin.settings',
        'admin.payment-address-review.pending',
        'admin.restaurant.payment-address-change.show',
        'admin.restaurant.payment-address-change.approve',
        'admin.restaurant.payment-address-change.reject',
        'admin.settings-password',
        'admin.two-factor.setup',
    ];

    public function handle(Request $request, Closure $next)
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! self::isReviewer($admin)) {
            return $next($request);
        }

        $routeName = (string) $request->route()?->getName();
        if (! $admin->two_factor_enabled) {
            if (self::routeAllowedBeforeEnrollment($routeName)) {
                return $next($request);
            }
            if ($request->expectsJson()) {
                return response()->json(['error' => 'reviewer_2fa_enrollment_required'], 428);
            }
            return redirect()->route('admin.two-factor.setup');
        }

        if (self::routeAllowedAfterEnrollment($routeName)) {
            return $next($request);
        }
        if ($request->expectsJson()) {
            return response()->json(['error' => 'payment_address_reviewer_scope_denied'], 403);
        }
        return response()->view(
            'admin-views.errors.no-permission',
            ['module' => self::REVIEW_MODULE],
            403
        );
    }

    public static function isReviewer(Admin $admin): bool
    {
        if ((int) $admin->role_id === 1 || ! $admin->role) {
            return false;
        }
        $modules = json_decode((string) $admin->role->modules, true);
        return is_array($modules) && in_array(self::REVIEW_MODULE, $modules, true);
    }

    public static function routeAllowedBeforeEnrollment(string $routeName): bool
    {
        return in_array($routeName, self::BEFORE_ENROLLMENT_ROUTES, true);
    }

    public static function routeAllowedAfterEnrollment(string $routeName): bool
    {
        return in_array($routeName, self::AFTER_ENROLLMENT_ROUTES, true);
    }
}
