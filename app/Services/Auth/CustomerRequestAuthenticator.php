<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerRequestAuthenticator
{
    public function __construct(
        private readonly CustomerBrowserSessionManager $browserSessions,
    ) {}

    public function resolve(Request $request): ?User
    {
        // During the dual-stack window an old H5 may carry both the new Cookie
        // and its legacy Bearer header. Prefer a valid Bearer so that client,
        // which does not know the CSRF header yet, keeps its exact old contract.
        $authorization = (string) $request->header('Authorization');
        if (
            $authorization !== ''
            && strcasecmp($authorization, 'Bearer null') !== 0
        ) {
            $bearerUser = Auth::guard('api')->user();
            if ($bearerUser instanceof User) {
                $request->attributes->set(
                    CustomerBrowserSessionManager::REQUEST_SOURCE,
                    'bearer'
                );

                return $this->bindUser($request, $bearerUser);
            }
        }

        $browserSession = $this->browserSessions->authenticate($request);
        if ($browserSession) {
            if (
                $this->isUnsafe($request)
                && ! $this->browserSessions->csrfIsValid($request)
            ) {
                throw new HttpResponseException(response()->json([
                    'errors' => [[
                        'code' => 'customer_csrf_mismatch',
                        'message' => 'The customer session request token is invalid.',
                    ]],
                ], 419));
            }

            $request->attributes->set(
                CustomerBrowserSessionManager::REQUEST_SOURCE,
                'cookie'
            );

            return $this->bindUser($request, $browserSession->user);
        }

        $user = Auth::guard('api')->user();
        if (! $user instanceof User) {
            return null;
        }

        $request->attributes->set(
            CustomerBrowserSessionManager::REQUEST_SOURCE,
            'bearer'
        );

        return $this->bindUser($request, $user);
    }

    private function bindUser(Request $request, User $user): User
    {
        Auth::shouldUse('api');
        Auth::guard('api')->setUser($user);
        $request->setUserResolver(static fn (?string $guard = null): User => $user);

        return $user;
    }

    private function isUnsafe(Request $request): bool
    {
        return ! in_array(
            strtoupper($request->method()),
            ['GET', 'HEAD', 'OPTIONS'],
            true
        );
    }
}
