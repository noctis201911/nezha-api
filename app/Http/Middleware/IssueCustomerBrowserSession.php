<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\CustomerAccessTokenIssuer;
use App\Services\Auth\CustomerBrowserSessionManager;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IssueCustomerBrowserSession
{
    public function __construct(
        private readonly CustomerBrowserSessionManager $browserSessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (
            ! $this->browserSessions->requestCanReceiveCookie($request)
            || ! $response instanceof JsonResponse
            || $response->getStatusCode() < 200
            || $response->getStatusCode() >= 300
        ) {
            return $response;
        }

        $payload = $response->getData(true);
        $returnedToken = is_array($payload) ? ($payload['token'] ?? null) : null;
        $pendingHash = $request->attributes->get(
            CustomerAccessTokenIssuer::REQUEST_TOKEN_HASH
        );
        $pendingUserId = $request->attributes->get(
            CustomerAccessTokenIssuer::REQUEST_USER_ID
        );
        $pendingAccessTokenId = $request->attributes->get(
            CustomerAccessTokenIssuer::REQUEST_ACCESS_TOKEN_ID
        );

        if (
            ! is_string($returnedToken)
            || $returnedToken === ''
            || ! is_string($pendingHash)
            || ! hash_equals($pendingHash, hash('sha256', $returnedToken))
            || ! is_numeric($pendingUserId)
        ) {
            return $response;
        }

        $user = User::query()->find((int) $pendingUserId);
        if ($user && (bool) $user->status) {
            $this->browserSessions->issueForLogin(
                $user,
                is_string($pendingAccessTokenId)
                    ? $pendingAccessTokenId
                    : null,
            );
        }

        return $response;
    }
}
