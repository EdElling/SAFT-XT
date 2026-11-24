# Konzept: Fundament 2 (Schreibweg, Nebenläufigkeit, Hintergrundarbeit, Formelsprache, Qualitätssicherung)

Stand: 23.11.2026 · Status: **F0, F1, F2 und F4 umgesetzt (23.11.2026), F3 und F5 offen** · Backlog: B-143 · Herkunft: [Architektur-Review vom 23.11.2026](../REVIEW_ARCHITEKTUR_2026-11-23.md)

## 1. Ziel

Die nächsten grossen Vorhaben – Regel-Engine (B-142), Zeilenrechte (B-020), Sammeländerung (B-133), Nummernkreise
(B-135), REST-Anbindungen (B-060), Läufe (B-137) – setzen in ihren Konzepten dieselben vier Grundlagen voraus, die
heute fehlen:

1. **Eine atomare Schreibeinheit** für Datensätze: Speichern, Nachführen, Prüfen und Protokollieren als Ganzes, mit
   Rückrollen bei jedem Fehler.
2. **Schutz vor verlorenen Änderungen**, wenn zwei Personen oder eine Person und ein Lauf denselben Datensatz ändern.
3. **Ein gemeinsames Modell für Hintergrundarbeit**, das auf einfachem Webspace ohne Worker funktioniert und mit Worker
   besser wird.
4. **Eine spezifizierte Formelsprache**, deren Ergebnis unabhängig davon gleich ist, ob PHP oder die Datenbank rechnet.

Dazu kommen Massnahmen, die den Code dauerhaft prüfbar halten (statische Analyse, Architekturtests), zwei
Aufräumarbeiten (Migrationsnamen, Änderungsprotokoll) und ein Architekturdokument.

**Fundament 2 liefert keine neue Funktion für Anwender.** Es ersetzt bestehende Mechanismen durch gemeinsame, prüft sie
und dokumentiert sie. Sichtbar werden nur: eine Meldung „inzwischen geändert“, Fortschrittsanzeigen bei langen
Arbeiten und ein kürzeres Änderungsprotokoll.

## 2. Ausgangslage

**Es gibt keine produktive Installation.** Vorhanden sind das Entwicklungs- und Testsystem und eine Demo auf einem
Webhoster (Strato) auf Stand 0.4.0. Das erlaubt Entscheidungen, die später teuer wären:

- Brechende Änderungen am Schema und an Namen (Migrationen, Berechtigungsnamen) sind **ohne Umstellungspfad** möglich.
  Die Demo wird mit 0.6.0 **neu installiert** (Demo-Seeder), nicht aktualisiert.
- Die Plugin-API darf eine **Major-Version** bekommen, wenn es sauberer ist (heute 1.9; vier Beispiel-Plugins, alle im
  Repository).
- Datenformate (`saft-app/1`, `saft-config/1`) bleiben, weil Pakete bereits exportiert sein können; sie sind von
  Fundament 2 nicht betroffen.

Was heute wie läuft:

| Baustein | Heute |
|---|---|
| Speichern aus der Datenansicht | `DynamicRecordActions` ruft `save()` bzw. `update()` direkt; **keine Transaktion**. `DynamicRecord::booted()` hängt daran: Vorgaben, Übernehmen aus, fortlaufend, berechnete Felder, `RecordSaving`, Summen in Master-Datensätzen (`Recalculator::propagate`, speichert Eltern rekursiv), Dateien (`RecordFiles::sync`), Protokoll (`activity_log`, **Hauptverbindung**), `RecordSaved` |
| Import | `RecordImporter` mit eigener Transaktion auf `app_data`, Protokoll abgeschaltet; `DataImporter` verschachtelt Haupt- und `app_data`-Transaktion |
| Plugin-API | `Saft::transaction()` und `DataTable::create/update/delete()` ohne eigene Transaktion |
| Kopieren, Dokument ablegen | `RecordCopier`, `DocumentGenerator` rufen `save()` direkt |
| Nebenläufigkeit | keine; `updated_at` vorhanden, wird nicht geprüft. Metadaten: Sperre je Tabelle nur im `DynamicSchemaService` |
| Lange Arbeiten | Export bis 50'000 Zeilen im Speicher (Livewire), Import bis 10'000 Datensätze (`set_time_limit(600)`), Datenstände (600 s), Dokumente ≤ 50 je Lauf, KI-App-Designer (bis 2 × 900 s), Auswertungen des Designers als Schleife im Browser, Aufgaben über `tasks:run-due` jede Minute mit `set_time_limit(0)` |
| Formeln | PHP: `Evaluator` (bcmath, Skala 12, `bcround` kaufmännisch); SQL: `ReportExpression::sql()` mit `NULLIF`, `CAST AS numeric`, Rundung der Datenbank; kein gemeinsamer Testsatz |
| Qualitätssicherung | Pint; 1'350 Testmethoden; Vertragstest für die Plugin-API; **keine** statische Analyse |
| Migrationen | 85 Dateien, Zeitstempel seit `2026_11_24` künstlich (bis `2027_01_08`), echtes Datum der letzten: 22.11.2026 |
| Änderungsprotokoll | `clean_after_days = 365` konfiguriert, aber nie ausgeführt; `DynamicRecord` protokolliert alle Spalten |

