<?php

declare(strict_types=1);

namespace OpenLibrary;

use OpenLibrary\Exception\ApiException;
use OpenLibrary\Exception\NetworkException;
use OpenLibrary\Exception\RateLimitException;
use OpenLibrary\Http\CurlTransport;
use OpenLibrary\Http\Response;
use OpenLibrary\Http\TransportInterface;
use OpenLibrary\RateLimit\Throttler;
use OpenLibrary\Search\SearchQuery;
use OpenLibrary\Search\SearchResult;

/**
 * Entry point: search books on Open Library.
 *
 * Rate limiting (per https://openlibrary.org/developers/api):
 *  - un-identified clients are limited to 1 request/second,
 *  - identified clients (User-Agent containing a contact email) to 3/second,
 *  - exceeding a limit yields HTTP 429, usually with a Retry-After header.
 *
 * This client therefore (a) throttles on the client side with a small
 * safety buffer, (b) retries 429/5xx/network errors with exponential
 * backoff (honouring Retry-After when present), and (c) throws structured
 * exceptions when the budget is exhausted or the error is permanent.
 */
final class Client
{
    private TransportInterface $transport;
    private Throttler $throttler;

    /** @var callable(int $ms): void */
    private $sleep;

    /**
     * @param callable(int $ms): void|null $sleep Injectable sleep (tests).
     */
    public function __construct(
        private Config $config = new Config(),
        ?TransportInterface $transport = null,
        ?callable $sleep = null,
    ) {
        $this->transport = $transport ?? new CurlTransport($config->timeoutSeconds());
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
        // 350 ms ≈ 3 req/s, 1050 ms ≈ 1 req/s, each with a small buffer.
        $this->throttler = new Throttler(
            $this->config->identified() ? 350 : 1050,
            $this->sleep,
        );
    }

    /**
     * Search books via /search.json.
     *
     * @throws RateLimitException 429 after all retries
     * @throws ApiException       other 4xx, 5xx after retries, or invalid JSON
     * @throws NetworkException   transport failure after all retries
     */
    public function search(SearchQuery $query): SearchResult
    {
        $url = $this->config->baseUrl() . '/search.json?' . http_build_query($query->toParams(), '', '&');
        $headers = [
            'User-Agent: ' . $this->config->userAgent(),
            'Accept: application/json',
        ];

        $attempt = 0;
        while (true) {
            $this->throttler->wait();

            try {
                $response = $this->transport->request('GET', $url, $headers);
            } catch (NetworkException $e) {
                if ($attempt >= $this->config->maxRetries()) {
                    throw $e;
                }
                $this->sleepForBackoff(++$attempt);
                continue;
            }

            $status = $response->status;

            if ($status === 200) {
                $data = $response->json();
                if ($data === null) {
                    throw new ApiException(200, 'Response was not valid JSON', $url, $response->body);
                }

                return SearchResult::fromSearchJson($data);
            }

            if ($status === 429) {
                if ($attempt >= $this->config->maxRetries()) {
                    throw new RateLimitException($response->retryAfterSeconds(), $url);
                }
                $this->sleepForRateLimit($response, ++$attempt);
                continue;
            }

            if ($status >= 500) {
                if ($attempt >= $this->config->maxRetries()) {
                    throw new ApiException($status, 'Open Library server error', $url, $response->body);
                }
                $this->sleepForBackoff(++$attempt);
                continue;
            }

            // Permanent 4xx — retrying will not help.
            throw new ApiException($status, $this->extractMessage($response), $url, $response->body);
        }
    }

    /**
     * Convenience: search by ISBN (10- or 13-digit).
     */
    public function searchByIsbn(string $isbn): SearchResult
    {
        return $this->search((new SearchQuery())->isbn($isbn));
    }

    private function sleepForRateLimit(Response $response, int $attempt): void
    {
        $retryAfterMs = $response->retryAfterSeconds() !== null
            ? $response->retryAfterSeconds() * 1000
            : null;
        $backoff = $this->backoffMs($attempt);
        ($this->sleep)(max($retryAfterMs ?? 0, $backoff));
    }

    private function sleepForBackoff(int $attempt): void
    {
        ($this->sleep)($this->backoffMs($attempt));
    }

    /**
     * Exponential backoff with jitter: base * 2^(attempt-1) + 0..250 ms.
     */
    private function backoffMs(int $attempt): int
    {
        $delay = $this->config->baseRetryDelayMs() * (2 ** ($attempt - 1));

        return $delay + random_int(0, 250);
    }

    /**
     * Prefer the machine-readable JSON error fields (error/detail/message),
     * fall back to a short body excerpt for HTML error pages.
     */
    private function extractMessage(Response $response): string
    {
        $data = $response->json();
        if (is_array($data)) {
            foreach (['error', 'detail', 'message'] as $key) {
                if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                    return $data[$key];
                }
            }
        }

        $body = trim($response->body());

        return $body === '' ? 'Request failed' : mb_substr($body, 0, 200);
    }
}
