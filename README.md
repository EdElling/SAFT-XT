# SAFT XT

**English** · [Deutsch](README.de.md)

Modular, metadata-driven database application framework built on Laravel 13, FilamentPHP v5 and PostgreSQL, MySQL or MariaDB.

**Manuals:**

- [Overview](docs/manual/README.md) 
- [User guide](docs/manual/USER_GUIDE.md)
- [Administrator guide](docs/manual/ADMIN_GUIDE.md)
- [FAQ](docs/manual/FAQ.md)
- [System administration](docs/manual/SYSADMIN_GUIDE.md)
- [Developer guide: plugins](docs/manual/DEVELOPER_GUIDE.md)
  

## Features

### Tables and data
- **Table designer:** create your own tables and fields in the interface (schema `app_data`), with safe structure
  changes; field types from text, number, date and select list to relations; required and unique checks, validation
  rules per field and table; **computed fields** with formulas, also sums over child records; sequences and “copy
  from” the parent record; data model as a diagram; tab **“Used in”** shows where a table appears in reports, menus,
  roles, plugins and other tables
- **AI assistant in the table designer** (optional): describe a new table in your own words or adapt an existing one –
  with fields, types, relations and labels in four languages
- **Data maintenance:** lists with search, filter, sorting, paging and column selection; master-detail cascade across
  several levels; preview of related records; recycle bin with restore; history per record
- **Import:** create a new table from an Excel or CSV file; **import data per table** (empty template, checked like
  manual entry, all or nothing), enabled per table and with its own permission
