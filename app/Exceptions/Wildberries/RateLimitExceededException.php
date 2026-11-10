<?php

namespace App\Exceptions\Wildberries;

use Exception;

class RateLimitExceededException extends Exception
{
    public const int DEFAULT_RETRY_AFTER = 10;

    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("Wildberries rate limit exceeded, retry after {$retryAfter} s.");
    }
}
