<?php

declare(strict_types=1);

namespace OpenLibrary\Search;

use OpenLibrary\Exception\ApiException;
use OpenLibrary\Model\Book;

/**
 * One page of /search.json results, mapped to typed Book objects.
 */
final class SearchResult implements \Countable, \IteratorAggregate
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromSearchJson(array $data): self
    {
        if (!isset($data['docs']) || !is_array($data['docs'])) {
            throw new ApiException(200, 'Unexpected response: missing "docs" in search result');
        }

        return new self($data);
    }

    /**
     * Total number of documents matched (not just this page).
     */
    public function total(): int
    {
        return (int) ($this->data['numFound'] ?? 0);
    }

    public function page(): int
    {
        return (int) ($this->data['page'] ?? 1);
    }

    public function pageSize(): int
    {
        return (int) ($this->data['page_size'] ?? 0);
    }

    /**
     * @return Book[]
     */
    public function books(): array
    {
        return array_map(
            static fn (array $doc): Book => Book::fromDoc($doc),
            array_map(
                static fn ($doc): array => is_array($doc) ? $doc : [],
                $this->data['docs'],
            ),
        );
    }

    public function firstBook(): ?Book
    {
        return $this->books()[0] ?? null;
    }

    public function isLastPage(): bool
    {
        $total = $this->total();
        $size = $this->pageSize();

        return $total === 0 || $this->page() * $size >= $total;
    }

    public function count(): int
    {
        return count($this->data['docs'] ?? []);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->books());
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->data;
    }
}