## 3. Entscheidungen (Vorschlag)

| Frage | Vorschlag |
|---|---|
| Transaktion über zwei Verbindungen | **Je eine Transaktion auf beiden Verbindungen** (Entscheid 23.11.2026, statt Protokoll nach dem Commit): der Writer öffnet sie auf der Hauptverbindung und auf der Verbindung der Daten (`app_data` bzw. die externe), committet die Daten zuerst, die Hauptverbindung danach. Scheitert eine Phase, rollen beide zurück – auch Protokoll, Benachrichtigungen und Plugin-Schreibzugriffe auf Systemtabellen. Restrisiko: Daten committet, Commit der Hauptverbindung scheitert → Protokolleintrag fehlt (wie bei `afterCommit`). Eine verteilte Transaktion gibt es nicht (verschiedene Server möglich, `DB_APP_DATA_HOST`). `afterCommit` bleibt für Wirkungen nach aussen (E-Mail, Webhooks). |
| Einziger Schreibweg | Klasse **`RecordWriter`** (`App\DynamicData\Write`). Oberfläche, Import, Plugin-API, Kopieren, Dokumente rufen sie ausdrücklich mit `WriteOptions`. `DynamicRecord::save()`/`delete()` ausserhalb des Writers **leiten an ihn weiter** statt zu werfen (Entscheid 23.11.2026): bestehender Code, Plugins, Seeder und 230 Teststellen laufen unverändert und bekommen die Transaktion trotzdem. Architekturtest 3 prüft heuristisch, dass App-Code den Writer ausdrücklich nutzt. |
| Nebenläufigkeit | **Optimistische Sperre** über `updated_at` für Designer-Tabellen; Vergleich der gelesenen Originalwerte für angebundene Tabellen ohne Zeitstempel. Keine pessimistischen Sperren (Webspace, kein Redis). |
| Hintergrundarbeit | Eine Systemtabelle **`work_items`** („Arbeiten“) mit **wiederaufnehmbaren Schritten** und drei austauschbaren Treibern: Scheduler (vorhandener Cron), Queue-Worker (wenn vorhanden), Browser (für die startende Person). Kein eigenes Framework; der Treiber Queue nutzt Laravels `jobs`-Tabelle. |
| Formelsprache | **Spezifikation als Dokument** plus **Konformitätstabelle** (JSON), die gegen alle Auswertungswege und alle drei Datenbanken läuft. Die PHP-Auswertung ist die Referenz; wo SQL abweicht, wird das SQL angepasst oder die Funktion für SQL gesperrt. |
| Statische Analyse | **Larastan**, Start Level 5 mit Baseline, in CI bei jedem Pull-Request und lokal vor dem Commit. |
| Migrationen | **Einmalig umbenennen** auf fortlaufende Präfixe (`0001_…`), Inhalt unverändert; Entwicklungs-, Test- und Demo-Datenbanken werden neu aufgesetzt. Keine Zusammenfassung der Migrationen (Risiko bei drei Datenbanken, Nutzen gering). |
| Berechtigungsnamen | **Bleiben am Tabellennamen** (`data.<tabelle>.<aktion>`). Begründung: lesbar im Protokoll, Plugins nennen Tabellen beim Namen (`requiredTables`), Umbenennen von Tabellen ist bewusst ausgeschlossen. Wird in ARCHITECTURE.md als Entscheidung festgehalten. |
| Protokoll | Aufgabenart **„Änderungsprotokoll kürzen“** (Tage je Protokollname, Vorgabe 365, Anmeldungen 90) als System-Aufgabe; Aufbewahrung je Tabelle und Löschkonzept folgen in einem eigenen Vorhaben. |
| Reihenfolge | Fundament 2 **vor** B-142, B-020, B-133, B-135, B-060 – als Release **0.6.0**. |

## 4. F1 – Schreibweg `RecordWriter`

### 4.1 Aufbau

```
RecordWriter::create(table, values, options)  → DynamicRecord
RecordWriter::update(record, values, options) → DynamicRecord
RecordWriter::delete(record, options)         → void   (Papierkorb oder endgültig)
RecordWriter::restore(record, options)        → DynamicRecord
RecordWriter::batch(callable)                 → mixed  (mehrere Aufrufe in einer Transaktion)
```

`options` (Objekt `WriteOptions`): `expectedVersion` (Abschnitt 5), `authorize` (Rechte prüfen, Vorgabe ja),
`pluginEvents` (Vorgabe ja; Demo-Daten aus), `log` (Vorgabe ja; Import schreibt einen Sammeleintrag), `causer`,
`origin` (Datenansicht, Import, Plugin, Regel, Lauf, Sammeländerung – kommt ins Protokoll als `via`).

