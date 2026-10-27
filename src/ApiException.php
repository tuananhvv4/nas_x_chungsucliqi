<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly array $response = [],
        public readonly ?int $retryAfter = null
    ) {
        parent::__construct($message, $httpStatus);
    }
}

