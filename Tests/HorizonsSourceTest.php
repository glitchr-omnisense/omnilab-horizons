<?php

namespace Omnilab\Horizons\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Horizons\HorizonsSource;
use Omnilab\Horizons\HorizonsSourceFactory;
use Omnilab\Model\BBox;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Scheme;
use Omnilab\Model\Series;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use Omnilab\Source\SeriesQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from ssd.jpl.nasa.gov/api/horizons.api on 2026-10-15
 * (API 1.2): Mars from Strasbourg, hourly on 2026-10-16, every quantity;
 * the Moon from Sydney that day; Apophis daily around its approach of
 * April 2029; comet NEOWISE (C/2020 F3) in July 2020; "Moon" (two major
 * bodies match), a name no small body has, and a table too long.
 */
final class HorizonsSourceTest extends TestCase
{
    /** @var list<array<string, string>> the queries asked */
    private array $calls = [];

    private function source(?MockResponse $response = null): HorizonsSource
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            self::assertSame('/api/horizons.api', parse_url($url, \PHP_URL_PATH));
            $this->calls[] = $query;
            if ($response) {
                return $response;
            }
            $fixture = match ($query['COMMAND']) {
                "'499'" => str_contains($query['STEP_SIZE'], ' m\'') ? 'toolong.json' : 'mars-hourly.json',
                "'301'" => 'moon-sydney.json',
                "'99942;'" => 'apophis-daily.json',
                "'DES=C/2020 F3; CAP;'" => 'neowise.json',
                "'Moon'" => 'moon.json',
                default => 'nomatch.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });
        $source = (new HorizonsSourceFactory($http))->create(['throttle' => 0]);
        self::assertInstanceOf(HorizonsSource::class, $source);

        return $source;
    }

    private static function strasbourg(): Point
    {
        return new Point(48.5734, 7.7521, 140);
    }

    public function testMarsFromStrasbourgHourlyInOneCall(): void
    {
        $day = new \DateTimeImmutable('2026-10-16', new \DateTimeZone('UTC'));
        $series = $this->source()->series(new SeriesQuery(['499:ra', '499:dec', '499:elevation', '499:magnitude', '499:distance', '499:illumination', '499:angular_diameter', '499:elongation'], self::strasbourg(), $day, $day));

        self::assertCount(1, $this->calls, 'one call for a body, whatever its quantities');
        $call = $this->calls[0];
        self::assertSame(["'499'", "'OBSERVER'", "'coord@399'", "'GEODETIC'", "'7.7521,48.5734,0.14'"], [$call['COMMAND'], $call['EPHEM_TYPE'], $call['CENTER'], $call['COORD_TYPE'], $call['SITE_COORD']]);
        self::assertSame(["'2026-10-16 00:00:00'", "'2026-10-16 23:59:59'", "'1 h'", "'1,4,9,10,13,20,23'"], [$call['START_TIME'], $call['STOP_TIME'], $call['STEP_SIZE'], $call['QUANTITIES']], 'a date is its whole day');
        self::assertSame(["'DEG'", "'YES'", 'json'], [$call['ANG_FORMAT'], $call['CSV_FORMAT'], $call['format']]);

        [$ra, $dec, $elevation, $magnitude, $distance, $illumination, $diameter, $elongation] = $series;
        self::assertSame('499:ra', $ra->variable);
        self::assertSame('deg', $ra->unit);
        self::assertSame('Mars (499): right ascension (ICRF, astrometric)', $ra->label);
        self::assertSame('horizons', $ra->source);
        self::assertNull($ra->forecast, 'computed, neither observed nor forecast');
        self::assertSame('PT1H', $ra->step);
        self::assertEquals(self::strasbourg(), $ra->place);
        self::assertCount(24, $ra);
        self::assertSame('2026-10-16T00:00:00+00:00', $ra->times()[0]->format(\DATE_ATOM));
        self::assertSame('2026-10-16T23:00:00+00:00', $ra->times()[23]->format(\DATE_ATOM));
        self::assertEqualsWithDelta(132.61884339, $ra->values()[0], 1e-9);
        self::assertEqualsWithDelta(19.024737707, $dec->values()[0], 1e-9);
        self::assertEqualsWithDelta(7.282674898, $elevation->values()[0], 1e-9);
        self::assertEqualsWithDelta(-1.500866559, $elevation->values()[23], 1e-9, 'below the horizon at 23:00');
        self::assertSame('mag', $magnitude->unit);
        self::assertEqualsWithDelta(1.039, $magnitude->values()[0], 1e-9);
        self::assertSame('au', $distance->unit);
        self::assertEqualsWithDelta(1.55761945348745, $distance->values()[0], 1e-12);
        self::assertSame('%', $illumination->unit);
        self::assertEqualsWithDelta(89.87794, $illumination->values()[0], 1e-9);
        self::assertSame('arcsec', $diameter->unit);
        self::assertEqualsWithDelta(72.465, $elongation->values()[0], 1e-9);
        self::assertGreaterThan(50, $elevation->max(), 'Mars high in the morning sky');
    }

