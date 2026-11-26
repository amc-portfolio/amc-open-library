<?php

declare(strict_types=1);

namespace OpenLibrary\Tests;

use OpenLibrary\Client;
use OpenLibrary\Config;
use OpenLibrary\Exception\ApiException;
use OpenLibrary\Exception\NetworkException;
use OpenLibrary\Exception\RateLimitException;
use OpenLibrary\Http\Response;
use OpenLibrary\Http\TransportInterface;
use OpenLibrary\Model\Author;
use OpenLibrary\Model\Book;
use OpenLibrary\Search\SearchQuery;
use OpenLibrary\Search\SearchResult;
use PHPUnit\Framework\TestCase;

/** Queues transport responses and records every request. */
final class FakeTransport implements TransportInterface
{
    /** @var list<Response|\Throwable> */
    private array $queue;

    /** @var list<array{method: string, url: string, headers: list<string>}> */
    public array $requests = [];

    public function __construct(Response|\Throwable ...$queue)
    {
        $this->queue = array_values($queue);
    }

    public function request(string $method, string $url, array $headers = []): Response
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
        if ($this->queue === []) {
            throw new \RuntimeException('FakeTransport queue exhausted');
        }

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}

/** Records sleep calls without waiting. */
final class SleepTracker
{
    /** @var list<int> */
    public array $calls = [];

    public function __invoke(int $milliseconds): void
    {
        $this->calls[] = $milliseconds;
    }
}

final class ClientTest extends TestCase
{
    public function testSuccessfulSearchReturnsTypedBooks(): void
    {
        $transport = new FakeTransport(self::searchResponse([self::sampleDoc()]));
        $client = new Client(new Config(), $transport, new SleepTracker());

        $result = $client->search(SearchQuery::query('pride and prejudice')->limit(5));

        self::assertCount(1, $result);
        self::assertSame('Pride and Prejudice', $result->firstBook()?->title());
        self::assertSame('Jane Austen', $result->firstBook()?->firstAuthor()?->name());
        self::assertSame('GET', $transport->requests[0]['method']);
        self::assertStringStartsWith('https://openlibrary.org/search.json?', $transport->requests[0]['url']);
    }

    public function testQueryParametersAreSerialized(): void
    {
        $transport = new FakeTransport(self::searchResponse([self::sampleDoc()]));
        $client = new Client(new Config(), $transport, new SleepTracker());

        $client->search(SearchQuery::query('austen')->author('Jane Austen')->page(2)->pageSize(25));

        parse_str((string) parse_url($transport->requests[0]['url'], PHP_URL_QUERY), $params);
        self::assertSame('austen', $params['q']);
        self::assertSame('Jane Austen', $params['author']);
        self::assertSame(2, (int) $params['page']);
        self::assertSame(25, (int) $params['page_size']);
    }

    public function testIdentifiedClientIncludesContactEmailInUserAgent(): void
    {
        $transport = new FakeTransport(self::searchResponse([self::sampleDoc()]));
        $client = new Client(
            new Config(userAgent: 'MyLibraryApp', email: 'contact@example.org'),
            $transport,
            new SleepTracker(),
        );

        $client->search(SearchQuery::query('x'));

        self::assertSame('MyLibraryApp (contact@example.org)', self::userAgent($transport));
    }

    public function testUnidentifiedClientUsesDefaultUserAgentWithoutEmail(): void
    {
        $transport = new FakeTransport(self::searchResponse([self::sampleDoc()]));
        $client = new Client(new Config(), $transport, new SleepTracker());

        $client->search(SearchQuery::query('x'));

        self::assertSame('openlibrary-php/1.0.0', self::userAgent($transport));
    }

