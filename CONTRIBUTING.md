# Contributing

Where this file is silent, the [laranail contributing guide](https://github.com/laranail/.github/blob/HEAD/CONTRIBUTING.md) applies.

Contributions are welcome via pull requests.

## Process

1. Fork the project and create a `<type>/<slug>` branch (`feat`, `fix`, `docs`, `chore`, `ci`).
2. Write the failing test first, then the change, then the docs.
3. Run the gates below, commit with a [Conventional Commits](https://www.conventionalcommits.org/)
   message, and open a pull request.

## Gates

| Command | What it checks |
|---|---|
| `composer check` | everything, in one command: `lint`, `test`, then the `database` group on MySQL and PostgreSQL through dbpantry (`vendor/bin/laranail-test-db`). Run it before pushing |
| `composer test` | the Pest suite (Arch, Unit, Feature) |
| `composer test-coverage` | the suite with a line coverage report. There is no fixed floor: coverage ratchets, so a pull request may not lower the total it reports, and a number set before it was measured would only be a guess CI enforces |
| `composer mutate` | Pest mutation testing of the whole package, failing below a 70 % score (slow; CI runs it weekly, not on pull requests). For your change only: `vendor/bin/pest --mutate --path=src/Changed.php,src/Other.php --exclude-group=mutation-excluded --min=70` |
| `composer lint` | Pint (shared laranail config), PHPStan at level max, Rector dry run; never changes files |
| `composer composer-audit` | known-vulnerable dependencies |
| `composer bench` | the phpbench read benchmark (`benchmarks/`): one request's reads uncached, cold and warm. Run it before and after a change to the read path or the cache, and quote both. phpbench installs into `tools/phpbench/` on first run, apart from the package, because it depends on the abandoned `doctrine/annotations` |

## The database group, locally

`composer test` runs on SQLite. The `database` group also runs against MySQL 8.4 and PostgreSQL 17,
the versions CI uses, from [simtabi/dbpantry](https://github.com/simtabi/dbpantry), the shared local
database toolkit, so this package keeps no databases of its own. The one prerequisite is
`MDB_PUBLISH=direct` in dbpantry's `.env` (dbpantry reads it from that file only; an environment
variable is refused), so its engines listen on this machine.

```bash
vendor/bin/laranail-test-db              # start dbpantry's test fixture, give this run its own databases, run the group on both
vendor/bin/laranail-test-db -- --compact # the same, passing arguments to Pest
vendor/bin/laranail-test-db env mysql    # create a database for a run by hand and print its DB_* variables
vendor/bin/laranail-test-db down         # stop the fixture
```

The helper comes from laranail/package-tools and reads `extra.laranail.test-db` in `composer.json`.
It finds dbpantry beside this checkout (`DBPANTRY_DIR` overrides it), starts its fixture (in memory,
durability off, beside the long-lived stack) and creates a database named `settings_r<id>` for each
run, so two runs, from two terminals or two sessions, never share tables. Ports, users and password
files come from dbpantry's `make endpoints`, so no port or credential is copied here. `composer check`
runs it after the lint and the SQLite suite; `bin/test-db` still works but is deprecated. Run the
group on both before opening a pull request that touches storage, migrations or queries.

## Guidelines

- `declare(strict_types=1)` in every file; classes `final` unless Laravel requires extension.
- Constructor injection only: no `app()`, `config()` or facades outside `src/Providers` and
  `src/Facades` (the architecture test enforces it).
- Every name the package registers is vendor-scoped: config `laranail.settings`, commands
  `laranail::settings.<command>`, publish tags `laranail::settings-<suffix>`.
- Test credentials are generated at run time, never written down.
- Update `CHANGELOG.md` under `## [Unreleased]` in the same pull request.