    public function testNotAvailableIsNull(): void
    {
        $day = new \DateTimeImmutable('2026-10-16', new \DateTimeZone('UTC'));
        [$airmass, $extinction] = $this->source()->series(new SeriesQuery(['499:airmass', '499:extinction'], self::strasbourg(), $day, $day));

        self::assertNull($airmass->unit);
        self::assertEqualsWithDelta(7.353, $airmass->values()[0], 1e-9);
        self::assertNull($airmass->values()[23], '"n.a." below the horizon');
        self::assertSame('mag', $extinction->unit);
    }

    public function testTheMoonFromSydneyInItsTimeZone(): void
    {
        $day = new \DateTimeImmutable('2026-10-16', new \DateTimeZone('UTC'));
        [$elevation, $illumination] = $this->source()->series(new SeriesQuery(['301:elevation', '301:illumination'], new Point(-33.8688, 151.2093, 58), $day, $day, timezone: 'Australia/Sydney'));

        self::assertSame("'151.2093,-33.8688,0.058'", $this->calls[0]['SITE_COORD'], 'east longitude first, the elevation in km');
        self::assertSame('Moon (301): elevation above the horizon (apparent, airless)', $elevation->label);
        self::assertSame('2026-10-16T11:00:00+11:00', $elevation->times()[0]->format(\DATE_ATOM), 'UT given in the zone asked');
        self::assertEqualsWithDelta(16.512049677, $elevation->values()[0], 1e-9);
        self::assertEqualsWithDelta(26.44602, $illumination->values()[0], 1e-9);
    }

