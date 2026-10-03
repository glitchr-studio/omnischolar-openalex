<?php

namespace Omnischolar\OpenAlex;

use Omnischolar\Exception\NotSupportedException;
use Omnischolar\Model\Affiliation;
use Omnischolar\Model\Author;
use Omnischolar\Model\Contributor;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Identifiers;
use Omnischolar\Model\Metrics;
use Omnischolar\Model\Scheme;
use Omnischolar\Model\Venue;
use Omnischolar\Model\Work;
use Omnischolar\Model\WorkType;
use Omnischolar\Source\Capability;
use Omnischolar\Source\HttpSource;
use Omnischolar\Source\Page;
use Omnischolar\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenAlex (api.openalex.org): an author's profile with their counts
 * (works, citations, h-index, i10-index, by year) and the institutions seen
 * on their works; their works with abstracts, citations and open-access
 * links; any work by its OpenAlex id, DOI or PubMed id; a search.
 *
 * Free without a key within a daily budget; a free key (api_key) raises it
 * tenfold. The mailto is sent as OpenAlex asks of polite callers.
 */
final class OpenAlexSource extends HttpSource
{
    public const BASE_URI = 'https://api.openalex.org/';

    /** The most per page OpenAlex serves. */
    public const MAX_PER_PAGE = 100;

    /** The fields a work is read from: everything else is left on the server. */
    private const WORK_FIELDS = 'id,doi,title,display_name,publication_year,publication_date,type,language,primary_location,best_oa_location,open_access,authorships,biblio,ids,cited_by_count,keywords,abstract_inverted_index,is_paratext';

    /** OpenAlex's work types for each kind. */
    private const TYPES = [
        'article' => ['article', 'review', 'letter'],
        'book' => ['book'],
        'chapter' => ['book-chapter', 'reference-entry'],
        'conference' => ['conference-paper'],
        'preprint' => ['preprint'],
        'thesis' => ['dissertation'],
        'report' => ['report', 'standard'],
        'dataset' => ['dataset', 'supplementary-materials'],
        'software' => ['software'],
        'review' => ['peer-review'],
        'editorial' => ['editorial', 'erratum', 'retraction', 'paratext'],
        'other' => ['other', 'libguides'],
    ];

    public function __construct(
        HttpClientInterface $http,
        private readonly ?string $mailto = null,
        private readonly ?string $apiKey = null,
        private readonly bool $paratext = false,
        string $baseUri = self::BASE_URI,
        array $headers = [],
    ) {
        parent::__construct($http, $baseUri, $headers);
    }

    public function getName(): string
    {
        return 'openalex';
    }

    public function capabilities(): array
    {
        return [Capability::AUTHOR, Capability::WORKS, Capability::WORK, Capability::SEARCH, Capability::METRICS, Capability::CITATIONS, Capability::ABSTRACTS, Capability::OPEN_ACCESS, Capability::AFFILIATIONS];
    }

    public function author(Identifier|string $author): ?Author
    {
        $data = $this->call('authors/'.$this->authorKey($author));

        return null === $data ? null : $this->toAuthor($data);
    }

    public function works(Identifier|string $author, ?Query $query = null): Page
    {
        $id = self::identify($author, Scheme::OPENALEX, Scheme::ORCID) ?? throw NotSupportedException::identifier('openalex', (string) $author, 'list the works of');
        $filter = Scheme::ORCID === $id->scheme ? 'author.orcid:'.$id->value : 'author.id:'.$id->value;

        return $this->list($query ?? new Query(), [$filter]);
    }

    public function work(Identifier|string $id): ?Work
    {
        $identifier = self::identify($id, Scheme::OPENALEX, Scheme::DOI, Scheme::PMID, Scheme::PMCID) ?? throw NotSupportedException::identifier('openalex', (string) $id);
        $key = match ($identifier->scheme) {
            Scheme::OPENALEX => $identifier->value,
            Scheme::DOI => 'doi:'.$identifier->value,
            Scheme::PMID => 'pmid:'.$identifier->value,
            default => 'pmcid:'.$identifier->value,
        };
        $data = $this->call('works/'.$key, ['select' => self::WORK_FIELDS]);

        return null === $data ? null : $this->toWork($data);
    }

    public function search(Query $query): Page
    {
        $filters = [];
        if (null !== $query->title) {
            $filters[] = 'title.search:'.self::escape($query->title);
        }
        if (null !== $query->author) {
            $filters[] = 'raw_author_name.search:'.self::escape($query->author);
        }

        return $this->list($query, $filters, $query->text);
    }

