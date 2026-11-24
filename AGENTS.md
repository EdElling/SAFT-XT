# AGENTS.md – Einstieg für KI-Agenten und neue Entwickler

SAFT XT ist ein metadatenbasiertes Datenbank-Applikations-Framework auf Laravel 13, FilamentPHP v5 und PHP 8.4 mit
PostgreSQL, MySQL oder MariaDB. Diese Datei sagt, was ein Agent zuerst liest, welche Regeln verbindlich sind und
wann eine Aufgabe fertig ist. Sie gilt für Arbeit **am Kern** in diesem Repository. Wer ein **Plugin** für eine
bestimmte Installation schreibt, arbeitet stattdessen mit dem Entwickler-Kontext, den `php artisan saft:plugin-context`
erzeugt (eigene `AGENTS.md`, `api-reference.md`, `schema.md`; siehe Entwicklerhandbuch, Kapitel 15).

## Zuerst lesen

1. [`CLAUDE.md`](CLAUDE.md) – verbindliche Regeln und Arbeitsweise (Tech-Stack, Konventionen, Schritt für Schritt mit Bestätigung).
2. [`docs/ARCHITECTURE.de.md`](docs/ARCHITECTURE.de.md) ([English](docs/ARCHITECTURE.md)) – Schichten, die zwei
   Verbindungen, der eine Schreibweg, Rechte, Erweiterungspunkte, Hintergrundarbeit und die nummerierten
   Architekturentscheidungen. Eine Änderung, die einer Entscheidung widerspricht, braucht eine neue Entscheidung dort.
3. Das Konzept des Vorhabens unter [`docs/concept/`](docs/concept/) und den Eintrag in [`BACKLOG.md`](BACKLOG.md).
4. Für Oberfläche und Verhalten: die [Handbücher](docs/manual/README.de.md); für die Plugin-API das
   [Entwicklerhandbuch](docs/manual/DEVELOPER_GUIDE.de.md).

## Regeln, die die Maschine prüft

- Datensätze des Table-Designers werden nur über `App\DynamicData\Write\RecordWriter` mit `WriteOptions` geschrieben.
- Kein dialektspezifisches Raw-SQL ausserhalb von `app/DynamicData/Ddl`, `app/Reports/Dialects`, `app/Database`.
- Super-Admin nur über `User::isSuperAdmin()` bzw. `User::superAdmins()`.
- Jede neue Lesestelle über `DynamicRecord::forTable()`/`forTableName()` wird in `tests/Fixtures/read-sites.json`
  mit Begründung und `scope` eingetragen.
- Migrationen heissen `0001_01_01_0000NN_<name>.php` in einer Nummernfolge über `database/migrations` und
  `database/settings`; `php artisan make:migration` vergibt die Nummer.
- Larastan Level 5: `composer analyse` muss ohne neue Meldungen durchlaufen, die Baseline darf nur schrumpfen.
- Die Plugin-API ist ein Vertrag: Änderungen an Klassen im `ApiManifest` brauchen eine neue API-Version und den
  aktualisierten Schnappschuss `tests/Feature/Plugins/plugin-api.json`.
- Handbücher: Anwender, FAQ und Administration in Deutsch, Englisch, Französisch und Italienisch, Sysadmin und
  Entwickler in Deutsch und Englisch; alle Fassungen im selben Commit, gleiche Gliederung
  (`tests/Unit/ManualStructureTest.php`).

Die Prüfungen stehen in `tests/Unit/ArchitectureTest.php`, `MigrationNamingTest.php`, `StaticAnalysisBaselineTest.php`,
`ManualStructureTest.php` und `tests/Feature/Plugins/PluginApiContractTest.php`.

## Befehle

```bash
composer analyse                                   # Larastan
vendor/bin/pint --dirty                            # Code-Stil (Preset laravel, strict_types)
vendor/bin/phpunit --filter <Testklasse>           # einzelne Feature- oder Unit-Tests (PostgreSQL)
vendor/bin/phpunit -c phpunit.mysql.xml --filter … # dasselbe auf MySQL; phpunit.mariadb.xml für MariaDB
vendor/bin/phpunit -c phpunit.dusk.xml --filter …  # Browser-Tests (Dusk) gegen die Datenbank SAFT2_dusk
php artisan saft:plugins --check                   # Plugins prüfen
```

Volle Feature-Läufe laufen auf GitHub (`gh workflow run tests.yml -f suites=feature`), die Browser-Suite dort vor
einem Release. Test-Läufe nie parallel starten: sie teilen die Testdatenbank.

## Wann eine Aufgabe fertig ist

- Die betroffenen Tests sind grün, `composer analyse` meldet nichts Neues, Pint ist gelaufen.
- Neue oder geänderte Oberfläche steht in den Handbüchern aller Sprachen; neue Umgebungsvariablen in der
  `.env`-Tabelle des Sysadmin-Handbuchs; neue Entscheidungen in `docs/ARCHITECTURE.de.md` und `.md`.
- Konzept und `BACKLOG.md` sind nachgeführt (Stand, Datum, Commit).
- Commit erst nach grünem Test, danach Push und Feature-Lauf auf GitHub.
