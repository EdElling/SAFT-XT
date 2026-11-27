# Konzept: Datensätze auswählen und Sammeländerung in SAFT XT

Stand: 27.11.2026 · Status: **entschieden (§11); S1 umgesetzt 27.11.2026, S2 umgesetzt 27.11.2026, S3–S4 offen** · Backlog: B-133
(ersetzt B-024)

> **Überarbeitung 27.11.2026:** Seit dem Entwurf vom 23.11.2026 gibt es Fundament 2 und die Nummernkreise. Der
> frühere Baustein „Transaktion beim Speichern“ heisst jetzt `RecordWriter` mit `WriteOptions` und `batch()`
> (ARCHITECTURE Nr. 10, 11). Lange Vorgänge laufen als Arbeiten (`App\Work\WorkType`, Nr. 19–21). Die Kennung
> zusammengehöriger Änderungen ist `WriteOptions::withRun()` mit dem Schlüssel `bulk:<uuid>`; sie steht als `run` in
> den Eigenschaften des Protokolleintrags. Das Nachnummerieren nutzt dafür `number_backfill:<uuid>`. Es ist bewusst
> keine Sammeländerung (NUMBERING.md 12.3). Die Nummernkreise (N1–N4) sind fertig. Statt der festen Sperre
> (`lockForUpdate` über den ganzen Lauf) nutzt die Sammeländerung jetzt die optimistische Sperre (Nr. 13).

## 1. Ziel

In jeder Datentabelle mehrere Datensätze auswählen und **gemeinsam ändern**, ohne jeden einzeln zu öffnen. Beispiele
aus der Musikschule:

- Nach der Semester-Fortschreibung alle Anmeldungen von *Warteliste* auf *aktiv* setzen.
- Eine Lehrperson in allen Kursen des laufenden Semesters durch eine andere ersetzen (Filter Semester + Lehrperson).
- Bei allen Kursen eines Fachs den Tarif um 5 % erhöhen (S2).

Sammeländerungen sind mächtig und deshalb gefährlich. Es gelten folgende Grundsätze:

- **Dieselben Prüfungen wie beim Einzelbearbeiten:** Pflicht, Feld- und Tabellenregeln, Plugin-Listener (z. B. die
  Konfliktprüfung der Musikschule), Nummernkreise, Änderungsprotokoll, später Regeln (B-142) und Zeilenrechte (B-020).
  Eine Sammeländerung ist kein Weg an den Regeln vorbei; sie schreibt je Datensatz über den `RecordWriter`.
- **Vorher sehen, was passiert:** Anzahl, Beispiele vorher → nachher, Prüfung über alle Datensätze vor dem ersten
  Schreiben.
- **Alles oder nichts:** Scheitert ein Datensatz, bleibt kein Datensatz geändert (§6).
- **Nachvollziehbar und rücknehmbar:** Jede Sammeländerung hat die Kennung `bulk:<uuid>` im Protokoll und lässt sich
  zurücknehmen (Etappe S3).

**Abgrenzung** (27.11.2026):

- **Nur Feldinhalte.** Die Sammeländerung setzt oder leert Werte vorhandener Datensätze einer Datentabelle bzw.
  Detailtabelle. Die Struktur (Tabellen, Felder, Typen, Regeln, Formeln, Auswahlwerte, Einstellungen der
  Nummernkreise) ändert nur der Table-Designer über `DynamicSchemaService`; gesetzt wird nur eine vorhandene Wahl.
  Verwaltungstabellen (Benutzer, Rollen, Menüs, Aufgaben) gehören nicht dazu. Innerhalb der Daten sind Felder
  ausgenommen, deren Wert SAFT bestimmt oder die strukturelle Bedeutung haben (§4). Verknüpfungen bleiben änderbar
  (z. B. Lehrperson eines Kurses); Summen im Master rechnet der Writer wie beim Einzelbearbeiten mit.
- **Keine Läufe.** Vorgänge, die Datensätze in anderen Tabellen anlegen, Nummern vergeben, Dokumente erzeugen oder
  E-Mails versenden (Rechnungslauf, Mahnlauf), sind Fachlogik: als Plugin (z. B. B-137, eigene Arbeit über
  `workTypes()`, ab S4 als Sammelaktion über `DataAction::BULK`) oder als Regel mit Auslöser „manuell“ der Regel-Engine
  (B-142). Sie nutzen dieselbe Auswahl, Kennung und Arbeit, bauen sie aber nicht neu.

## 2. Heute (27.11.2026)

