<?php

namespace Omnischolar\OpenAlex;

use Omnischolar\Config;
use Omnischolar\Source\SourceFactory;
use Omnischolar\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * OpenAlex: profiles, works, citations, h-index - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'          # sent with every call, as OpenAlex asks
 *     api_key: ~                            # a free key from openalex.org/settings/api: ten times the keyless daily budget
 *     paratext: false                       # true keeps covers, tables of contents and the like
 *     base_uri: 'https://api.openalex.org/'
 */
final class OpenAlexSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnischolar.factory_name' => 'openalex',
            'omnischolar.required_options' => [],
            'api_key' => null,
            'paratext' => false,
            'base_uri' => OpenAlexSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new OpenAlexSource(
            $this->http ?? HttpClient::create(),
            $c->get('mailto'),
            $c->get('api_key'),
            (bool) $c->get('paratext'),
            (string) $c->get('base_uri', OpenAlexSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
        );
    }
}
