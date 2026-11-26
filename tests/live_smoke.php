<?php

declare(strict_types=1);

/**
 * Live smoke test against the real Open Library API (no API key needed).
 * Run: php tests/live_smoke.php
 *
 * The client's built-in throttler spaces these ~3 requests out to stay
 * under the 1 req/s limit for un-identified traffic.
 */

require __DIR__ . '/../autoload.php';

use OpenLibrary\Client;
use OpenLibrary\Config;
use OpenLibrary\Exception\OpenLibraryException;
use OpenLibrary\Search\SearchQuery;

function check(bool $cond, string $label): void
{
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$cond) {
        exit(1);
    }
}

try {
    $client = new Client(new Config(userAgent: 'openlibrary-php-smoke-test'));

    // 1. Free-text search -----------------------------------------------------
    $result = $client->search(
        SearchQuery::query('pride and prejudice')->limit(3)
    );

    echo "Search 'pride and prejudice' — {$result->total()} total matches\n";
    check($result->total() > 0, 'returns matches');
    check(count($result) === 3, 'respects limit=3');
    foreach ($result as $i => $book) {
        $author = $book->firstAuthor()?->name() ?? '?';
        echo "  " . ($i + 1) . ". {$book->title()} — {$author} (" . ($book->firstPublishYear() ?? '?') . ")\n";
        echo '     ' . $book->url() . ' | cover: ' . ($book->coverUrl('S') ?? 'none') . "\n";
    }

    // 2. Pagination ------------------------------------------------------------
    $page2 = $client->search(
        SearchQuery::query('pride and prejudice')->limit(3)->page(2)
    );
    check($page2->page() === 2, 'page 2 requested');
    check(!$page2->isLastPage(), 'page 2 is not the last page (total=' . $page2->total() . ')');

    // 3. ISBN lookup -------------------------------------------------------------
    $isbnResult = $client->searchByIsbn('9780679783268');
    $found = $isbnResult->firstBook();
    echo "ISBN 9780679783268 — " . ($isbnResult->total() > 0
        ? $found?->title() . ' (editions: ' . $found?->editionCount() . ')'
        : 'no matches') . "\n";
    check($isbnResult->total() >= 0, 'isbn search completes');

    // 4. Author search -----------------------------------------------------------
    $authorResult = $client->search(
        SearchQuery::query('austen')->author('Jane Austen')->limit(2)
    );
    check($authorResult->total() > 0, "author search returns {$authorResult->total()} matches");

    echo "\nAll live checks passed.\n";
} catch (OpenLibraryException $e) {
    echo '  FAIL  ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    if ($e instanceof \OpenLibrary\Exception\ApiException) {
        echo '        status: ' . $e->status . ' url: ' . $e->url . "\n";
    }
    exit(1);
}
