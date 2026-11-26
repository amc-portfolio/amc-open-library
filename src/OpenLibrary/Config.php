<?php

declare(strict_types=1);

namespace OpenLibrary;

/**
 * Immutable configuration for the Client.
 *
 * Per Open Library's usage guidelines, identify your application with a
 * User-Agent that includes a contact email — identified requests get a 3x
 * rate limit (3 req/s instead of 1 req/s), and the team can contact you
 * instead of silently blocking you.
 */
final class Config
{
    public function __construct(
        private string $baseUrl = 'https://openlibrary.org',
        private string $userAgent = 'openlibrary-php/1.0.0',
        private ?string $email = null,
        private int $timeoutSeconds = 10,
        private int $maxRetries = 3,
        private int $baseRetryDelayMs = 500,
    ) {
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /**
     * User-Agent as it will be sent: "<userAgent> (<email>)" when an email
     * is configured, matching the documented format
     * "MyLibraryApp (contact@example.org)".
     */
    public function userAgent(): string
    {
        return $this->email !== null
            ? "{$this->userAgent} ({$this->email})"
            : $this->userAgent;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function maxRetries(): int
    {
        return $this->maxRetries;
    }

    public function baseRetryDelayMs(): int
    {
        return $this->baseRetryDelayMs;
    }

    public function identified(): bool
    {
        return $this->email !== null;
    }
}
