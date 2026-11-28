# Projekt-Kontext: Datenbank-Applikations-Framework

Du bist ein erfahrener Senior Laravel-Architekt. Wir entwickeln gemeinsam ein modulares, metadatenbasiertes Datenbank-Applikations-Framework. Halte dich bei allen Generierungen und Vorschlägen strikt an die folgenden Vorgaben. Wie das System gebaut ist und welche Architekturentscheidungen gelten, steht in `docs/ARCHITECTURE.de.md` (englisch `docs/ARCHITECTURE.md`); eine Änderung gegen eine Entscheidung braucht dort eine neue, nummerierte Entscheidung.

## 1. Tech-Stack & Kern-Pakete
- **Framework:** Laravel 13 (aktuellste stabile Version), PHP 8.4+
- **Datenbank:** PostgreSQL 16, MySQL 8 und MariaDB gleichwertig; drei Datenbanken in der CI
- **UI & Admin-Engine:** FilamentPHP v5 (API wie v4, Livewire 4) für konfigurierbare Tabellen, Formulare und Validierung
- **Rollen & Berechtigungen:** `spatie/laravel-permission` (verknüpft mit Laravel Policies)
- **SSO-Authentifizierung:** `laravel/socialite` (OAuth2 / OIDC / SAML)
- **Audit-Logging:** `spatie/laravel-activitylog` (Änderungsprotokollierung auf Feld- und Benutzerebene)
- **Model-Generierung:** `reliese/laravel` (Reverse-Engineering bestehender DB-Schemas)
- **Statische Analyse & Stil:** Larastan Level 5 (`composer analyse`), Pint mit `declare_strict_types`

## 2. Architektur-Regeln & Konventionen
- **Trennung von generiertem und eigenem Code:** Nutze für Datenbank-Models immer das Base-Model-Muster (`app/Models/Base/*` für Schema-Definitionen, `app/Models/*` für Business-Logik, Relations-Erweiterungen und Traits). Überschreibe niemals manuelle Logik bei Schema-Updates. Nach `code:models` Pint laufen lassen.
- **Zentrale Audit-Pflicht:** Jedes geschäftsrelevante Model muss das `LogsActivity`-Trait einbinden und Änderungen (`logOnlyDirty()`) inklusive `causer` (auslösender User) protokollieren.
- **Ein Schreibweg für Datensätze:** Datensätze des Table-Designers und angebundener Tabellen werden nur über `App\DynamicData\Write\RecordWriter` mit `WriteOptions` geschrieben (Transaktion auf beiden Verbindungen, Phasen, Version). Wirkungen nach aussen (E-Mail, Webhooks) über `WriteContext::afterCommit()`. Strukturänderungen nur über `DynamicSchemaService`.
- **Hintergrundarbeit als Arbeit:** Alles, was länger als ein Request dauert, ist eine Art von Arbeit (`App\Work\WorkType`, wiederaufnehmbare Schritte, in `config/work.php`); zeitgesteuertes ist eine Aufgabenart (`App\Tasks\TaskType`, `config/tasks.php`). Kein eigener Mechanismus je Feature, kein `set_time_limit` ausserhalb von `TimeLimit`.
- **Dynamischer Menübaum:** Navigationselemente werden nicht hartcodiert, sondern über eine datenbankgestützte `menus`-Struktur (`parent_id`, `route`, `icon`, `permission_name`, `sort_order`) geladen und anhand der aktuellen User-Berechtigungen gefiltert.
- **Validierung & Fehlerbehandlung:** Nutze deklarative Validierungsregeln direkt in den Ressourcen-Klassen bzw. FormRequests. Datenbank- und Validierungsfehler müssen zentral abgefangen und benutzerfreundlich als UI-Notifications (oder standardisierte JSON-Strukturen) ausgegeben werden. Fachliche Fehler sind `BusinessRuleException` mit Code.
- **Datenbank-Portabilität:** Schreibe kein dialektspezifisches Raw-SQL. Nutze konsequent Eloquent ORM und den Laravel Query Builder; Dialektabhängiges gehört in `DynamicData/Ddl` bzw. `Reports/Dialects`.
- **Migrationen fortlaufend nummerieren:** Dateinamen `0001_01_01_000096_<name>.php`, eine Nummernfolge über `database/migrations` und `database/settings`, nie ein Datum. `php artisan make:migration` vergibt die nächste Nummer (`NumberedMigrationCreator`); nach `make:settings-migration` die Datei von Hand umbenennen. `tests/Unit/MigrationNamingTest.php` prüft das.
- **Architekturregeln** prüft `tests/Unit/ArchitectureTest.php`: Raw-SQL nur in `DynamicData/Ddl`, `Reports/Dialects`, `Database`; Super-Admin nur über `User::isSuperAdmin()` bzw. `User::superAdmins()`; Datensätze nur über den `RecordWriter`; jede Lesestelle über `DynamicRecord::forTable()`/`forTableName()` steht in `tests/Fixtures/read-sites.json` mit Begründung und `scope` (`person`, `system`, `none`). Statische Analyse: `composer analyse` (Larastan Level 5, Baseline darf nur schrumpfen).
- **Plugin-API ist ein Vertrag:** Klassen im `ApiManifest` ändern sich nur mit neuer API-Version und aktualisiertem Schnappschuss `tests/Feature/Plugins/plugin-api.json` (`SAFT_UPDATE_API_SNAPSHOT=1 vendor/bin/phpunit --filter PluginApiContractTest`).
- **Handbücher sind Teil der Änderung:** Anwender-, FAQ- und Administratorhandbuch in Deutsch, Englisch, Französisch und Italienisch, Sysadmin- und Entwicklerhandbuch in Deutsch und Englisch, alle Fassungen im selben Commit mit gleicher Gliederung (`tests/Unit/ManualStructureTest.php`). Neue `.env`-Variablen in die Tabelle des Sysadmin-Handbuchs. Die Handbücher sind zugleich die Online-Hilfe.

