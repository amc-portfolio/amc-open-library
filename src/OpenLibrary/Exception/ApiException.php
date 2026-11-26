<?php

declare(strict_types=1);

namespace OpenLibrary\Exception;

/**
 * An HTTP-level failure returned by the Open Library API (4xx/5xx, or a
 * 200 whose body is not valid JSON).
 */
class ApiException extends OpenLibraryException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly ?string $url = null,
        public readonly string $rawBody = '',
    ) {
        $parts = array_filter([$message, $url !== null ? "from {$url}" : '']);
        parent::__construct($parts === [] ? "HTTP {$status} from Open Library" : implode(' ', $parts));
    }

    public function isClientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    public function isServerError(): bool
    {
        return $this->status >= 500;
    }
}
