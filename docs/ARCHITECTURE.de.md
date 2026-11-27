# SAFT XT – Architektur

[English](ARCHITECTURE.md) · **Deutsch**

Stand: 24.11.2026 (Fundament 2, F5; Release 0.6.0) · Für: Entwicklung am Kern, Plugin-Entwicklung, KI-Agenten

Dieses Dokument beschreibt, wie SAFT XT gebaut ist und warum. Es nennt die Schichten, die zwei Datenbankverbindungen,
den einen Schreibweg, das Rechte-Modell, die Erweiterungspunkte und die Hintergrundarbeit, und hält am Ende die
Architekturentscheidungen fest, nummeriert, mit Datum und Begründung. Was Anwender und Administration brauchen,
steht in den [Handbüchern](manual/README.de.md); wie man ein Plugin schreibt, im
[Entwicklerhandbuch](manual/DEVELOPER_GUIDE.de.md). Grössere Vorhaben haben ein Konzept unter `docs/concept/`; die
Entscheidungen hier fassen deren Ergebnisse zusammen.

## Inhalt

- [1. Grundsätze](#1-grundsätze)
- [2. Schichten und Verzeichnisse](#2-schichten-und-verzeichnisse)
- [3. Die zwei Verbindungen](#3-die-zwei-verbindungen)
- [4. Metadaten und Datensätze](#4-metadaten-und-datensätze)
- [5. Der Schreibweg](#5-der-schreibweg)
- [6. Lesen und Rechte](#6-lesen-und-rechte)
- [7. Erweiterungspunkte](#7-erweiterungspunkte)
- [8. Hintergrundarbeit](#8-hintergrundarbeit)
- [9. Portabilität und Qualitätssicherung](#9-portabilität-und-qualitätssicherung)
- [10. Entscheidungen](#10-entscheidungen)

## 1. Grundsätze

SAFT XT ist ein metadatenbasiertes Datenbank-Applikations-Framework auf Laravel 13, FilamentPHP v5 (Livewire 4) und
PHP 8.4. Fachanwendungen entstehen durch Konfigurieren statt Programmieren: Tabellen, Felder, Formeln, Prüfregeln,
Menüs, Rollen, Auswertungen, Dokumentvorlagen und Aufgaben sind Daten. Programmiert wird nur, was sich nicht
konfigurieren lässt, und zwar als Plugin gegen eine versionierte API.

Fünf Grundsätze ziehen sich durch den Code:

1. **Eine Wahrheit.** Die Datenbank ist massgeblich; Definitionen (`saft-app/1`, `saft-schema/1`) werden aus ihr
   erzeugt, nie umgekehrt gepflegt.
2. **Ein Weg je Aufgabe.** Strukturänderungen laufen nur über `DynamicSchemaService`, Datensätze werden nur über den
   `RecordWriter` geschrieben, Hintergrundarbeit nur als Arbeit (`WorkType`). Architekturtests halten das fest.
3. **Portabel.** PostgreSQL, MySQL und MariaDB sind gleichwertig; dialektabhängiges SQL steht nur in Dialektklassen.
4. **Betrieb ohne Sonderausstattung.** Webspace mit Cron genügt; Queue-Worker und Redis sind optional.
5. **Prüfbar durch die Maschine.** Was sich als Regel formulieren lässt, prüft ein Test oder die statische Analyse,
   weil bei KI-gestützter Entwicklung das Prüfen der Engpass ist, nicht das Schreiben.

## 2. Schichten und Verzeichnisse

Von aussen nach innen: Oberfläche (Filament, Livewire) → Fachlogik je Thema (eigene Namensräume unter `app/`) →
Models (Eloquent) → Datenbank. Fachlogik hängt nie von der Oberfläche ab; Filament-Klassen rufen Dienste und
enthalten selbst keine Regeln. Die wichtigsten Namensräume:

| Verzeichnis | Inhalt |
|---|---|
| `app/Models`, `app/Models/Base` | Systemtabellen als Eloquent-Models im Base-Model-Muster: `Base/*` wird mit Reliese aus dem Schema erzeugt und enthält nur Spalten, Casts und Relationen; `Models/*` erbt davon und trägt Geschäftslogik, Traits (`HasAuditLog`, `BelongsToApp`, `HasStableKey`, `HasTranslatedAttributes`) und Scopes. `DynamicRecord` ist das generische Model für Daten des Table-Designers und angebundener Tabellen |
| `app/DynamicData` | Table-Designer und Datensätze: Schema (`Schema`, `Ddl`, `TableDefinition`, `SchemaVerifier`), Schreibweg (`Write`), Formeln (`Formula`), Prüf- und Tabellenregeln (`Rules`, `Validation`), Dateien (`Files`), Import und Export, Kopieren, Kaskade, Löschsperre, Rechte (`DataAccess`, `DataPermissions`), Oberflächen-Bausteine (`Ui`: Formular-, Tabellen- und Aktionsbauer) |
| `app/Services/DynamicSchemaService.php` | der einzige Änderungsweg für die Struktur von Designer-Tabellen: Metadaten und DDL mit Sperre je Tabelle und Angleich nach Fehlern |
| `app/ExternalData` | Table-Connector: externe Verbindungen zur Laufzeit (`ConnectionRegistry`, `ext_<id>`), Schema-Inspektion und -Vergleich, Typabbildung, Datenstände (`Snapshots`) |
| `app/Reports` | Auswertungen: Definition, Auflösung, Abfragebau (`ReportQuery`, `ReportExpression`), Abfrage-Dialekte, Darstellung, Export, Verknüpfungen |
| `app/Apps` | Apps (fachliche Klammer): aktuelle App, Rechte je App, Schnittstellen zwischen Apps, Definition und Änderungsplan (`Definition`, `Update`), KI-App-Designer (`Designer`), Verschieben, Entfernen |
| `app/Documents` | Dokumentvorlagen: Rendern (PDF, DOCX), Briefpapier, QR-Rechnung, Zusammenführen, Versand, Bausteine für Sammelläufe |
| `app/Plugins` | Plugin-Register, Lebenszyklus, Abhängigkeiten, Rechte und Menüs der Plugins, Entwickler-Kontext; `Plugins/Api` ist die zugesicherte API (`Saft`, `DataTable`, Ereignisse, `ApiManifest`) |
| `app/Work`, `app/Jobs` | Arbeiten (Hintergrundarbeit in Schritten): Vertrag, Runner, Dienst, Treiber; `Jobs/AdvanceWork` ist der Treiber Queue |
| `app/Tasks` | geplante Aufgaben: Vertrag `TaskType`, Register, Runner, Zeitplan, System-Aufgaben unter `Types` |
| `app/Auth` | Anmeldung: Betriebsarten lokal/SSO/hybrid, SSO-Treiber (OIDC, SAML), Rollenabgleich, Zwei-Faktor, „Ansicht als Rolle“ (`RoleSimulation`) |
| `app/Policies` | Laravel-Policies der Systemressourcen, abgebildet auf Rechte `<präfix>.<aktion>` |
| `app/Navigation` | Menübaum aus der Tabelle `menus`, nach Rechten gefiltert |
| `app/Filament`, `app/Livewire` | Oberfläche: Ressourcen, Seiten, Widgets, Aktionen; eigene Livewire-Komponenten (Detailtabelle, Fortschritt einer Arbeit, KI-Hilfe) |
| `app/Ai`, `app/Help`, `app/Documentation` | KI-Anbindung (Anthropic), KI-Hilfe mit Wissensquellen, Online-Hilfe aus den Handbüchern |
| `app/Database` | Sicherung und Dumper (hier ist Raw-SQL zu Hause), Migrations-Erzeuger mit Nummern |
| `app/Exceptions` | `BusinessRuleException` (fachlich, mit Code), Einordnung von Datenbankfehlern, Darstellung für die Oberfläche |
| `plugins/*` | mitgelieferte Plugins als Composer-Pakete mit eigenen Tests (Testsuite `Plugins`) |
| `database/migrations`, `database/settings` | eine Nummernfolge `0001_01_01_0000NN_…` über beide Verzeichnisse |
| `docs/concept`, `docs/manual` | Konzepte je Vorhaben; Handbücher, die auch die Online-Hilfe sind |

Übersetzungen liegen unter `lang/{de,en,fr,it}`; jede Zeichenkette der Oberfläche hat einen Schlüssel. Die
Handbücher für Anwender, FAQ und Administration gibt es in vier Sprachen, die technischen in Deutsch und Englisch.

## 3. Die zwei Verbindungen

SAFT arbeitet immer mit mindestens zwei Datenbankverbindungen, auch wenn beide auf denselben Server zeigen:

| Verbindung | Inhalt | Konfiguration |
|---|---|---|
| **Hauptverbindung** (`DB_CONNECTION`) | Systemtabellen: Benutzer, Rollen, Rechte, Menüs, Metadaten des Table-Designers (`custom_tables`, `custom_fields`), Auswertungen, Apps, Dokumentvorlagen, Aufgaben, Arbeiten, Änderungsprotokoll (`activity_log`), Benachrichtigungen, Systemtabellen der Plugins | Laravel-Standard |
| **`app_data`** | die physischen Tabellen des Table-Designers, die Kopien der Datenstände (`ds_…`) und die Zähler der Nummernkreise (`saft_number_sequences`); PostgreSQL: Schema `app_data`, MySQL/MariaDB: eigene Datenbank `<name>_app_data` | `config/dynamic_data.php`; eigener Benutzer, Host und Passwort möglich (`DB_APP_DATA_*`), empfohlen für Produktion |
| **`ext_<id>`** | angebundene externe Tabellen (Table-Connector), je Verbindung zur Laufzeit konfiguriert, Zugangsdaten verschlüsselt, „nur lesen“ auf Datenbankebene erzwungen | Tabelle `database_connections`, `ConnectionRegistry` |

`DynamicDataNamespace` ist der Zugang zum Namensraum: Verbindung, DDL-Dialekt und Qualifizierung von Tabellennamen.
Tabellennamen sind global eindeutig (ein Namensraum für alle Apps); der Table-Designer schlägt das Präfix der App vor.

Folgen: Es gibt **keine verteilte Transaktion** zwischen Hauptverbindung und `app_data` (verschiedene Server sind
erlaubt). Der Schreibweg öffnet darum auf beiden Verbindungen je eine Transaktion und committet die Daten zuerst
(Abschnitt 5). DDL ist auf MySQL/MariaDB nicht transaktional; `DynamicSchemaService` gleicht nach einem Fehler an,
und `app-data:verify` prüft Metadaten gegen die physischen Tabellen.

## 4. Metadaten und Datensätze

Eine Designer-Tabelle existiert zweimal: als Metadaten (`CustomTable` mit `CustomField`s auf der Hauptverbindung:
Name, Bezeichnungen je Sprache, Feldtypen, Optionen, Formeln, Feld- und Tabellenregeln, App) und als physische
Tabelle in `app_data` mit `id`, Fachspalten, `created_at`/`updated_at`, `created_by`/`updated_by` und `deleted_at`
(Papierkorb). Angebundene Tabellen haben nur Metadaten; ihre Struktur kommt aus der externen Datenbank
(`SchemaInspector`, `ExternalTypeMapper`).

`DynamicRecord` ist **ein** Eloquent-Model für alle diese Tabellen. Es wird über `forTable(CustomTable)` bzw.
`forTableName()` an eine Definition gebunden (Register je Prozess: `$fillable`, Casts, Verbindung, Schlüsseltyp,
Soft-Deletes nur, wo die Spalte existiert). Eloquent erzeugt intern laufend neue Instanzen; das Register sorgt dafür,
dass sie konfiguriert bleiben. Jede Stelle, die über dieses Model **liest**, steht in `tests/Fixtures/read-sites.json`
mit Begründung (Abschnitt 6).

Feldtypen (`FieldType`) legen fest, wie ein Wert gespeichert, geprüft, angezeigt, importiert, exportiert und in
Auswertungen behandelt wird; Formeln (`Formula/Parser`, `Evaluator`) werden in PHP mit Dezimalarithmetik ausgewertet
und für Auswertungen in SQL übersetzt. Die Formelsprache ist in [`FORMULA_SPEC.md`](concept/FORMULA_SPEC.md)
spezifiziert; eine Konformitätstabelle läuft gegen beide Auswertungswege auf allen drei Datenbanken.

## 5. Der Schreibweg

Datensätze werden ausschliesslich über `App\DynamicData\Write\RecordWriter` geschrieben (`create`, `update`, `save`,
`delete`, `forceDelete`, `restore`, `batch`). Jeder äusserste Aufruf:

1. öffnet eine Transaktion auf der Verbindung der Daten (`app_data` oder `ext_<id>`) und eine auf der Hauptverbindung;
2. prüft bei Änderungen die **Version** (`WriteOptions::expectedVersion`, `RecordVersion` aus `updated_at` bzw. den
   gelesenen Originalwerten bei angebundenen Tabellen ohne Zeitstempel) mit `lockForUpdate()` und wirft
   `StaleRecordException`, wenn jemand inzwischen gespeichert hat;
3. durchläuft die **Vorher-Phase** (`WritePhases`): nur lesbar? → geschützte Dokumente → Vorgaben der Datenbank bzw.
   „Übernehmen aus“ und fortlaufende Nummern → Nummernkreise vergeben bzw. schützen → Plugin-Ereignis `RecordSaving`
   (danach dürfen Nummern nicht anders sein) → berechnete Felder → `created_by`/`updated_by`;
4. schreibt den Datensatz;
5. durchläuft die **Nachher-Phase**: Summen in Master-Datensätzen → Dateien abgleichen → `RecordSaved`; beim Löschen
   Löschsperre vorher, Summen und `RecordDeleted` nachher;
6. protokolliert auf der Hauptverbindung (`activity_log`, mit `causer` und Herkunft `via` aus `WriteOptions::origin`);
7. committet die Daten, dann die Hauptverbindung. Scheitert eine Phase, rollen beide zurück, auch Protokoll,
   Benachrichtigungen und Systemtabellen der Plugins.

Verschachtelte Aufrufe (Summen, Plugins in Ereignissen, `batch()`) laufen in derselben Transaktion; `WriteContext`
hält die aktiven Optionen je Prozess. **Wirkungen nach aussen** (E-Mail, Webhooks, Benachrichtigungen an Personen)
werden mit `WriteContext::afterCommit()` bzw. `Saft::afterCommit()` angemeldet und laufen erst nach dem Commit des
äussersten Vorgangs; ein zurückgerollter Vorgang hinterlässt keine E-Mail.

`WriteOptions` sagt, wer schreibt (`ui`, `plugin`, `import`, `copy`, `document`, `recompute`, `seed`, `work`,
`direct`), ob Plugin-Ereignisse und Protokolleinträge je Datensatz gewünscht sind, welche Version erwartet wird und ob
bewusst überschrieben wird („Trotzdem speichern“, steht im Protokoll). `DynamicRecord::save()`/`delete()` ausserhalb
des Writers **leiten an ihn weiter**, damit alter Code, Seeder und Plugins die Transaktion trotzdem bekommen; App-Code
ruft den Writer aber ausdrücklich, weil nur so Optionen sichtbar sind (Architekturtest 3).

Strukturänderungen gehen denselben Gedanken auf der Metadaten-Ebene: `DynamicSchemaService` ist der einzige Weg,
Tabellen und Felder anzulegen, zu ändern und zu entfernen; er nimmt eine Sperre je Tabelle, prüft vorhandene Werte
(Pflichtfeld, Typwechsel, Eindeutigkeit, Tabellenregeln), schreibt Metadaten und DDL über den Dialekt und legt die
Rechte `data.<tabelle>.<aktion>` mit an.

## 6. Lesen und Rechte

**Rechte** sind Spatie-Permissions, Rollen bündeln sie. Es gibt drei Familien:

| Familie | Muster | Vergabe |
|---|---|---|
| Systemressourcen | `<präfix>.<aktion>` mit den Aktionen `view_any`, `view`, `create`, `update`, `delete` und weiteren, z. B. `users.update`, `work.view_any` | Migrationen; Policies unter `app/Policies` bilden Filament-Abilities darauf ab |
| Datensätze einer Designer-Tabelle | `data.<tabelle>.<aktion>` mit den Aktionen `view`, `create`, `update`, `delete`, `export`, `import` | mit der Tabelle angelegt und entfernt (`DataPermissions`); geprüft über `DataAccess::allows()` |
| Plugins | `<plugin-id>.<aktion>` | vom Plugin gemeldet (`SaftPlugin::permissions()`) |

Der **Super-Admin** ist die Systemrolle `super-admin`; ob eine Person sie hat, entscheidet allein
`User::isSuperAdmin()` (Abfragen: `User::superAdmins()`). Sie erhält alle Rechte über `Gate::after`; eine Policy, die
ausdrücklich `false` liefert, gilt auch für sie. Ein Architekturtest verbietet jede andere Erkennung über den
Rollennamen, weil „Ansicht als Rolle“ (`RoleSimulation`) sonst Lücken hätte: Dort sieht eine Administratorin die
Oberfläche mit den Rechten einer Rolle, und alle Prüfungen müssen die simulierten Rollen sehen, auch im Hintergrund.

**Apps** sind die fachliche Klammer (Konzept [`APPS.md`](concept/APPS.md)): Jede Tabelle, jedes Menü, jede
Auswertung, Rolle und Aufgabe gehört zu genau einer App. Die App bestimmt, was eine Person sieht (Navigation,
Dashboard, Listen, KI-Kontext); den Zugriff regeln weiterhin die Rechte. Rollen gehören einer App, ausser den
Systemrollen `super-admin` und `benutzer`; wer eine Rolle einer App hat, sieht die App (`CurrentApp`). Verweise
zwischen Apps gibt es nur über **ausdrücklich freigegebene Schnittstellen** (`AppInterfaces`): einzelne Tabellen
mit ausgewählten Feldern, nur lesend, und solange eine andere App sie nutzt, ein Vertrag (nicht löschen, umbenennen,
zurücknehmen). Eine App lässt sich als Definition `saft-app/1` exportieren, als Paket installieren und über einen
Änderungsplan (Drei-Wege-Vergleich, Einstufung sicher/heikel/zerstörend) aktualisieren.

**Lesen** von Datensätzen ist heute auf der Ebene Tabelle und Aktion geschützt (`data.<tabelle>.view`). Zeilenrechte
(Konzept [`ROW_ACCESS.md`](concept/ROW_ACCESS.md), B-020) werden als globaler Scope auf `DynamicRecord` dazukommen.
Als Vorbereitung steht jede Lesestelle in `tests/Fixtures/read-sites.json` mit einer Einordnung: `person` liest im
Namen der angemeldeten Person (Datenansicht, Detailtabellen, Auswahl von Verknüpfungen, Dokumente, Export, Dateien,
Plugin-API mit Rechteprüfung), `system` setzt SAFTs eigene Regeln um und muss alle Datensätze sehen (Summen,
Eindeutigkeit, fortlaufende Nummern, Löschsperre, Datenstände, Nachberechnung), `none` liest nicht. Ein
Architekturtest hält die Liste vollständig.

## 7. Erweiterungspunkte

Alles, was eine Installation ergänzt, hängt an einem benannten Vertrag und wird in einer Konfigurationsliste oder vom
Plugin angemeldet. Die Kernklassen werden über den Container erzeugt.

| Erweiterung | Vertrag | Anmeldung |
|---|---|---|
| **Plugin** | `App\Plugins\SaftPlugin` (Rechte, Menüvorschläge, benötigte Tabellen, Seiten, Ressourcen, Widgets, Handbücher) | Composer-Paket unter `plugins/` oder aus eigenem Repository mit `PluginServiceProvider`; ruht, wenn Abhängigkeiten fehlen |
| **Plugin-API** | `App\Plugins\Api\*` (`Saft`, `DataTable`, Ereignisse, `RecordListener`, `DataAction`, `FormulaFunction`, `FieldRule`, `DashboardWidget`, `Manual`) und die als `@api` markierten Verträge | `ApiManifest` listet die zugesicherten Klassen; `PluginApiContractTest` vergleicht Signaturen mit `plugin-api.json`; eigene Versionsnummer (1.10), Plugins nennen die verlangte Version |
| **Art einer geplanten Aufgabe** | `App\Tasks\TaskType` (`key`, Parameterfelder, `run()` ohne Person) | `config/tasks.php` oder `SaftPlugin::taskTypes()` |
| **Art einer Arbeit** | `App\Work\WorkType` (`prepare()`, `step()`), optional `WritesRecords`, `CleansUp`, `LongSteps`, `DescribesProgress` | `config/work.php` oder `SaftPlugin::workTypes()`; gestartet mit `WorkService` bzw. `Saft::startWork()` |
| **Formelfunktion** | `App\Plugins\Api\FormulaFunction` (Name, Argumente, Auswertung in PHP; für SQL gesperrt, wenn nicht übersetzbar) | `SaftPlugin::formulaFunctions()` |
| **Prüfregel für Felder** | `App\Plugins\Api\FieldRule` | `SaftPlugin::fieldRules()` |
| **Aktion in der Datenansicht** | `App\Plugins\Api\DataAction` | `SaftPlugin::dataActions()` |
| **Reaktion auf Änderungen** | `RecordListener` für `RecordSaving`/`RecordSaved`/`RecordDeleting`/`RecordDeleted`, innerhalb der Transaktion; `RecordRejected` lehnt ab | `SaftPlugin::recordListeners()` |
| **Datenbank-Dialekt** | `App\DynamicData\Ddl\DdlDialect` (Struktur) und `App\Reports\Dialects\QueryDialect` (Auswertungen) | `config/dynamic_data.php` `dialects`, `QueryDialectResolver` |
| **Externe Datenbank** | `App\ExternalData\ConnectionDriver` mit Schema-Inspektion und Typabbildung (PostgreSQL, MySQL/MariaDB, SQL Server) | `ConnectionRegistry` |
| **SSO** | `App\Auth\Sso\SsoDriver` (OIDC, SAML) | `SsoDriverRegistry` |
| **KI-Anbieter** | `App\Ai\ChatClient` | `AiConfig` |

Plugins verwenden nur die API: Datensätze über `Saft::table()` (`DataTable`, mit Rechteprüfung, `withoutAuthorization()`
für Hintergrundarbeit), Schreiben läuft über den Writer. Rechte, Routen und Übersetzungen eines Plugins tragen seine
`id` als Präfix. Der **Entwickler-Kontext** (`saft:plugin-context`) erzeugt für eine Installation `AGENTS.md`,
`api-reference.md`, `schema.md` und `schema.json`, damit externe Entwickler und KI-Agenten gegen die passende API und
die Fachtabellen arbeiten.

## 8. Hintergrundarbeit

SAFT kennt zwei Formen von Arbeit ausserhalb eines Requests, beide ohne Pflicht zu einem Queue-Worker:

**Geplante Aufgaben** (`ScheduledTask`, `TaskRunner`) laufen zu Zeitpunkten: `tasks:run-due` im Cron jede Minute
führt fällige Aufgaben aus, protokolliert jeden Lauf, verhindert doppelte Läufe und benachrichtigt die Super-Admins bei
Fehlschlag und Erholung. System-Aufgaben (Sicherung, Aufräumen von Dateien, Datenständen, Läufen, Arbeiten und
Änderungsprotokoll, Demo-Reset) kommen ab Werk; Plugins steuern Arten bei.

**Arbeiten** (`work_items`, Konzept [`FOUNDATION_2.md`](concept/FOUNDATION_2.md) §6) sind lange Vorgänge einer
Person, zerlegt in **wiederaufnehmbare Schritte**: `prepare()` bestimmt im ersten Schritt Zustand und Menge, danach
ruft ein Treiber `step()`, bis die Art „fertig“ meldet. Zwischen zwei Schritten kann der Prozess wechseln; alles, was
der nächste Schritt braucht, steht im Zustand (JSON) oder im Ordner der Arbeit (`storage/app/private/work/<uuid>`).
Jeder Schritt läuft als die startende Person in ihrer App, mit den simulierten Rollen, falls unter „Ansicht als Rolle“
gestartet. Drei Treiber rufen denselben `WorkRunner::advance()` mit einem Zeitbudget und einer Cache-Sperre je Arbeit:

| Treiber | Wann | Budget |
|---|---|---|
| Browser (`WorkProgress`, Polling alle 2 s) | solange die startende Person die Seite offen hat; `startAndAdvance()` erledigt kleine Mengen schon im Request | `WORK_BROWSER_BUDGET`, `WORK_INLINE_BUDGET` |
| Scheduler (`work:run`, Cron jede Minute) | immer; übernimmt verlassene Arbeiten anhand des Heartbeats | `WORK_SCHEDULER_BUDGET` |
| Queue (`AdvanceWork`) | nur mit `WORK_DRIVER=queue` und einer Queue-Verbindung ausser `sync`; der Job reiht sich mit 2 s Pause wieder ein | `WORK_QUEUE_BUDGET` |

Zusätze zum Vertrag: `WritesRecords` führt Schritt und Fortschritt in einer `RecordWriter::batch()`-Transaktion
(Daten und Cursor sind eine Einheit), `LongSteps` erlaubt genau einen unteilbar langen Schritt je Aufruf (KI-Anfragen),
`CleansUp` räumt bei Fehlschlag oder Abbruch auf (Import nimmt angelegte Datensätze zurück, Datenstand verwirft die
Kopie), `DescribesProgress` liefert den Fortschrittstext. Ergebnisse (Dateien) stehen 24 h zum Download; die
System-Aufgabe `work_prune` räumt stündlich. Als Arbeiten laufen heute Export, Datenimport, Dokumente, Datenstände,
Nachberechnung berechneter Felder, Nachnummerieren, Sammeländerung und der KI-App-Designer.

## 9. Portabilität und Qualitätssicherung

- **Drei Datenbanken gleichwertig:** PostgreSQL 16, MySQL 8, MariaDB; CI führt die Feature-Tests auf allen dreien aus,
  die Browser-Tests vor einem Release. Kein dialektspezifisches Raw-SQL ausserhalb von `DynamicData/Ddl`,
  `Reports/Dialects` und `Database` (Architekturtest 1 mit begründeter Ausnahmeliste).
- **Statische Analyse:** Larastan Level 5 (`composer analyse`), Baseline für den Bestand, die nur kleiner werden darf
  (`StaticAnalysisBaselineTest`); eigener CI-Job vor den Tests. Ziel Level 7 bis 1.0.
- **Architekturtests** (`tests/Unit/ArchitectureTest.php`): Raw-SQL, Super-Admin-Erkennung, Schreibweg,
  Lesestellen. Dazu `MigrationNamingTest` (eine lückenlose Nummernfolge über `database/migrations` und
  `database/settings`), `PluginApiContractTest` (API-Vertrag), `FormulaSpecTest` und `FormulaConformanceTest`
  (Formelsprache), `ManualStructureTest` (Handbücher und dieses Dokument: Sprungmarken, gleiche Gliederung je Sprache).
- **Code-Stil:** Pint mit Preset `laravel` und `declare_strict_types`; nach `code:models` Pint laufen lassen, weil
  Reliese keine eigene Vorlage erlaubt.
- **Tests:** Suiten `Unit`, `Feature`, `Plugins` (PHPUnit, Konfigurationen je Datenbank), Browser-Tests mit Dusk gegen
  eine eigene Datenbank (`SAFT2_dusk`), Testliste `docs/test/SAFT_Manuelle_Testfaelle.xlsx`. Ein `TestDatabaseGuard`
  verhindert Läufe gegen die Entwicklungsdatenbank.
- **Sicherheit:** Sicherheits-Header, 2FA, HIBP-Abgleich, verschlüsselte Zugangsdaten mit maskiertem Protokoll,
  SAML-Replay-Schutz, Host- und Namensmuster gegen DSN-Injection, „nur lesen“ auf Datenbankebene; Meldungen über
  `SECURITY.md`.

## 10. Entscheidungen

Nummerierte Architekturentscheidungen mit Datum, Entscheidung, Begründung und Folgen. Was vor dem Architektur-Review
vom 23.11.2026 galt, ist mit „seit Beginn“ (November 2026) bezeichnet; die Konzepte unter `docs/concept/` enthalten
die Diskussion. Neue Entscheidungen werden hier angehängt, nicht umgeschrieben; eine überholte wird als „abgelöst
durch Nr. n“ markiert.

1. **Metadaten und Daten getrennt** (seit Beginn). Systemtabellen und Metadaten des Table-Designers liegen auf der
   Hauptverbindung, die physischen Tabellen im Namensraum `app_data` mit eigener Verbindung. *Warum:* ein eigener
   Datenbankbenutzer mit Rechten nur auf `app_data`, Sicherung und Verifikation je Namensraum, bei Hostern getrennte
   Datenbanken möglich. *Folge:* keine verteilte Transaktion (Nr. 10).
2. **Base-Model-Muster** (seit Beginn). Systemtabellen als `Models/Base/*` (Reliese, aus dem Schema erzeugt) und
   `Models/*` (Geschäftslogik). Designer-Tabellen haben kein eigenes Model, sondern das generische `DynamicRecord` mit
   Register. *Warum:* Schema-Updates überschreiben nie Logik; Tabellen entstehen zur Laufzeit.
3. **Portabilität über Dialektklassen** (seit Beginn, Test seit 23.11.2026). Eloquent und Query Builder überall;
   dialektspezifisches SQL nur in `DynamicData/Ddl`, `Reports/Dialects`, `Database`. *Warum:* drei gleichwertige
   Datenbanken, CI auf allen; metadatenbasierte Systeme vertragen keinen schleichenden Dialekt.
4. **Betrieb ohne Queue-Worker als Normalfall** (seit Beginn; Arbeiten 24.11.2026). Webspace mit Cron genügt;
   Worker und Redis sind optional. *Warum:* Zielgruppe sind kleine Installationen. *Folge:* Hintergrundarbeit als
   wiederaufnehmbare Schritte mit austauschbaren Treibern (Nr. 19).
5. **Apps als fachliche Klammer** (B-107). Jedes Objekt gehört zu genau einer App; Rollen je App; Zugang aus den
   Rollen; Verweise zwischen Apps nur über freigegebene Schnittstellen, nur lesend; Tabellennamen bleiben global
   eindeutig. *Warum:* mehrere Fachanwendungen in einer Installation ohne gegenseitige Störung; Pakete und KI
   brauchen eine klare Grenze.
6. **Datenbank ist massgeblich, Definition wird erzeugt** (B-107). `saft-app/1` und `saft-schema/1` sind Export,
   nicht Quelle; Aktualisieren läuft über einen Änderungsplan mit Drei-Wege-Vergleich und Einstufung statt
   Überschreiben. *Warum:* keine zweite Wahrheit; zerstörende Änderungen sichtbar machen.
7. **Plugin-API als Vertrag** (seit Beginn). Zugesicherte Klassen im `ApiManifest`, Vertragstest gegen
   `plugin-api.json`, eigene Versionsnummer, Pflicht-Tabellen als Vertrag, Plugins ruhen bei fehlenden
   Abhängigkeiten. *Warum:* Plugins externer Entwickler und KI-Agenten müssen sich auf Signaturen verlassen können.
8. **Berechtigungsnamen am Tabellennamen** (23.11.2026). `data.<tabelle>.<aktion>` bleibt; Umbenennen von Tabellen
   bleibt ausgeschlossen. *Warum:* lesbar im Protokoll, Plugins nennen Tabellen beim Namen (`requiredTables`); eine
   Migration auf stabile Schlüssel brächte Aufwand ohne Nutzen, solange Umbenennen nicht erlaubt ist.
9. **Super-Admin nur über `User::isSuperAdmin()`** (23.11.2026). Kein `hasRole()` und kein Rollenvergleich am
   Namen anderswo; begründete Ausnahme `RoleSimulation::canSimulate()`. *Warum:* „Ansicht als Rolle“ muss überall
   greifen; Architekturtest 2.
10. **Je eine Transaktion auf beiden Verbindungen, Daten zuerst** (23.11.2026). Der Writer öffnet Transaktionen auf
    der Datenverbindung und der Hauptverbindung, committet die Daten, dann die Hauptverbindung; scheitert eine
    Phase, rollen beide zurück. *Warum:* Protokoll, Benachrichtigungen und Plugin-Systemtabellen gehören zum Vorgang;
    eine verteilte Transaktion gibt es nicht. *Restrisiko (hingenommen):* Daten committet, Commit der Hauptverbindung
    scheitert, Protokolleintrag fehlt.
11. **`RecordWriter` als einziger Schreibweg** (23.11.2026). Oberfläche, Import, Plugin-API, Kopieren, Dokumente,
    Arbeiten rufen ihn mit `WriteOptions`; `DynamicRecord::save()`/`delete()` leiten weiter statt zu werfen.
    *Warum:* Regel-Engine (B-142), Zeilenrechte (B-020) und Nummernkreise (B-135) setzen eine atomare Schreibeinheit
    voraus; Weiterleiten statt Werfen hält bestehenden Code, Seeder und Plugins lauffähig. Architekturtest 3.
12. **Änderungsprotokoll bleibt auf der Hauptverbindung** (23.11.2026). Keine zweite Protokolltabelle in `app_data`.
    *Warum:* ein Protokoll für System und Daten, Spatie-Standard; die Transaktion aus Nr. 10 deckt es ab.
13. **Optimistische Sperre** (23.11.2026). Version aus `updated_at` (Designer-Tabellen) bzw. den gelesenen
    Originalwerten (angebundene Tabellen ohne Zeitstempel), geprüft mit `lockForUpdate()` im Writer; Meldung
    „inzwischen geändert von …“ mit „Neu laden“ und „Trotzdem speichern“ (Überschreiben steht im Protokoll); auch
    für die Edit-Seiten der Verwaltung. *Warum:* keine pessimistischen Sperren auf Webspace ohne Redis; beide Knöpfe,
    weil Anwender die Wahl brauchen.
14. **Formelsprache als Spezifikation mit Konformitätstabelle** (23.11.2026). `FORMULA_SPEC.md`, Konformitätsfälle
    als JSON gegen PHP-Auswertung und SQL-Übersetzung auf drei Datenbanken; PHP ist die Referenz, abweichendes SQL
    wird angepasst oder die Funktion für SQL gesperrt. *Warum:* dieselbe Formel als Feld und als Auswertungsspalte
    muss dasselbe ergeben; Zeilenrechte werden ein dritter Auswertungsweg.
15. **Larastan Level 5 mit schrumpfender Baseline** (23.11.2026). Start Level 5, Baseline für den Bestand, Test
    prüft, dass sie nur kleiner wird; Ziel Level 7 bis 1.0. *Warum:* bei KI-gestützter Entwicklung ist das Prüfen der
    Engpass; Level 6 hätte zu Beginn eine zu grosse Baseline erzeugt.
16. **Migrationen fortlaufend nummeriert, nicht zusammengefasst** (23.11.2026). `0001_01_01_0000NN_<name>.php`,
    eine Nummernfolge über `database/migrations` und `database/settings`; `make:migration` vergibt die Nummer. *Warum:*
    Datumspräfixe aus der Zukunft brachen die Reihenfolge; Zusammenfassen hätte bei drei Datenbanken und
    Daten-Migrationen mehr Risiko als Nutzen. *Folge:* Installationen vor 0.6.0 werden neu aufgesetzt (keine produktiven).
17. **`strict_types` über Pint** (23.11.2026). Regel `declare_strict_types` in `pint.json`, auch für die Base-Models.
    *Warum:* Reliese 1.x erlaubt keine eigene Vorlage.
18. **Änderungsprotokoll kürzen als Aufgabe, Löschkonzept eigenes Vorhaben** (23.11.2026). Aufgabenart
    `activity_prune` (Tage je Protokollname, Vorgabe 365, Anmeldungen 90), ab Werk inaktiv mit Hinweis auf dem
    Dashboard. *Warum:* das Protokoll wuchs unbegrenzt; Aufbewahrung je Tabelle braucht fachliche Entscheidungen je App.
19. **Arbeiten: eine Systemtabelle, wiederaufnehmbare Schritte, drei Treiber** (24.11.2026). `work_items` mit
    Parametern, Zustand (Cursor), Fortschritt, Heartbeat und Abbruchmarke; `prepare()` im ersten Schritt; das Budget
    gehört dem Treiber, der Schritt sieht `remaining()`; kein Status „hängt“ (der nächste Treiber übernimmt anhand des
    Heartbeats, ab drei Übernahmen ohne Fortschritt gilt die Arbeit als fehlgeschlagen); jeder Schritt läuft als die
    startende Person mit ihren simulierten Rollen. *Warum:* ein Modell statt je Feature ein eigenes; Export, Import,
    Dokumente, Datenstände, Designer und spätere Läufe, Postausgang und Sicherung bauen darauf.
20. **Schreibende Arbeiten: Schritt und Fortschritt in einer Transaktion** (24.11.2026). `WritesRecords` führt den
    Schritt in `RecordWriter::batch()` aus und speichert den Cursor in derselben Transaktion der Hauptverbindung.
    *Warum:* nach einem Abbruch darf kein Block doppelt oder halb geschrieben sein. *Restrisiko* wie Nr. 10: der Block
    läuft noch einmal.
21. **Import in Blöcken mit Rücknahme** (24.11.2026). Prüfphase über die ganze Datei, dann Blöcke mit eigener
    Transaktion; angelegte IDs werden festgehalten, `CleansUp::abort()` nimmt sie bei Fehler oder Abbruch zurück.
    *Warum:* eine Transaktion über alles hielte auf MySQL lange Sperren, eine Staging-Tabelle wäre mehr Aufwand;
    Verhalten bleibt „alles oder nichts“.
22. **Grenzen mit Vorgabe statt Verbot** (24.11.2026). Export 500'000 Zeilen, Import 100'000, Dokumente ohne
    Grenze, Datenstände 1'000'000; `0` hebt eine Grenze auf. *Warum:* Speicherplatz unter `storage/app/private/work`
    schützen, ohne Installationen mit eigenem Server zu beschränken.
23. **Treiber Queue nur auf Wunsch** (24.11.2026). Job `AdvanceWork` ist aktiv mit `WORK_DRIVER=queue` und einer
    Queue-Verbindung ausser `sync`; sonst treiben Browser und Scheduler. *Warum:* ein versehentlich konfigurierter
    Worker darf das Verhalten nicht ändern; wer einen Worker hat, bekommt kürzere Pausen und entlastet den Browser.
24. **Lesestellen-Inventur** (24.11.2026). Jede Lesestelle über `DynamicRecord::forTable()`/`forTableName()` steht in
    `tests/Fixtures/read-sites.json` mit Begründung und Einordnung `person`/`system`/`none`; Architekturtest 4.
    *Warum:* der Zeilenrechte-Scope (B-020) muss wissen, wo er greift und wo er ausdrücklich abgeschaltet wird; eine
    vergessene Stelle wäre ein Datenleck.
25. **Metadaten je Request einmal, Abfragebudget im Test** (26.11.2026). Tabellen samt Feldern, Details einer Tabelle,
    abhängige berechnete Felder, Dokumentvorlagen und die Spaltenauswahl der Person liest der Kern über den scoped
    Dienst `CustomTableLookup` (`byName()`, `remember()`); Speichern oder Löschen von `CustomTable`, `CustomField`,
    `ReportLink` oder `DocumentTemplate` leert ihn. Auch `CurrentApp` ist scoped. `QueryBudgetTest` legt eine
    Obergrenze der Abfragen für Seite und Klick der Master-Detail-Ansicht fest (Lager-Demo). *Warum:* auf dem Webspace
    kostet jede Abfrage eine Rundreise zum Datenbankrechner (Strato rund 2,2 ms); die Kaskade fragte dieselben
    Metadaten je Detailtabelle neu ab (B-150: Klick 58 → 32, Seite 101 → 47 Abfragen). *Folge:* wer Metadaten am
    Model vorbei schreibt (Query Builder), muss `CustomTableLookup::forget()` aufrufen; Datensätze werden bewusst
    nicht zwischengespeichert.
26. **Zähler der Nummernkreise neben den Daten** (26.11.2026, B-135). Feldtyp „Nummernkreis“: die Nummer vergibt die
    Vorher-Phase des `RecordWriter` beim Anlegen aus der Tabelle `saft_number_sequences` (Feld, Periode, Gruppe,
    letzter Wert; Primärschlüssel über die drei Spalten) im Namensraum `app_data`, gesperrt in derselben Transaktion
    wie der Datensatz; eine vergebene Nummer ändert kein Schreibweg mehr (Herkunft `import` und `seed` dürfen Nummern
    mitbringen und heben den Zähler an). Die Tabelle legt `NumberSequences::ensureTable()` mit dem ersten Feld dieses
    Typs an; das Präfix `saft_` ist für Designer-Tabellen gesperrt, `app-data:verify` übergeht es. *Warum:* auf der
    Hauptverbindung könnte ein Fehler zwischen den beiden Commits (Nr. 10) einen Datensatz mit Nummer, aber ohne
    erhöhten Zähler hinterlassen; danach scheiterte jede Vergabe an der Eindeutigkeit. Eine Migration ginge bei
    `migrate:fresh` mit dem Namensraum verloren. Eine fehlende Zählerzeile wird per Upsert angelegt statt nach einem
    `SELECT … FOR UPDATE`: dessen Lückensperre führte auf MySQL bei gleichzeitigem Anlegen zu Deadlocks.
    *Folge:* kein Fremdschlüssel auf `custom_fields`, `DynamicSchemaService` und das Zurücksetzen der Demo-Daten
    löschen Zähler selbst; `NumberSequenceConcurrencyTest` prüft parallele Prozesse auf allen drei Datenbanken.
    *Nachtrag N3 (26.11.2026):* Mit „Vergeben, wenn“ (`assign_when`, eine Formel ohne berechnete Felder und Summen)
    vergibt dieselbe Vorher-Phase die Nummer erst **nach** `RecordSaving` und vor den Formeln, beim ersten Speichern
    mit erfüllter Bedingung, auch beim Ändern – gleiche Sperre, gleiche Transaktion. Ein geändertes Muster hebt die
    Zähler auf die höchste vorhandene passende Nummer.
    *Nachtrag N4 (26.11.2026):* Mit einer Gruppe (`group_field`, ein aktives Pflichtfeld vom Typ Auswahl, und
    `group_codes`, Wert → Kurzzeichen) zählt die Zählerzeile je **Kurzzeichen** (Spalte `grp`), nicht je Wert: So kommt
    die Gruppe beim Zurücklesen aus der Nummer selbst (Import, Umstellen, Zählerstände), Wahlen mit gleichem Kurzzeichen
    teilen bewusst einen Zähler, und Doppel sind ausgeschlossen. Massgeblich ist die Gruppe zum Zeitpunkt der Vergabe;
    eine spätere Änderung lässt die Nummer stehen. Kurzzeichen entfernter Wahlen bleiben in den Optionen.
27. **Sammeländerung: Probelauf statt Sperre, Journal statt einer Transaktion** (27.11.2026, B-133). Die Arbeit
    `bulk_records` prüft zuerst alle ausgewählten Datensätze, indem sie sie je Block über `RecordWriter::trial()`
    wirklich speichert (alle Phasen, Plugin-Listener, Nummernkreise, Protokoll) und auf beiden Verbindungen
    zurückrollt (Sicherungspunkte; `afterCommit()`-Wirkungen verfallen). Erst wenn alle bestehen, schreibt sie in
    Blöcken mit eigener Transaktion, je Datensatz mit der Version aus der Prüfphase (optimistische Sperre, Nr. 13), und
    hält die alten Werte vor dem Commit im Journal im Ordner der Arbeit fest; scheitert ein Block oder bricht die
    Person ab, schreibt `CleansUp::abort()` die alten Werte der fertigen Blöcke ohne Plugin-Ereignisse zurück.
    Herkunft `bulk`, Kennung `run` = `bulk:<uuid>`. *Warum:* „alles oder nichts“ wie beim Import (Nr. 21), ohne eine
    Transaktion über tausende Datensätze mit langen Sperren auf MySQL; der Probelauf prüft auch Plugin-Regeln, die
    `RecordChecker` nicht kennt, und eine Sammeländerung bleibt kein Weg an den Regeln vorbei. *Restrisiko:* Wer
    zwischen Schreiben und Rücknahme einen Datensatz ändert, behält seinen Stand (Eintrag im Systemprotokoll); eine in
    der Schreibphase über „Vergeben, wenn“ vergebene Nummer bleibt (Lücke wie beim Löschen).
28. **Rückgängig: Kopf als Verzeichnis, Werte aus dem Protokoll, Rohwerte im Protokoll** (27.11.2026, B-133 S3). Jede
    erfolgreiche Sammelaktion (ändern, löschen, wiederherstellen, Rücknahme) legt in derselben Transaktion wie ihr
    letzter Block einen Kopf in `bulk_changes` an: Kennung `run`, App, Tabelle, Person, Art und Angaben je Feld,
    Schlüssel der geänderten Datensätze. Die alten Werte stehen nicht dort, sondern im Änderungsprotokoll (Nr. 12); die
    Rücknahme liest sie je Block über den Index `(subject_type, subject_id)` und filtert nach `run`. Sie ist selbst eine
    Arbeit `bulk_records` mit allen Prüfungen und Plugin-Ereignissen; Datensätze, bei denen ein Feld der Sammeländerung
    seither anders ist oder die eine Prüfung nicht mehr bestehen, bleiben und werden gemeldet (kein „alles oder
    nichts“). `DynamicRecord` protokolliert dafür **Rohwerte** wie in der Datenbank, alt und neu in derselben Form;
    Spatie gab neue Datumswerte bisher in UTC weiter. Die Aufgabe `activity_prune` löscht Köpfe mit derselben Frist.
    Fremde Sammelaktionen nimmt zurück, wer `activity_log.view` hat. *Warum:* Art und Angaben einer Sammeländerung
    stehen nicht im Protokoll und `work_items` werden aufgeräumt; eine Gruppierung über `properties->run` ginge ohne
    Index und sähe gescheiterte Läufe; eine Kopie der alten Werte wäre eine zweite Wahrheit. *Folge:* Ohne
    Änderungsprotokoll (`ACTIVITYLOG_ENABLED=false`) gibt es keine Rücknahme; Einträge von vor 0.8.1 mit UTC-Datum
    rechnet `LoggedValue` in die Zeitzone der Anwendung um (Voraussetzung: `APP_TIMEZONE` unverändert).
