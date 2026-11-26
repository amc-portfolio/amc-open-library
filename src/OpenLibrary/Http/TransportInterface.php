<?php

declare(strict_types=1);

namespace OpenLibrary\Http;

use OpenLibrary\Exception\NetworkException;

/**
 * HTTP transport abstraction. The default implementation is
 * CurlTransport; swap in a fake (or Guzzle-based) transport for testing
 * or to plug the library into an existing HTTP stack.
 */
interface TransportInterface
{
    /**
     * @param string[] $headers Raw "Name: value" header lines.
     *
     * @throws NetworkException when the request cannot complete (DNS,
     *   connection failure, timeout, …).
     */
    public function request(string $method, string $url, array $headers = []): Response;
}
