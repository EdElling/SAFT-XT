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