    public function testApophisBySbdbIdentifierDaily(): void
    {
        $from = new \DateTimeImmutable('2029-04-10', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2029-04-16', new \DateTimeZone('UTC'));
        [$distance, $magnitude] = $this->source()->series(new SeriesQuery(['jpl.sbdb:99942:distance', 'jpl.sbdb:99942:magnitude'], self::strasbourg(), $from, $to, SeriesQuery::DAILY));

        self::assertSame(["'99942;'", "'1 d'", "'9,20'"], [$this->calls[0]['COMMAND'], $this->calls[0]['STEP_SIZE'], $this->calls[0]['QUANTITIES']]);
        self::assertSame('99942 Apophis (2004 MN4): distance from the observer', $distance->label);
        self::assertCount(7, $distance);
        self::assertEqualsWithDelta(0.0004423031854, $distance->min(), 1e-12, 'its approach of 13 April 2029');
        self::assertSame('2029-04-14', $distance->times()[array_search($distance->min(), $distance->values(), true)]->format('Y-m-d'));
        self::assertEqualsWithDelta(10.816, $magnitude->values()[0], 1e-9);
    }

    public function testACometsTotalMagnitude(): void
    {
        $from = new \DateTimeImmutable('2020-07-15', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2020-07-17', new \DateTimeZone('UTC'));
        [$magnitude, $nuclear, $brightness] = $this->source()->series(new SeriesQuery([Identifier::of(Scheme::JPL_SBDB, 'C/2020 F3').':magnitude', 'jpl.sbdb:C/2020 F3:nuclear_magnitude', 'jpl.sbdb:C/2020 F3:surface_brightness'], self::strasbourg(), $from, $to, SeriesQuery::DAILY));

        self::assertSame("'DES=C/2020 F3; CAP;'", $this->calls[0]['COMMAND'], 'a comet\'s latest apparition');
        self::assertSame([7.354, 7.541, 7.726], $magnitude->values(), 'T-mag, its total magnitude');
        self::assertSame([null, null, null], $nuclear->values(), 'N-mag: "n.a."');
        self::assertSame([null, null, null], $brightness->values(), 'a comet has no surface brightness column');
        self::assertSame('NEOWISE (C/2020 F3): apparent visual magnitude (a comet\'s total)', $magnitude->label);
    }

    public function testTheCommandOfABody(): void
    {
        self::assertSame('499', HorizonsSource::command('499'));
        self::assertSame('-74', HorizonsSource::command('-74'));
        self::assertSame('Mars Barycenter', HorizonsSource::command('Mars Barycenter'), 'Horizons\' own syntax');
        self::assertSame('433;', HorizonsSource::command(Identifier::of(Scheme::JPL_SBDB, '433')));
        self::assertSame('DES=20000433;', HorizonsSource::command('jpl.sbdb:20000433'), 'an SPK-ID');
        self::assertSame('DES=2004 MN4;', HorizonsSource::command('jpl.sbdb:2004 MN4'));
        self::assertSame('DES=1P; CAP;', HorizonsSource::command('jpl.sbdb:1P/Halley'));
        self::assertSame('DES=C/2020 F3; CAP;', HorizonsSource::command('jpl:C/2020 F3'));
        self::assertSame('NAME=Eros;', HorizonsSource::command('jpl.sbdb:Eros'), 'a small body\'s name, not Horizons\' text search (Eros there is Kerberos)');
    }

    public function testWhatIsRefusedBeforeAnyCall(): void
    {
        $day = new \DateTimeImmutable('2026-10-16');
        foreach ([
            new SeriesQuery(['ra'], self::strasbourg(), $day, $day),
            new SeriesQuery(['499:temperature'], self::strasbourg(), $day, $day),
            new SeriesQuery(['wikidata:Q111:ra'], self::strasbourg(), $day, $day),
            new SeriesQuery(['499:ra'], new BBox(7, 48, 8, 49), $day, $day),
            new SeriesQuery(['499:ra'], Identifier::of(Scheme::GHCN, 'USW00094728'), $day, $day),
            new SeriesQuery(['499:ra'], self::strasbourg(), $day, $day, SeriesQuery::DAILY, Series::MAX),
            new SeriesQuery(['499:ra'], self::strasbourg(), $day, $day, 'PT30S'),
        ] as $query) {
            try {
                $this->source()->series($query);
                self::fail(\sprintf('%s refused', implode(', ', $query->variables)));
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testAnUnknownBodyHasNoPointsAndSeveralAreNamed(): void
    {
        $day = new \DateTimeImmutable('2026-10-16');
        [$series] = $this->source()->series(new SeriesQuery(['jpl.sbdb:Zzyzx:ra'], self::strasbourg(), $day, $day));
        self::assertSame("'NAME=Zzyzx;'", $this->calls[0]['COMMAND']);
        self::assertCount(0, $series);

        try {
            $this->source()->series(new SeriesQuery(['Moon:ra'], self::strasbourg(), $day, $day));
            self::fail('two bodies');
        } catch (ProviderException $e) {
            self::assertStringContainsString('several bodies match "Moon": 3 Earth-Moon Barycenter EMB; 301 Moon Luna; name one by its id', $e->getMessage());
        }
    }

    public function testHorizonsErrors(): void
    {
        try {
            $this->source()->series(new SeriesQuery(['499:ra'], self::strasbourg(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'), 'PT1M'));
            self::fail('too long');
        } catch (ProviderException $e) {
            self::assertStringContainsString('exceeds 90024', $e->getMessage());
        }
        try {
            $this->source(new MockResponse('{"signature":{"source":"NASA/JPL Horizons API","version":"1.2"},"result":"\nNo ephemeris for target \"Mars\" prior to A.D. 1600-JAN-01 23:58:09.5423 UT\n"}'))->series(new SeriesQuery(['499:ra'], self::strasbourg(), new \DateTimeImmutable('1500-01-01'), new \DateTimeImmutable('1500-01-02')));
            self::fail('before its ephemeris');
        } catch (ProviderException $e) {
            self::assertStringContainsString('No ephemeris for target "Mars" prior to A.D. 1600', $e->getMessage());
        }
        try {
            $this->source(new MockResponse('', ['http_code' => 503]))->series(new SeriesQuery(['499:ra'], self::strasbourg()));
            self::fail('a 503');
        } catch (UnavailableException $e) {
            self::assertSame(503, $e->status);
        }
        $this->expectException(RateLimitedException::class);
        $this->source(new MockResponse('', ['http_code' => 429]))->series(new SeriesQuery(['499:ra'], self::strasbourg()));
    }

    public function testNoPeriodIsTodayAndTheFactory(): void
    {
        $this->source()->series(new SeriesQuery(['499:ra'], self::strasbourg()));
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d');
        self::assertSame(["'$today 00:00:00'", "'$today 23:59:59'"], [$this->calls[0]['START_TIME'], $this->calls[0]['STOP_TIME']]);

        $factory = new HorizonsSourceFactory(new MockHttpClient());
        $source = $factory->create(['mailto' => 'support@glitchr.io']);
        self::assertSame('horizons', $factory->getName());
        self::assertSame('horizons', $source->getName());
        self::assertSame([Capability::SERIES, Capability::FORECAST, Capability::HISTORICAL], $source->capabilities());
        $this->expectException(NotSupportedException::class);
        $source->entity('499');
    }

    public function testNoSearch(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->source()->search(new Query('Mars'));
    }
}