| Baustein | Stand |
|---|---|
| Auswahl per Häkchen | **umgesetzt** mit B-124 D3a. Häkchen erscheinen, sobald eine Sammelaktion sichtbar ist; heute ist das nur „Dokumente erstellen“ (`DynamicRecordActions::documentBulk`). Gelöschte Datensätze sind nicht auswählbar (`checkIfRecordIsSelectableUsing`). |
| Sammelaktion Löschen | `DynamicRecordActions::deleteBulk()` ist vorhanden (je Datensatz `RecordWriter::delete()`, gesperrte werden übersprungen und gezählt), steht aber in keiner Datentabelle in der Werkzeugleiste. |
| Detailtabellen (`DynamicDetailTable`) | keine Auswahl, keine Sammelaktionen |
| Schreibweg | `RecordWriter` mit Phasen, Version (`WriteOptions::expecting`, `StaleRecordException`), `batch()` für mehrere Schreibvorgänge in einer Transaktion auf beiden Verbindungen, `afterCommit()` für E-Mails und Webhooks |
| Prüfungen ausserhalb eines Formulars | `RecordChecker::check()` (Pflicht, Länge, Min/Max, E-Mail/URL, Feldregeln aus Plugins, eindeutig, Auswahlwerte, Verknüpfungen, Tabellenregeln); genutzt von Import und Plugin-API `DataTable` |
| Arbeiten | `work_items`, `WorkType` mit `prepare()`/`step()`, `WritesRecords` (Schritt und Cursor in einer Transaktion), `CleansUp` (Rücknahme bei Fehler/Abbruch), `WorkService::startAndAdvance()` erledigt kleine Mengen im Request |
| Vorbild Lauf mit Kennung | `NumberBackfillWork`: Blöcke zu 200, Protokoll je Datensatz mit `run`, Übersprungene statt alles oder nichts |
| Vorbild Prüfphase + Rücknahme | `RecordImportWork`: Prüfphase über alles, dann Blöcke; `abort()` nimmt Angelegtes zurück (Nr. 21) |
| Filament | „Alle auswählen“ über alle Seiten (`trackDeselectedRecords`), Auswahl wird beim Ändern eines Filters aufgehoben (`deselectAllRecordsWhenFiltered`, Vorgabe an) |
| Tabellen-Stammdaten | Schalter `export_enabled`, `import_enabled` zusätzlich zum Recht der Rolle |

## 3. Entscheidungen (Vorschlag)

| Frage | Vorschlag |
|---|---|
| Wirkt auf | **Ausgewählte Datensätze, geschnitten mit dem aktuellen Filter**, nur nicht gelöschte. Die Schlüssel werden beim Start einmal festgehalten; später neu hinzukommende Datensätze gehören nicht dazu. |
| Weg des Speicherns | Je Datensatz `RecordWriter::update()` mit `WriteOptions` Herkunft **`bulk`** (neue Konstante, `WriteOptions` steht nicht im `ApiManifest`), Plugin-Ereignisse an, Protokoll an, `withRun('bulk:<uuid>')`, Version aus der Prüfphase (`expecting`). Kein `UPDATE … WHERE`. |
| Ablauf | **Als Arbeit** `bulk_records` (§6), gestartet mit `startAndAdvance()`: Kleine Mengen sind im Request fertig (wirkt wie synchron), grosse laufen mit Fortschritt weiter. |
| Alles oder nichts | Prüfphase über alle Datensätze (Probelauf je Block in `batch()`, zurückgerollt); erst wenn alle bestehen, Schreibphase in Blöcken. Scheitert danach ein Block (inzwischen geändert, Plugin), nimmt `CleansUp::abort()` die geschriebenen Blöcke aus dem Journal zurück (Muster Import, Nr. 21). |
| Obergrenze | `dynamic_data.bulk_max` (`APP_DATA_BULK_MAX`), Vorgabe **5'000**, `0` = ohne Grenze (Muster Nr. 22). |
| Recht | Recht **ändern** der Tabelle und Schalter **„Sammeländerung erlauben“** (`bulk_enabled`) in den Tabellen-Stammdaten, Vorgabe **an**. Kein eigenes Recht je Rolle. |
| Tabellen | Designer-Tabellen. Angebundene Tabellen bekommen in S1 keine Sammeländerung (kein Papierkorb, keine Zeitstempel, Trigger der fremden Anwendung); später prüfen (S4). |
| Protokoll | Je Datensatz ein Eintrag wie beim Einzelbearbeiten, mit `via: bulk` und `run: bulk:<uuid>`. Die Arbeit hält Tabelle, Felder, Art, Anzahl und Person. Eine eigene Tabelle `bulk_changes` entscheidet S3. |

## 4. Dialog „Ändern …“

Knopf **„Ändern …“** in der Werkzeugleiste, aktiv sobald Zeilen ausgewählt sind. Der Dialog zeigt oben „**N Datensätze
ausgewählt**“ und listet die änderbaren Felder. Je Feld:

- ein Häkchen **„ändern“**. Nur angehakte Felder werden angefasst.
- die **Art der Änderung**:

| Art | Für | Beispiel |
|---|---|---|
| **setzen** | alle änderbaren Typen; das Eingabefeld des Feldtyps aus dem Formular (Auswahl, Datum, Verknüpfung mit Suche, Ja/Nein) | Status ← *aktiv*; Lehrperson ← *M. Keller* |
| **leeren** | Felder ohne Pflicht | Bemerkung ← leer |
| **rechnen** (S2) | Zahl: `+ − × ÷` und „um %“, mit Runden auf Stellen; Datum: ± Tage, Monate | Tarif × 1,05, gerundet auf 0,05; Fälligkeit + 30 Tage |
| **ersetzen** (S2) | Text: Suchtext → Ersatz innerhalb des Werts, wahlweise „Gross-/Kleinschreibung beachten“ und „nur ganzes Wort“, keine regulären Ausdrücke; je Feld, nie über alle Felder; Datensätze ohne Treffer bleiben unverändert | Adresse: „Str.“ → „Strasse“ |
| **Formel** (S2) | Ganzzahl, Dezimalzahl, Ja/Nein (§12 Frage 5); Formel in der vorhandenen Sprache (FORMULA_SPEC.md), je Datensatz mit dem bisherigen Wert, berechneten Feldern und Summen über Kind-Datensätze | `round(tarif * 1.05 / 0.05, 0) * 0.05` |

