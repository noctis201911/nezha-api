<?php

namespace App\Exceptions;

use RuntimeException;

class TelegramLoginException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($message);
    }
}
