<?php

declare(strict_types=1);

namespace Calliostro\Spotify\Exception;

use Throwable;

/**
 * Exception thrown when Spotify returns HTTP 429 Too Many Requests
 */
class RateLimitException extends SpotifyException
{
    private int $retryAfter;

    public function __construct(
        string $message = 'Rate limit exceeded',
        int $retryAfter = 0,
        int $code = 429,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->retryAfter = $retryAfter;
    }

    /**
     * Get the number of seconds to wait before retrying (from Retry-After header)
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