**Nicht änderbar** und deshalb nicht angeboten (Vorschlag, §11 Frage 3):

| Feld | setzen | leeren | Grund |
|---|---|---|---|
| Nummernkreis | nein | nein | Eine vergebene Nummer ändert kein Schreibweg (Nr. 26). |
| berechnet | nein | nein | Wert kommt aus der Formel. |
| Datei, Bild | nein | nein | Hochladen gehört ins Formular; Leeren entfernt Dateien und gefährdet abgelegte Dokumente. |
| eindeutig | nein | ja, ohne Pflicht | Ein Wert für mehrere Datensätze verletzt die Eindeutigkeit; leere Werte nicht. |
| inaktiv, Systemspalten | nein | nein | |
| Verknüpfung zur Haupttabelle in einer Detailtabelle | nein | nein | gesperrt wie im Formular der Detailtabelle |

**Gruppen- und Datumsfelder eines Nummernkreises** bleiben änderbar wie beim Einzelbearbeiten. Eine vergebene Nummer
bleibt dabei stehen (N4). Der Dialog warnt, sobald so ein Feld angehakt ist: „Bei 12 Datensätzen steht schon eine
Nummer; sie bleibt (Kurzzeichen bzw. Periode passen danach nicht mehr zum Feld).“ Ändert die Sammeländerung ein Feld,
das in **„Vergeben, wenn“** (N3) vorkommt, vergibt der Writer Nummern je Datensatz in Schlüsselreihenfolge. Die
Vorschau nennt das: „Bei voraussichtlich 47 Datensätzen wird eine Nummer vergeben.“

„**Wert übernehmen aus**“ wirkt nur beim Anlegen. Wird eine Verknüpfung geändert, bleiben übernommene Werte wie beim
Einzelbearbeiten unverändert. Der Dialog weist darauf hin („Einzelpreis wird nicht neu übernommen“).

## 5. Vorschau

Nach „Weiter“ zeigt die zweite Seite des Dialogs (synchron, ohne zu schreiben):

1. **Wirkt auf:** „50 Datensätze ausgewählt“. Über `dynamic_data.bulk_max` gibt es eine Meldung mit dem Hinweis, den Filter
   einzugrenzen; „Ändern“ ist dann gesperrt.
2. **Beispiele:** die ersten 10 als Tabelle *Datensatz · Feld · vorher → nachher*. Bei „rechnen“ und „Formel“ (S2) ist
   das der einzige Weg, das Ergebnis vorher zu sehen.
3. **Hinweise:** Nummernkreise (§4), „Wert übernehmen aus“, bei vielen Datensätzen „läuft als Arbeit im Hintergrund
   weiter“.
4. **Knöpfe:** „**Nur prüfen**“ startet die Arbeit ohne Schreibphase und meldet das Ergebnis; „**N Datensätze
   ändern**“ startet sie mit Schreibphase. Beide prüfen vollständig. Der getrennte Probelauf mit späterer Bestätigung
   entfällt: Bei einer Arbeit läge zwischen Probelauf und Bestätigung beliebig viel Zeit, und die Schreibphase muss
   ohnehin noch einmal prüfen.

## 6. Ausführung als Arbeit `bulk_records`

Parameter: Tabelle, Vorgang (`update`, später `delete`, `restore`), Änderungen je Feld (Art, Wert), Schlüssel der
Datensätze (im Ordner der Arbeit, ab einer gewissen Menge als Datei), `dry_run`.

1. **`prepare()`:** Kennung `bulk:<uuid>`, Menge = Anzahl Schlüssel.
2. **Prüfphase** (Blöcke zu 100): je Block in `RecordWriter::batch()` jeden Datensatz mit den neuen Werten wirklich
   speichern (alle Phasen, Plugin-Listener, Nummernkreise, Tabellenregeln), dann die Transaktion zurückrollen. Das
   prüft genau das, was später geschrieben wird, auch Plugin-Regeln, die `RecordChecker` nicht kennt. E-Mails und
   Webhooks entstehen nicht (`afterCommit()` wird verworfen). Je Datensatz wird die Version festgehalten;
   unveränderte Datensätze (neuer Wert = alter Wert) werden gezählt und übersprungen. Fehler werden gesammelt
   (höchstens 20 mit Datensatz und Meldung). Hat ein Block Fehler, prüft die Arbeit trotzdem weiter, damit die Liste
   vollständig ist.
3. **Ende der Prüfphase:** mit Fehlern → Arbeit „fehlgeschlagen“ mit der Liste, nichts geändert. Mit `dry_run` →
   „fertig: alle 47 bestehen die Prüfungen, 3 sind schon so“.
