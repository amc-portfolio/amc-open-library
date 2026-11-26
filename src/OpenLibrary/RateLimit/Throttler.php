<?php

declare(strict_types=1);

namespace OpenLibrary\RateLimit;

/**
 * Client-side minimum-interval throttler.
 *
 * Open Library's documented limits are 1 request/second for unidentified
 * requests and 3 requests/second for identified ones (User-Agent carrying
 * a contact email). The throttler enforces a small buffer above those
 * limits (1050 ms / 350 ms) so a burst of back-to-back calls stays safely
 * under the wire. This is a complement to — not a replacement for — the
 * retry logic that reacts to HTTP 429 responses.
 *
 * The sleep is injectable so tests can run without real waiting.
 */
final class Throttler
{
    private int $lastRequestNanos = 0;

    /** @var callable(int $ms): void */
    private $sleep;

    /**
     * @param callable(int $ms): void|null $sleep
     */
    public function __construct(
        private int $minIntervalMs,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * Block just long enough that this call stays at least $minIntervalMs
     * after the previous one. Must be invoked before every request.
     */
    public function wait(): void
    {
        $now = hrtime(true);

        if ($this->lastRequestNanos > 0) {
            $elapsedUs = intdiv($now - $this->lastRequestNanos, 1000);
            $minUs = $this->minIntervalMs * 1000;
            if ($elapsedUs < $minUs) {
                $remainingUs = $minUs - (int) $elapsedUs;
                // The injected sleeper accepts milliseconds, while hrtime uses nanoseconds.
                ($this->sleep)(intdiv($remainingUs + 999, 1000));
            }
        }

        $this->lastRequestNanos = hrtime(true);
    }
}
