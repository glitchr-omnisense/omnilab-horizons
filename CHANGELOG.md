# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab Horizons: the ephemeris of a body of the solar system - a planet, a moon, the Sun, an
  asteroid, a comet, a spacecraft - seen from a place on the Earth over a period, as series: right
  ascension and declination, azimuth and elevation, airmass, apparent magnitude, illuminated
  fraction, angular diameter, distances to the observer and to the Sun and their rates, solar
  elongation and phase angle, through NASA/JPL's Horizons API, no key needed.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/horizons` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
