# SAFT XT in Zahlen

[English](METRICS.md) · **Deutsch**

Stand: 27.11.2026, Version 0.9.0 (Commit `76701a7`). Gezählt werden nur Dateien unter Versionskontrolle
(`git ls-files`), ohne `vendor/` und `node_modules/`. Die Zahlen veralten mit jedem Commit; wie sie entstehen, steht
am Ende.

## Überblick

| Kennzahl | Wert |
|---|---:|
| PHP-Zeilen gesamt | 163'806 in 1'352 Dateien |
| davon Anwendung (`app/`) | 77'962 in 630 Dateien (ca. 54'000 ohne Leer- und Kommentarzeilen) |
| davon Tests (`tests/`) | 47'842 in 243 Dateien |
| Testfälle Unit, Feature, Plugins | 1'480 |
| Testfälle Browser (Dusk) | 304 |
| Handbuchzeilen (fünf Handbücher, alle Sprachen) | 17'228 |
| Commits / Releases | 224 / 12 (v0.1.0 bis v0.8.0; 0.9.0 noch ohne Tag) |

## Codezeilen

Zeilen einschliesslich Leer- und Kommentarzeilen.

| Bereich | Dateien | Zeilen |
|---|---:|---:|
| `app/` (PHP) | 630 | 77'962 |
| `plugins/` (PHP, vier Beispiel-Plugins) | 59 | 6'126 |
| `tests/` (PHP) | 243 | 47'842 |
| `database/` (Migrationen, Settings, Seeder, Factories) | 119 | 8'642 |
| `config/` | 23 | 2'762 |
| `lang/` (PHP-Sprachdateien) | 214 | 17'664 |
| `lang/` (JSON) | 3 | 693 |
| Blade-Vorlagen | 62 | 2'865 |
| Markdown (Handbücher, Konzepte, Readme, Backlog) | 73 | 30'958 |

Nicht mitgezählt: `resources/js/vendor/mermaid.min.js` (mitgeliefert, 4'330 Zeilen).

## Dateien in `app/`

| Verzeichnis | Dateien | Zeilen |
|---|---:|---:|
| `Filament` (Oberfläche) | 157 | 19'063 |
| `DynamicData` (Table-Designer, Schreibweg, DDL) | 106 | 16'979 |
| `Models` (davon 26 Base-Models) | 61 | 4'914 |
| `Plugins` (Plugin-API) | 34 | 3'490 |
| `Auth` | 29 | 2'007 |
| `Reports` | 29 | 4'321 |
| `Apps` | 26 | 6'351 |
| `Documents` | 26 | 3'332 |
| `Work` (Arbeiten) | 23 | 2'865 |
| `ExternalData` (Table-Connector) | 22 | 3'421 |
| `Tasks` (Aufgaben) | 19 | 1'541 |
| `Console` | 17 | 1'373 |
| `Http` | 15 | 830 |
| `Policies` | 12 | 491 |
| übrige 17 Verzeichnisse | 54 | 6'984 |
| **Summe** | **630** | **77'962** |

Bausteine im Einzelnen:

| Baustein | Anzahl |
|---|---:|
| Eloquent-Models (Base / Fachlogik) | 26 / 35 |
| Filament-Ressourcen / eigene Seiten / Widgets | 14 / 16 / 12 |
| Policies | 12 |
| Artisan-Befehle | 17 |
| Arten von Arbeit (`config/work.php`) | 10 |
| Aufgabenarten (`config/tasks.php`) | 10 |
| Migrationen / Settings-Migrationen | 92 / 10 |
| Beispiel-Plugins | 4 |

## Tests

Testfälle laut `phpunit --list-tests` (Datenprovider zählen je Datensatz).

| Suite | Dateien | Testmethoden | Testfälle | Zeilen |
|---|---:|---:|---:|---:|
| Feature | 159 | 1'229 | 1'275 | 33'425 |
| Unit (u. a. Architektur-, Migrations-, Handbuchregeln) | 21 | 74 | 162 | 1'540 |
| Plugins (`plugins/*/tests`) | 4 | 43 | 43 | – |
| Browser (Laravel Dusk) | 42 | 304 | 304 | 10'583 |
| Handbuch-Screenshots (`tests/Manual`) | 1 | – | 68 | – |

Unit-, Feature- und Plugin-Tests laufen auf GitHub gegen PostgreSQL, MySQL und MariaDB, die Browser-Suite vor jedem
Release ebenfalls auf allen drei Datenbanken.

## Dokumentation

Zeilen der Handbücher je Sprache:

| Handbuch | DE | EN | FR | IT |
|---|---:|---:|---:|---:|
| Anwenderhandbuch | 735 | 699 | 768 | 735 |
| Administratorhandbuch | 2'029 | 1'976 | 2'109 | 2'057 |
| Häufige Fragen | 242 | 233 | 249 | 242 |
| Systemadministration | 474 | 466 | – | – |
| Entwicklerhandbuch | 2'122 | 2'092 | – | – |

Dazu 72 Screenshots, 11 Konzepte unter `docs/concept/` und 28 nummerierte Architekturentscheidungen in
[docs/ARCHITECTURE.de.md](docs/ARCHITECTURE.de.md). Die Oberfläche ist in vier Sprachen übersetzt (Deutsch,
Englisch, Französisch, Italienisch).

## Qualität und Abhängigkeiten

| Kennzahl | Wert |
|---|---:|
| Larastan-Level | 5 |
| Einträge in der Baseline (`phpstan-baseline.neon`) | 203 |
| Composer-Pakete direkt (require / require-dev) | 21 / 15 |
| Composer-Pakete installiert (inkl. transitiv) | 166 / 67 |
| npm-Pakete (dependencies / devDependencies) | 1 / 6 |
| Backlog-Einträge in [BACKLOG.md](BACKLOG.md) (B-001 bis B-172) | 123, davon 57 erledigt |
| GitHub-Workflows | 2 (`tests.yml`, `security-audit.yml`) |

## Projektverlauf

| Kennzahl | Wert |
|---|---:|
| Erster Commit in diesem Repository | 16.11.2026 |
| Commits | 224 |
| Releases | 12 (v0.1.0 bis v0.8.0) |
| Aktuelle Version (`config/saft.php`) | 0.9.0 |

## So entstehen die Zahlen

```bash
# Dateien und Zeilen je Bereich (Beispiel app/)
git ls-files 'app/*.php' | wc -l
git ls-files 'app/*.php' | xargs cat | wc -l

# Testfälle je Suite (führt keine Tests aus)
vendor/bin/phpunit --testsuite Feature --list-tests | grep -c '^ - '
vendor/bin/phpunit -c phpunit.dusk.xml --testsuite Browser --list-tests | grep -c '^ - '

# Testmethoden
git ls-files 'tests/Feature/*Test.php' | xargs grep -hE 'public function test|#\[Test\]' | wc -l

# Commits und Releases
git rev-list --count HEAD
git tag | wc -l
```
