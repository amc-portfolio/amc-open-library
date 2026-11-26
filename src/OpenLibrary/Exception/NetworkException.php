<?php

declare(strict_types=1);

namespace OpenLibrary\Exception;

/**
 * Thrown when the request never completes successfully at the transport
 * level: DNS failure, connection refused/reset, timeout, or any other
 * cURL error. Transient ones are retried by the client before this is
 * surfaced to the caller.
 */
class NetworkException extends OpenLibraryException
{
    public function __construct(
        string $message,
        public readonly int $curlError = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $curlError, $previous);
    }
}
