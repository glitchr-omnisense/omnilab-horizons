<?php

namespace Omnilab\Horizons;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Scheme;
use Omnilab\Model\Series;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\SeriesQuery;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * JPL Horizons (ssd.jpl.nasa.gov/api/horizons.api): the ephemeris of a body
 * of the solar system - a planet, a moon, the Sun, an asteroid, a comet, a
 * spacecraft - seen from a place on the Earth over a period, as series:
 * its right ascension and declination, azimuth and elevation, airmass,
 * apparent magnitude and surface brightness, illuminated fraction, angular
 * diameter, its distances to the observer and to the Sun and their rates,
 * its solar elongation and phase angle.
 *
 * A series is one variable at a place over a time, and an ephemeris is
 * that once the body is said: each variable names its body before its
 * quantity, "<body>:<quantity>" - "499:ra" (Mars), "301:elevation" (the
 * Moon), "jpl.sbdb:99942:distance" (Apophis), "jpl.sbdb:1P:magnitude".
 * A body is a Horizons id (a number: 10 the Sun, 301 the Moon, 499 Mars,
 * -74 the Mars Reconnaissance Orbiter), a small body of the jpl.sbdb
 * scheme (a number, a designation, a comet's, an SPK-ID, a name), or
 * Horizons' own command ("DES=2004 MN4;"). One call per body, whatever
 * its quantities.
 *
 * No key. JPL's fair use: one request at a time, a User-Agent that names
 * the caller (set "mailto"); the calls are spaced by 0.2 s by default.
 */
final class HorizonsSource extends HttpSource
{
    public const BASE_URI = 'https://ssd.jpl.nasa.gov/api/';

    /**
     * The quantities a variable names: quantity => [Horizons' quantity
     * code, the pattern of its column, unit, label]. Angles in degrees
     * (ANG_FORMAT=DEG), distances in au, rates in km/s.
     */
    public const QUANTITIES = [
        'ra' => [1, '~^R\.A\._*\(ICRF\)$~', 'deg', 'Right ascension (ICRF, astrometric)'],
        'dec' => [1, '~^DEC_*\(ICRF\)$~', 'deg', 'Declination (ICRF, astrometric)'],
        'azimuth' => [4, '~^Azi(?:muth)?_*\(a-app\)$~', 'deg', 'Azimuth (apparent, airless; from the north, eastward)'],
        'elevation' => [4, '~^Elev(?:ation)?_*\(a-app\)$~', 'deg', 'Elevation above the horizon (apparent, airless)'],
        'airmass' => [8, '~^a-mass$~', null, 'Airmass'],
        'extinction' => [8, '~^mag_ex$~', 'mag', 'Visual magnitude extinction'],
        'magnitude' => [9, '~^(?:APmag|T-mag)$~', 'mag', 'Apparent visual magnitude (a comet\'s total)'],
        'nuclear_magnitude' => [9, '~^N-mag$~', 'mag', 'A comet\'s nuclear magnitude'],
        'surface_brightness' => [9, '~^S-brt$~', 'mag/arcsec²', 'Surface brightness'],
        'illumination' => [10, '~^Illu%$~', '%', 'Illuminated fraction'],
        'angular_diameter' => [13, '~^Ang-diam$~', 'arcsec', 'Angular diameter'],
        'heliocentric_distance' => [19, '~^r$~', 'au', 'Distance from the Sun'],
        'heliocentric_range_rate' => [19, '~^rdot$~', 'km/s', 'Rate of change of the distance from the Sun'],
        'distance' => [20, '~^delta$~', 'au', 'Distance from the observer'],
        'range_rate' => [20, '~^deldot$~', 'km/s', 'Rate of change of the distance from the observer'],
        'elongation' => [23, '~^S-O-T$~', 'deg', 'Solar elongation (Sun-observer-target)'],
        'phase_angle' => [24, '~^S-T-O$~', 'deg', 'Phase angle (Sun-target-observer)'],
    ];

    public function __construct(
        HttpClientInterface $http,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.2,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'horizons';
    }

    public function capabilities(): array
    {
        return [Capability::SERIES, Capability::FORECAST, Capability::HISTORICAL];
    }

    /**
     * One series per variable, "<body>:<quantity>", in the order asked: the
     * body seen from the query's Point (its elevation in metres, 0 when
     * unknown), each step from the start of the period to its end (an end
     * at midnight is its whole day; no start is today, no end a day
     * later). The times are UT, given in the query's time zone when it
     * names one. A body Horizons does not find answers a series without
     * points; a name several bodies share is a ProviderException that
     * lists them.
     */
    public function series(SeriesQuery $query): array
    {
        $this->station($query);
        if (!$query->place instanceof Point) {
            throw NotSupportedException::operation('horizons', 'serve an ephemeris over a box: an observer stands at a Point');
        }
        if (null !== $query->aggregation) {
            throw NotSupportedException::operation('horizons', \sprintf('aggregate an ephemeris (%s): its points are instants', $query->aggregation));
        }
        $step = self::step($query->step);
        // Each variable read before any call: its body and its quantity.
        $wanted = [];
        foreach ($query->variables as $variable) {
            $wanted[$variable] = self::variable($variable);
        }
        $utc = new \DateTimeZone('UTC');
        $zone = null === $query->timezone ? $utc : new \DateTimeZone($query->timezone);
        $from = null === $query->from ? new \DateTimeImmutable('today', $zone) : \DateTimeImmutable::createFromInterface($query->from);
        $to = null === $query->to ? $from->modify('+1 day -1 second') : \DateTimeImmutable::createFromInterface($query->to);
        if (null !== $query->to && '00:00:00' === $to->format('H:i:s')) {
            $to = $to->modify('+1 day -1 second');
        }
        $site = \sprintf('%s,%s,%s', self::number($query->place->longitude), self::number($query->place->latitude), self::number(($query->place->elevation ?? 0) / 1000));

        $tables = [];
        foreach (array_unique(array_column($wanted, 0)) as $command) {
            $codes = [];
            foreach ($wanted as [$body, $quantity]) {
                if ($body === $command) {
                    $codes[] = self::QUANTITIES[$quantity][0];
                }
            }
            $codes = array_values(array_unique($codes));
            sort($codes);
            $tables[$command] = $this->ephemeris($command, $site, $from->setTimezone($utc), $to->setTimezone($utc), $step, $codes);
        }

        $series = [];
        foreach ($wanted as $variable => [$command, $quantity]) {
            [, $pattern, $unit, $label] = self::QUANTITIES[$quantity];
            $table = $tables[$command];
            $column = null;
            foreach ($table['columns'] as $i => $name) {
                if (preg_match($pattern, $name)) {
                    $column = $i;
                    break;
                }
            }
            $points = [];
            foreach ($table['rows'] as [$time, $values]) {
                $points[] = [$time->setTimezone($zone), null === $column ? null : self::value($values[$column] ?? null)];
            }
            $series[] = new Series(
                variable: $variable,
                unit: $unit,
                place: $query->place,
                points: $points,
                step: $query->step,
                label: null === $table['body'] ? $label : $table['body'].': '.lcfirst($label),
                // Computed: neither observed nor forecast in the weather's sense.
                forecast: null,
                source: 'horizons',
            );
        }

        return $series;
    }

    /**
     * Horizons' command for a body: a Horizons id as it is ("499", "-74"),
     * a small body of the jpl.sbdb scheme as a small-body search ("433;",
     * "DES=2004 MN4;", "DES=1P; CAP;" - a comet's latest apparition -,
     * "NAME=Eros;", an SPK-ID "DES=20000433;"), anything else as Horizons
     * reads it. An identifier of another scheme is refused.
     */
    public static function command(Identifier|string $body): string
    {
        if (\is_string($body)) {
            $body = trim($body);
            if (preg_match('~^-?\d+$~', $body)) {
                return $body;
            }
            $parsed = preg_match('~^[a-z][a-z._-]*:~i', $body) ? Identifier::parse($body) : null;
            if (null === $parsed && preg_match('~^[a-z][a-z._-]*:~i', $body) && null !== Identifier::scheme(strstr($body, ':', true))) {
                throw NotSupportedException::identifier('horizons', $body, 'name a body by');
            }
            if (null === $parsed) {
                return $body;
            }
            $body = $parsed;
        }
        if (Scheme::JPL_SBDB !== $body->scheme) {
            throw NotSupportedException::identifier('horizons', (string) $body, 'name a body by');
        }
        $value = $body->value;

        return match (true) {
            // An SPK-ID (20000433, 1000036): the SBDB's, looked up by DES.
            (bool) preg_match('~^\d{7,}$~', $value) => 'DES='.$value.';',
            (bool) preg_match('~^\d+$~', $value) => $value.';',
            // A periodic comet, its name after the slash left out (1P/Halley), or a comet's designation (C/2020 F3).
            (bool) preg_match('~^(\d+[PDXI](?:-[A-Z]{1,2})?)(?:/.*)?$~i', $value, $m) => 'DES='.strtoupper($m[1]).'; CAP;',
            (bool) preg_match('~^[PCDXI]/\d{4} ~i', $value) => 'DES='.$value.'; CAP;',
            (bool) preg_match('~^(?:A/)?\d{4} [A-Z]{2}\d*$~i', $value) => 'DES='.$value.';',
            default => 'NAME='.$value.';',
        };
    }

    /**
     * A variable's body (Horizons' command) and quantity, refused before
     * any call when either is not read.
     *
     * @return array{string, string}
     */
    private static function variable(string $variable): array
    {
        $at = strrpos($variable, ':');
        $quantity = false === $at ? $variable : strtolower(trim(substr($variable, $at + 1)));
        if (false === $at || '' === trim(substr($variable, 0, $at))) {
            throw NotSupportedException::operation('horizons', \sprintf('serve "%s" without its body: name it "<body>:%s" ("499:%1$s" for Mars)', $variable, $quantity));
        }
        if (!isset(self::QUANTITIES[$quantity])) {
            throw NotSupportedException::operation('horizons', \sprintf('serve "%s" (it serves %s)', $quantity, implode(', ', array_keys(self::QUANTITIES))));
        }

        return [self::command(substr($variable, 0, $at)), $quantity];
    }

    /** Horizons' step for an ISO 8601 duration: PT1H is "1 h", PT10M "10 m", P1D "1 d", P1M "1 mo", P1Y "1 y". */
    private static function step(string $step): string
    {
        return match (true) {
            (bool) preg_match('~^PT([1-9]\d*)M$~', $step, $m) => $m[1].' m',
            (bool) preg_match('~^PT([1-9]\d*)H$~', $step, $m) => $m[1].' h',
            (bool) preg_match('~^P([1-9]\d*)D$~', $step, $m) => $m[1].' d',
            (bool) preg_match('~^P([1-9]\d*)W$~', $step, $m) => (7 * (int) $m[1]).' d',
            (bool) preg_match('~^P([1-9]\d*)M$~', $step, $m) => $m[1].' mo',
            (bool) preg_match('~^P([1-9]\d*)Y$~', $step, $m) => $m[1].' y',
            default => throw NotSupportedException::operation('horizons', \sprintf('step by "%s" (it steps by minutes, hours, days, weeks, months or years: PT10M, PT1H, P1D...)', $step)),
        };
    }

    /**
     * One observer table: the body as Horizons names it, the columns, the
     * rows (a time and its values).
     *
     * @param list<int> $codes
     *
     * @return array{body: ?string, columns: list<string>, rows: list<array{\DateTimeImmutable, list<string>}>}
     */
    private function ephemeris(string $command, string $site, \DateTimeImmutable $from, \DateTimeImmutable $to, string $step, array $codes): array
    {
        $quote = static fn (string $value): string => "'".str_replace("'", '', $value)."'";
        $data = $this->getJson('horizons.api', [
            'format' => 'json',
            'COMMAND' => $quote($command),
            'OBJ_DATA' => "'NO'",
            'MAKE_EPHEM' => "'YES'",
            'EPHEM_TYPE' => "'OBSERVER'",
            'CENTER' => "'coord@399'",
            'COORD_TYPE' => "'GEODETIC'",
            'SITE_COORD' => $quote($site),
            'START_TIME' => $quote($from->format('Y-m-d H:i:s')),
            'STOP_TIME' => $quote($to->format('Y-m-d H:i:s')),
            'STEP_SIZE' => $quote($step),
            'QUANTITIES' => $quote(implode(',', $codes)),
            'ANG_FORMAT' => "'DEG'",
            'CSV_FORMAT' => "'YES'",
            'TIME_DIGITS' => "'SECONDS'",
            'EXTRA_PREC' => "'YES'",
        ]) ?? throw new ProviderException('horizons', 'the API answered nothing');
        if (\is_string($data['error'] ?? null) && '' !== trim($data['error'])) {
            throw new ProviderException('horizons', (string) self::text($data['error']));
        }
        $result = (string) ($data['result'] ?? '');
        $start = strpos($result, '$$SOE');
        $end = strpos($result, '$$EOE');
        if (false === $start || false === $end) {
            if (str_contains($result, 'No matches found')) {
                return ['body' => null, 'columns' => [], 'rows' => []];
            }
            if (preg_match('~Multiple major-bodies match|Matching small-bodies~', $result)) {
                throw new ProviderException('horizons', \sprintf('several bodies match "%s": %s; name one by its id', $command, self::matches($result)));
            }

            throw new ProviderException('horizons', (string) (self::text(substr(trim(str_replace('*', '', $result)), 0, 300)) ?? 'no ephemeris'));
        }
        // The column names: the line before the stars that precede $$SOE.
        $head = array_values(array_filter(array_map('trim', explode("\n", substr($result, 0, $start))), static fn (string $l) => '' !== $l && !preg_match('~^\*+$~', $l)));
        $columns = array_map('trim', explode(',', (string) end($head)));
        $rows = [];
        foreach (explode("\n", trim(substr($result, $start + 5, $end - $start - 5))) as $line) {
            $values = array_map('trim', explode(',', $line));
            // "2026-Oct-16 00:00:00" (a "b" before it is a date B.C., not read).
            $time = \DateTimeImmutable::createFromFormat('!Y-M-d H:i:s', $values[0], new \DateTimeZone('UTC'));
            if (false !== $time) {
                $rows[] = [$time, $values];
            }
        }
        preg_match('~Target body name:\s*(.+?)\s{2,}~', $result, $m);

        return ['body' => isset($m[1]) ? trim($m[1]) : null, 'columns' => $columns, 'rows' => $rows];
    }

    /** The bodies of a list of matches, five at most: "4 Mars Barycenter; 499 Mars...". */
    private static function matches(string $result): string
    {
        $lines = explode("\n", $result);
        $found = [];
        $listing = false;
        foreach ($lines as $line) {
            if (preg_match('~^\s*-{4,}~', $line)) {
                $listing = true;
                continue;
            }
            if ($listing) {
                if ('' === trim($line)) {
                    break;
                }
                $found[] = trim((string) preg_replace('~\s{2,}~', ' ', $line));
            }
        }
        $more = \count($found) > 5 ? \sprintf(' and %d more', \count($found) - 5) : '';

        return $found ? implode('; ', \array_slice($found, 0, 5)).$more : 'Horizons lists them';
    }

    /** A cell of the table: a number, or null for "n.a." and blanks. */
    private static function value(?string $cell): int|float|null
    {
        $cell = null === $cell ? '' : trim($cell);

        return is_numeric($cell) ? 0 + $cell : null;
    }

    /** A number as Horizons takes it, never in exponent form. */
    private static function number(float $value): string
    {
        $text = rtrim(rtrim(\sprintf('%.6F', $value), '0'), '.');

        return '-0' === $text ? '0' : $text;
    }
}
