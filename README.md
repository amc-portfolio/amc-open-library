# Open Library PHP Client

An object-oriented PHP client for Open Library's `/search.json` API. It provides fluent search queries, typed book and author models, pagination helpers, request throttling, and retries for transient failures.

## Requirements

- PHP 8.1 or newer
- PHP extensions: `curl` and `json`

## Installation

After the package is published to Packagist, install it with Composer:

```bash
composer require amc/openlibrary-php
```

To work from a source checkout, install the project dependencies instead:

```bash
composer install
```

## Quick start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use OpenLibrary\Client;
use OpenLibrary\Config;
use OpenLibrary\Search\SearchQuery;

$client = new Client(new Config(
    userAgent: 'MyLibraryApp',
    email: 'contact@example.org',
));

$results = $client->search(
    SearchQuery::query('Pride and Prejudice')
        ->author('Jane Austen')
        ->page(1)
        ->pageSize(10)
);

echo "{$results->total()} matching works\n";

foreach ($results as $book) {
    $author = $book->firstAuthor()?->name() ?? 'Unknown author';
    echo $book->title() . " — {$author}\n";
    echo $book->url() . "\n";
}
```

Providing a contact email adds it to the User-Agent as `MyLibraryApp (contact@example.org)` and enables the identified-client request rate.

## Search queries

`SearchQuery` is a fluent builder. It supports `q`, `title`, `author`, `authorKey`, `isbn`, `firstPublishYear`, `publisher`, `subject`, `language`, `sort`, `fields`, `page`, `pageSize`, and `limit`.

Use `extra()` for other Open Library search parameters:

```php
$query = SearchQuery::query('Austen')
    ->extra('lccn', 'PR4665 .A87')
    ->extra('oclc', 123456);
```

Empty string and `null` values are omitted when parameters are serialized.

### Search by ISBN

```php
$results = $client->searchByIsbn('9780679783268');
$book = $results->firstBook();

echo $book?->title() ?? 'No match found';
```

## Results and models

`SearchResult` implements `Countable` and `IteratorAggregate`. It exposes:

- `total()`, `page()`, and `pageSize()`
- `books()` and `firstBook()`
- `isLastPage()`
- `raw()` for the original response data

Each result is a `Book`. It exposes fields such as `title()`, `key()`, `url()`, `authors()`, `firstAuthor()`, `firstPublishYear()`, `publishers()`, `isbns()`, `editionCount()`, `languages()`, and `subjects()`.

If a cover is available, `coverUrl()` returns its medium-size URL by default. Supported sizes are `S`, `M`, `L`, and `B`:

```php
$cover = $book->coverUrl('L');
```

The `Author` model exposes `name()`, `key()`, `url()`, `birthYear()`, `deathYear()`, and `aliases()`. Missing or sparse search fields are handled with `null` or empty arrays rather than exceptions.

## Configuration and request behavior

`Config` accepts these named arguments:

```php
$config = new Config(
    baseUrl: 'https://openlibrary.org',
    userAgent: 'MyLibraryApp',
    email: 'contact@example.org',
    timeoutSeconds: 10,
    maxRetries: 3,
    baseRetryDelayMs: 500,
);
```

The client throttles requests to approximately 1 request per second without a contact email and 3 per second when identified. It retries HTTP 429, HTTP 5xx, and network failures up to `maxRetries`; 429 responses honor a numeric `Retry-After` value when present. Other 4xx responses are not retried.

The default transport uses cURL. `TransportInterface` can be implemented to use a different HTTP stack or provide a test double.

## Exceptions

All library exceptions extend `OpenLibraryException`:

- `ApiException` reports HTTP/API errors and exposes `status`, `url`, and `rawBody`.
- `RateLimitException` extends `ApiException` and exposes `retryAfterSeconds` after retries are exhausted.
- `NetworkException` reports transport-level failures and exposes `curlError`.

For example:

```php
use OpenLibrary\Exception\ApiException;
use OpenLibrary\Exception\NetworkException;
use OpenLibrary\Exception\RateLimitException;

try {
    $results = $client->search(SearchQuery::query('Austen'));
} catch (RateLimitException $e) {
    // Optionally schedule a later attempt using $e->retryAfterSeconds.
} catch (NetworkException $e) {
    // Handle a connection or timeout failure.
} catch (ApiException $e) {
    // Inspect $e->status and $e->rawBody.
}
```

## Tests

Install the development dependencies and run the PHPUnit suite:

```bash
composer install
composer test
```

The suite uses fake HTTP responses and does not make live API calls. An optional live smoke test is available separately:

```bash
composer smoke
```

## License

MIT