4. **Schreibphase** (`WritesRecords`, Blöcke zu 100): je Datensatz `update()` mit `expecting(<Version aus der
   Prüfphase>)`; vorher die alten Werte der geänderten Felder ins **Journal** im Ordner der Arbeit. Ein inzwischen
   geänderter Datensatz (`StaleRecordException`) oder ein neuer Fehler beendet die Arbeit; `abort()` schreibt die
   alten Werte aus dem Journal zurück (gleiche Kennung, Herkunft `bulk`, Version des Laufs) und meldet den Datensatz.
   Liegen alle Datensätze in einem Block, ist das eine einzige Transaktion ohne Rücknahme.
5. **Ergebnis:** „47 Datensätze geändert, 3 unverändert“; die Auswahl wird aufgehoben, die Liste lädt neu. In S3
   kommt der Link „Rückgängig“ dazu.

**Warum keine Sperre über den ganzen Lauf:** Eine Transaktion über tausende Datensätze hielte auf MySQL lange Sperren
(Begründung Nr. 21). Die optimistische Sperre je Datensatz erkennt dasselbe ohne Sperre: Wer zwischen Prüfung und
Schreiben speichert, gewinnt, und die Sammeländerung wird vollständig zurückgenommen.

**Reihenfolge** nach Schlüssel. Summen im Master (`Recalculator::propagate`) laufen je Datensatz; eine Bündelung ist
eine spätere Optimierung. Die Konfliktprüfung der Musikschule sieht in der Schreibphase die schon geänderten
Datensätze desselben Blocks; Konflikte zwischen ausgewählten Kursen erkennt sie deshalb auch.

## 7. Protokoll und Rückgängig

**Protokoll (S1):** Jede Änderung erscheint im Verlauf des Datensatzes wie eine Einzeländerung, mit dem Zusatz
„**Sammeländerung**“ (`via: bulk`) und der Kennung `run: bulk:<uuid>`. Eine Rücknahme nach Fehler (§6 Schritt 4)
erscheint ebenfalls, mit derselben Kennung.

**Rückgängig (S3):** „Letzte Sammeländerungen“ in der Datenansicht, die eigenen für alle mit *ändern*; dazu der Link
in der Erfolgsmeldung. Die Rücknahme ist selbst eine Arbeit `bulk_records` mit eigener Kennung, liest die alten Werte
aus dem Protokoll (`properties.old`) und überspringt Datensätze, die seither wieder geändert wurden (Meldung).
Folgen über Regeln (B-142) und vergebene Nummern nimmt sie nicht zurück. Ob die Liste aus `activity_log` (gruppiert
nach `run`) oder aus einer Tabelle `bulk_changes` kommt, entscheidet S3; `work_items` werden aufgeräumt und taugen
nicht als Gedächtnis.

## 8. Weitere Sammelaktionen

| Aktion | Vorschlag |
|---|---|
| Dokumente erstellen | vorhanden (B-124 D3a) |
| **Löschen** | `deleteBulk()` in die Werkzeugleiste (S1), Recht *löschen*, Anzahl in der Bestätigung, Kennung `bulk:<uuid>`. Gesperrte Datensätze (`DeleteGuard`) werden übersprungen und gemeldet (§11 Frage 6). |
| **Wiederherstellen** | im Papierkorb-Filter für ausgewählte Datensätze (S1) |
| **Auswahl exportieren** | wahlweise „nur ausgewählte“ (S4) |
| **Kopieren** | bewusst nicht |
| **Sammelaktionen aus Plugins** | `DataAction::BULK` neben `TABLE` und `RECORD` (S4, Plugin-API minor) |
| **Regeln mit Auslöser „manuell“** | als Sammelaktion (Konzept Regel-Engine 4.1), z. B. einfache Läufe „je Anmeldung eine Rechnung anlegen“ |
| **Läufe aus Plugins** | Rechnungs- und Mahnlauf als Plugin-Arbeit (B-137), ab S4 als Sammelaktion |

**Detailtabellen** (Kurs → Anmeldungen) bekommen Auswahl und dieselben Sammelaktionen; die Verknüpfung zur
Haupttabelle ist dort nicht änderbar.

## 9. Zusammenspiel

| Bereich | Verhalten |
|---|---|
| Zeilenrechte (B-020) | Auswahl nur aus sichtbaren Datensätzen; geändert werden nur solche, die *ändern* erfüllen und danach weiter erfüllen. Die Lesestelle der Arbeit kommt mit `scope: person` in `read-sites.json`. |
| Regeln (B-142) | laufen je Datensatz in der Transaktion des Blocks; in der Prüfphase zurückgerollt |
| Nummernkreise (B-135) | Nummernkreis-Felder nicht änderbar; Gruppe und Bezugsdatum änderbar mit Warnung; „Vergeben, wenn“ vergibt Nummern in Schlüsselreihenfolge (§4). Eine Rücknahme gibt vergebene Nummern nicht zurück (Lücke wie bei einem Löschen). |
| Plugin-API | Plugins sehen `RecordSaving`/`RecordSaved` wie beim Bearbeiten, auch in der Prüfphase (dort zurückgerollt; Wirkungen nach aussen über `afterCommit()`). `DataTable::updateMany()` und `DataAction::BULK` erst in S4. S1 ändert keine Klasse im `ApiManifest`. |
| Arbeiten | Seite „Meine Arbeiten“ zeigt die Sammeländerung mit Fortschritt; Abbruch → Rücknahme |
| KI (B-051 Stufe 3) | später: Filter und Dialog vorausgefüllt aus einem Satz; die Person bestätigt |
| App-Definition (`saft-app/1`) | neuer optionaler Schlüssel `bulk_enabled` an der Tabelle |
| Handbücher | Anwender, FAQ, Admin in vier Sprachen; Entwickler (Herkunft `bulk`, Arbeit) und Sysadmin (`APP_DATA_BULK_MAX`) in zwei |