    public function test429HonorsRetryAfterBeforeRetrying(): void
    {
        $sleep = new SleepTracker();
        $transport = new FakeTransport(
            new Response(429, '', ['Retry-After' => '2']),
            self::searchResponse([self::sampleDoc()]),
        );
        $client = new Client(new Config(email: 'me@example.org'), $transport, $sleep);

        $result = $client->search(SearchQuery::query('x'));

        self::assertCount(1, $result);
        self::assertCount(2, $transport->requests);
        self::assertCount(2, $sleep->calls);
        self::assertGreaterThanOrEqual(2000, $sleep->calls[0], 'Retry-After delay');
        self::assertLessThanOrEqual(350, $sleep->calls[1], 'retry throttle delay');
    }

    public function test429WithoutRetryAfterUsesExponentialBackoff(): void
    {
        $sleep = new SleepTracker();
        $transport = new FakeTransport(
            new Response(429, self::jsonBody(['error' => 'too many requests'])),
            self::searchResponse([self::sampleDoc()]),
        );
        $client = new Client(new Config(maxRetries: 2), $transport, $sleep);

        $client->search(SearchQuery::query('x'));

        self::assertGreaterThanOrEqual(500, $sleep->calls[0]);
        self::assertLessThanOrEqual(750, $sleep->calls[0]);
    }

    public function test429ExhaustingRetriesThrowsRateLimitException(): void
    {
        $transport = new FakeTransport(
            new Response(429, '', ['Retry-After' => '5']),
            new Response(429, '', ['Retry-After' => '5']),
            new Response(429, '', ['Retry-After' => '5']),
        );
        $client = new Client(new Config(maxRetries: 1), $transport, new SleepTracker());

        $exception = $this->assertThrows(
            RateLimitException::class,
            fn () => $client->search(SearchQuery::query('x')),
        );

        self::assertSame(429, $exception->status);
        self::assertSame(5, $exception->retryAfterSeconds);
        self::assertCount(2, $transport->requests, 'initial request plus one retry');
    }

    public function test5xxIsRetriedWithExponentialBackoffThenSucceeds(): void
    {
        $sleep = new SleepTracker();
        $transport = new FakeTransport(
            new Response(500, 'oops'),
            new Response(503, 'unavailable'),
            self::searchResponse([self::sampleDoc()]),
        );
        $client = new Client(new Config(maxRetries: 3), $transport, $sleep);

        $result = $client->search(SearchQuery::query('x'));

        self::assertCount(1, $result);
        self::assertCount(3, $transport->requests);
        self::assertGreaterThanOrEqual(500, $sleep->calls[0]);
        self::assertLessThanOrEqual(750, $sleep->calls[0]);
        self::assertGreaterThanOrEqual(1000, $sleep->calls[2]);
        self::assertLessThanOrEqual(1250, $sleep->calls[2]);
    }

    public function test5xxExhaustingRetriesThrowsApiException(): void
    {
        $transport = new FakeTransport(new Response(500, 'boom'), new Response(500, 'boom'));
        $client = new Client(new Config(maxRetries: 1), $transport, new SleepTracker());

        $exception = $this->assertThrows(
            ApiException::class,
            fn () => $client->search(SearchQuery::query('x')),
        );

        self::assertSame(500, $exception->status);
        self::assertTrue($exception->isServerError());
    }

    public function testPermanent404IsNotRetriedAndIncludesApiError(): void
    {
        $transport = new FakeTransport(new Response(404, self::jsonBody(['error' => 'Not Found'])));
        $client = new Client(new Config(maxRetries: 3), $transport, new SleepTracker());

        $exception = $this->assertThrows(
            ApiException::class,
            fn () => $client->search(SearchQuery::query('x')),
        );

        self::assertSame(404, $exception->status);
        self::assertStringContainsString('Not Found', $exception->getMessage());
        self::assertCount(1, $transport->requests);
        self::assertTrue($exception->isClientError());
    }

    public function testNetworkFailureIsRetriedThenSucceeds(): void
    {
        $transport = new FakeTransport(
            new NetworkException('cURL error 28: Operation timed out', 28),
            self::searchResponse([self::sampleDoc()]),
        );
        $client = new Client(new Config(maxRetries: 2), $transport, new SleepTracker());

        $result = $client->search(SearchQuery::query('x'));

        self::assertCount(1, $result);
        self::assertCount(2, $transport->requests);
    }

