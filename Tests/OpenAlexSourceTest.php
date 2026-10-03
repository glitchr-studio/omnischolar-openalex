<?php

namespace Omnischolar\OpenAlex\Tests;

use Omnischolar\Exception\NotSupportedException;
use Omnischolar\Exception\RateLimitedException;
use Omnischolar\Exception\UnavailableException;
use Omnischolar\Model\Affiliation;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Scheme;
use Omnischolar\Model\Work;
use Omnischolar\Model\WorkType;
use Omnischolar\OpenAlex\OpenAlexSource;
use Omnischolar\OpenAlex\OpenAlexSourceFactory;
use Omnischolar\Source\Capability;
use Omnischolar\Source\Query;
use Omnischolar\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from api.openalex.org on 2026-10-04 (Keitaro Nakatani,
 * A5108007452): author.json (authors/A5108007452), works-2024.json
 * (works?filter=author.id:A5108007452,publication_year:2024-2024,is_paratext:false),
 * works-page1.json and works-page2.json (the first two pages of 25, newest
 * first, by cursor), work-doi.json (works/doi:10.1039/d4sc04973j).
 */
final class OpenAlexSourceTest extends TestCase
{
    private const NEXT = 'IlsxNDUzOTM5MjAwMDAwLCA5OC4wLCAyNCwgJ2h0dHBzOi8vb3BlbmFsZXgub3JnL1c0MjM1MTg3MzUwJ10i';

