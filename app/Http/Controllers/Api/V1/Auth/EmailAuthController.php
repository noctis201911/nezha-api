<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\EmailLoginException;
use App\Http\Controllers\Controller;
use App\Services\Auth\EmailLoginService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailAuthController extends Controller
{
    public function __construct(private readonly EmailLoginService $emailLogin) {}

    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:191'],
            'locale' => ['nullable', 'string', 'max:12'],
            'terms_accepted' => ['required', 'accepted'],
        ]);

        return $this->run(fn () => $this->emailLogin->begin(
            $validated['email'],
            (string) $request->ip(),
            $validated['locale'] ?? 'zh-CN',
            (bool) $validated['terms_accepted'],
        ));
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'browser_secret' => ['required', 'string', 'max:128'],
            'code' => ['required', 'digits:6'],
        ]);

        return $this->run(fn () => $this->emailLogin->verify(
            $validated['challenge_id'],
            $validated['browser_secret'],
            $validated['code'],
        ));
    }

    private function run(callable $action): JsonResponse
    {
        try {
            $result = $action();
            $status = (int) ($result['_http_status'] ?? 200);
            unset($result['_http_status']);

            return response()->json($result, $status);
        } catch (LockTimeoutException) {
            return response()->json([
                'errors' => [[
                    'code' => 'email_auth_busy',
                    'message' => 'Another verification request is being processed. Please try again.',
                ]],
            ], 429);
        } catch (EmailLoginException $error) {
            return response()->json([
                'errors' => [[
                    'code' => $error->errorCode,
                    'message' => $error->getMessage(),
                ]],
            ], $error->httpStatus);
        } catch (Throwable $error) {
            Log::error('Customer email login failed.', [
                'exception' => $error::class,
            ]);

            return response()->json([
                'errors' => [[
                    'code' => 'email_auth_server_error',
                    'message' => 'Email sign-in is temporarily unavailable.',
                ]],
            ], 500);
        }
    }
}