## 10. Etappen

| Etappe | Inhalt | Umfang |
|---|---|---|
| **S1 Sammeländerung** | Arbeit `bulk_records` (Prüf- und Schreibphase, Journal, Rücknahme), Dialog „Ändern …“ mit *setzen* und *leeren*, Vorschau, Schalter `bulk_enabled`, Obergrenze, Kennung `bulk:<uuid>`; Löschen und Wiederherstellen in der Werkzeugleiste; Auswahl in Detailtabellen | mittel |
| **S2 Rechnen, Ersetzen und Formel** | Arten *rechnen* (Zahl, Datum), *ersetzen* (Text, in PHP, kein SQL) und *Formel* mit Vorschau; eine Formelfunktion `replace()` erst bei Bedarf (FORMULA_SPEC.md, Konformität auf drei Datenbanken) | klein |
| **S3 Rückgängig** | Liste „Letzte Sammeländerungen“, Rücknahme als Arbeit, später Geändertes überspringen | mittel |
| **S4 Erweiterungen** | Auswahl exportieren, `DataAction::BULK`, `DataTable::updateMany()`, angebundene Tabellen | klein |

**Stand S1 (27.11.2026): umgesetzt.** S1a Backend: `RecordWriter::trial()`/`savepoint()`, Herkunft
`WriteOptions::BULK`, `App\DynamicData\Bulk\BulkChange` (änderbare Felder, Prüfung), `BulkPreview` (Beispiele, Hinweise
zu Nummernkreisen), Arbeit `bulk_records` (`BulkRecordsWork`: Prüfphase im Probelauf, Schreibphase mit Version und
Journal, Rücknahme), Migration 101 `custom_tables.bulk_enabled` (auch in der App-Definition), Grenze
`APP_DATA_BULK_MAX`, Entscheidung Nr. 27 in ARCHITECTURE. S1b Oberfläche: `App\DynamicData\Ui\BulkRecordActions`
(„Ändern …“, „Ausgewählte löschen“ mit Kennung und Grenze, „Ausgewählte wiederherstellen“) in Datenansicht und
Detailtabellen, Schalter im Table-Designer, Vermerk „(Sammeländerung)“ im Verlauf. Abweichungen vom Plan: Der Dialog ist
**eine Seite** mit Live-Vorschau statt zwei Schritte (ein Filament-Wizard kann keinen zweiten Knopf „Nur prüfen“ tragen);
gelöschte Datensätze sind mit dem Papierkorb-Filter wieder auswählbar (sonst ginge Wiederherstellen nicht), jede Aktion
nimmt nur die passenden; „Ändern …“ fehlt im Filter „nur gelöschte“. Bei der Prüfung in der Musikschule-Demo gefunden und
behoben: Verschachtelte Schreibvorgänge (Summen im Master) erbten die erwartete Version des Kind-Datensatzes und meldeten
„inzwischen geändert“ – betraf seit 0.6.0 auch das Einzelbearbeiten (`WriteOptions::nested()`). S1c: Browser-Test
`BulkEditTest` (SAE-01 bis SAE-04), manuelle Testfälle SAE-01 bis SAE-07, Handbücher.

**Stand S2 (27.11.2026): umgesetzt** nach den Entscheidungen in §12. S2a Backend: `BulkChange` mit den Arten `calc`,
`replace`, `formula` und `resolve()` je Datensatz (alle Arten sehen die Werte vor der Änderung), `BulkCalc`, `BulkReplace`,
`BulkFormula`, `Evaluator::evaluateStrict()` (FORMULA_SPEC Kontext „Sammeländerung“, Konformitätsfälle `bulk`),
`RecordChecker` mit Prüfung der Stellen (auch Import), Zählung „ohne Wert bzw. ohne Treffer“ in Arbeit und Vorschau,
Doppelte eindeutiger Felder über alle Blöcke (`unique.json` im Ordner der Arbeit). S2b Oberfläche: Eingaben je Art im
Dialog, Formel mit Prüfung beim Verlassen, Vorschau mit Beispielen, die sich ändern (aus den ersten 500), Zählungen,
roten Rechenfehlern und der Meldung bei unvollständiger Eingabe. Bei der Prüfung in der Musikschule-Demo ergänzt: Häkchen
„Schreibweise übernehmen“ beim Ersetzen (§12 Frage 10). Die Demo hat dafür Adressen „Bahnhofstr.“ und „Hauptstr.“.
S2c: Browser-Test SAE-08 bis SAE-11, manuelle Testfälle SAE-08 bis SAE-12, Handbücher. Zurückgestellt: Felder
verknüpfter Datensätze in Formeln (B-166), Text- und Datumsergebnisse (B-167), Auf- und Abrunden (B-168). Keine
Migration, keine neuen Rechte, Plugin-API unverändert.

## 11. Entscheidungen zu den offenen Fragen (27.11.2026: alle Empfehlungen angenommen)