- **Export** of the current view as Excel or CSV, enabled per table and with its own permission
- **Number format per person:** as the language, automatic by browser, or Switzerland (1'234.50)

### Reports
- **Report editor** with drag and drop: lists, groupings, cross tables and key figures across linked tables, with
  filters and sorting; live preview while editing
- **Charts:** bar, line and pie chart
- **Formulas:** calculated values, calculated columns and figures from values (e.g. budget per publication)
- **AI assistant for reports** (optional): describe a report in your own words, the proposal is checked like any input;
  only the data model is transmitted, never records (Anthropic, Amazon Bedrock or Google Vertex AI)
- Share reports, add them to the menu and the dashboard; **table links** connect tables without foreign keys, also
  across databases; **data snapshots** of external tables for fast reports

### External databases
- **Database connections** to PostgreSQL, MySQL, MariaDB and **Microsoft SQL Server** – with connection test,
  encrypted password, TLS and read-only mode
- **Table connector:** link tables and views of external databases one by one or in bulk and use them like designer
  tables (menu, permissions, list, details, export, reports); foreign keys become relations, CHECK constraints become
  validation rules; writing can be enabled per table; comparison with the database schema

### Permissions, sign-in and operations
- **Permissions:** roles with function permissions and data permissions per table (spatie/laravel-permission); “view as
  role” to check permissions
- **Sign-in:** local, single sign-on (Microsoft Entra ID, SAML 2.0) or both; role mapping, single logout, emergency
  sign-in
- **Dynamic menu** from the database, filtered by permissions, including menu entries for data tables with their own
  column selection
- **Activity log** for all changes, sign-ins, sign-outs, exports, imports and AI requests (spatie/laravel-activitylog)
- **Scheduled tasks:** refresh data snapshots and clean up logs on a schedule, with run log and notification on
  failure
- **Dashboard** with role-dependent widgets, e.g. own reports, recent changes and the state of the scheduled
  (background) tasks
- **Databases for SAFT itself:** PostgreSQL, MySQL or MariaDB

### Languages and manuals
- **User interface** in German, English, French and Italian, selectable per person – including menus, table and field
  names (more languages via `config/locales.php`); German texts in Swiss spelling
- **Online manuals in four languages:** user guide, FAQ and administrator guide with screenshots, right in the
  application; system administration and developer guide in German and English

### Extensibility
- **Plugins:** own pages and business functions as Composer packages, without changing the core – with permissions,
  menu suggestions, a stable interface (data access with permissions and validation rules, events on saving, buttons in
  data views) and protection of the tables and fields they need; managed under **Administration › Application › Plugins**, example
  plugins “Lager-Umbuchung”, “Lager-Tafel”, “CH-Prüfungen” and “Musikschule: Planung” ([developer guide](docs/manual/DEVELOPER_GUIDE.md))
- **Plugins also extend the dashboard and table designer:** own tiles, formula functions for computed fields (e.g.
  rounding to 5 centimes) and validation rules for fields (e.g. AHV number, IBAN)
- **For developers and AI agents:** plugin scaffold (`saft:make-plugin`), check before delivery
  (`saft:plugins --check`), test base and **developer context** (rules, API reference and schema of the business tables
  as files, e.g. for Claude Code or Cursor)

### Other
- **Transfer apps:** export an app (tables, reports, menus, roles, tasks, manual) as a file and install it in another
  installation, e.g. from test to production – with preview and diagram, also as a copy with a new prefix
- **Global settings:** display name and colour scheme of the application (branding); connection of external AI models for
  the assistants (provider, model, encrypted API key, daily limit, connection test); personal settings such as language,
  number format and contrast mode under “My settings”
- **Central error handling:** database, validation and rule errors appear as an understandable message instead of a
  technical error (or as a uniform JSON structure)
- **Database backup** by command (`php artisan db:backup`) for PostgreSQL, MySQL and MariaDB

## Requirements

- PHP 8.4+ with the extensions `pdo_pgsql` or `pdo_mysql`, `intl`, `zip`, `openssl`
- Composer
- PostgreSQL 16+ (the database user must be allowed to create schemas) **or** MySQL 8.0.16+ (the user also needs
  rights on the database `<DB>_app_data` for the tables of the table designer); chosen with `DB_CONNECTION` and the
  blocks `PGSQL_*` / `MYSQL_*` in `.env`

## Installation

Step-by-step guides: [development machine](docs/manual/INSTALL_DEVELOPMENT.md) ·
[production server](docs/manual/INSTALL_PRODUCTION.md). In short:

```bash
composer install
cp .env.example .env          # fill in DB_* and ADMIN_*
php artisan key:generate
php artisan migrate
php artisan db:seed           # roles, permissions, menu, super admin (ADMIN_EMAIL/ADMIN_PASSWORD)
php artisan serve             # http://localhost:8000 -> /admin
```

Optional demo data (articles, customers, orders, order lines in the menu group "Shop (demo)", plus the shared
example report "Umsatz je Monat und Kategorie" (revenue per month and category) as a bar chart):

```bash
php artisan db:seed --class=DemoDataSeeder
```

Five more demos as tables in SAFT itself (without a database connection), each with its own menu group, data model and
example reports – the same data as the external demos:

```bash
php artisan db:seed --class=LagerDemoSeeder       # "Warehouse (demo)": suppliers, items, locations, stock, movements …
php artisan db:seed --class=VertriebDemoSeeder    # "Sales (demo)": customers, events, enquiries, orders – uses the warehouse items
php artisan db:seed --class=GitarrenshopDemoSeeder  # "Guitar shop (demo)": income and expenses with VAT, receipts, instalments, item images
php artisan db:seed --class=MusikschuleDemoSeeder   # "Music school (demo)": 21 tables from an app definition (AI app designer), courses, lessons, invoices
php artisan db:seed --class=ForschungDemoSeeder   # "Research (demo)": organisations, people, projects, publications …
```

On a production server (`APP_ENV=production`, e.g. Strato), add `--force`; otherwise Laravel asks for confirmation.

Optional external demo database for the database connections (schema `forschung` with persons, projects,
publications and organisations, on the same server as SAFT):

```bash
createdb -h 127.0.0.1 -U <user> SAFT2_ext
php artisan external-demo:install      # tables, data, read-only connection "Forschung (Demo)" and six linked tables
```

### Production

On a production server, install without the development packages and set `APP_ENV=production` in `.env`:

```bash
composer install --no-dev --optimize-autoloader
```

**This is security-relevant:** In every environment except `production`, Laravel Dusk registers the route
`/_dusk/login/{userId}`, which lets anyone sign in as any user without a password. Without the development packages
this route does not exist.

The demo data is not available there, because `DemoDataSeeder` needs the development package Faker. The tests also
only run with a full installation.

## Operations

| Task | Command |
|---|---|
| Force local sign-in (emergency exit) | `AUTH_FORCE_LOCAL=true` in `.env` |
| Set the sign-in mode from the console | `php artisan auth:mode local` |
| Compare the table designer with the database | `php artisan app-data:verify` |
| Back up the database (to `storage/backups`, files from file fields as a ZIP alongside) | `php artisan db:backup` |
| Clean up orphaned files from file fields (`--dry-run` only counts) | `php artisan files:prune` |
| Recalculate computed fields | `php artisan computed:recompute` |
| Compare linked tables with their external databases (`--apply` applies differences) | `php artisan table-connector:verify` |
| Change the maximum number of records per export (default 50,000) | `APP_DATA_EXPORT_MAX_ROWS` in `.env` |
| Time zone and default language | `APP_TIMEZONE`, `APP_LOCALE` in `.env` |

A backup can be restored into a **new, empty** database (adjust the database name and file):

```bash
createdb -h 127.0.0.1 -U <user> SAFT2_restored
pg_restore -h 127.0.0.1 -U <user> --no-owner -d SAFT2_restored storage/backups/<file>.dump
```

With MySQL, the `.sql` file contains the main database and `…_app_data` under their original names
(`mysql -h 127.0.0.1 -u <user> -p < storage/backups/<file>.sql`); for other names, adjust the `CREATE DATABASE` and
`USE` lines first.

The files from file fields are in the ZIP file with the same name and `_files.zip`; unzip it after restoring the
database: `unzip storage/backups/<file>_files.zip -d storage/app/private`.

Backups contain all data and stay outside the repository. Keep an additional copy outside this machine.

## Tests

The tests run against a separate PostgreSQL database `SAFT2_test` and the external test database `SAFT2_ext_test`
(see `phpunit.xml`; the tests create the schema `forschung` there themselves). `composer test:mysql` runs the same
tests against MySQL (`phpunit.mysql.xml`, database `SAFT2_test` on the MySQL server), `composer test:mariadb` against
MariaDB (`phpunit.mariadb.xml`); the tests for MySQL as an
external source use `SAFT2_ext_test` there and are skipped if no MySQL server is reachable:

```bash
createdb -h 127.0.0.1 -U <user> SAFT2_test
createdb -h 127.0.0.1 -U <user> SAFT2_ext_test
php artisan test
```

### Browser tests (Laravel Dusk)

The browser tests run headless against their own server (port 8010) and the database `SAFT2_dusk`.
**Every test empties this database.** `tests/DuskTestCase.php` therefore aborts as soon as any other database would
be used. Do not use `php artisan dusk`: it temporarily swaps the `.env`.

One-time setup:

```bash
createdb -h 127.0.0.1 -U <user> SAFT2_dusk
createdb -h 127.0.0.1 -U <user> SAFT2_ext_dusk
npx @puppeteer/browsers install chrome@stable --path ~/.cache/saft-dusk
npx @puppeteer/browsers install chromedriver@stable --path ~/.cache/saft-dusk
npm install   # axe-core for the contrast check (WCAG)
```

Also copy the template `.env.browser.example` to `.env.browser` (not in the repository), enter the credentials
`PGSQL_*` and run `php artisan key:generate --env=browser`.

Run:

```bash
composer test:browser          # SAFT on PostgreSQL
composer test:browser:mysql    # the same tests with SAFT on MySQL
composer test:browser:mariadb  # the same tests with SAFT on MariaDB
```

**MySQL:** On the MySQL server, create the database `SAFT2_dusk` (SAFT creates `SAFT2_dusk_app_data` itself; the user
needs rights on both), copy `.env.browser-mysql.example` to `.env.browser-mysql`, enter the credentials `MYSQL_*` and
`PGSQL_*` and run `php artisan key:generate --env=browser-mysql`. The external test database of the table connector
(`SAFT2_ext_dusk`) stays on PostgreSQL.

**MariaDB:** create the databases `SAFT2_dusk` and `SAFT2_dusk_app_data` on the MariaDB server and give the user rights
on them, copy `.env.browser-mariadb.example` to `.env.browser-mariadb`, enter the credentials `MARIADB_*` and
`PGSQL_*` and run `php artisan key:generate --env=browser-mariadb`.

**When do they run?** The complete suite (about half an hour per database) runs immediately before a release is
published, on all three databases – most easily on GitHub (see below). After changes, the affected test or test class
is enough, e.g. `vendor/bin/phpunit -c phpunit.dusk.xml tests/Browser/ListTest.php`.

### Tests on GitHub Actions

The pipeline `.github/workflows/tests.yml` runs **only on demand**, never automatically on a push: on GitHub under
**Actions → Tests → Run workflow** or on the console:

```bash
gh workflow run tests.yml                                         # default: feature and browser tests, all three databases
gh workflow run tests.yml -f suites=feature -f databases=mariadb  # only a part
gh workflow run tests.yml -f sqlserver=true                       # also SQL Server as an external source
gh run watch                                                      # follow the progress
```

Each test type and database runs as its own job, all in parallel; PostgreSQL, MySQL and MariaDB (and SQL Server on
request) are throwaway containers with test passwords from the workflow file, no secrets are needed. On failures,
screenshots, browser console and Laravel log are attached to the run as an artifact (`gh run download <run>`);
`gh run rerun <run> --failed` reruns only the failed jobs. A complete run takes about 145 minutes of compute time
(for private repositories within GitHub's quota), the feature tests alone about 25.

Screenshots of failed tests are stored in `tests/Browser/screenshots`.

### Screenshots for the user manual

The figures in the user manual are created with the same tools, in English and German:

```bash
composer manual:screenshots
```

The command uses the browser test setup and also empties `SAFT2_dusk`. The images are written to
`docs/manual/images/<language>/`; details are in Appendix A of the system administration guide.

## Development

Architecture and working rules for further development are in [CLAUDE.md](CLAUDE.md).

## License

SAFT XT is licensed under the **PolyForm Noncommercial License 1.0.0**. The text in
[LICENSE](LICENSE) is binding; in short:

- **Permitted, free of charge:** use for noncommercial purposes – personal use (research, study, testing, hobby
  projects) and use by charitable organisations, educational institutions, public research organisations, public
  safety or health organisations, environmental protection organisations and government institutions; viewing,
  changing and passing on the source code for these purposes.
- **Required:** anyone receiving a copy also receives the licence text (or its URL) and the line beginning with
  `Required Notice:` from [LICENSE](LICENSE).
- **Only with a separate written licence:** any commercial use, including internal use in companies, sale or
  distribution for a fee and operation as a paid service. Contact: see [LICENSE](LICENSE).
- **No warranty and no liability**, as far as the law allows.

Third-party components keep their own licences, listed in [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

**Predecessor SAFT2:** the predecessor project SAFT2 (versions up to 2.8.0) was published under a different licence
(EULA). It is withdrawn, no longer supported and must not be used; use SAFT XT instead.

**Licence text:** [PolyForm Noncommercial 1.0.0](https://polyformproject.org/licenses/noncommercial/1.0.0#changes-and-new-works-license) on the PolyForm Project website.