    public function testNetworkFailureExhaustingRetriesThrowsWithCurlError(): void
    {
        $transport = new FakeTransport(
            new NetworkException('cURL error 6: Could not resolve host', 6),
            new NetworkException('cURL error 6: Could not resolve host', 6),
        );
        $client = new Client(new Config(maxRetries: 1), $transport, new SleepTracker());

        $exception = $this->assertThrows(
            NetworkException::class,
            fn () => $client->search(SearchQuery::query('x')),
        );

        self::assertSame(6, $exception->curlError);
        self::assertCount(2, $transport->requests);
    }

    public function testInvalidJsonResponseThrowsApiException(): void
    {
        $transport = new FakeTransport(new Response(200, '<html>weird</html>'));
        $client = new Client(new Config(), $transport, new SleepTracker());

        $exception = $this->assertThrows(
            ApiException::class,
            fn () => $client->search(SearchQuery::query('x')),
        );

        self::assertStringContainsString('not valid JSON', $exception->getMessage());
    }

    public function testUnidentifiedClientThrottlesAtOneRequestPerSecond(): void
    {
        $sleep = new SleepTracker();
        $transport = new FakeTransport(
            self::searchResponse([self::sampleDoc()]),
            self::searchResponse([self::sampleDoc()]),
        );
        $client = new Client(new Config(), $transport, $sleep);

        $client->search(SearchQuery::query('a'));
        $client->search(SearchQuery::query('b'));

        self::assertCount(1, $sleep->calls);
        self::assertGreaterThanOrEqual(1040, $sleep->calls[0]);
        self::assertLessThanOrEqual(1050, $sleep->calls[0]);
    }

    public function testIdentifiedClientThrottlesAtThreeRequestsPerSecond(): void
    {
        $sleep = new SleepTracker();
        $transport = new FakeTransport(
            self::searchResponse([self::sampleDoc()]),
            self::searchResponse([self::sampleDoc()]),
        );
        $client = new Client(new Config(email: 'me@example.org'), $transport, $sleep);

        $client->search(SearchQuery::query('a'));
        $client->search(SearchQuery::query('b'));

        self::assertCount(1, $sleep->calls);
        self::assertGreaterThanOrEqual(340, $sleep->calls[0]);
        self::assertLessThanOrEqual(350, $sleep->calls[0]);
    }

    public function testBookMapsFieldsAndBuildsCoverUrls(): void
    {
        $book = Book::fromDoc(self::sampleDoc());

        self::assertSame('Pride and Prejudice', $book->title());
        self::assertSame('works/OL15626917W', $book->key());
        self::assertSame('https://openlibrary.org/works/OL15626917W', $book->url());
        self::assertSame('https://covers.openlibrary.org/b/id/1234567-M.jpg', $book->coverUrl());
        self::assertSame('https://covers.openlibrary.org/b/id/1234567-L.jpg', $book->coverUrl('L'));
        self::assertSame(1813, $book->firstPublishYear());
        self::assertSame(['Scribner'], $book->publishers());
        self::assertCount(2, $book->isbns());
        self::assertSame(120, $book->editionCount());
    }

    public function testBookToleratesSparseSearchDocuments(): void
    {
        $book = Book::fromDoc(['title' => 'Mystery']);

        self::assertSame('Mystery', $book->title());
        self::assertNull($book->firstAuthor());
        self::assertNull($book->coverUrl());
        self::assertSame(0, $book->editionCount());
        self::assertSame([], $book->isbns());
    }

    public function testAuthorMapsFields(): void
    {
        $author = Author::fromDoc([
            'key' => '/authors/OL236414A',
            'name' => 'Jane Austen',
            'birth_year' => 1775,
            'death_year' => 1817,
            'aliases' => ['J. Austen'],
        ]);

        self::assertSame('Jane Austen', $author->name());
        self::assertSame('https://openlibrary.org/authors/OL236414A', $author->url());
        self::assertSame(1775, $author->birthYear());
        self::assertSame(1817, $author->deathYear());
        self::assertSame(['J. Austen'], $author->aliases());
    }