1. **Obergrenze 500 synchron oder Arbeit?** *Empfehlung:* Arbeit von Anfang an, gestartet mit `startAndAdvance()`,
   Grenze `APP_DATA_BULK_MAX` mit Vorgabe 5'000 (`0` = ohne). *Warum:* CLAUDE.md und Nr. 19 verlangen für alles, was
   länger als ein Request dauern kann, eine Arbeit statt eines eigenen Mechanismus. Auf Strato kostet ein Speichern
   mit Phasen grob 10 Abfragen à 2,2 ms; 500 Datensätze mit Prüfphase lägen bei 20–30 s und damit an der Grenze der
   PHP-Laufzeit. Für die Person ändert sich bei kleinen Mengen nichts, weil `startAndAdvance()` sie im Request
   erledigt.
2. **Eigenes Recht je Rolle?** *Empfehlung:* nein; Recht *ändern* und Schalter `bulk_enabled` (Vorgabe an) genügen.
3. **Alles oder nichts, oder Übersprungene zulassen?** *Empfehlung:* alles oder nichts für *Ändern*. Die Prüfphase
   listet die Fehler; man wählt die Datensätze ab oder korrigiert sie. *Warum:* Wer „Lehrperson ersetzen“ sagt, will
   keinen halb ersetzten Stundenplan, und die Rücknahme in S3 bleibt einfach. Anders als beim Nachnummerieren wählt
   die Person hier den Wert. Ein Knopf „ohne die fehlerhaften ändern“ kann später kommen (S4), wenn es sich als
   lästig erweist.
4. **Nicht änderbare Felder:** *Empfehlung:* wie in §4: Nummernkreis, berechnet, Datei/Bild nicht; eindeutig nur
   *leeren* (ohne Pflicht); Gruppen- und Datumsfelder eines Nummernkreises änderbar mit Warnung, weil das
   Einzelbearbeiten es auch erlaubt (Grundsatz „dieselben Regeln“). Soll die Gruppe nach der Vergabe fest sein,
   gehört das als Einstellung an den Nummernkreis, für beide Wege (Backlog-Vorschlag).
5. **„Wert übernehmen aus“ neu übernehmen?** *Empfehlung:* nicht in S1, nur Hinweis; gälte auch fürs Einzelbearbeiten
   und wird ein eigener Backlog-Eintrag.
6. **Löschen in einer Sammelaktion: alles oder nichts?** *Empfehlung:* nein, gesperrte überspringen und melden wie
   heute. *Warum:* Löschen landet im Papierkorb und ist rücknehmbar; die Sperre ist ein fachlicher Grund, kein Fehler
   der Eingabe. Synchron bis zur Obergrenze, mit Kennung.
7. **Rückgängig für alle oder nur die eigenen?** *Empfehlung:* eigene für alle mit *ändern*, fremde nur für
   Administratoren der App; Entscheidung in S3.
8. **Angebundene Tabellen?** *Empfehlung:* nicht in S1 (siehe §3), Prüfung in S4.

## 12. Entscheidungen zu S2 „Rechnen, Ersetzen und Formel“ (27.11.2026: alle Empfehlungen angenommen)

**Befund im Code (27.11.2026).** `BulkChange::apply()` arbeitet schon je Datensatz, aber mit einem Wert für alle
(`values()`); die Prüfphase gibt diese Werte an `RecordChecker::check()`. Für S2 rechnet `BulkChange` den neuen Wert je
Datensatz aus dem bisherigen (`valuesFor($record)`), Prüfung, Vorschau und Schreibphase nutzen dasselbe. Die
Formelsprache liefert heute **nur Zahlen** (Text nur in Vergleichen, keine Datumsrechnung, `add_days` reserviert),
liest **keine Felder verknüpfter Haupt-Datensätze** (nur Aggregate über Kind-Tabellen), verbietet das **eigene Feld**
(`tables.formula.errors.self`) und ergibt bei `/ 0` **leer**. `RecordChecker` prüft die Länge von Text, nicht aber
precision/scale von Dezimalzahlen; ein Überlauf fiele heute erst als Datenbankfehler auf. In der Musikschule-Demo gibt es
keine Adresse mit „Str.“.

1. **Rechnen mit leeren Werten: überspringen, als 0 oder Fehler?** *Empfehlung:* **überspringen**. Der Datensatz
   bleibt unverändert und wird gesondert gezählt („3 ohne Wert, unverändert“), in Vorschau, „Nur prüfen“ und Ergebnis.
   *Warum:* Das entspricht der Leer-Regel der Formelsprache (leer breitet sich aus, FORMULA_SPEC §5). „Als 0“ setzte
   bei „Tarif × 1,05“ Kurse ohne Tarif auf 0,00. Ein Fehler machte jede Sammeländerung über einen Filter mit einzelnen
   leeren Werten unmöglich (alles oder nichts). Wer leere Werte füllen will, filtert danach und *setzt*.
