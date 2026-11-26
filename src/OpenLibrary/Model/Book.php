<?php

declare(strict_types=1);

namespace OpenLibrary\Model;

/**
 * A search result document (a "work"/edition aggregate) from /search.json.
 *
 * Unknown/absent fields return null or [] rather than throwing — the API's
 * documents are sparse.
 */
final class Book
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

    public function title(): ?string
    {
        $t = $this->doc['title'] ?? null;

        return is_string($t) ? $t : null;
    }

    /**
     * The Open Library key, e.g. "works/OL15626917W".
     */
    public function key(): ?string
    {
        $k = $this->doc['key'] ?? null;

        return is_string($k) ? $k : null;
    }

    /**
     * https URL of the record on openlibrary.org, or null.
     */
    public function url(): ?string
    {
        return $this->key() !== null ? 'https://openlibrary.org/' . ltrim($this->key(), '/') : null;
    }

    /**
     * @return Author[]
     */
    public function authors(): array
    {
        $authors = $this->doc['authors'] ?? [];

        return is_array($authors)
            ? array_map(
                static fn ($a): Author => Author::fromDoc(is_array($a) ? $a : []),
                $authors,
            )
            : [];
    }

    public function firstAuthor(): ?Author
    {
        return $this->authors()[0] ?? null;
    }

    public function firstPublishYear(): ?int
    {
        $y = $this->doc['first_publish_year'] ?? null;

        return is_int($y) ? $y : null;
    }

    /**
     * @return string[]
     */
    public function publishers(): array
    {
        return $this->stringList('publisher');
    }

    /**
     * @return string[]
     */
    public function isbns(): array
    {
        $isbns = $this->doc['isbn'] ?? [];

        return is_array($isbns) ? array_map('strval', $isbns) : [];
    }

    public function editionCount(): int
    {
        return (int) ($this->doc['edition_count'] ?? 0);
    }

    public function coverId(): ?int
    {
        $id = $this->doc['cover_i'] ?? null;

        return is_int($id) ? $id : null;
    }

    /**
     * Cover image URL, or null when the record has no cover.
     *
     * $size: S (60x90), M (180x270), L (600x900), B (1200x1800).
     */
    public function coverUrl(string $size = 'M'): ?string
    {
        $size = strtoupper($size);
        if (!in_array($size, ['S', 'M', 'L', 'B'], true) || $this->coverId() === null) {
            return null;
        }

        return "https://covers.openlibrary.org/b/id/{$this->coverId()}-{$size}.jpg";
    }

    /**
     * @return string[]
     */
    public function languages(): array
    {
        return $this->stringList('language');
    }

    /**
     * @return string[]
     */
    public function subjects(): array
    {
        return $this->stringList('subject');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->doc;
    }

    /**
     * @return string[]
     */
    private function stringList(string $key): array
    {
        $value = $this->doc[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $value),
            static fn (string $s): bool => $s !== '',
        ));
    }
}
