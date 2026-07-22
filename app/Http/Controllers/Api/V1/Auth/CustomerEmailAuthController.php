<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\CustomerEmailAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerEmailAuthController extends Controller
{
    public function __construct(private readonly CustomerEmailAuthService $emailAuth) {}

    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:191'],
        ]);

        return response()->json($this->emailAuth->start($validated['email']), 202);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string', 'size:43'],
            'browser_secret' => ['required', 'string', 'max:128'],
            'code' => ['required', 'digits:6'],
        ]);

        return response()->json($this->emailAuth->verify(
            $validated['challenge_id'],
            $validated['browser_secret'],
            (string) $validated['code'],
        ));
    }

    public function proveLegacyPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string', 'size:43'],
            'browser_secret' => ['required', 'string', 'max:128'],
            'completion_token' => ['required', 'string', 'size:43'],
            'password' => ['required', 'string', 'min:6', 'max:191'],
        ]);

        return response()->json($this->emailAuth->proveLegacyPassword(
            $validated['challenge_id'],
            $validated['browser_secret'],
            $validated['completion_token'],
            $validated['password'],
        ));
    }

    public function complete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string', 'size:43'],
            'browser_secret' => ['required', 'string', 'max:128'],
            'completion_token' => ['required', 'string', 'size:43'],
            'name' => ['required', 'string', 'max:200'],
            'terms_accepted' => ['accepted'],
            'locale' => ['nullable', 'string', 'max:16'],
            'ref_code' => ['nullable', 'string', 'max:191'],
        ]);

        return response()->json($this->emailAuth->completeRegistration(
            $validated['challenge_id'],
            $validated['browser_secret'],
            $validated['completion_token'],
            $validated['name'],
            (bool) $validated['terms_accepted'],
            (string) ($validated['locale'] ?? 'zh-CN'),
            $validated['ref_code'] ?? null,
        ));
    }
}
