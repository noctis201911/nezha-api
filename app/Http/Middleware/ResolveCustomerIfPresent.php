<?php

namespace App\Http\Middleware;

use App\Services\Auth\CustomerRequestAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCustomerIfPresent
{
    public function __construct(
        private readonly CustomerRequestAuthenticator $authenticator,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->authenticator->resolve($request);

        return $next($request);
    }
}
