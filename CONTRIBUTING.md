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
| `composer test` | the Pest suite (Arch, Unit, Feature) |
| `composer test-coverage` | the suite with line coverage, failing below 90 % |
| `composer mutate` | Pest mutation testing of the whole package, failing below a 70 % score (slow; CI runs it weekly). For your change only: `vendor/bin/pest --mutate --path=src/Changed.php,src/Other.php --min=70`, which is what pull requests run |
| `composer lint` | Pint (shared laranail config), PHPStan at level max, Rector dry run; never changes files |
| `composer composer-audit` | known-vulnerable dependencies |

## Guidelines

- `declare(strict_types=1)` in every file; classes `final` unless Laravel requires extension.
- Constructor injection only: no `app()`, `config()` or facades outside `src/Providers` and
  `src/Facades` (the architecture test enforces it).
- Every name the package registers is vendor-scoped: config `laranail.settings`, commands
  `laranail::settings.<command>`, publish tags `laranail::settings-<suffix>`.
- Test credentials are generated at run time, never written down.
- Update `CHANGELOG.md` under `## [Unreleased]` in the same pull request.
