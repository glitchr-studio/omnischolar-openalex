# omnischolar/openalex

## Installation

```sh
composer require omnischolar/openalex
```

```php
use Omnischolar\OpenAlex\OpenAlexSourceFactory;

$openalex = (new OpenAlexSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',
    'api_key' => null,          // optional
    'paratext' => false,        // optional
]);
```

With the Symfony bundle, the factory is registered as `openalex` (see the core's docs).

## Calls

| Method | Reads | OpenAlex |
|---|---|---|
| `author()` | OpenAlex `A...`, ORCID | `authors/{id}`, `authors/orcid:{orcid}` |
| `works()` | OpenAlex `A...`, ORCID | `works?filter=author.id:` / `author.orcid:`, cursor paging, 100 a page at most |
| `work()` | OpenAlex `W...`, DOI, PMID, PMCID | `works/{id}`, `works/doi:`, `works/pmid:`, `works/pmcid:` |
| `search()` | `text` (full-text search), `title` (`title.search`), `author` (`raw_author_name.search`) | `works?search=` |

`Query`: `from`/`to` (`publication_year`), `types` (OpenAlex types), `openAccess`
(`open_access.is_oa`), `sort` (`newest`, `oldest`, `cited`, `relevance` with a text), `limit`,
`cursor`. `domains` is refused. Paratext (covers, tables of contents) is filtered out unless
`paratext: true`. Only the fields used are asked (`select`).

## What a work holds

Title, type (`article` in a conference source is a `conference`), authors with their OpenAlex
id, ORCID and institutions, year and date, venue (journal, repository, conference; ISSNs; its host
organisation as publisher), volume, issue, pages, abstract (rebuilt from OpenAlex's inverted
index), language, open access, landing page, best open-access PDF, licence, citation count,
keywords; identifiers: OpenAlex, DOI, PMID, PMCID, and the arXiv or HAL id when the landing page
is there.

## What an author holds

Name and the other spellings OpenAlex saw, OpenAlex id and ORCID, `Metrics` (works, citations,
h-index, i10-index, two-year mean citedness, works and citations by year), the institutions seen
on the works as `Affiliation::OBSERVED` with their years and ROR (the last known ones are
current), the top ten topics as keywords, the country.

## What OpenAlex does not give

No search by name for `works()` (resolve the author first, or use `search(author: ...)`); no
lookup by arXiv id; no subject-domain filter here; a date known by its year only is served as
January 1st (the Merger prefers other sources' dates); no positions nor degrees - the
institutions are inferred from the works. The keyless budget is limited per day:
`RateLimitedException` when it is spent.

## Tests

`Tests/Fixtures` holds answers recorded on 2026-10-04 (Keitaro Nakatani, A5108007452): his
profile, his 2024 works (four, among them *Chemical Science* and *ACS Applied Materials &
Interfaces*), the first two pages of his works, a work by DOI. The tests run on Symfony's
`MockHttpClient`.
