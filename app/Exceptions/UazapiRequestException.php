<?php

namespace App\Exceptions;

use RuntimeException;

class UazapiRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
