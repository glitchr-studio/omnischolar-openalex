# omnischolar/openalex

OpenAlex for [glitchr/omnischolar](https://github.com/glitchr-studio/omnischolar): a researcher's
profile with their counts (works, citations, h-index, i10-index, by year) and the institutions
seen on their works; their works with abstracts, citation counts and open-access links; any work
by its OpenAlex id, DOI or PubMed id; a search. No key needed.

```php
$openalex = (new OpenAlexSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$openalex->author('A5108007452')->metrics->hIndex;                      // 51
$openalex->works('A5108007452', new Query(from: 2024, to: 2024));       // a Page of Work
$openalex->work('10.1039/d4sc04973j');
```

```yaml
omnischolar:
    sources:
        openalex:
            factory: openalex
            options:
                mailto: 'support@glitchr.io'      # sent with every call, as OpenAlex asks
                api_key: ~                        # free, from openalex.org/settings/api: ten times the keyless daily budget
                paratext: false                   # true keeps covers, tables of contents...
```

See [docs/](docs/index.md) for what is read, how it maps, and what OpenAlex does not give.

License: LGPL-3.0-or-later.
