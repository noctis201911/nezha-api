<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaMerchantTwoFactor;
use App\Http\Controllers\MerchantTwoFactorController;

class VendorMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        Helpers::check_subscription_validity();
        if (Auth::guard('vendor')->check()) {
            $actor = auth('vendor')->user();
            if(! $actor->status || ! $actor->restaurants()->where('status', 1)->exists())
            {
                return $this->reject($request, 'vendor', 'restaurant_login_url');
            }
            if (! $this->sessionIsCurrent($request, $actor)) {
                return $this->reject($request, 'vendor', 'restaurant_login_url');
            }
            return $next($request);
        } elseif (Auth::guard('vendor_employee')->check()) {
            $employee = auth('vendor_employee')->user();
            if (! $employee->status || ! $employee->restaurant?->status) {
                return $this->reject($request, 'vendor_employee', 'restaurant_employee_login_url');
            }
            if (! $this->sessionIsCurrent($request, $employee)) {
                return $this->reject($request, 'vendor_employee', 'restaurant_employee_login_url');
            }
            return $next($request);
        }
        return redirect()->route('home');
    }

    private function sessionIsCurrent(Request $request, $actor): bool
    {
        $generation = (int) $actor->auth_generation;
        if ($request->session()->get(MerchantTwoFactorController::SESSION_GENERATION) !== $generation) {
            return false;
        }

        $state = NezhaMerchantTwoFactor::state($actor);
        if ($state === NezhaMerchantTwoFactor::STATE_ENROLLMENT) {
            return false;
        }

        return $state === NezhaMerchantTwoFactor::STATE_OPTIONAL
            || $request->session()->get(MerchantTwoFactorController::SESSION_PASSED_GENERATION) === $generation;
    }

    private function reject(Request $request, string $guard, string $loginKey)
    {
        auth()->guard($guard)->logout();
        MerchantTwoFactorController::clearPending($request);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $userLink = Helpers::get_login_url($loginKey);

        return to_route('login', [$userLink]);
    }
}
