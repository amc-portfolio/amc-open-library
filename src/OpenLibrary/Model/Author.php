<?php

declare(strict_types=1);

namespace OpenLibrary\Model;

/**
 * An author document (as nested in search results, or from the Authors API).
 */
final class Author
{
    /**
     * @param array<string, mixed> $doc
     */
    public function __construct(private array $doc)
    {
    }

    /**
     * @param array<string, mixed> $doc
     */
    public static function fromDoc(array $doc): self
    {
        return new self($doc);
    }

    public function name(): ?string
    {
        $n = $this->doc['name'] ?? null;

        return is_string($n) ? $n : null;
    }

    public function key(): ?string
    {
        $k = $this->doc['key'] ?? null;

        return is_string($k) ? $k : null;
    }

    public function url(): ?string
    {
        return $this->key() !== null ? 'https://openlibrary.org/' . ltrim($this->key(), '/') : null;
    }

    public function birthYear(): ?int
    {
        $y = $this->doc['birth_year'] ?? null;

        return is_int($y) ? $y : null;
    }

    public function deathYear(): ?int
    {
        $y = $this->doc['death_year'] ?? null;

        return is_int($y) ? $y : null;
    }

    /**
     * @return string[]
     */
    public function aliases(): array
    {
        $a = $this->doc['aliases'] ?? [];

        return is_array($a) ? array_map('strval', $a) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->doc;
    }
}