### 4.2 Ablauf eines Aufrufs

1. **Transaktionen** auf der Hauptverbindung und auf der Verbindung der Daten öffnen, falls der Writer nicht schon aktiv
   ist (`WriteContext`; verschachtelte Aufrufe – `batch()`, Summen in Master-Datensätzen, Plugins in Ereignissen – laufen
   in derselben Transaktion und erben die Optionen).
2. **Version prüfen** (F2), bei Abweichung `StaleRecordException`.
3. **Vorher-Phase** (heute in `DynamicRecord::booted()` als `saving`): Vorgaben, Übernehmen aus, fortlaufend,
   `RecordSaving` für Plugins, berechnete Felder, `updated_by`. Später hier: Regeln der Phase `before`,
   Nummernkreise, Zeilenrecht für *ändern*.
4. **Schreiben** (`save()` des Models ohne weitere Hooks).
5. **Nachher-Phase**: Summen in Master-Datensätzen (`propagate`, jetzt innerhalb derselben Transaktion), Dateien
   abgleichen, `RecordSaved`. Später: Regeln der Phase `after`.
6. **Commit** der Daten, dann der Hauptverbindung (Protokoll, Benachrichtigungen liegen damit in derselben Einheit).
   Wirkungen nach aussen (E-Mails, Webhooks B-060) später über `afterCommit`. Bei Rollback passiert davon nichts.

   Umgesetzt: Die Nachher-Phase (5) läuft in den Eloquent-Ereignissen `saved`/`deleted`, solange der Writer den
   Datensatz schreibt – dort sind die Originalwerte noch die vor der Änderung, was `propagate` (alter und neuer Master)
   und `previous()` der Plugin-Ereignisse brauchen.

Eine Ausnahme in Schritt 2 bis 5 rollt alles zurück und wird als `BusinessRuleException`, `ValidationException`,
`RecordRejected` oder `StaleRecordException` unverändert weitergereicht; die Oberfläche zeigt sie wie heute.

### 4.3 Was aus `DynamicRecord` herauswandert

`DynamicRecord::booted()` behält nur, was das Model zum **Lesen** braucht (Scope für `deleted_at`, Casts, Schlüssel),
sowie zwei Zeilen, die die Nachher-Phase in `saved`/`deleted` an `WritePhases` geben. Alles Schreibende liegt in
`WritePhases` (Reihenfolge wie bisher, dokumentiert). Das Model bleibt als Typ in der Plugin-API zugesichert; `save()`
und `delete()` ausserhalb des Writers leiten an ihn weiter (Umsetzung 23.11.2026, siehe Abschnitt 3).

### 4.4 Umstellung der Aufrufer

| Stelle | Umstellung |
|---|---|
| `DynamicRecordActions` (anlegen, bearbeiten, löschen, wiederherstellen, Sammel-Löschen, kopieren) | Writer; `expectedVersion` aus dem Formular |
| `DynamicDetailTable`, Kaskade | wie oben |
| `RecordImporter`, `DataImporter` | `batch()` statt eigener Transaktion; `log: false` je Datensatz, Sammeleintrag danach |
| `RecordCopier` | `batch()` |
| `DocumentGenerator` (Dokument ins Dateifeld) | Writer |
| `Recalculator::propagate`, `recomputeTable` | intern vom Writer aufgerufen; `computed:recompute` nutzt `batch()` je Tabelle in Blöcken |
| Plugin-API `DataTable::create/update/delete()` | Writer mit `authorize` nach `authorizing`; `DataTable::transaction()` → `batch()` |
| `AppRemover`, Demo-Reset, Seeder | `batch()` mit `pluginEvents: false`, `log: false` |

### 4.5 Plugin-API

Die Signaturen von `DataTable` bleiben. Neu (Minor, API 1.10): `update()` nimmt optional `expectedVersion`;
`StaleRecordException` ist zugesichert; `RecordSaved` läuft jetzt **innerhalb** der Transaktion (bisher danach ohne
Transaktion). Ein Listener, der eine Ausnahme wirft, rollt damit die Änderung zurück – das war in der Dokumentation
schon so beschrieben, stimmte aber nicht. Im Changelog ausdrücklich nennen.

## 5. F2 – Verlorene Änderungen verhindern

- **Umgesetzt 23.11.2026, Abweichung:** Die Version ist für alle Tabellen eine **Prüfsumme über die Rohwerte der
  bearbeitbaren Felder** (`RecordVersion`, ohne berechnete Felder und Systemspalten), nicht `updated_at`. Grund: Eine
  nachgeführte Summe im Master-Datensatz (Bestellposition erfasst) änderte `updated_at` und löste einen Fehlalarm aus,
  obwohl niemand ein bearbeitbares Feld geändert hat; angebundene Tabellen ohne Zeitstempel sind so gleich geschützt.
  `updated_by`/`updated_at` liefern nur den Meldungstext. Das Formular trägt die Version in einem versteckten Feld.
