# SAFT XT in numbers

**English** · [Deutsch](METRICS.de.md)

As of 27 Nov 2026, version 0.9.0 (commit `76701a7`). Only files under version control are counted (`git ls-files`),
without `vendor/` and `node_modules/`. The figures age with every commit; how they are produced is shown at the end.

## Overview

| Metric | Value |
|---|---:|
| PHP lines in total | 163,806 in 1,352 files |
| of which application (`app/`) | 77,962 in 630 files (about 54,000 without blank and comment lines) |
| of which tests (`tests/`) | 47,842 in 243 files |
| Test cases unit, feature, plugins | 1,480 |
| Test cases browser (Dusk) | 304 |
| Manual lines (five manuals, all languages) | 17,228 |
| Commits / releases | 224 / 12 (v0.1.0 to v0.8.0; 0.9.0 not yet tagged) |

## Lines of code

Lines including blank and comment lines.

| Area | Files | Lines |
|---|---:|---:|
| `app/` (PHP) | 630 | 77,962 |
| `plugins/` (PHP, four example plugins) | 59 | 6,126 |
| `tests/` (PHP) | 243 | 47,842 |
| `database/` (migrations, settings, seeders, factories) | 119 | 8,642 |
| `config/` | 23 | 2,762 |
| `lang/` (PHP language files) | 214 | 17,664 |
| `lang/` (JSON) | 3 | 693 |
| Blade templates | 62 | 2,865 |
| Markdown (manuals, concepts, readme, backlog) | 73 | 30,958 |

Not counted: `resources/js/vendor/mermaid.min.js` (bundled, 4,330 lines).

## Files in `app/`

| Directory | Files | Lines |
|---|---:|---:|
| `Filament` (user interface) | 157 | 19,063 |
| `DynamicData` (table designer, write path, DDL) | 106 | 16,979 |
| `Models` (26 of them base models) | 61 | 4,914 |
| `Plugins` (plugin API) | 34 | 3,490 |
| `Auth` | 29 | 2,007 |
| `Reports` | 29 | 4,321 |
| `Apps` | 26 | 6,351 |
| `Documents` | 26 | 3,332 |
| `Work` (background work) | 23 | 2,865 |
| `ExternalData` (table connector) | 22 | 3,421 |
| `Tasks` (scheduled tasks) | 19 | 1,541 |
| `Console` | 17 | 1,373 |
| `Http` | 15 | 830 |
| `Policies` | 12 | 491 |
| other 17 directories | 54 | 6,984 |
| **Total** | **630** | **77,962** |

Building blocks in detail:

| Building block | Count |
|---|---:|
| Eloquent models (base / business logic) | 26 / 35 |
| Filament resources / custom pages / widgets | 14 / 16 / 12 |
| Policies | 12 |
| Artisan commands | 17 |
| Kinds of work (`config/work.php`) | 10 |
| Task types (`config/tasks.php`) | 10 |
| Migrations / settings migrations | 92 / 10 |
| Example plugins | 4 |

## Tests

Test cases according to `phpunit --list-tests` (data providers count once per data set).

| Suite | Files | Test methods | Test cases | Lines |
|---|---:|---:|---:|---:|
| Feature | 159 | 1,229 | 1,275 | 33,425 |
| Unit (including architecture, migration and manual rules) | 21 | 74 | 162 | 1,540 |
| Plugins (`plugins/*/tests`) | 4 | 43 | 43 | – |
| Browser (Laravel Dusk) | 42 | 304 | 304 | 10,583 |
| Manual screenshots (`tests/Manual`) | 1 | – | 68 | – |

Unit, feature and plugin tests run on GitHub against PostgreSQL, MySQL and MariaDB; the browser suite also runs on all
three databases before every release.

## Documentation

Lines of the manuals per language:

| Manual | DE | EN | FR | IT |
|---|---:|---:|---:|---:|
| User guide | 735 | 699 | 768 | 735 |
| Administrator guide | 2,029 | 1,976 | 2,109 | 2,057 |
| FAQ | 242 | 233 | 249 | 242 |
| System administration | 474 | 466 | – | – |
| Developer guide | 2,122 | 2,092 | – | – |

In addition 72 screenshots, 11 concepts under `docs/concept/` and 28 numbered architecture decisions in
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). The user interface is translated into four languages (German, English,
French, Italian).

## Quality and dependencies

| Metric | Value |
|---|---:|
| Larastan level | 5 |
| Entries in the baseline (`phpstan-baseline.neon`) | 203 |
| Direct Composer packages (require / require-dev) | 21 / 15 |
| Installed Composer packages (including transitive) | 166 / 67 |
| npm packages (dependencies / devDependencies) | 1 / 6 |
| Backlog entries in [BACKLOG.md](BACKLOG.md) (B-001 to B-172) | 123, 57 of them done |
| GitHub workflows | 2 (`tests.yml`, `security-audit.yml`) |

## Project history

| Metric | Value |
|---|---:|
| First commit in this repository | 16 Nov 2026 |
| Commits | 224 |
| Releases | 12 (v0.1.0 to v0.8.0) |
| Current version (`config/saft.php`) | 0.9.0 |

## How the figures are produced

```bash
# Files and lines per area (example app/)
git ls-files 'app/*.php' | wc -l
git ls-files 'app/*.php' | xargs cat | wc -l

# Test cases per suite (does not run any tests)
vendor/bin/phpunit --testsuite Feature --list-tests | grep -c '^ - '
vendor/bin/phpunit -c phpunit.dusk.xml --testsuite Browser --list-tests | grep -c '^ - '

# Test methods
git ls-files 'tests/Feature/*Test.php' | xargs grep -hE 'public function test|#\[Test\]' | wc -l

# Commits and releases
git rev-list --count HEAD
git tag | wc -l
```
