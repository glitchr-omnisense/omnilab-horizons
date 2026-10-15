<?php

namespace Omnilab\Horizons;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * JPL Horizons: the ephemeris of a body of the solar system seen from a
 * place on the Earth, as series - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent: JPL's fair use asks an application-specific one with a contact
 *     throttle: 0.2                    # seconds between two calls: JPL asks one request at a time
 *     base_uri: 'https://ssd.jpl.nasa.gov/api/'
 */
final class HorizonsSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'horizons',
            'omnilab.required_options' => [],
            'throttle' => 0.2,
            'base_uri' => HorizonsSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new HorizonsSource(
            $this->http ?? HttpClient::create(),
            (string) $c->get('base_uri', HorizonsSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