- Der Writer vergleicht in Schritt 2 innerhalb der Transaktion (`SELECT … FOR UPDATE` über den Query Builder,
  `lockForUpdate()`, portabel) und wirft `StaleRecordException`, wenn der Stand abweicht.
- Die Oberfläche zeigt: „*Anna Muster* hat diesen Datensatz um 14:32 geändert. Ihre Eingaben wurden nicht gespeichert.“
  mit den Knöpfen **Neu laden** und **Trotzdem speichern** (nur wenn die Person das Recht *ändern* hat; das ist ein
  bewusstes Überschreiben und steht so im Protokoll, `overwrote`). Umgesetzt als Meldung mit zwei Aktionen, die
  Livewire-Ereignisse an die Komponente mit dem offenen Formular senden (`HandlesStaleRecords`): „Trotzdem speichern“
  setzt die Version im offenen Formular auf den aktuellen Stand und schickt es erneut ab – das ganze Formular
  überschreibt dann den fremden Stand.
- Sammeländerung (B-133) und Läufe (B-137) arbeiten ohne Version je Datensatz, dafür mit **Vorschau und Transaktion**;
  der Writer prüft dort nichts, das steht in `origin`.
- Metadaten: die elf Bearbeiten-Seiten der Verwaltung (`EditRecord`) prüfen `updated_at` über das Trait
  `DetectsConcurrentEdits` (Hook `beforeValidate`), gleiche Meldung, gleiche Knöpfe; wer geändert hat, kommt aus dem
  Änderungsprotokoll. Nicht dabei: der Auswertungs-Designer und die Feld-Modale des Table-Designers (dort schützt die
  Sperre je Tabelle im `DynamicSchemaService`).
- Plugin-API 1.10: `DataTable::version()`, `update(…, $expectedVersion)`, `StaleRecordException` zugesichert.

Nicht Ziel: Anzeige „wird gerade bearbeitet von …“ (bräuchte Heartbeats, bringt auf Webspace wenig).

## 6. F3 – Hintergrundarbeit („Arbeiten“)

### 6.1 Modell

Tabelle `work_items` (Base-Model-Muster, `LogsActivity` nur für Start, Abbruch, Fehler):

| Spalte | Inhalt |
|---|---|
| `id`, `uuid` | Schlüssel |
| `type` | Art, z. B. `export`, `record_import`, `documents`, `snapshot_refresh`, `app_designer`, `recompute`; Plugins können Arten beisteuern (wie `TaskType`) |
| `app_id`, `user_id` | App, startende Person (null bei System) |
| `status` | `queued`, `running`, `done`, `failed`, `cancelled` |
| `total`, `done` | Fortschritt in Einheiten der Art (Zeilen, Datensätze, Seiten) |
| `state` | JSON: Cursor für die Wiederaufnahme (letzte ID, Blatt, Seite, Gesprächsstand) |
| `result` | JSON: Ergebnis (Datei unter `storage/app/private/work/<uuid>/`, Zahlen, Meldung) |
| `error`, `error_detail` | wie bei `task_runs` |
| `heartbeat_at`, `started_at`, `finished_at`, `expires_at` | Steuerung und Aufräumen |

Eine **Art** (`WorkType`) liefert: `prepare(params) → state, total`, `step(state, budget) → state | finished`,
`describe()`, `maxBudget()`. Jeder Schritt ist **kurz** (Ziel ≤ 20 s), arbeitet einen Block ab, speichert den
Cursor und läuft in **einer Transaktion je Block** (Import: alles oder nichts bleibt – dort wird in einer
Transaktion über alle Blöcke gearbeitet, oder bei Grössen über der Blockgrenze in eine Staging-Tabelle importiert
und am Ende umgehängt; zu entscheiden, Abschnitt 11).

### 6.2 Treiber

| Treiber | Wann | Wie |
|---|---|---|
| **Browser** | die startende Person sieht die Seite | Livewire-Komponente `WorkProgress` pollt alle 2 s `advance()`: nimmt die Sperre der Arbeit, führt Schritte bis zu einem Zeitbudget (Vorgabe 15 s, unter `max_execution_time`) aus, zeigt Fortschritt, Abbrechen, Ergebnis-Download. So fühlt sich eine Arbeit wie heute „direkt“ an, ohne an einem Timeout zu scheitern. |
| **Scheduler** | immer (vorhandener Cron jede Minute) | neuer Befehl `work:run` im `schedule:run`: nimmt offene Arbeiten ohne frischen Heartbeat, Zeitbudget 50 s je Aufruf, `withoutOverlapping`. Übernimmt, wenn die Person die Seite verlässt. |
| **Queue-Worker** | wenn `queue:work` läuft (eigener Server) | Job `AdvanceWork` je Schritt, der sich selbst erneut einreiht; auf Webspace nie gestartet. Erkennung: Einstellung `WORK_DRIVER=queue` oder Heartbeat des Workers. |