    public function testSearchResultPaginationAndIteration(): void
    {
        $result = SearchResult::fromSearchJson([
            'numFound' => 120,
            'page' => 2,
            'page_size' => 50,
            'docs' => [self::sampleDoc('A'), self::sampleDoc('B')],
        ]);

        self::assertSame(120, $result->total());
        self::assertSame(2, $result->page());
        self::assertSame(50, $result->pageSize());
        self::assertFalse($result->isLastPage());
        self::assertCount(2, $result);
        self::assertSame(['A', 'B'], array_map(static fn (Book $book): ?string => $book->title(), iterator_to_array($result)));
    }

    public function testSearchResultIsLastPageWhenPageCoversTotal(): void
    {
        $result = SearchResult::fromSearchJson([
            'numFound' => 100,
            'page' => 2,
            'page_size' => 50,
            'docs' => [self::sampleDoc()],
        ]);

        self::assertTrue($result->isLastPage());
    }

    public function testSearchResultRejectsResponseWithoutDocs(): void
    {
        $this->assertThrows(
            ApiException::class,
            static fn () => SearchResult::fromSearchJson(['error' => 'weird']),
        );
    }

    public function testSearchQueryDropsEmptyValuesAndPreservesExtraParameters(): void
    {
        $params = (new SearchQuery())
            ->q('x')
            ->author('')
            ->isbn('9780679783268')
            ->extra('lccn', 'PR4665 .A87')
            ->toParams();

        self::assertSame('x', $params['q']);
        self::assertSame('9780679783268', $params['isbn']);
        self::assertSame('PR4665 .A87', $params['lccn']);
        self::assertArrayNotHasKey('author', $params);
    }

    public function testSearchByIsbnBuildsIsbnQuery(): void
    {
        $transport = new FakeTransport(self::searchResponse([self::sampleDoc()]));
        $client = new Client(new Config(), $transport, new SleepTracker());

        $client->searchByIsbn('9780679783268');

        parse_str((string) parse_url($transport->requests[0]['url'], PHP_URL_QUERY), $params);
        self::assertSame('9780679783268', $params['isbn']);
    }

    private function assertThrows(string $class, callable $callback): \Throwable
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            self::assertInstanceOf($class, $exception);
            return $exception;
        }

        self::fail("Expected exception of type {$class}");
    }

    private static function userAgent(FakeTransport $transport): ?string
    {
        foreach ($transport->requests[0]['headers'] as $header) {
            if (stripos($header, 'User-Agent:') === 0) {
                return trim(substr($header, strlen('User-Agent:')));
            }
        }

        return null;
    }

    private static function jsonBody(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    private static function searchResponse(array $docs, int $numFound = 0, int $page = 1, int $pageSize = 0): Response
    {
        return new Response(200, self::jsonBody([
            'numFound' => $numFound !== 0 ? count($docs) : $numFound,
            'page' => $page,
            'page_size' => $pageSize !== 0 ? count($docs) : $pageSize,
            'docs' => $docs,
        ]));
    }

    private static function sampleDoc(string $title = 'Pride and Prejudice'): array
    {
        return [
            'key' => 'works/OL15626917W',
            'type' => '/type/work',
            'title' => $title,
            'authors' => [
                ['key' => '/authors/OL236414A', 'name' => 'Jane Austen', 'birth_year' => 1775, 'death_year' => 1817],
            ],
            'first_publish_year' => 1813,
            'publisher' => ['Scribner'],
            'isbn' => ['0679783261', '9780679783268'],
            'cover_i' => 1234567,
            'edition_count' => 120,
            'language' => ['eng'],
            'subject' => ['English fiction'],
        ];
    }
}