2. **Runden.** *Empfehlung:* Gerundet wird **immer kaufmännisch** (ab 5 weg von 0, wie FORMULA_SPEC §7). Das Ziel ist
   wählbar: **Nachkommastellen** (0 bis zur Skala des Felds, Vorgabe = Skala des Felds) oder **Vielfaches** (z. B.
   0,05; 0,5; 10). Das Vielfache wird als `round(x / v) × v` gerechnet und muss > 0 sein und in die Skala des Felds
   passen (0,005 bei 2 Stellen wird abgelehnt). Gerechnet wird **exakt mit bcmath** über `Evaluator::decimal()`/
   `Evaluator::round()`, mit 12 Zwischenstellen wie in der Formelsprache. Ganzzahlfelder runden auf 0 Stellen.
   Auf- und Abrunden kommen nicht in S2 (Backlog, falls gewünscht). „um %“ heisst `× (1 + p/100)`, auch negativ
   (−10 %). „÷ 0“ lehnt schon der Dialog ab.
3. **Überlauf von precision/scale.** *Empfehlung:* **Prüffehler je Datensatz**, keine Kappung. Dazu prüft
   `RecordChecker` künftig die Zahl der Vorkommastellen eines Dezimalfelds (`precision − scale`) und den Bereich von
   Ganzzahlen (bigint). Meldung: „Tarif: 123'456'789.00 passt nicht ins Feld (höchstens 8 Stellen vor dem Komma)“.
   *Warum:* Das kommt auch Import und Plugin-API zugute (heute ein Datenbankfehler) und bleibt auf drei Datenbanken
   gleich. Bestehende Min-/Max-Regeln greifen wie bisher.
4. **Datum ± Monate am Monatsende, Datum/Zeit.** *Empfehlung:* **ohne Überlauf**: 31.01. + 1 Monat = 28.02. (bzw.
   29.02.), 31.03. − 1 Monat = 28./29.02. (Carbon `addMonthsNoOverflow`), so wie es Tabellenkalkulationen tun (EDATE).
   Tage zählen kalendarisch. Felder **Datum und Uhrzeit** bekommen dieselben Arten (± Tage, ± Monate); die Uhrzeit
   bleibt stehen. Stunden und Minuten kommen nicht in S2. Werktage gibt es nicht (kein Kalender in SAFT).
5. **Formel: Ergebnistyp und Feldtyp.** *Empfehlung:* Weil die Sprache nur Zahlen liefert, gibt es *Formel* in S2 für
   **Ganzzahl, Dezimalzahl und Ja/Nein**. Zahlen werden auf die Skala des Felds gerundet (wie bei berechneten Feldern),
   Ja/Nein ist ungleich 0 → ja, 0 → nein. Für Text, Auswahl, Datum und Verknüpfung gibt es keine Formel, bis die
   Sprache Text- bzw. Datumsergebnisse kann (`add_days`, `replace()`, Verkettung; FORMULA_SPEC zuerst, Backlog). Das
   eigene Feld ist erlaubt und bedeutet den **bisherigen Wert**. Das Beispiel aus §4 wird damit
   `round(tarif * 1.05 / 0.05, 0) * 0.05`.
