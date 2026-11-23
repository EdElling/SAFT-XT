# Review: Architektur und Gesamtkonzeption von SAFT XT

Stand: 23.11.2026 · Geprüfter Stand: Release 0.5.0 (Pre-Release), Commit 101bcf4 · Umfang: Code unter `app/`, Konzepte unter `docs/concept/`, Handbücher unter `docs/manual/`, Backlog, Tests, CI

## 1. Zusammenfassung

SAFT XT ist für sein Alter aussergewöhnlich weit: ein metadatenbasiertes Framework mit Table-Designer, Auswertungen, externen Datenbanken, Apps mit Paketformat und Änderungsplan, Dokumenten, Plugins mit versionierter API, SSO und drei unterstützten Datenbanken. Die Konzeptdokumente sind durchdacht, nennen Alternativen, Risiken und offene Fragen und bauen konsequent aufeinander auf. Die Testbasis (rund 1'350 Testmethoden, davon 1'020 Feature- und 290 Browser-Tests auf drei Datenbanken) ist für ein Projekt dieser Grösse stark.

Die Schwachstellen liegen nicht in einzelnen Fehlern, sondern in **vier Querschnittsthemen, die in fast jedem geplanten Konzept erneut auftauchen** und bisher je Feature einzeln gelöst werden:

1. **Keine atomare Schreibeinheit für Datensätze.** Speichern, Summen nachführen, Dateien abgleichen und Protokollieren laufen ohne gemeinsame Transaktion; Metadaten, Protokoll und Daten liegen zudem auf zwei Verbindungen. Regel-Engine, Zeilenrechte und Nummernkreise setzen diese Einheit voraus („einmal gebaut“) – sie existiert noch nicht.
2. **Keine Nebenläufigkeitskontrolle.** Zwei Personen, die denselben Datensatz bearbeiten, überschreiben sich stillschweigend.
3. **Alles synchron im Request.** Der bewusste Verzicht auf einen Queue-Worker (Webspace) ist nachvollziehbar, aber jedes Konzept (Läufe, Postausgang, Web-Cron, Sammeländerung) erfindet dafür einen eigenen Mechanismus.
4. **Eine Formelsprache, mehrere Auswertungen.** Formeln werden in PHP (bcmath) und in SQL (Auswertungen) ausgewertet, künftig ein drittes Mal für Zeilenrechte. Ohne gemeinsame Spezifikation und Konformitätstests driften die Semantiken auseinander.

Dazu kommen Wartbarkeitsrisiken: fünf Klassen mit 850 bis 1'200 Zeilen, keine statische Analyse (PHPStan/Larastan fehlt), Migrationen mit künstlichen Zeitstempeln in der Zukunft, und ein Änderungsprotokoll, das nie bereinigt wird.

**Empfehlung:** Vor den grossen Konzepten (B-142 Regel-Engine, B-020 Zeilenrechte, B-133 Sammeländerung, B-060 REST) zuerst die vier Querschnittsthemen als eigene Etappe lösen. Sie sind die Grundlage, auf der diese Konzepte ausdrücklich aufbauen; nachträglich sind sie um ein Mehrfaches teurer.

## 2. Kennzahlen

| Grösse | Wert |
|---|---|
| PHP-Code unter `app/` | 560 Dateien, 67'100 Zeilen |
| Commits / Zeitraum | 129 Commits, 16. bis 23. November 2026 (8 Tage) |
| Eingefügte Zeilen gesamt (inkl. Docs, Tests, Lang) | rund 209'000 |
| Migrationen | 85 |
| Tests | 207 Dateien; 1'020 Feature-, 286 Browser-, 45 Unit-Testmethoden |
| Handbücher | rund 19'600 Zeilen in vier Sprachen (Anwender, Admin, FAQ) bzw. zwei (Sysadmin, Entwickler, Installation) |
| Konzepte | 8 Dokumente, 2'100 Zeilen |
| Übersetzungen | 3'302 Schlüssel je Sprache (de/fr/it), 2'829 (en, Englisch fällt teils auf Laravel-Standard zurück) |
| Grösste Klassen | `DynamicSchemaService` 1'192, `ReportDesigner` 1'108, `DynamicRecordActions` 944, `TableAssistant` 924, `ChangePlanner` 851 Zeilen |
| Raw-SQL-Stellen | 23, ausschliesslich in Dialektklassen, Dumper und zwei klar begründeten Ausnahmen |

