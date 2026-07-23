<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\AccountDeletionException;
use App\Exceptions\TelegramLoginException;
use App\Http\Controllers\Controller;
use App\Services\Auth\TelegramLoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramAuthController extends Controller
{
    public function __construct(private readonly TelegramLoginService $telegramLogin) {}

    public function start(): JsonResponse
    {
        try {
            return response()->json($this->telegramLogin->begin());
        } catch (TelegramLoginException $error) {
            return $this->errorResponse($error);
        } catch (AccountDeletionException $error) {
            return $error->render();
        } catch (Throwable $error) {
            return $this->unexpectedError($error);
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        $providerError = $request->query('error');
        if (is_string($providerError) && $providerError !== '') {
            return $this->frontendRedirect('cancelled');
        }

        $state = $request->query('state');
        $code = $request->query('code');
        if (! is_string($state) || $state === '' || ! is_string($code) || $code === '') {
            return $this->frontendRedirect('invalid_request');
        }

        try {
            $exchangeCode = $this->telegramLogin->completeCallback($state, $code);

            return $this->frontendRedirect(null, $exchangeCode);
        } catch (TelegramLoginException $error) {
            Log::notice('Telegram login callback rejected.', [
                'error_code' => $error->errorCode,
            ]);

            return $this->frontendRedirect($error->errorCode);
        } catch (Throwable $error) {
            Log::error('Telegram login callback failed.', [
                'exception' => $error::class,
            ]);

            return $this->frontendRedirect('server');
        }
    }

    public function exchange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:512'],
            'browser_secret' => ['required', 'string', 'max:512'],
        ]);

        try {
            return $this->loginResponse($this->telegramLogin->exchange(
                $validated['code'],
                $validated['browser_secret'],
            ));
        } catch (TelegramLoginException $error) {
            return $this->errorResponse($error);
        } catch (AccountDeletionException $error) {
            return $error->render();
        } catch (Throwable $error) {
            return $this->unexpectedError($error);
        }
    }

    public function linkWithPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:512'],
            'browser_secret' => ['required', 'string', 'max:512'],
            'email' => ['required', 'email', 'max:191'],
            'password' => ['required', 'string', 'max:191'],
        ]);

        try {
            return $this->loginResponse($this->telegramLogin->linkWithPassword(
                $validated['code'],
                $validated['browser_secret'],
                $validated['email'],
                $validated['password'],
            ));
        } catch (TelegramLoginException $error) {
            return $this->errorResponse($error);
        } catch (AccountDeletionException $error) {
            return $error->render();
        } catch (Throwable $error) {
            return $this->unexpectedError($error);
        }
    }

    public function linkWithGoogle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:512'],
            'browser_secret' => ['required', 'string', 'max:512'],
            'credential' => ['required', 'string', 'max:8192'],
        ]);

        try {
            return $this->loginResponse($this->telegramLogin->linkWithGoogle(
                $validated['code'],
                $validated['browser_secret'],
                $validated['credential'],
            ));
        } catch (TelegramLoginException $error) {
            return $this->errorResponse($error);
        } catch (AccountDeletionException $error) {
            return $error->render();
        } catch (Throwable $error) {
            return $this->unexpectedError($error);
        }
    }

    private function errorResponse(TelegramLoginException $error): JsonResponse
    {
        return response()->json([
            'errors' => [[
                'code' => $error->errorCode,
                'message' => $error->getMessage(),
            ]],
        ], $error->httpStatus);
    }

    private function loginResponse(array $result): JsonResponse
    {
        $status = (int) ($result['_http_status'] ?? 200);
        unset($result['_http_status']);

        return response()->json($result, $status);
    }

    private function unexpectedError(Throwable $error): JsonResponse
    {
        Log::error('Telegram customer login failed.', [
            'exception' => $error::class,
        ]);

        return response()->json([
            'errors' => [[
                'code' => 'telegram_server_error',
                'message' => 'Telegram login is temporarily unavailable.',
            ]],
        ], 500);
    }

    private function frontendRedirect(?string $error = null, ?string $code = null): RedirectResponse
    {
        $frontendUri = (string) config('telegram_login.frontend_uri');
        $separator = str_contains($frontendUri, '?') ? '&' : '?';
        $query = $code !== null
            ? 'code='.rawurlencode($code)
            : 'error='.rawurlencode($error ?: 'server');

        return redirect()->away($frontendUri.$separator.$query);
    }
}
