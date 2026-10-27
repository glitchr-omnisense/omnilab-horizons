# omnilab/horizons

NASA/JPL Horizons, through its API (`https://ssd.jpl.nasa.gov/api/horizons.api`), for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): the ephemeris of a planet, moon,
asteroid, comet or spacecraft seen from a place on the Earth over a period - right ascension and
declination, azimuth and elevation, magnitude, illumination, distances, elongation - as series,
each variable naming its body (`499:elevation`). No key.

## Installation

```sh
composer require omnilab/horizons
```

```php
use Omnilab\Horizons\HorizonsSourceFactory;

$horizons = (new HorizonsSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // recommended: JPL asks a User-Agent that names the caller
    'throttle' => 0.2,                 // optional: seconds between two calls
]);
```

With the Symfony bundle, the factory is registered as `horizons` when the bundle knows it;
otherwise register `HorizonsSourceFactory` as a service (it is autoconfigured as a
`SourceFactoryInterface`).

## Credentials

None: the Horizons API takes no key. JPL asks that its APIs be called one request at a time, with a
User-Agent that names the application: give `mailto`.

## Options

The factory's options, with the defaults it applies (`HorizonsSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `mailto` | `?string` | null | no | a contact address, in the User-Agent: JPL's fair use asks an application-specific one |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0.2` | no | seconds left between two calls: JPL asks one request at a time |
| `base_uri` | `string` | `https://ssd.jpl.nasa.gov/api/` | no | the API the source calls |

## Capabilities

