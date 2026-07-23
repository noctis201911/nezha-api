<?php

namespace App\Http\Middleware;

use App\Services\Auth\CustomerRequestAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCustomer
{
    public function __construct(
        private readonly CustomerRequestAuthenticator $authenticator,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->authenticator->resolve($request)) {
            return response()->json([
                'errors' => [[
                    'code' => 'auth-001',
                    'message' => 'Unauthorized.',
                ]],
            ], 401);
        }

        return $next($request);
    }
}