Die Entwicklungsgeschwindigkeit (rund 26'000 Zeilen pro Tag) ist nur mit KI-Unterstützung möglich. Das ist kein Mangel, verschiebt aber den Engpass: nicht das Schreiben, sondern das **Prüfen** von Code wird knapp. Daraus folgt die hohe Bedeutung von statischer Analyse, Architekturtests und Invarianten, die die Maschine prüfen kann (Abschnitt 6).

## 3. Was trägt (Stärken)

- **Klare Trennung der Schichten:** Metadaten (`custom_tables`, `custom_fields`, Hauptverbindung) und physische Tabellen (`app_data`) mit einem einzigen Änderungsweg (`DynamicSchemaService`), Sperre je Tabelle, Angleich nach Fehlern bei nicht-transaktionaler DDL (MySQL) und `app-data:verify` als Restkontrolle.
- **Portabilität ernst genommen:** DDL- und Abfrage-Dialekte als Klassen (`DynamicData/Ddl`, `Reports/Dialects`), Query Builder statt Raw-SQL, drei Datenbanken in CI. Das ist in metadatenbasierten Systemen selten konsequent umgesetzt.
- **Sicherheit über dem üblichen Mass:** Sicherheits-Header, HSTS, 2FA mit Pflicht je Einstellung, HIBP-Abgleich, Passwortlänge 12, verschlüsselte Zugangsdaten mit maskiertem Protokoll, SAML-Replay-Schutz über atomares `Cache::add`, zweistufiger ACS ohne Session-Übernahme, Host- und Namensmuster gegen DSN-Injection in `ConnectionRegistry`, „nur lesen“ auf Datenbankebene erzwungen, CSP und `nosniff` für ausgelieferte Dateien, Mustache ohne Code-Ausführung mit HTML-Maskierung, KI-Antworten werden wie Benutzereingaben geprüft.
- **Plugin-API mit Vertrag:** Manifest der zugesicherten Klassen (`ApiManifest`), Vertragstest gegen `plugin-api.json`, eigene Versionsnummer, Pflicht-Tabellen als Vertrag, Plugins ruhen bei fehlenden Abhängigkeiten.
- **Apps mit stabilen Schlüsseln und Drei-Wege-Vergleich:** Die Entscheidung „Datenbank ist massgeblich, JSON wird erzeugt“ vermeidet eine zweite Wahrheit. Der Änderungsplan mit Einstufung sicher/heikel/zerstörend ist der richtige Baustein für Pakete, KI und später REST.
- **Konzeptkultur:** Jedes grössere Vorhaben hat ein Konzept mit Entscheidungen, Etappen, Risiken und offenen Fragen; der Backlog verweist darauf. Das ist der wertvollste Teil der Dokumentation.
- **Betriebstauglichkeit für kleine Installationen:** Webspace ohne Worker, Cron-Heartbeat, Sicherung in PHP, Fehlerreferenzen in Log und Oberfläche, Laufprotokolle.

## 4. Konzeptionelle Brüche und Schwachstellen

### 4.1 Speichern eines Datensatzes ist keine Einheit

`DynamicRecord` erledigt beim Speichern nacheinander: Vorgaben, „Übernehmen aus“, fortlaufende Nummer, Plugin-Ereignis `RecordSaving`, berechnete Felder, Schreiben, Summen in Master-Datensätzen nachführen (`Recalculator::propagate`), Dateien abgleichen (`RecordFiles::sync`), Protokoll (`activity_log` auf der **Hauptverbindung**), Plugin-Ereignis `RecordSaved`. Keiner dieser Schritte steht in einer Transaktion, wenn der Aufruf aus der Datenansicht kommt (`DynamicRecordActions`, `DynamicFormBuilder`); nur Import (`RecordImporter`, `DataImporter`) und Plugin-API (`Saft::transaction()`) öffnen eine.

Folgen heute: Scheitert `propagate` oder `sync`, ist der Datensatz gespeichert, Summe oder Dateizuordnung nicht. Ein `RecordSaved`-Listener eines Plugins, der eine Ausnahme wirft, hinterlässt einen gespeicherten Datensatz mit Fehlermeldung.

Folgen für die Konzepte: `RULES.md` 7.1 („Die Engine muss sicherstellen, dass eine Transaktion offen ist“), `ROW_ACCESS.md` 3 („Dafür braucht es dieselbe Transaktion beim Speichern wie bei Regel-Engine und Nummernkreisen (einmal gebaut)“) und `NUMBERING.md` setzen diese Einheit voraus. Sie dreimal nebenher zu bauen wäre der falsche Weg.

Erschwerend: Hauptverbindung und `app_data` können verschiedene Server sein (`DB_APP_DATA_HOST`). Eine echte verteilte Transaktion ist ausgeschlossen. Das muss **als Entscheidung** festgehalten werden: Transaktion auf `app_data`, Protokoll „best effort“ danach, oder Protokoll in `app_data` mitführen.

**Empfehlung:** Einen `RecordWriter` (Unit of Work) als einzigen Schreibweg für Datensätze einführen: öffnet die Transaktion auf `app_data`, führt Vorher-Phase, Schreiben, Nachher-Phase und Plugin-Ereignisse aus, verschiebt Benachrichtigungen und Mails nach `afterCommit`. Datenansicht, Import, Sammeländerung, Plugin-API und spätere Regel-Engine rufen nur ihn. Ein Architekturtest stellt sicher, dass `DynamicRecord::save()` ausserhalb nicht direkt aufgerufen wird.

### 4.2 Keine Nebenläufigkeitskontrolle (verlorene Änderungen)

Es gibt weder ein Versionsfeld noch einen Vergleich von `updated_at` beim Speichern. Zwei Personen öffnen denselben Datensatz; wer zuletzt speichert, gewinnt, ohne Hinweis. Mit Sammeländerung (B-133), Regeln, die in andere Tabellen schreiben (B-142), und Läufen im Hintergrund wird das zum Alltag. Auch Metadaten (Table-Designer, Rollen, Auswertungen) sind nicht geschützt; dort mildert nur die Sperre je Tabelle im `DynamicSchemaService`.

**Empfehlung:** Optimistische Sperre über `updated_at` (vorhanden in allen Designer-Tabellen): Das Formular trägt den gelesenen Wert mit, der `RecordWriter` prüft ihn und meldet „inzwischen geändert von …“ mit Neu-laden. Für angebundene Tabellen ohne Zeitstempel: Vergleich der Originalwerte (das Muster aus `setKeysForSaveQuery` existiert schon).

### 4.3 Hintergrundarbeit ohne gemeinsames Modell

Entscheid „SAFT läuft ohne Queue-Worker“ zieht sich durch: Export bis 50'000 Zeilen im Speicher, Import bis 10'000 Datensätze im Request (`set_time_limit(600)`), Datenstände 600 s, Dokumente höchstens 50 je Lauf, KI-App-Designer bis 900 s plus Puffer, Auswertungen des Designers als „Schleife im Browser“, Postausgang mit Wiederholung in `REST_CONNECTIONS.md`, „direkt mit Zeitlimit, sonst in Teilen“ in `RULES.md` 8.5, Web-Cron in B-117. Jede Stelle löst dasselbe Problem anders, und mehrere verlassen sich auf `set_time_limit`, das bei FPM-Timeouts, Proxys und Webspace-Limits nicht greift.

**Empfehlung:** Ein kleiner **Arbeitsvorrat** als Systemtabelle (`jobs` von Laravel ist vorhanden, `QUEUE_CONNECTION=database`): Jede lange Arbeit wird in **wiederaufnehmbare Schritte** zerlegt (Zeilenbereiche, Datensatz-Chunks) und von drei austauschbaren Treibern abgearbeitet: Queue-Worker (wo vorhanden), `schedule:run` (Cron jede Minute, Zeitbudget je Aufruf), Browser-Schleife (Livewire-Polling, nur für die startende Person). Export, Import, Dokumente, Datenstände, Läufe, Postausgang und KI-Designer nutzen dasselbe Modell. Fortschritt und Abbruch werden einmal gebaut. Das ist auch die Voraussetzung dafür, dass `afterCommit`-Benachrichtigungen aus 4.1 ohne Worker zuverlässig gehen.

### 4.4 Eine Formelsprache, drei Auswertungswege

- Berechnete Felder: PHP, `Evaluator` mit bcmath, Skala 12, Division durch 0 → leer, Text nur in Vergleichen.
- Auswertungen: `ReportExpression::sql()` übersetzt denselben Baum in SQL (`NULLIF`, `CAST AS numeric`), Rundung durch die Datenbank.
- Geplant (`ROW_ACCESS.md` 4.2): `RowFilterCompiler` übersetzt einen Sprachteil in Query-Builder-Aufrufe.

Schon heute können Rundung (`bcround` HalfAwayFromZero gegen `ROUND()` der Datenbank, das bei MySQL und PostgreSQL für `numeric` gleich, für `float` unterschiedlich arbeitet), Textvergleich (`strcmp` gegen Kollation) und Leer-Semantik abweichen. Wer dieselbe Formel als Feld und als Auswertungsspalte sieht, erwartet dasselbe Ergebnis.

**Empfehlung:** Eine kurze **Sprachspezifikation** (Typen, Leer-Regeln, Rundung, Vergleich von Text, Kurzschluss) als Dokument und eine **Konformitäts-Testtabelle** (Formel, Eingaben, erwartetes Ergebnis), die gegen alle Auswertungswege auf allen drei Datenbanken läuft. Jede neue Funktion (Datum, `changed()`, `user()`) wird dort zuerst eingetragen.

### 4.5 Rechte: Tabellenebene, Namensbindung, Sonderrolle

- Berechtigungen `data.<tabelle>.<aktion>` hängen am **technischen Tabellennamen**, nicht am stabilen Schlüssel. Umbenennen ist heute verhindert, aber jede weitere Namensabhängigkeit (Regeln, Auswertungen, Plugins `requiredTables`) zementiert das. Die stabilen Schlüssel sind da, werden aber nicht für Rechte genutzt.
- Der Super-Admin wird an 23 Stellen über den Rollennamen `super-admin` erkannt (`hasRole`), zusätzlich zum `Gate::after`. Jede dieser Stellen ist eine potenzielle Lücke in „Ansicht als Rolle“.
- Zeilenrechte (`ROW_ACCESS.md`) sind richtig als globaler Scope mit Ausnahmeliste und Architekturtest geplant. Der dort genannte Grundsatz „eine vergessene Stelle ist ein Datenleck“ gilt bereits heute für `DataAccess::allows()`: Es gibt keinen Test, der alle Lesestellen gegen eine Rolle ohne Recht prüft.

**Empfehlung:** Vor Z2 der Zeilenrechte eine **Lesestellen-Inventur** als Test: jede Stelle, die `DynamicRecord::forTable()` aufruft, steht auf einer Liste mit Begründung (wie für `withoutRowAccess()` geplant). Super-Admin-Erkennung in eine Methode kapseln und `hasRole('super-admin')` per Architekturtest verbieten.

### 4.6 Apps: logische Klammer mit offenem Widerspruch

Das App-Konzept ist bewusst keine Mandantenfähigkeit; Tabellennamen bleiben global, Plugins sehen alles, Verweise über App-Grenzen sind echte Fremdschlüssel in derselben Datenbank. Das ist konsistent und dokumentiert. Im Backlog steht aber **B-109 „Eigene Datenbank je App“** offen, und `APPS.md` 5 nennt den Widerspruch selbst: Mit getrennten Datenbanken müssten Verknüpfungen über App-Grenzen ohne Fremdschlüssel auskommen. Solange B-109 offen ist, baut jede neue Freigabe über Schnittstellen eine Hypothek auf.

**Empfehlung:** B-109 entscheiden, am besten verwerfen. Was es leisten soll (Trennung, Sicherung je App, Übertragung), leisten `BACKUP_RESTORE.md` (Sicherung je App im Format `saft-appdata/1`) und das Paketformat bereits.

### 4.7 Grosse Klassen und verteilte Umbenennungslogik

`DynamicSchemaService` (1'192 Zeilen) enthält neben DDL die Nachführung von Formeln, Regeln, Kopien, Anzeigefeldern und Zählbereichen bei Umbenennungen (`rename*References`, `*Using`, `copiesFrom`, `relationsDisplaying`). Daneben gibt es `DynamicData/Usages/TableUsages` für „Verwendet in“. Dieselbe Frage „wer verweist auf dieses Feld“ wird also zweimal beantwortet. Mit Regeln, Zeilenrechten, Nummernkreisen und Dokumentvorlagen kommen vier weitere Verweisarten dazu.

`ReportDesigner` (1'108 Zeilen) und `DynamicRecordActions` (944) sind Livewire- bzw. UI-Klassen mit viel Fachlogik; `TableAssistant` (924) mischt Prompt, Prüfung und Umsetzung; `ChangePlanner` (851) ist bereits in Plan/Step/Executor gegliedert und als Vorbild geeignet.

**Empfehlung:** Eine **Verweis-Registry**: Jede Objektart meldet, welche Felder sie referenziert und wie sie umbenennt oder blockiert (`FieldReferenceProvider`). `DynamicSchemaService` fragt nur die Registry; „Verwendet in“, Löschen-Blocker, Umbenennen und der Änderungsplan nutzen dieselbe Quelle. Danach `DynamicSchemaService` in Operationen (CreateTable, ChangeField, DropField …) aufteilen, wie es `ChangeStep` schon vorzeichnet.

### 4.8 Keine statische Analyse

`composer.json` enthält Pint, aber weder PHPStan/Larastan noch Rector. Bei 67'000 Zeilen in acht Tagen ist das die grösste Lücke der Qualitätssicherung: Typfehler in selten durchlaufenen Zweigen (Fehlerpfade, MySQL-Angleich, SAML-Logout) findet kein Test. 25 Dateien ohne `declare(strict_types=1)` sind die generierten Base-Models; das ist in Ordnung, sollte aber im Reliese-Template ergänzt werden.

**Empfehlung:** Larastan Level 5 einführen, Baseline erzeugen, in CI und vor jedem Commit laufen lassen; Level schrittweise auf 7 heben. Zusätzlich Architekturtests (z. B. mit `pestphp/pest-plugin-arch` oder einem eigenen Test wie `PluginApiContractTest`) für: kein `DB::raw` ausserhalb `Dialects`/`Ddl`, kein `hasRole('super-admin')`, kein `DynamicRecord::save()` ausserhalb des `RecordWriter`.

### 4.9 Migrationen mit künstlichen Zeitstempeln

Die Migration `2027_01_08_100000_create_document_templates.php` wurde am 22.11.2026 angelegt; alle Migrationen seit `2026_11_24` tragen Daten in der Zukunft. Laravel ordnet nach Dateinamen. Legt jemand eine Migration mit dem echten Datum an (`2026_11_24_…`), läuft sie auf einer **frischen** Installation vor `2027_01_08_…`, auf einer **bestehenden** danach. Abhängigkeiten (etwa `document_templates` → `apps`) brechen dann nur bei Neuinstallationen, also gerade in CI und bei neuen Kunden.

**Empfehlung:** Die Konvention festhalten (`CLAUDE.md`): neue Migrationen bekommen den nächsten Tag nach der höchsten vorhandenen, nie das echte Datum. Noch besser vor 1.0: einmalig auf fortlaufende Präfixe umstellen (`0001_…`), solange Produktivinstallationen überschaubar sind, mit Umbenennung der Einträge in `migrations`.

### 4.10 Änderungsprotokoll wächst unbegrenzt

`config/activitylog.php` setzt `clean_after_days = 365`, aber `activitylog:clean` wird nirgends geplant, und es gibt keine Aufgabenart dafür (nur `TaskRunsPrune`, `SnapshotPrune`, `BackupPrune`, `FilesPrune`). `DynamicRecord` protokolliert **alle** Nicht-Systemspalten (`logAll`), also auch Personendaten; eine Aufbewahrungsfrist je App oder Tabelle gibt es nicht. Für Schweizer DSG und DSGVO fehlt damit ein Löschkonzept: Papierkorb und Protokoll halten gelöschte Personendaten unbegrenzt.

**Empfehlung:** Aufgabenart „Änderungsprotokoll kürzen“ (Tage je Protokollname), Aufbewahrungsfrist je Tabelle im Table-Designer, „endgültig löschen“ aus dem Papierkorb nach Frist, und ein Kapitel „Datenschutz und Löschen“ im Admin-Handbuch.

### 4.11 Dokumentation

Stärken: vollständige Struktur in vier Sprachen (Überschriften und Bilder stimmen je Sprache überein), Screenshots automatisiert, Hilfe-Assistent liest die Handbücher, Konzepte mit Entscheidungen.

Schwachstellen:

- **Kein Architekturdokument.** Die Architektur steht in Klassen-Docblocks (gut), `CLAUDE.md` (Vorgaben) und `BACKLOG.md`, nirgends als Übersicht: Schichten, Verbindungen, Schreibwege, Rechte-Modell, Erweiterungspunkte, bewusste Entscheidungen (ADRs: kein Worker, zwei Verbindungen, Rechte am Namen, Datenbank als Wahrheit). Für neue Mitwirkende und für KI-Agenten ist das die wichtigste fehlende Datei.
- **Übersetzungen ohne Veraltungs-Erkennung.** Vier Fassungen werden von Hand synchron gehalten. Ein Hash des Quellabschnitts im Zielabschnitt (oder ein Test, der Absatzzahlen je Abschnitt vergleicht) würde Abweichungen melden.
- **Kleinigkeiten:** `AGENTS.md` ist Laravel-Boost-Boilerplate ohne Projektbezug; `DEVELOPER_GUIDE.md` trägt „Version 0.1“ bei API 1.9; `CLAUDE.md` nennt PHP 8.3+, `composer.json` verlangt 8.4; README verspricht „clean up logs on a schedule“, das gilt nur für Laufprotokolle (4.10).

### 4.12 Weitere Beobachtungen

- **Metadaten je Request:** Tabellendefinitionen werden je Prozess in `DynamicRecord::$definitions` gehalten und je Request aus der Datenbank geladen; `CurrentApp::appIds()` iteriert Rollen × Rechte. Bei Dutzenden Tabellen und entferntem Datenbankserver (Strato) summiert sich das; ein Cache mit Invalidierung über den vorhandenen `saved`-Hook an `CustomTable`/`CustomField` wäre billig.
- **Plugins sind vertrauter Code.** Dokumentiert in Entwicklerhandbuch 16, aber im Admin-Handbuch sollte stehen: Ein Plugin installieren heisst, dem Autor Vollzugriff auf Server und Datenbank zu geben; `saft:plugins --check` ist keine Sicherheitsprüfung.
- **Dusk-Route** in Nicht-Produktion ist klar dokumentiert; ein Startup-Check, der bei `APP_ENV=production` mit installierten Dev-Paketen warnt, würde die Hürde senken.
- **Englisch fällt zurück** (2'829 statt 3'302 Schlüssel): bewusst über Laravel-Lang, sollte aber in den Tests sichtbar sein, sonst fehlen Texte unbemerkt.

## 5. Risiken für die Weiterentwicklung (geordnet)

| Rang | Risiko | Wirkung | Betroffene Vorhaben |
|---|---|---|---|
| 1 | Speichern ohne Transaktion und ohne Schreibweg (4.1) | Inkonsistente Summen, Dateien, Protokoll; Regeln nicht zurückrollbar | B-142, B-020, B-135, B-133 |
| 2 | Verlorene Änderungen (4.2) | Datenverlust ohne Hinweis, schwer reproduzierbar | B-133, B-142, B-134 |
| 3 | Hintergrundarbeit je Feature anders (4.3) | Timeouts, Speicherfehler, mehrfacher Aufwand | B-060, B-117, B-137, B-138, R3b Läufe |
| 4 | Formelsemantik driftet (4.4) | Falsche Zahlen in Auswertungen gegenüber Feldern, schwer zu entdecken | B-119, B-020, B-142 |
| 5 | Keine statische Analyse (4.8) | Fehler in Fehlerpfaden, Refactorings riskant | alle |
| 6 | Verweise an fünf Stellen (4.7) | Jede neue Objektart verdoppelt Umbenennen/Blockieren | B-142, B-135, B-139 |
| 7 | Migrationsreihenfolge (4.9) | Bricht frische Installationen und CI | jede neue Migration |
| 8 | Protokoll ohne Löschkonzept (4.10) | Compliance, Datenbankwachstum | Betrieb |
| 9 | B-109 unentschieden (4.6) | Schnittstellen bauen auf FK, die später wegfallen müssten | B-107 Etappe 5 |

## 6. Refactoring-Roadmap

**Jetzt, vor den nächsten Konzepten (eine Etappe „Fundament 2“):**

1. `RecordWriter` als einziger Schreibweg mit Transaktion auf `app_data`, Phasen vorher/nachher, `afterCommit` für Benachrichtigungen; Entscheidung zum Protokoll auf der Hauptverbindung dokumentieren.
2. Optimistische Sperre über `updated_at` im Formular und in der Plugin-API.
3. Larastan Level 5 mit Baseline in CI; drei Architekturtests (Raw-SQL, Super-Admin-Erkennung, Schreibweg).
4. Migrationskonvention in `CLAUDE.md`; Aufgabenart „Änderungsprotokoll kürzen“.
5. `ARCHITECTURE.md` (deutsch, später englisch) mit Schichten, Verbindungen, Schreibwegen, Rechte-Modell und den sieben wichtigsten Entscheidungen.

**Mittelfristig (mit B-142 und B-020):**

6. Hintergrund-Arbeitsvorrat mit drei Treibern (Worker, Cron, Browser-Schleife); Export, Import, Dokumente, Datenstände umstellen.
7. Formel-Spezifikation und Konformitätstests über alle Auswertungswege und Datenbanken.
8. Verweis-Registry; `DynamicSchemaService` in Operationen aufteilen.
9. Lesestellen-Inventur als Test (Grundlage für den Zeilenrechte-Scope).

**Vor 1.0:**

10. `ReportDesigner`, `DynamicRecordActions` und `TableAssistant` in Fachlogik und UI trennen; Fachlogik ohne Livewire testbar machen.
11. Metadaten-Cache mit Invalidierung.
12. Rechte an stabile Schlüssel binden (Migration der Berechtigungsnamen) oder begründet beim Namen bleiben und Umbenennen dauerhaft ausschliessen.
13. Löschkonzept (Aufbewahrung je Tabelle, Papierkorb-Frist, Anonymisierung) samt Handbuchkapitel.

## 7. Fehlende Features

Nach Nutzen für den Zweck „Fachanwendungen konfigurieren statt programmieren“ geordnet. Bereits im Backlog Stehendes ist mit Nummer genannt.

**Hoch:**

- **REST-API als Server (B-023).** Ohne sie ist SAFT eine Insel: keine Anbindung von Formularen auf der Website, von Mobil-Apps, von Automatisierungsdiensten in Richtung SAFT. Die Freigabe-Logik der Apps und der `RecordWriter` sind die Grundlage; API-Tokens je Person oder Dienstkonto, Rechte wie in der Oberfläche.
- **Zeilenrechte (B-020)** und danach **Feldrechte.** Ohne sie ist jede Tabelle mit Personendaten entweder für alle oder für niemanden sichtbar; für Musikschule, Verein, Verwaltung die häufigste Anforderung.
- **Regel-Engine und Läufe (B-142).** Deckt die 80 % ab, für die heute ein Plugin nötig ist; Rechnungs- und Mahnlauf sind die Prüfsteine.
- **Sammeländerung (B-133)** mit Rückgängig.
- **Nummernkreise (B-135).** Voraussetzung für jeden Beleg.

**Mittel:**

- **Gespeicherte Ansichten (B-022)**, auch als Menüeintrag und für Rollen; heute nur Spaltenauswahl je Menüeintrag.
- **Globale Suche über Datensätze.** Filaments globale Suche ist nur für Menüs eingerichtet; eine Suche über die Designer-Tabellen mit Rechten (und später Zeilenrechten) fehlt.
- **Benachrichtigungen auf Änderungen** („informiere mich, wenn Status auf X wechselt“) als Spezialfall der Regel-Engine, ohne Admin.
- **Statusmodell** (erlaubte Übergänge je Auswahlfeld), in `RULES.md` 13.3 vorgemerkt; verständlicher als Regeln.
- **Datensatz-Versionen wiederherstellen.** Der Verlauf ist da (Protokoll), „auf diesen Stand zurück“ fehlt.
- **Mehrsprachige Daten.** Bezeichnungen sind mehrsprachig, Feldwerte nicht (etwa Kursname de/fr). Ein Feldtyp „Text je Sprache“ wäre im Modell der Übersetzungen (`spatie/laravel-translatable`) naheliegend.
- **Auswertungen als PDF (B-138)** und als geplanter Versand.
- **Dateianhang: Virenprüfung (B-106)** und Vorschau für Office-Dateien.
- **Strenge CSP (B-058).**

**Niedriger, aber betriebsrelevant:**

- **Installation und Update über die Oberfläche (B-031)** inklusive Prüfung der Voraussetzungen.
- **Gesundheits- und Metrik-Endpunkt** über `/up` hinaus (Scheduler-Heartbeat, Aufgabenstatus, Datenbankverbindungen) für externes Monitoring.
- **SCIM oder Gruppen-Synchronisation** aus Entra ID, damit Rollen nicht nur bei der Anmeldung abgeglichen werden.
- **Passkeys/WebAuthn** als zweiter Faktor neben TOTP.
- **Wiederherstellen über die Oberfläche (B-141)**, Konzept liegt vor.
- **MCP-Server für den Entwicklerkontext (B-033)**: würde den Weg „KI-Agent baut Plugin“ abrunden.

## 8. Fazit

Die Konzeption ist stimmig und die Richtung richtig: konfigurieren statt programmieren, Plugins für den Rest, Apps als Paket, KI als geprüfter Vorschlag. Die Konzepte der nächsten Vorhaben sind reifer als das Fundament, auf dem sie stehen wollen. Die Lücke ist klar umrissen: eine atomare Schreibeinheit, Schutz vor verlorenen Änderungen, ein gemeinsames Modell für Hintergrundarbeit und eine spezifizierte Formelsprache. Werden diese vier Punkte als eigene Etappe vor B-142 und B-020 gelöst und mit statischer Analyse und Architekturtests abgesichert, trägt die Architektur die geplante Breite. Werden sie je Konzept nebenher gelöst, entstehen vier Fassungen desselben Problems, und spätestens die Regel-Engine wird sie alle gleichzeitig brauchen.