    /** @param list<string> $filters */
    private function list(Query $query, array $filters, ?string $search = null): Page
    {
        if ($query->domains) {
            throw NotSupportedException::operation('openalex', 'filter by domain');
        }
        if (null !== $query->from || null !== $query->to) {
            $filters[] = 'publication_year:'.match (true) {
                null === $query->to => '>'.($query->from - 1),
                null === $query->from => '<'.($query->to + 1),
                default => $query->from.'-'.$query->to,
            };
        }
        if ($query->types) {
            $types = array_merge(...array_map(static fn (WorkType $t) => self::TYPES[$t->value], $query->types));
            $filters[] = 'type:'.implode('|', array_unique($types));
        }
        if (null !== $query->openAccess) {
            $filters[] = 'open_access.is_oa:'.($query->openAccess ? 'true' : 'false');
        }
        if (!$this->paratext) {
            $filters[] = 'is_paratext:false';
        }
        $sort = match ($query->sort) {
            Query::OLDEST => 'publication_date:asc',
            Query::CITED => 'cited_by_count:desc',
            Query::RELEVANCE => null !== $search ? 'relevance_score:desc' : 'publication_date:desc',
            default => 'publication_date:desc',
        };
        $data = $this->call('works', [
            'filter' => implode(',', $filters),
            'search' => $search,
            'sort' => $sort,
            'per-page' => max(1, min(self::MAX_PER_PAGE, $query->limit)),
            'cursor' => $query->cursor ?? '*',
            'select' => self::WORK_FIELDS,
        ]) ?? [];
        $works = array_map($this->toWork(...), $data['results'] ?? []);

        return new Page($works, $works ? ($data['meta']['next_cursor'] ?? null) : null, $data['meta']['count'] ?? null);
    }

    /** @param array<string, mixed> $query */
    private function call(string $path, array $query = []): ?array
    {
        return $this->getJson($path, $query + ['mailto' => $this->mailto, 'api_key' => $this->apiKey]);
    }

    private function authorKey(Identifier|string $author): string
    {
        $id = self::identify($author, Scheme::OPENALEX, Scheme::ORCID) ?? throw NotSupportedException::identifier('openalex', (string) $author);

        return Scheme::ORCID === $id->scheme ? 'orcid:'.$id->value : $id->value;
    }

    private static function escape(string $value): string
    {
        // A comma separates filters, a pipe ORs values.
        return str_replace([',', '|'], ' ', $value);
    }

    /** @param array<string, mixed> $a */
    private function toAuthor(array $a): Author
    {
        $current = array_map(static fn (array $i) => $i['id'] ?? null, $a['last_known_institutions'] ?? []);
        $affiliations = [];
        foreach ($a['affiliations'] ?? [] as $affiliation) {
            $institution = $affiliation['institution'] ?? [];
            $years = array_map('intval', $affiliation['years'] ?? []);
            sort($years);
            $affiliations[] = new Affiliation(
                organization: (string) ($institution['display_name'] ?? ''),
                kind: Affiliation::OBSERVED,
                start: $years ? (string) $years[0] : null,
                end: $years ? (string) end($years) : null,
                country: $institution['country_code'] ?? null,
                ror: $institution['ror'] ?? null,
                url: $institution['id'] ?? null,
                years: $years,
                current: \in_array($institution['id'] ?? null, $current, true),
            );
        }
        $byYear = [];
        foreach ($a['counts_by_year'] ?? [] as $row) {
            $byYear[(int) $row['year']] = ['works' => $row['works_count'] ?? null, 'citations' => $row['cited_by_count'] ?? null];
        }
        ksort($byYear);
        $ids = $a['ids'] ?? [];

        return new Author(
            name: (string) $a['display_name'],
            identifiers: Identifiers::of(
                Identifier::tryOf(Scheme::OPENALEX, $a['id'] ?? null),
                Identifier::tryOf(Scheme::ORCID, $a['orcid'] ?? $ids['orcid'] ?? null),
            ),
            alternativeNames: array_values(array_diff($a['display_name_alternatives'] ?? [], [$a['display_name']])),
            affiliations: $affiliations,
            metrics: new Metrics(
                works: $a['works_count'] ?? null,
                citations: $a['cited_by_count'] ?? null,
                hIndex: $a['summary_stats']['h_index'] ?? null,
                i10Index: $a['summary_stats']['i10_index'] ?? null,
                twoYearMeanCitedness: isset($a['summary_stats']['2yr_mean_citedness']) ? (float) $a['summary_stats']['2yr_mean_citedness'] : null,
                byYear: $byYear,
                source: 'openalex',
            ),
            urls: array_values(array_filter([$a['id'] ?? null])),
            keywords: array_map(static fn (array $t) => $t['display_name'], \array_slice($a['topics'] ?? [], 0, 10)),
            country: $a['last_known_institutions'][0]['country_code'] ?? null,
            source: 'openalex',
        );
    }