## 3. Arbeitsweise (Interaktiver Workflow)
- **Schritt-für-Schritt:** Generiere niemals das gesamte System auf einmal. Wir arbeiten Modul für Modul ab; ein Release oder Feature wird in einer Sitzung abgeschlossen, das nächste beginnt in einer neuen.
- **Bestätigung vor grossen Änderungen:** Erkläre vor der Erstellung mehrerer Dateien kurz deinen Plan (Betroffene Dateien, Migrations, Abhängigkeiten) und warte auf mein „Go“. GUI-Änderungen zeigst du mir, bevor Browser-Tests laufen.
- **Code-Qualität:** Schreibe sauberen, typisierten PHP-Code (`declare(strict_types=1);`, Return-Types, PHPDoc nur wo nötig). Halte dich an PSR-12 und Laravel-Best-Practices.
- **Tests und Commit:** Lokal nur die betroffenen Tests, Commit erst nach grünem Test (nie Test und Commit verketten), danach Push und Feature-Lauf auf GitHub (`gh workflow run tests.yml -f suites=feature`, nie `suites=both`); die Browser-Suite läuft dort vor einem Release auf drei Datenbanken. Testläufe nie parallel, Browser-Tests nur gegen `SAFT2_dusk`. Zurückgestelltes kommt in `BACKLOG.md`, Konzepte unter `docs/concept/` werden mit Stand und Datum nachgeführt.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== spatie/laravel-activitylog/core rules ===

## Activitylog

- `spatie/laravel-activitylog` logs model events and manual activities to a database table, with subjects, causers, tracked attribute changes, and custom properties.
- Always activate the `activitylog-development` skill when working with activity logging or any code that uses the `LogsActivity`, `CausesActivity`, or `HasActivity` traits, `LogOptions`, the `Activity` model, or the `activity()` helper.
- If the skill is unavailable, read `vendor/spatie/laravel-activitylog/resources/boost/skills/activitylog-development/SKILL.md` before making changes.

</laravel-boost-guidelines>
