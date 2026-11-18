# Contributing

Thank you for helping. This is a small project with a high bar: every
change keeps the tests, static analysis and coding standard green.

## Setup

```sh
git clone https://github.com/IndianOpinion/translate-sidecar.git
cd translate-sidecar
composer install
```

PHP 8.1 or later. The code must run on 8.1, so no 8.2+ syntax (no readonly
classes, no DNF types, no standalone `true`, `false` or `null` types, no
constants in traits).

## Tests

```sh
composer test        # PHPUnit, no WordPress needed (tests/stubs/)
composer stan        # PHPStan level 6 with WordPress stubs
composer cs          # PHP_CodeSniffer, PSR-12
composer check       # all three
```

Database tests run against a real MySQL or MariaDB when you point them at
one; they create and drop the database named in `TS_MYSQL_DB` (default
`translate_sidecar_test`), so use a throwaway server:

```sh
TS_MYSQL_SOCKET=/path/to/mysqld.sock vendor/bin/phpunit --testsuite integration
```

Tests never use the network.

## Style

- PSR-12, `declare(strict_types=1);` in every file.
- Typed exceptions; never `@`, `eval` or shell calls.
- Escape every output in WordPress; nonce and capability checks on every
  admin action.
- Prose: short sentences, plain words, British spelling, no em dashes.

## Pull requests

- One topic per pull request. Describe the behaviour change and the PRD
  case it touches (`docs/PRD.md`).
- Add or update a test for every behaviour change.
- Update `CHANGELOG.md` under "Unreleased".
- A DCO-style sign-off is not required.
