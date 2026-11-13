# omnilab/horizons

NASA/JPL's Horizons for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): the
ephemeris of a body of the solar system - a planet, a moon, the Sun, an asteroid, a comet, a
spacecraft - seen from a place on the Earth over a period, as series: its right ascension and
declination, azimuth and elevation, airmass and extinction, apparent magnitude and surface
brightness, illuminated fraction, angular diameter, its distances to the observer and to the Sun
and their rates, its solar elongation and phase angle. No key.

A series is one variable at a place over a time; an ephemeris is that once the body is said, so
each variable names its body before its quantity: `<body>:<quantity>`.

```php
$horizons = (new HorizonsSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$strasbourg = new Point(48.5734, 7.7521, 140);
$day = new \DateTimeImmutable('2026-10-16', new \DateTimeZone('UTC'));
[$elevation, $distance] = $horizons->series(new SeriesQuery(['499:elevation', '499:distance'], $strasbourg, $day, $day));
$elevation->label;          // Mars (499): elevation above the horizon (apparent, airless)
$elevation->max();          // 60.10 (deg), 24 hourly points
$distance->values()[0];     // 1.55761945348745 (au)
$horizons->series(new SeriesQuery(['jpl.sbdb:99942:distance'], $strasbourg, $from, $to, 'PT10M')); // Apophis, 13 April 2029
```

A body is a Horizons id (`10` the Sun, `301` the Moon, `499` Mars, `-74` the Mars Reconnaissance
Orbiter), a small body of the core's `jpl.sbdb` scheme (`jpl.sbdb:433`, `jpl.sbdb:2004 MN4`,
`jpl.sbdb:1P`, `jpl.sbdb:C/2020 F3`, `jpl.sbdb:Eros`), or Horizons' own command. Quantities: `ra`,
`dec`, `azimuth`, `elevation`, `airmass`, `extinction`, `magnitude`, `nuclear_magnitude`,
`surface_brightness`, `illumination`, `angular_diameter`, `heliocentric_distance`,
`heliocentric_range_rate`, `distance`, `range_rate`, `elongation`, `phase_angle`.

```yaml
omnilab:
    sources:
        horizons:
            factory: horizons
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent, as JPL asks
```

See [docs/](docs/index.md) for what is read, how it maps, and what Horizons does not give here.

## Install and a first call

```sh
composer require omnilab/horizons
```

A real call, with no account and no key: Mars' elevation over Strasbourg on 16 October 2026, from
JPL Horizons. `mailto` is your address, sent in the User-Agent so that the service can reach you
before it blocks a caller; the source keeps to the limits its [`policy.json`](policy.json) declares.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Horizons\HorizonsSourceFactory;
use Omnilab\Model\Point;
use Omnilab\Source\SeriesQuery;

$horizons = (new HorizonsSourceFactory())->create(['mailto' => 'you@example.org']);   // your address, in the User-Agent
$day = new DateTimeImmutable('2026-10-16', new DateTimeZone('UTC'));
[$elevation] = $horizons->series(new SeriesQuery(['499:elevation'], new Point(48.5734, 7.7521, 140), $day, $day));
echo $elevation->label, ': highest ', $elevation->max(), ' ', $elevation->unit, "\n";
```

Its output on 2026-11-13:

```
Mars (499): elevation above the horizon (apparent, airless): highest 60.100290517 deg
```

## Terms of use

Horizons is a service of the Solar System Dynamics group of NASA's Jet Propulsion Laboratory
(Caltech). JPL asks that its APIs be called one request at a time, with a User-Agent that names
the application (the source waits 0.2 s between two calls and sends `mailto` in its User-Agent);
a table holds 90,024 lines at most. Cite it as JPL asks: Giorgini, J.D., and JPL Solar System
Dynamics Group, NASA/JPL Horizons On-Line Ephemeris System, https://ssd.jpl.nasa.gov/horizons/.

## Usage policy

Declared as data in [`policy.json`](policy.json) (read at https://ssd-api.jpl.nasa.gov/doc/horizons.html on 2026-11-10), and honoured on every request by the
core: a limit reached waits when its window ends within `max_wait` seconds, else the request is not
sent ([Usage policies](https://github.com/glitchr-omnisense/omnilab/blob/1.x/docs/policies.md)).

- Limits: not published (unknown); at most 1 in flight.
- Licence: not stated; commercial use: unknown.
- Credit: not required.
- Needs: a User-Agent.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by NASA, the Jet Propulsion Laboratory or Caltech. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
