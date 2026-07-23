<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\CustomerBrowserSessionManager;
use App\Services\Auth\LegacyCustomerTokenMigrationConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\Token;

class CustomerBrowserSessionController extends Controller
{
    public function __construct(
        private readonly CustomerBrowserSessionManager $browserSessions,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $session = $this->browserSessions->current($request);

        return $this->sessionResponse([
            'authenticated' => (bool) $session,
            'csrf_token' => $session
                ? $this->browserSessions->csrfToken($request)
                : null,
            'idle_expires_at' => $session?->idle_expires_at?->toIso8601String(),
            'absolute_expires_at' => $session?->absolute_expires_at?->toIso8601String(),
        ]);
    }

    public function migrate(Request $request): JsonResponse
    {
        if (! $this->browserSessions->requestCanReceiveCookie($request)) {
            return response()->json([
                'errors' => [[
                    'code' => 'customer_browser_origin_required',
                    'message' => 'A trusted customer browser origin is required.',
                ]],
            ], 403);
        }

        $accessToken = $request->user()?->token();
        if (! $accessToken instanceof Token) {
            return response()->json([
                'errors' => [[
                    'code' => 'legacy_access_token_required',
                    'message' => 'A valid legacy customer access token is required.',
                ]],
            ], 401);
        }

        // The legacy Bearer authenticated this endpoint. If its first migrate
        // response was dropped after Set-Cookie, reuse that same session
        // instead of consuming another one of the five allowed slots.
        $current = $this->browserSessions->authenticate($request);
        if (
            $current
            && (int) $current->user_id === (int) $request->user()->getKey()
            && hash_equals(
                (string) $current->legacy_access_token_id,
                (string) $accessToken->id
            )
        ) {
            return $this->show($request);
        }

        try {
            $issued = $this->browserSessions->issueFromLegacyToken(
                $request->user(),
                $accessToken,
            );
        } catch (LegacyCustomerTokenMigrationConflict) {
            return response()->json([
                'errors' => [[
                    'code' => 'customer_browser_migration_in_progress',
                    'message' => 'This access token is already being migrated.',
                ]],
            ], 409);
        }

        return $this->sessionResponse([
            'authenticated' => true,
            'csrf_token' => $issued['csrf_token'],
            'idle_expires_at' => $issued['session']->idle_expires_at->toIso8601String(),
            'absolute_expires_at' => $issued['session']->absolute_expires_at->toIso8601String(),
            'legacy_revoke_pending' => true,
        ]);
    }

    public function confirmMigration(Request $request): JsonResponse
    {
        return $this->sessionResponse([
            'confirmed' => $this->browserSessions->confirmLegacyMigration($request),
        ]);
    }

    private function sessionResponse(array $payload): JsonResponse
    {
        return response()
            ->json($payload)
            ->header('Cache-Control', 'no-store, private');
    }
}