    /** @var list<array{string, array<string, string>}> */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = ['mailto' => 'support@glitchr.io']): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = [$path, $query];
            if ($response) {
                return $response;
            }
            $filter = $query['filter'] ?? '';
            $fixture = match (true) {
                '/authors/A5108007452' === $path, '/authors/orcid:0009-0005-1387-7295' === $path => 'author.json',
                '/works/doi:10.1039/d4sc04973j' === $path => 'work-doi.json',
                '/works' === $path && str_contains($filter, 'publication_year:2024-2024') => 'works-2024.json',
                '/works' === $path && str_contains($filter, 'author.id:A5108007452') && '*' === ($query['cursor'] ?? null) => 'works-page1.json',
                '/works' === $path && self::NEXT === ($query['cursor'] ?? null) => 'works-page2.json',
                default => null,
            };

            return $fixture ? new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture)) : new MockResponse('{"error":"Not found"}', ['http_code' => 404]);
        });

        return (new OpenAlexSourceFactory($http))->create($options);
    }

    public function testTheProfileWithItsCountsAndInstitutions(): void
    {
        $author = $this->source()->author('A5108007452');

        self::assertSame('Keitaro Nakatani', $author->name);
        self::assertSame('0009-0005-1387-7295', $author->orcid());
        self::assertSame('A5108007452', $author->identifiers->value(Scheme::OPENALEX));
        self::assertSame(288, $author->metrics->works);
        self::assertSame(51, $author->metrics->hIndex);
        self::assertSame(134, $author->metrics->i10Index);
        self::assertSame(9335, $author->metrics->citations);
        self::assertSame(['works' => 3, 'citations' => 23], $author->metrics->byYear[1988]);
        self::assertContains('Nakatani, K.', $author->alternativeNames);
        self::assertSame('Photochromic and Fluorescence Chemistry', $author->keywords[0]);

        $ens = array_values(array_filter($author->affiliations, static fn (Affiliation $a) => str_contains($a->organization, 'Paris-Saclay') && str_starts_with($a->organization, 'École')))[0];
        self::assertSame(Affiliation::OBSERVED, $ens->kind);
        self::assertTrue($ens->isCurrent(), 'a last known institution');
        self::assertSame('1994', $ens->start);
        self::assertSame('https://ror.org/00hx6zz33', $ens->ror);
        self::assertSame('FR', $author->country);

        self::assertSame('support@glitchr.io', $this->calls[0][1]['mailto'], 'the polite pool');
        self::assertArrayNotHasKey('api_key', $this->calls[0][1], 'no key, none sent');
    }

    public function testAnOrcidNamesTheAuthorToo(): void
    {
        self::assertSame('Keitaro Nakatani', $this->source()->author(Identifier::orcid('0009-0005-1387-7295'))->name);
        self::assertSame('/authors/orcid:0009-0005-1387-7295', $this->calls[0][0]);
    }

    public function testThe2024ArticlesAreThere(): void
    {
        $page = $this->source()->works('A5108007452', new Query(from: 2024, to: 2024));

        self::assertSame(4, $page->total);
        self::assertTrue($page->isLast());
        $venues = array_map(static fn (Work $w) => $w->venue?->name, $page->works);
        self::assertContains('Chemical Science', $venues);
        self::assertContains('ACS Applied Materials & Interfaces', $venues);
        self::assertSame('author.id:A5108007452,publication_year:2024-2024,is_paratext:false', $this->calls[0][1]['filter']);
        self::assertSame('publication_date:desc', $this->calls[0][1]['sort']);

        $acid = array_values(array_filter($page->works, static fn (Work $w) => '10.1039/d4sc04973j' === $w->doi()))[0];
        self::assertSame('Acid-sensitive photoswitches: towards catalytic on-demand release of stored light energy', $acid->title);
        self::assertSame(WorkType::ARTICLE, $acid->type);
        self::assertSame(2024, $acid->year);
        self::assertSame(['2041-6520', '2041-6539'], $acid->venue->issn);
        self::assertSame('15', $acid->volume);
        self::assertSame('39', $acid->issue);
        self::assertSame('16034-16039', $acid->pages);
        self::assertTrue($acid->openAccess);
        self::assertNotNull($acid->identifiers->value(Scheme::OPENALEX));
        self::assertSame('openalex', $acid->source);
        $nakatani = array_values(array_filter($acid->authors, static fn ($c) => 'Nakatani' === $c->familyName()))[0];
        self::assertSame('A5108007452', $nakatani->identifiers->value(Scheme::OPENALEX));
    }

    public function testThePagesFollowTheCursor(): void
    {
        $source = $this->source();
        $first = $source->works('A5108007452', new Query(limit: 25));
        self::assertCount(25, $first);
        self::assertSame(self::NEXT, $first->next);
        self::assertSame('*', $this->calls[0][1]['cursor']);
        self::assertSame('25', $this->calls[0][1]['per-page']);

        $second = $source->works('A5108007452', new Query(limit: 25, cursor: $first->next));
        self::assertCount(25, $second);
        self::assertSame(self::NEXT, $this->calls[1][1]['cursor']);
        self::assertNotSame($first->works[0]->title, $second->works[0]->title);

        $all = [...$first->works, ...$second->works];
        self::assertNotEmpty(array_filter($all, static fn (Work $w) => WorkType::CHAPTER === $w->type), 'book chapters');
        self::assertNotEmpty(array_filter($all, static fn (Work $w) => null !== $w->abstract), 'abstracts rebuilt from the inverted index');
    }

    public function testAWorkByItsDoi(): void
    {
        $work = $this->source()->work('https://doi.org/10.1039/D4SC04973J');

        self::assertSame('10.1039/d4sc04973j', $work->doi());
        self::assertSame('Chemical Science', $work->venue->name);
        self::assertSame('/works/doi:10.1039/d4sc04973j', $this->calls[0][0]);
        self::assertNull($this->source()->work('10.1039/unknown'), 'a 404 is unknown');
    }

    public function testAbstractsAreRebuilt(): void
    {
        self::assertSame('Light switches it on', OpenAlexSource::abstract(['Light' => [0], 'switches' => [1], 'it' => [2], 'on' => [3]]));
        self::assertNull(OpenAlexSource::abstract(null));
    }

    public function testWhatItCannotDo(): void
    {
        self::assertContains(Capability::METRICS, $this->source()->capabilities());
        $this->expectException(NotSupportedException::class);
        $this->source()->works('Keitaro Nakatani');
    }

    public function testDomainsAreRefused(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->source()->search(new Query(text: 'photochromism', domains: ['shs.droit']));
    }

    public function testTheKeyAndTheSearch(): void
    {
        $this->source(new MockResponse('{"meta":{"count":0},"results":[]}'), ['api_key' => 'k3y'])->search(new Query(text: 'photochromic', author: 'Nakatani', types: [WorkType::ARTICLE], sort: Query::RELEVANCE));
        [, $query] = $this->calls[0];
        self::assertSame('k3y', $query['api_key']);
        self::assertSame('photochromic', $query['search']);
        self::assertSame('relevance_score:desc', $query['sort']);
        self::assertSame('raw_author_name.search:Nakatani,type:article|review|letter,is_paratext:false', $query['filter']);
    }

    public function testErrors(): void
    {
        try {
            $this->source(new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['retry-after' => '30']]))->author('A5108007452');
            self::fail();
        } catch (RateLimitedException $e) {
            self::assertSame(30, $e->retryAfter);
        }
        $this->expectException(UnavailableException::class);
        $this->source(new MockResponse('', ['http_code' => 503]))->author('A5108007452');
    }
}
