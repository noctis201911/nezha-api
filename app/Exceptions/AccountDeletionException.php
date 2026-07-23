<?php

namespace App\Exceptions;

use RuntimeException;

class AccountDeletionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function render()
    {
        $payload = [
            'errors' => [[
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
            ]],
        ];

        if ($this->context !== []) {
            $payload['account_deletion'] = $this->context;
        }

        return response()->json($payload, $this->status);
    }
}
