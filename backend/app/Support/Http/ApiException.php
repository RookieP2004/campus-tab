<?php

namespace App\Support\Http;

use RuntimeException;

/**
 * A domain error with a stable machine-readable code, rendered by
 * ApiExceptionRenderer as { "error": { "code", "message", "details" } }.
 *
 *   throw new ApiException('module_disabled', 'Fees is not part of this licence.', 403);
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