Alle drei greifen über dieselbe Sperre (`Cache::lock('work:'.$id)`) auf dieselbe Arbeit zu; wer die Sperre hat, macht
den nächsten Schritt. Deshalb stören sie sich nicht, und auf Webspace reichen Browser plus Cron.

### 6.3 Umgestellte Arbeiten (Etappe F3b)

Export (schreibt die Datei blockweise statt im Speicher; die Grenze 50'000 Zeilen fällt auf ein grosszügiges
Maximum, Download aus `work/<uuid>/`), Datenimport in bestehende Tabellen, Dokumente für mehrere Datensätze
(Grenze 50 fällt), Datenstände, `computed:recompute`, KI-App-Designer (jede KI-Anfrage ein Schritt, der Gesprächsstand
im `state`; damit entfällt `set_time_limit(2 × 900)`), Auswertungen des App-Designers (heute Browser-Schleife),
Sicherung je App (B-141). **Nicht** umgestellt: Tabellen-Import aus Datei (legt Schema an, bleibt synchron mit
Grenze), geplante Aufgaben (bleiben `TaskRunner`; eine Aufgabenart kann aber eine Arbeit starten und auf sie warten).

### 6.4 Oberfläche

Seite **„Arbeiten“** unter Administration (eigene Arbeiten für alle, alle für `work.view_any`): Liste mit Art, Person,
Fortschritt, Dauer, Ergebnis, Abbrechen. Aufräumen: System-Aufgabe „Arbeiten aufräumen“ löscht Ergebnisse nach
`expires_at` (Vorgabe 24 h) und Einträge nach 30 Tagen. Dashboard-Kachel „Aufgaben“ zeigt hängende Arbeiten.

### 6.5 Entscheide zur Umsetzung von F3a (24.11.2026)

- **Zusätzliche Spalten:** `parameters` (Eingaben beim Start, getrennt vom Cursor `state`), `message`,
  `cancel_requested_at` (Abbrechen setzt nur die Marke, der Runner beendet zwischen zwei Schritten – kein Wettlauf mit
  einem laufenden Schritt), `stalls` (Übernahmen ohne Fortschritt; ab 3 gilt die Arbeit als fehlgeschlagen, damit ein
  Schritt, der PHP jedes Mal abbricht, nicht endlos wiederholt wird), `simulated_roles` (siehe unten).
- **`prepare()` läuft im ersten Schritt**, nicht beim Start: schon das Zählen kann lange dauern. Starten legt nur
  `queued` an.
- **Kein `maxBudget()`** in `WorkType`: Das Budget gehört dem Treiber; der Schritt sieht es über
  `WorkStep::remaining()` und bemisst seinen Block danach. Arten mit unteilbar langen Schritten (KI-Anfragen) bekommen
  in F3b bei Bedarf eine eigene Kennzeichnung.
- **Kein eigener Status „hängt“:** eine Arbeit `running` ohne frischen Heartbeat und ohne Sperre übernimmt der nächste
  Treiber; der Cursor macht das gefahrlos.
- **Schreibende Arten** (Schnittstelle `WritesRecords`): Der Runner führt den Schritt in `RecordWriter::batch()` aus
  und speichert den Cursor in derselben Transaktion der Hauptverbindung – Daten und Fortschritt sind eine Einheit
  (Restrisiko wie F1: Daten committet, Commit der Hauptverbindung scheitert → der Block läuft noch einmal).
  Lesende Arten laufen ohne Transaktion.
- **Kontext im Hintergrund:** Jeder Schritt läuft als die startende Person (`Auth`) in der App der Arbeit. Wurde
  unter „Ansicht als Rolle“ gestartet, speichert die Arbeit die simulierten Rollen und wendet sie in jedem Schritt an
  – im Hintergrund gelten dieselben Rechte wie im Browser.
- **Pilot-Art schon in F3a:** „Berechnete Felder neu berechnen“ (`computed:recompute`). Sie schreibt (prüft den
  Vertrag oben) und hat keine Oberfläche. Der Befehl startet eine Arbeit und treibt sie in der Konsole selbst voran,
  mit `--background` überlässt er sie `work:run`. Die Nachberechnung nach Formeländerungen im Table-Designer bleibt
  bis F3b synchron.
- **Plugins** steuern Arten über `SaftPlugin::workTypes()` bei (Plugin-API 1.10, noch unveröffentlicht).
- **Zugang:** „Meine Arbeiten“ im Benutzermenü für alle, „Betrieb › Arbeiten“ für `work.view_any`; dieselbe Seite.
  Rechte `work.view_any`, `work.cancel_any`, `work.details`. Eigene Arbeiten sehen und abbrechen darf jede Person.
- **Benachrichtigung** (Datenbank-Benachrichtigung des Panels), wenn eine Arbeit nicht im Browser zu Ende ging.
- **Aufräumen** als System-Aufgabe `work_prune`, ab Werk aktiv und stündlich (die 24 h für Ergebnisse sollen auch
  eingehalten werden). Der Treiber Queue folgt mit F3b.