6. **Formel: leeres Ergebnis und Division durch 0.** *Empfehlung:* Ein **leeres Ergebnis** (z. B. leerer Operand)
   lässt den Datensatz **unverändert** wie bei Frage 1 und wird gezählt; leeren geht über die Art *leeren*. Eine
   **Division durch 0** ist dagegen ein **Prüffehler je Datensatz** („#12 Gitarre A: Division durch 0“). Dazu bekommt
   `Evaluator` eine strenge Auswertung (`evaluateStrict()` o. ä.), die bei `/ 0` eine `FormulaException` wirft; Feld
   und Auswertung bleiben bei „leer“. Die Abweichung kommt als neuer Kontext **„Sammeländerung“** in FORMULA_SPEC §1,
   mit Fällen in der Konformitätstabelle (nur `field`). *Warum:* Eine still übersprungene Division durch 0 fiele erst
   viel später auf, ein leerer Operand ist dagegen ein bekannter, gewollter Fall.
7. **Formel: berechnete Felder, Summen, verknüpfte Felder.** *Empfehlung:* **Berechnete Felder und Aggregate über
   Kind-Tabellen erlaubt**. Sie lesen den gespeicherten Stand vor der Sammeländerung (Aggregat über
   `Recalculator`-Weg je Datensatz; bei 5'000 Datensätzen vertretbar, weil die Arbeit in Blöcken läuft). **Felder
   verknüpfter Haupt-Datensätze** (z. B. Tarif der Kursart im Kurs) kann die Sprache nicht. §4 („mit verknüpften
   Feldern“) wird auf „Aggregate über Kind-Datensätze“ berichtigt; ein Pfad `kursart.tarif_semester` wäre neue Syntax
   und kommt mit einem eigenen Backlog-Eintrag. Alle Formeln einer Sammeländerung sehen **die Werte vor der Änderung**,
   auch wenn ein anderes Feld derselben Sammeländerung geändert wird (wie ein `UPDATE … SET a = b, b = a`).
8. **Formel prüfen und speichern.** *Empfehlung:* Die Formel wird im Dialog beim Verlassen des Felds geprüft
   (`FormulaAnalyzer::compile()` mit Feldern der Tabelle, Fehler mit Stelle unter dem Feld). In den Parametern der
   Arbeit steht der **Text**. `BulkChange::validate()` übersetzt ihn in jedem Schritt neu, damit eine inzwischen
   geänderte Struktur (Feld gelöscht) als Fehler auffällt. Das Vorbild ist `NumberCondition::compile()`, aber ohne
   gespeicherten Baum.
9. **Ersetzen: welche Feldtypen?** *Empfehlung:* nur **Text und mehrzeiliger Text**. **Auswahl** nicht, weil sie den
   gespeicherten Wert hat, nicht die Bezeichnung; dafür gibt es *setzen*. **Verknüpfung** nicht, weil sie einen
   Schlüssel hat (*setzen* mit Suche). Nummernkreise sind ohnehin ausgeschlossen. *Leeres Suchwort* lehnt der Dialog
   ab, ein *leerer Ersatz* ist erlaubt (Text entfernen). Ein Ergebnis aus nur Leerzeichen oder leerem Text gilt als
   leer, dann greift die Pflicht.
10. **Ersetzen: „Gross-/Kleinschreibung“ und „nur ganzes Wort“.** *Empfehlung:* Ohne Häkchen wird unabhängig von
    Gross-/Kleinschreibung gesucht (Unicode, `mb_`), der Ersatz wird eingefügt wie eingegeben. „Nur ganzes Wort“ heisst:
    Vor und nach dem Treffer steht kein Buchstabe, keine Ziffer, kein `_`. So passt „Str.“ in „Str. des 1. Mai“, aber
    nicht in „Hauptstr. 5“; ein einfaches `\b` versagte am Punkt. Für „Hauptstr. 5“ → „Hauptstrasse 5“ sucht man
    ohne „ganzes Wort“ mit Gross-/Kleinschreibung „str.“ und ersetzt durch „strasse“. Intern darf das ein Muster mit `preg_quote()` sein.
    Die Person gibt nie einen regulären Ausdruck ein. Ersetzt werden **alle** Treffer im Wert. *Ergänzt nach der Prüfung
    in der Demo (27.11.2026):* drittes Häkchen **„Schreibweise übernehmen“**. Ist der Treffer ganz gross (ab zwei
    Buchstaben), wird auch der Ersatz ganz gross geschrieben; beginnt er gross, beginnt auch der Ersatz gross, sonst
    klein. So erledigt „str.“ → „strasse“ „Bahnhofstr.“ und „Str. des 1. Mai“ in einem Lauf statt in zwei. Mehrere
    Paare Suchen → Ersetzen je Feld gibt es bewusst nicht (Dialog und Vorschau würden schwer); je Feld und Lauf bleibt
    es ein Suchtext.
11. **Ersetzen bzw. Rechnen bei eindeutigen Feldern.** *Empfehlung:* *ersetzen*, *rechnen* und *Formel* sind auf
    eindeutigen Feldern **erlaubt** (heute nur *leeren*), weil die Werte je Datensatz verschieden bleiben können (z. B.
    Vertragsnummer „MV-“ → „MV-2026-“). *setzen* bleibt verboten. Doppelte erkennt die Prüfphase. Dazu führt die
    Arbeit die neuen Werte eindeutiger Felder über alle Blöcke in einer Datei im Ordner mit; `RecordChecker` prüft
    gegen den Bestand. Ein Wert, den ein anderer ausgewählter Datensatz erst frei macht (Tausch), gilt als doppelt. Das
    ist streng, aber einfach.
12. **Ergebnis länger als die Feldlänge.** *Empfehlung:* **Prüffehler je Datensatz** (vorhandene Längenprüfung des
    `RecordChecker`), nie abschneiden.
13. **Vorschau bei Werten je Datensatz.** *Empfehlung:* Die Beispiele zeigen bis zu **10 Datensätze, die sich
    ändern**, gesucht in Schlüsselreihenfolge unter den ersten **500** ausgewählten. So erscheinen beim Ersetzen mit
    wenigen Treffern trotzdem Beispiele. Darüber stehen Zählungen: „ändern sich · unverändert · ohne Wert“. Bis 500
    ausgewählte Datensätze sind sie exakt, darüber heisst es „unter den ersten 500: …; genaue Zahl mit ‚Nur prüfen‘“.
    Ein Fehler, den die Rechnung selbst erkennt (Division durch 0, passt nicht ins Feld, zu lang), steht rot in der
    Zelle des Beispiels. Pflicht-, Plugin- und Eindeutigkeitsfehler zeigt wie bisher erst die Prüfphase. *Warum:* Die
    Vorschau bleibt synchron und schnell (500 Datensätze in PHP rechnen ist billig, auch mit Aggregaten noch unter
    einer Sekunde), und die Arbeit liefert ohnehin die genauen Zahlen.
14. **Ergebnis und Protokoll.** *Empfehlung:* Die Meldung der Arbeit nennt zusätzlich „ohne Wert“ bzw. „ohne Treffer“
    getrennt von „schon so“. Das Protokoll ist unverändert (alter und neuer Wert je Feld). In den Parametern der
    Arbeit stehen Art und Angaben (`op: calc`, `operator`, `operand`, `round`; `op: replace`, `search`, `replace`,
    `case`, `word`, `keep`; `op: formula`, `formula`), damit S3 sie anzeigen kann.
15. **Rechte, Plugin-API, Migrationen.** *Empfehlung:* keine neuen Rechte, keine Migration. Die Plugin-API ist nicht
    betroffen (`BulkChange`, `Evaluator`, `FormulaAnalyzer` und `RecordChecker` stehen nicht im `ApiManifest`, geprüft 27.11.2026).
