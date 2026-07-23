<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTrustedCustomerLoginOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('nezha_customer_browser_auth.enabled', false)) {
            return $next($request);
        }

        // Native clients do not send Origin. Browser forms and fetch do, so a
        // present Origin must be an exact H5 origin to prevent login CSRF.
        $origin = rtrim((string) $request->header('Origin'), '/');
        $fetchSite = strtolower((string) $request->header('Sec-Fetch-Site'));
        $originRejected = $origin !== '' && ! in_array(
            $origin,
            config('nezha_customer_browser_auth.allowed_origins', []),
            true
        );
        // A browser cross-site form should still fail closed if a proxy or
        // privacy mode omitted Origin. Native clients send neither Origin nor
        // Fetch Metadata and retain their existing contract.
        $crossSiteWithoutOrigin = $origin === '' && $fetchSite === 'cross-site';

        if ($originRejected || $crossSiteWithoutOrigin) {
            return response()->json([
                'errors' => [[
                    'code' => 'customer_login_origin_rejected',
                    'message' => 'This login origin is not allowed.',
                ]],
            ], 403);
        }

        return $next($request);
    }
}