`capabilities()` answers `series`, `forecast`, `historical`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | no | a body's record is omnilab/jpl's (the Small-Body Database) |
| `search` | no |  |
| `series` | yes | the variables `<body>:<quantity>` (`499:elevation`, `jpl.sbdb:99942:distance`) seen from a `Point` on the Earth, one call per body; steps of minutes to years |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no |  |
| `forecast` | yes | an ephemeris answers for the future |
| `historical` | yes | and for the past (within each body's ephemeris) |
| `area` | no | a box is refused before any call: an observer stands at a point |
| `station` | no | a station is refused before any call |
| `cloud_cover` | no |  |

## Which API

The Horizons API (`https://ssd.jpl.nasa.gov/api/horizons.api`, API version 1.2 in its answers,
documented as 1.3): every Horizons setting is a parameter of the URL, the answer a JSON object
whose `result` is Horizons' text - here an observer table in CSV between `$$SOE` and `$$EOE` -
and whose `error`, when there is one, says what Horizons refused (with a 200). Asked each time:
`EPHEM_TYPE=OBSERVER`, `CENTER=coord@399` with `COORD_TYPE=GEODETIC` and `SITE_COORD` (east
longitude, latitude, altitude in km), `OBJ_DATA=NO`, `ANG_FORMAT=DEG` (angles in degrees, not
sexagesimal), `CSV_FORMAT=YES`, `TIME_DIGITS=SECONDS`, `EXTRA_PREC=YES`, and the quantity codes
the variables need.

## Series, and the body in the variable

`series()` is the contract's: one variable at a place (the observer, a `Point` on the Earth) over
a period. What an ephemeris adds is the body, which `SeriesQuery` has no field for, so each
variable names it before its quantity: `<body>:<quantity>`, split at the last colon -
`499:ra`, `301:elevation`, `jpl.sbdb:99942:distance`, `jpl.sbdb:C/2020 F3:magnitude`. Several
bodies may be asked at once; one call is made per body, whatever its quantities, and the series
come back in the order asked.

| Body | Horizons' command |
|---|---|
| a number: `10`, `301`, `499`, `-74` | as it is: a major body's or a spacecraft's id |
| `jpl.sbdb:433` (an `Identifier` of the `jpl.sbdb` scheme too) | `433;` (a numbered small body) |
| `jpl.sbdb:20000433` (an SPK-ID, seven digits or more) | `DES=20000433;` |
| `jpl.sbdb:2004 MN4` | `DES=2004 MN4;` |
| `jpl.sbdb:1P`, `jpl.sbdb:1P/Halley`, `jpl.sbdb:C/2020 F3` | `DES=1P; CAP;`: the comet's latest apparition |
| `jpl.sbdb:Eros` | `NAME=Eros;` |
| anything else (`Mars Barycenter`, `DES=2004 MN4;`) | as it is, Horizons' own syntax |

An identifier of another scheme (`wikidata:Q111`) is refused before any call. A plain name is
Horizons' text search over the major bodies, which matches parts of names: on 2026-10-15
`Eros` gave Kerberos (904), Pluto's moon - name a small body in the `jpl.sbdb` scheme
(`jpl.sbdb:Eros` is `NAME=Eros;`, 433 Eros). The series' label says which body Horizons answered
for: `Mars (499): right ascension (ICRF, astrometric)`.

| Quantity | Horizons (code: column) | Unit |
|---|---|---|
| `ra`, `dec` | 1: R.A., DEC (ICRF, astrometric) | deg |
| `azimuth`, `elevation` | 4: Azimuth, Elevation (apparent, airless) | deg |
| `airmass`, `extinction` | 8: a-mass, mag_ex | -, mag |
| `magnitude` | 9: APmag (a comet's T-mag, its total magnitude) | mag |
| `nuclear_magnitude` | 9: N-mag (a comet's) | mag |
| `surface_brightness` | 9: S-brt | mag/arcsec² |
| `illumination` | 10: Illu% | % |
| `angular_diameter` | 13: Ang-diam | arcsec |
| `heliocentric_distance`, `heliocentric_range_rate` | 19: r, rdot | au, km/s |
| `distance`, `range_rate` | 20: delta, deldot | au, km/s |
| `elongation` | 23: S-O-T | deg |
| `phase_angle` | 24: S-T-O | deg |

A value Horizons gives as `n.a.` (the airmass of a body below the horizon, a comet's nuclear
magnitude) is null; a column the body has not (a comet's surface brightness) gives null points.

**The period**: both ends included, an end at midnight standing for its whole day; no start is
today (00:00 UTC, or in the query's time zone), no end a day after the start. **The step**:
`PT10M` is `10 m`, `PT1H` `1 h`, `P1D` `1 d`, `P1W` `7 d`, `P1M` `1 mo`, `P1Y` `1 y`; any other
(seconds) is refused before any call. **The times**: Horizons' UT (UTC since 1962), given in the
query's time zone when it names one. **No aggregation**: an ephemeris' points are instants (a
query with `Series::MAX`... is refused). **The place**: a `Point`, its elevation in metres (0 when
unknown); a `BBox` and a station are refused. `forecast` is null: an ephemeris is computed, neither
observed nor a forecast in the weather's sense; the capabilities say `forecast` and `historical`,
since it answers for the future and the past alike.

## Errors

| Horizons says | Here |
|---|---|
| "No matches found" (a small-body search) | a series without points |
| several bodies match ("Moon": 3 Earth-Moon Barycenter, 301 Moon) | `ProviderException` listing five of them: name one by its id |
| `error`: a table past 90,024 lines, a start after the end, an unknown step, a time out of the body's ephemeris ("No ephemeris for target "Mars" after A.D. 2599-DEC-31") | `ProviderException` with Horizons' text |
| 429, 5xx | `RateLimitedException`, `UnavailableException` |

Refused before any call (`NotSupportedException`): a box or a station, an aggregation, a variable
without its body or a quantity not served, a body of another scheme (`wikidata:Q111`), a step in
seconds. A 404 or 410 is an empty answer; any other 4xx a `ProviderException` with what Horizons
said; an answer that is not JSON a `ProviderException`, as every source's (`HttpSource`).

## What Horizons does not give here

The vector and orbital-element tables, SPK files and close-approach tables (other `EPHEM_TYPE`s),
an observer elsewhere than on the Earth, the object data (`OBJ_DATA`: radius, mass, rotation of a
major body - free text), the quantities not listed above (the constellation, the local apparent
sidereal time, the Moon's and the planets' sub-observer points, the light time...), dates B.C.,
the TT and TDB time scales, the refraction model, and a list of times (`TLIST`).

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Horizons\HorizonsSourceFactory;
use Omnilab\Model\Point;
use Omnilab\Source\SeriesQuery;
use Symfony\Component\HttpClient\HttpClient;

$horizons = (new HorizonsSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org']);

// Mars and the Moon from Strasbourg, every three hours on 2026-10-28 (UTC), given in Paris time: one call per body.
[$mars, $marsMag, $moon] = $horizons->series(new SeriesQuery(['499:elevation', '499:magnitude', '301:illumination'], new Point(48.5734, 7.7521, 140), new DateTimeImmutable('2026-10-28'), new DateTimeImmutable('2026-10-28'), 'PT3H', timezone: 'Europe/Paris'));
echo $mars->label, ' (', $mars->unit, "):\n ";
foreach ($mars as [$time, $value]) {
    echo ' ', $time->format('H'), 'h ', round($value, 1);
}
echo "\n", $marsMag->label, ': ', $marsMag->min(), ' ', $marsMag->unit, "\n", $moon->label, ': ', $moon->min(), ' to ', $moon->max(), ' ', $moon->unit, "\n";
```

```
Mars (499): elevation above the horizon (apparent, airless) (deg):
  01h 9.4 04h 38.8 07h 58.4 10h 45.1 13h 16.2 16h -10.6 19h -24.1 22h -15.2
Mars (499): apparent visual magnitude (a comet's total): 0.837 mag
Moon (301): illuminated fraction: 89.69355 to 95.2556 %
```

(as answered on 2026-10-27: two calls, one per body; the period's dates are UTC midnights, the
times given in Paris time, an hour ahead)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        horizons: { factory: horizons, options: { mailto: '%env(OMNILAB_MAILTO)%' } }
```

## Verified, and not

Against the real service on 2026-10-15: Mars from Strasbourg hourly on 2026-10-16 (24 points, its
elevation up to 60.10°, APmag 1.039, 1.5576 au), each of the seventeen quantities; the Moon from
Sydney and from New York in their time zones; Apophis by `jpl.sbdb:99942` daily around 13 April
2029 and every ten minutes that evening (0.0002243 au from Strasbourg at 21:40 UTC); comet NEOWISE
(`C/2020 F3`) in July 2020, its total magnitude 7.354 to 7.726; 1P/Halley's distance from the Sun
in 2061 (0.835 and 0.597 au, monthly); "Moon" (two bodies), a name no small body has, a table of
525,600 lines, a period before 1600 for Mars. The fixtures in `Tests/Fixtures/` are its answers.
Each call took 0.7 to 1.3 s.

Again on 2026-10-27: Mars and the Moon from Strasbourg every three hours on 2026-10-28, in Paris
time (the example above).

Not verified: a 429 or a 503 from Horizons (none met: the mapping is the core's); a spacecraft;
the 90,024-line limit met exactly; a mirror (`base_uri`).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by NASA, JPL or Caltech. JPL asks that its APIs be called one request at a time with a
User-Agent that names the application, and Horizons be cited (Giorgini, J.D., and JPL Solar System
Dynamics Group, NASA/JPL Horizons On-Line Ephemeris System), as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-horizons#terms-of-use) says. Whoever
runs the package is responsible for complying with them and for any use made of what it returns:
see the README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-horizons#responsibility)
section.
