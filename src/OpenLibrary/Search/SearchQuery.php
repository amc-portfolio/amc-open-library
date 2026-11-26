<?php

declare(strict_types=1);

namespace OpenLibrary\Search;

/**
 * Fluent builder for /search.json parameters.
 *
 * Only non-empty values are serialized. Anything not covered by the fluent
 * setters can still be passed via extra() (or the constructor) in case the
 * API grows parameters.
 */
final class SearchQuery
{
    private array $params = [];

    public function __construct(array $params = [])
    {
        foreach ($params as $key => $value) {
            $this->set($key, $value);
        }
    }

    public static function query(string $q): self
    {
        return (new self())->q($q);
    }

    public function q(string $value): self
    {
        return $this->set('q', $value);
    }

    public function title(string $value): self
    {
        return $this->set('title', $value);
    }

    public function author(string $value): self
    {
        return $this->set('author', $value);
    }

    public function authorKey(string $value): self
    {
        return $this->set('author_key', $value);
    }

    public function isbn(string $value): self
    {
        return $this->set('isbn', $value);
    }

    public function firstPublishYear(string $value): self
    {
        return $this->set('first_publish_year', $value);
    }

    public function publisher(string $value): self
    {
        return $this->set('publisher', $value);
    }

    public function subject(string $value): self
    {
        return $this->set('subject', $value);
    }

    public function language(string $value): self
    {
        return $this->set('language', $value);
    }

    public function sort(string $value): self
    {
        return $this->set('sort', $value);
    }

    public function fields(string $value): self
    {
        return $this->set('fields', $value);
    }

    public function page(int $page): self
    {
        return $this->set('page', $page);
    }

    public function pageSize(int $size): self
    {
        return $this->set('page_size', $size);
    }

    public function limit(int $limit): self
    {
        return $this->set('limit', $limit);
    }

    /**
     * Set any raw /search.json parameter (e.g. 'lccn', 'oclc', 'type').
     */
    public function extra(string $key, string|int|float $value): self
    {
        return $this->set($key, (string) $value);
    }

    /**
     * @return array<string, string|int>
     */
    public function toParams(): array
    {
        return array_filter(
            $this->params,
            static fn ($v) => $v !== '' && $v !== null,
        );
    }

    private function set(string $key, string|int $value): self
    {
        $this->params[$key] = $value;

        return $this;
    }
}