## 7. F4 – Formelsprache: Spezifikation und Konformität

- **`docs/concept/FORMULA_SPEC.md`**: Typen (Zahl, Text, Wahrheitswert, Datum, leer), Leer-Regeln je Operator und
  Funktion, Rundung (kaufmännisch, ab 5 weg von 0, Skala des Zielfelds; Zwischenergebnisse 12 Stellen),
  Textvergleich (binär, nicht nach Kollation; `lower()` zum Vergleich), Kurzschluss bei `and`/`or`, Division durch 0,
  Reichweite von Pfaden und Aggregaten, welche Funktionen in welchem Kontext erlaubt sind (Feld, Auswertung, später
  Zeilenrecht und Regel).
- **`tests/Fixtures/formula-conformance.json`**: Liste von Fällen `{formula, fields: {…}, expected}`. Ein Test wertet
  jeden Fall mit `Evaluator` aus und, soweit die Formel in Auswertungen erlaubt ist, über eine Auswertung auf einer
  Hilfstabelle gegen PostgreSQL, MySQL und MariaDB. Abweichungen sind rot.
- Bekannte Kandidaten für Abweichungen, zuerst zu prüfen: Rundung (`bcround` gegen `ROUND()` bei `.5`), Vergleich
  gemischter Typen (`strcmp` gegen Kollation), `coalesce` mit leerem Text, Division mit Skala, `if()` mit leerer
  Bedingung.
- Neue Funktionen (Datum für B-142, `user()` für B-020) werden zuerst in Spezifikation und Tabelle eingetragen.

**Umgesetzt 23.11.2026:** `FORMULA_SPEC.md` mit Funktionsmatrix je Kontext, Syntax, Typen, Leer-Regeln, Genauigkeit,
Rundung, Vergleichen, Aggregaten, Plugin-Funktionen und den reservierten Namen für B-142, B-020, B-135;
`formula-conformance.json` mit 75 Fällen und `FormulaConformanceTest` (Feld- und Auswertungskontext, Rundung durch die
Datenbank). Ergebnis: **keine Abweichung** auf PostgreSQL und MySQL (lokal), MariaDB über die CI. Die Kandidaten oben
verhalten sich gleich, weil Auswertungen Spalten vorher auf `numeric`/`DECIMAL` wandeln und `ROUND` dort kaufmännisch
rundet; Vergleiche gibt es in Auswertungen heute nicht. Festgehalten als Entscheidung: SQL rechnet Zwischenergebnisse
genauer als die 12 Stellen von bcmath, Abweichungen jenseits davon sind hingenommen (Spezifikation Abschnitt 6).
Zusätzlich `tests/Unit/FormulaSpecTest.php`: die Matrix der Spezifikation muss mit `FormulaAnalyzer` übereinstimmen,
geplante Funktionen dürfen noch nicht existieren.

## 8. F5 – Qualitätssicherung und Aufräumen

### 8.1 Larastan und Architekturtests

- `larastan/larastan` unter `require-dev`, `phpstan.neon` mit Level 5, `phpstan-baseline.neon` für den Bestand;
  Befehl `composer analyse`; in `tests.yml` als eigener, schneller Job vor den Feature-Tests; Ziel Level 7 bis 1.0.
  Die Baseline wird nur kleiner (Test prüft die Anzahl Einträge).
- Architekturtests (eigene Testklasse, wie `PluginApiContractTest`):
  1. `DB::raw`, `selectRaw`, `whereRaw` … nur in `App\Reports\Dialects`, `App\DynamicData\Ddl`, `App\Database`
     und einer begründeten Ausnahmeliste.
  2. Keine Erkennung einer *Person* als Super-Admin über den Rollennamen (`hasRole`, `roles()->where`,
     `User::role`) ausserhalb von `User::isSuperAdmin()` und `User::superAdmins()`; begründete Ausnahme
     `RoleSimulation::canSimulate()`. Die Konstante `ROLE_SUPER_ADMIN` bleibt erlaubt, wo sie die Rolle aus
     Auswahllisten ausschliesst (Entscheid 23.11.2026, enger als zuerst formuliert).
  3. Kein `->save()`, `->update()`, `->delete()`, `->create()` auf `DynamicRecord` ausserhalb von
     `App\DynamicData\Write`.
  4. Jede Stelle, die `DynamicRecord::forTable()` lesend aufruft, steht in einer Liste mit Begründung
     (`tests/Fixtures/read-sites.json`). Das ist die Vorbereitung auf den Zeilenrechte-Scope aus `ROW_ACCESS.md` 7.
- `declare(strict_types=1);` in den Base-Models über die Pint-Regel `declare_strict_types` (`pint.json`), weil Reliese
  1.x keine eigene Vorlage erlaubt; Pint nach jedem `code:models` laufen lassen (Entscheid 23.11.2026).

