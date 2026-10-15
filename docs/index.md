# omnilab/horizons

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

## What Horizons does not give here

The vector and orbital-element tables, SPK files and close-approach tables (other `EPHEM_TYPE`s),
an observer elsewhere than on the Earth, the object data (`OBJ_DATA`: radius, mass, rotation of a
major body - free text), the quantities not listed above (the constellation, the local apparent
sidereal time, the Moon's and the planets' sub-observer points, the light time...), dates B.C.,
the TT and TDB time scales, the refraction model, and a list of times (`TLIST`).

## Verified, and not

Against the real service on 2026-10-15: Mars from Strasbourg hourly on 2026-10-16 (24 points, its
elevation up to 60.10°, APmag 1.039, 1.5576 au), each of the seventeen quantities; the Moon from
Sydney and from New York in their time zones; Apophis by `jpl.sbdb:99942` daily around 13 April
2029 and every ten minutes that evening (0.0002243 au from Strasbourg at 21:40 UTC); comet NEOWISE
(`C/2020 F3`) in July 2020, its total magnitude 7.354 to 7.726; 1P/Halley's distance from the Sun
in 2061 (0.835 and 0.597 au, monthly); "Moon" (two bodies), a name no small body has, a table of
525,600 lines, a period before 1600 for Mars. The fixtures in `Tests/Fixtures/` are its answers.
Each call took 0.7 to 1.3 s.

Not verified: a 429 or a 503 from Horizons (none met: the mapping is the core's); a spacecraft;
the 90,024-line limit met exactly; a mirror (`base_uri`).
