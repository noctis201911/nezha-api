<?php

namespace App\Http\Middleware;

use App\Services\Auth\CustomerRequestAuthenticator;
use Closure;
use Illuminate\Http\Request;

class APIGuestMiddleware
{
    public function __construct(
        private readonly CustomerRequestAuthenticator $authenticator,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $this->authenticator->resolve($request);
        if ($user) {
            $request->merge(['user' => $user]);
            return $next($request);
        } elseif ($request->guest_id) {
            return $next($request);
        }

        return response()->json([
            'errors' => [
                ['code' => 'auth-001', 'message' => 'Unauthorized.']
            ]
        ], 401);
    }
}