### 8.2 Migrationen umbenennen

- Einmaliges Skript benennt alle Dateien in `0001_01_01_000001_…` bis `0001_01_01_000095_…` um (Reihenfolge wie
  heute). Keine Inhaltsänderung. Es sind 95, nicht 85: die zehn Settings-Migrationen unter `database/settings` liegen
  in derselben Sortierung und gehören darum in dieselbe Nummernfolge (umgesetzt 23.11.2026).
- Konvention (`CLAUDE.md`): neue Migrationen nehmen die nächste Nummer. Kein eigener Befehl: `NumberedMigrationCreator`
  ersetzt den Datumspräfix, damit vergibt `php artisan make:migration` selbst die Nummer; nach `make:settings-migration`
  die Datei von Hand umbenennen. `tests/Unit/MigrationNamingTest.php` prüft Muster, Eindeutigkeit und Lückenlosigkeit.
- Folge: Entwicklungs-, Test- und Dusk-Datenbanken werden mit `migrate:fresh` neu aufgesetzt (Testdatenbanken ohnehin);
  die Strato-Demo wird mit 0.6.0 neu installiert. In den Release-Notes 0.6.0: „Installationen vor 0.6.0 müssen neu
  aufgesetzt werden; es gibt keine produktiven.“

### 8.3 Änderungsprotokoll kürzen

Aufgabenart `activity_prune` mit Parametern je Protokollname (Vorgabe 365 Tage, Anmeldungen 90), als System-Aufgabe
im `SystemTasksSeeder`, inaktiv ab Werk mit Hinweis auf dem Dashboard, wenn das Protokoll älter als die Vorgabe ist.
Kein Löschkonzept je Tabelle in dieser Etappe (eigenes Vorhaben, Abschnitt 11).

### 8.4 ARCHITECTURE.md

Deutsch zuerst (`docs/ARCHITECTURE.de.md`), englisch danach. Inhalt: Schichten und Verzeichnisse; die zwei
Verbindungen und was wo liegt; Schreibweg und Phasen; Rechte-Modell (Tabellen, Rollen, Super-Admin, Apps,
Schnittstellen); Erweiterungspunkte (Plugins, Aufgabenarten, Arbeiten, Formelfunktionen, Dialekte); Hintergrundarbeit;
die Entscheidungen aus Abschnitt 3 als nummerierte Liste mit Datum und Begründung (ADR-Stil). `AGENTS.md` verweist
darauf statt auf Laravel-Boost-Boilerplate; `CLAUDE.md` wird auf PHP 8.4 und den Schreibweg nachgeführt.

## 9. Zusammenspiel mit den folgenden Konzepten

| Konzept | Nutzt aus Fundament 2 |
|---|---|
| Regel-Engine B-142 | Writer-Phasen `before`/`after` (RULES.md 4.2, 7.1), `afterCommit` für Benachrichtigungen (7.1), Läufe als Arbeiten (8.5), Formel-Spezifikation für `changed()`, `old()`, Datum |
| Zeilenrechte B-020 | Prüfung für *ändern* in der Writer-Transaktion (ROW_ACCESS.md 3), Lesestellen-Liste (7), Formel-Spezifikation für den Sprachteil |
| Nummernkreise B-135 | Vergabe in der Vorher-Phase innerhalb der Transaktion mit `lockForUpdate()` |
| Sammeländerung B-133 | `batch()`, Probelauf als Transaktion mit Rollback, grosse Mengen als Arbeit |
| REST-Anbindungen B-060 | Postausgang als Arbeit mit Wiederholung, Abruf als Arbeit, Senden in `afterCommit` |
| Wiederherstellen B-141 | Sicherung und Wiederherstellen je App als Arbeit mit Fortschritt |
| Web-Cron B-117 | `work:run` und `tasks:run-due` hinter einer Adresse, Zeitbudget je Aufruf ist schon das Modell |

## 10. Etappen