    /** @param array<string, mixed> $w */
    private function toWork(array $w): Work
    {
        $location = $w['primary_location'] ?? null;
        $source = $location['source'] ?? null;
        $best = $w['best_oa_location'] ?? null;
        $type = WorkType::fromName($w['type'] ?? null);
        if (WorkType::ARTICLE === $type && 'conference' === ($source['type'] ?? null)) {
            $type = WorkType::CONFERENCE;
        }

        $authors = [];
        foreach ($w['authorships'] ?? [] as $authorship) {
            $ids = Identifiers::of(
                Identifier::tryOf(Scheme::OPENALEX, $authorship['author']['id'] ?? null),
                Identifier::tryOf(Scheme::ORCID, $authorship['author']['orcid'] ?? null),
            );
            $name = (string) ($authorship['author']['display_name'] ?? $authorship['raw_author_name'] ?? '');
            $raw = (string) ($authorship['raw_author_name'] ?? '');
            $parsed = str_contains($raw, ',') ? Contributor::fromName($raw) : Contributor::fromName($name);
            $authors[] = new Contributor(
                $name,
                $parsed->given,
                $parsed->family,
                $ids,
                Contributor::AUTHOR,
                array_values(array_unique(array_map(static fn (array $i) => (string) $i['display_name'], $authorship['institutions'] ?? []))),
            );
        }

        $ids = $w['ids'] ?? [];
        $identifiers = [
            Identifier::tryOf(Scheme::OPENALEX, $w['id'] ?? null),
            Identifier::tryOf(Scheme::DOI, $w['doi'] ?? $ids['doi'] ?? null),
            Identifier::tryOf(Scheme::PMID, isset($ids['pmid']) ? basename((string) $ids['pmid']) : null),
            Identifier::tryOf(Scheme::PMCID, $ids['pmcid'] ?? null),
        ];
        // A copy on arXiv or HAL: its id from the landing page (or arXiv's DataCite DOI).
        foreach ([$location['landing_page_url'] ?? null, $best['landing_page_url'] ?? null] as $url) {
            if (\is_string($url) && null !== ($found = Identifier::parse((string) preg_replace('~^https?://doi\.org/10\.48550/arxiv\.~i', 'arxiv:', $url))) && \in_array($found->scheme, [Scheme::ARXIV, Scheme::HAL], true)) {
                $identifiers[] = $found;
            }
        }
        if (\is_string($w['doi'] ?? null) && preg_match('~10\.48550/arxiv\.(.+)$~i', $w['doi'], $m)) {
            $identifiers[] = Identifier::tryOf(Scheme::ARXIV, $m[1]);
        }

        $biblio = $w['biblio'] ?? [];
        $pages = null;
        if (null !== ($biblio['first_page'] ?? null)) {
            $pages = $biblio['first_page'].(null !== ($biblio['last_page'] ?? null) && $biblio['last_page'] !== $biblio['first_page'] ? '-'.$biblio['last_page'] : '');
        }

        $venue = null;
        if (null !== $source && null !== ($source['display_name'] ?? null)) {
            $venue = new Venue(
                name: (string) $source['display_name'],
                type: match ($source['type'] ?? null) {
                    'journal' => Venue::JOURNAL,
                    'conference' => Venue::CONFERENCE,
                    'repository' => Venue::REPOSITORY,
                    'book series' => Venue::BOOK_SERIES,
                    'ebook platform' => Venue::BOOK,
                    default => Venue::OTHER,
                },
                issn: array_values(array_filter(array_map(static fn ($i) => Identifier::tryOf(Scheme::ISSN, (string) $i)?->value, $source['issn'] ?? []))),
                publisher: $source['host_organization_name'] ?? null,
                identifiers: Identifiers::of(Identifier::tryOf(Scheme::OPENALEX, $source['id'] ?? null)),
            );
        }

        return new Work(
            title: self::text($w['title'] ?? $w['display_name'] ?? null) ?? '',
            type: $type,
            authors: $authors,
            year: $w['publication_year'] ?? null,
            date: $w['publication_date'] ?? null,
            venue: $venue,
            publisher: \in_array($type, [WorkType::BOOK, WorkType::CHAPTER], true) ? ($source['host_organization_name'] ?? null) : null,
            volume: $biblio['volume'] ?? null,
            issue: $biblio['issue'] ?? null,
            pages: $pages,
            abstract: self::abstract($w['abstract_inverted_index'] ?? null),
            language: $w['language'] ?? null,
            openAccess: $w['open_access']['is_oa'] ?? null,
            url: $location['landing_page_url'] ?? null,
            pdfUrl: $best['pdf_url'] ?? null,
            license: $best['license'] ?? $location['license'] ?? null,
            citations: $w['cited_by_count'] ?? null,
            keywords: array_map(static fn (array $k) => (string) $k['display_name'], $w['keywords'] ?? []),
            identifiers: new Identifiers($identifiers),
            source: 'openalex',
        );
    }

    /**
     * The abstract, rebuilt from the index OpenAlex serves (each word with its positions).
     *
     * @param array<string, list<int>>|null $index
     */
    public static function abstract(?array $index): ?string
    {
        if (!$index) {
            return null;
        }
        $words = [];
        foreach ($index as $word => $positions) {
            foreach ($positions as $position) {
                $words[$position] = $word;
            }
        }
        ksort($words);

        return self::text(implode(' ', $words));
    }
}
