<?php

declare(strict_types=1);

namespace OpenLibrary\Exception;

/**
 * Thrown when Open Library responds with HTTP 429 (rate limited) after the
 * configured number of retries has been exhausted. Carries the Retry-After
 * value (seconds) if the server sent one, so callers can schedule a later
 * retry of their own.
 */
class RateLimitException extends ApiException
{
    public function __construct(
        public readonly ?int $retryAfterSeconds,
        ?string $url = null,
    ) {
        $hint = $retryAfterSeconds !== null ? " (server asks to wait {$retryAfterSeconds}s)" : '';
        parent::__construct(
            429,
            'Open Library rate limit exceeded, tried all allowed retries' . $hint,
            $url,
        );
    }
}
