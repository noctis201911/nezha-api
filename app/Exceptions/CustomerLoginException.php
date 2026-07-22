<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class CustomerLoginException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 403,
        public readonly ?array $context = null,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $payload = [
            'status' => $this->errorCode,
            'errors' => [[
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
            ]],
        ];

        if ($this->context !== null) {
            $payload['context'] = $this->context;
        }

        return response()->json($payload, $this->httpStatus);
    }
}