| Etappe | Inhalt | Umfang |
|---|---|---|
| **F0 Vorbereitung** | **Umgesetzt 23.11.2026** (Commits 2a89a7c, a1512a6, 5562d44, a8277c7, 0bfb415): Larastan Level 5 mit Baseline (336 Meldungen, von 946), Architekturtests 1 und 2, 95 Migrationen umnummeriert, `make:migration` nummeriert, Aufgabenart `activity_prune` (inaktiv, Dashboard-Hinweis), `strict_types` über Pint. Keine Verhaltensänderung für Anwender. | klein |
| **F1 Schreibweg** | **Umgesetzt 23.11.2026:** `RecordWriter`, `WriteOptions`, `WriteContext`, `WritePhases`; zwei Transaktionen je Vorgang; Umstellung von Datenansicht, Import, Kopieren, Dokumenten, Nachführung, Demo-Reset und Plugin-API; Architekturtest 3; Plugin-API 1.10; Entwickler- und Admin-Handbuch | gross |
| **F2 Nebenläufigkeit** | **Umgesetzt 23.11.2026:** `RecordVersion`, `StaleRecordException`, Prüfung mit `lockForUpdate()` im Writer, Meldung mit „Neu laden / Trotzdem speichern“ in Datenansicht und Detailtabelle, elf Edit-Seiten der Verwaltung, Plugin-API, Browser-Test EIN-18, Handbücher | mittel |
| **F3a Arbeiten** | `work_items`, `WorkType`, Treiber Browser und Scheduler, Seite „Arbeiten“, Aufräumen, Dashboard | mittel |
| **F3b Umstellung** | Export, Datenimport, Dokumente, Datenstände, Recompute, KI-App-Designer und seine Auswertungen auf Arbeiten; Treiber Queue; Grenzen anheben; Handbücher | gross |
| **F4 Formelsprache** | **Umgesetzt 23.11.2026:** `FORMULA_SPEC.md`, 75 Konformitätsfälle, `FormulaConformanceTest` auf drei Datenbanken (keine Abweichung gefunden), `FormulaSpecTest` gegen Drift | mittel |
| **F5 Architektur** | `ARCHITECTURE.de.md`/`.md`, Lesestellen-Liste (Test 4), `AGENTS.md`, `CLAUDE.md` | klein |

Reihenfolge: F0 → F1 → F2 → F4 → F3a → F3b → F5. F4 vor F3, weil B-142 und B-020 auf die Spezifikation warten, die
Arbeiten dagegen erst mit Läufen und REST dringend werden. Release 0.6.0 nach F5. Jede Etappe für sich auslieferbar;
die Browser-Suite läuft wie üblich vor dem Release auf drei Datenbanken.

## 11. Risiken und offene Fragen zur Diskussion

**Risiken**

- **Breite von F1:** Jede Schreibstelle wird angefasst; Regressionen in Summen, Dateien, Plugin-Ereignissen. Mildert:
  die 40 Feature-Testklassen unter `DynamicData` und die Browser-Tests laufen vorher und nachher; der Architekturtest
  verhindert, dass alte Wege zurückkommen.
- **`afterCommit` auf Webspace:** Wenn der Request nach dem Commit abbricht (Timeout), fehlt der Protokolleintrag.
  Hinnehmbar und dokumentiert; Alternative wäre das Protokoll in `app_data` zu führen (Abschnitt Fragen).
- **Arbeiten ohne Worker:** Der Browser-Treiber hängt an der offenen Seite, der Scheduler an einem Cron jede Minute.
  Bei groben Cron-Intervallen (Strato: 5 Minuten) läuft eine verlassene Arbeit entsprechend verzögert weiter.

**Fragen**

1. **Protokoll-Ort:** ~~`activity_log` auf der Hauptverbindung mit `afterCommit` oder eine zweite Protokolltabelle in
   `app_data`?~~ **Entschieden 23.11.2026:** `activity_log` bleibt auf der Hauptverbindung, der Writer führt dort eine
   eigene Transaktion und committet sie nach den Daten (Abschnitt 3).
2. **„Trotzdem speichern“** ~~anbieten oder nur „Neu laden“?~~ **Entschieden 23.11.2026:** beide Knöpfe; Überschreiben
   steht im Protokoll (`overwrote`).
3. **Import grösser als ein Block:** eine Transaktion über alle Blöcke (kann auf MySQL lange Sperren halten) oder
   Staging-Tabelle mit Umhängen am Ende (mehr Aufwand, saubere Transaktion)? Vorschlag: eine Transaktion bis zur
   heutigen Grenze von 10'000 Datensätzen, darüber Staging erst bei Bedarf.
4. **Plugin-API Major-Version** nutzen, um `DynamicRecord` ganz aus der API zu nehmen (nur noch `DataTable` und ein
   schlankes `Record`-Objekt)? Jetzt wäre es billig; später nie mehr. Vorschlag: nein, der Typ ist nützlich für
   Eloquent-Abfragen; nur `save()` sperren.
5. **Migrationen zusammenfassen** statt umbenennen (ein Satz `create_*`-Migrationen für 0.6.0)? Schneller bei Tests,
   aber drei Datenbanken und Daten-Migrationen (Menüs, Rechte) bergen Risiko. Vorschlag: nur umbenennen.
6. **Export-Grenze** nach F3b ganz aufheben oder bei z. B. 500'000 Zeilen belassen (Speicherplatz unter `work/`)?
7. **Löschkonzept** (Aufbewahrung je Tabelle, Papierkorb-Frist, Anonymisierung): eigenes Backlog-Thema mit Konzept,
   oder als F6 anhängen? Vorschlag: eigenes Thema, weil fachliche Entscheidungen je App nötig sind.
8. **Larastan-Level** 5 oder gleich 6 zum Start? Level 6 verlangt Array-Typen in allen PHPDocs; bei 67'000 Zeilen eine
   grosse Baseline. Vorschlag: 5, Baseline abbauen, dann 6.
