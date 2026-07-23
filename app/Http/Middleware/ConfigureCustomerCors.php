<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigureCustomerCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = rtrim((string) $request->header('Origin'), '/');
        $trustedCustomerOrigin = (bool) config(
            'nezha_customer_browser_auth.enabled',
            false
        ) && $origin !== '' && in_array(
            $origin,
            config('nezha_customer_browser_auth.allowed_origins', []),
            true
        );

        // Only the approved customer H5 origins receive credentialed CORS.
        // Every other browser client retains the previous wildcard,
        // no-credentials contract used by vendor/rider bearer auth.
        config()->set(
            'cors.allowed_origins',
            $trustedCustomerOrigin ? [$origin] : ['*']
        );
        config()->set(
            'cors.supports_credentials',
            $trustedCustomerOrigin
        );

        return $next($request);
    }
}
