<?php

namespace App\Services\Mcp;

use RuntimeException;

class McpCatalogException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'error',
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return array_merge([
            'error' => true,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->details);
    }
}
