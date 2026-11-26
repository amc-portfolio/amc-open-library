<?php

declare(strict_types=1);

namespace OpenLibrary\Http;

/**
 * A single HTTP response. Header lookup is case-insensitive.
 */
final class Response
{
    /**
     * @param array<string, string> $headers Normalized to lowercase keys.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        private array $headers = [],
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * Parsed JSON body, or null when the body is not valid JSON.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        if ($this->body === '') {
            return null;
        }
        try {
            $data = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * The Retry-After header as whole seconds, or null when absent/unparseable.
     * Only the delta-seconds form is used; an HTTP-date value is treated as
     * "immediately" rather than parsed, which is the safe reading for a retry.
     */
    public function retryAfterSeconds(): ?int
    {
        $value = $this->header('Retry-After');
        if ($value !== null && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }
}
