# Konzept: Verlauf lesbar und einzelne Änderung rückgängig machen in SAFT XT

Stand: 28.11.2026 · Status: **entschieden (§4, alle Empfehlungen angenommen 27.11.2026); H1 und H2 umgesetzt 28.11.2026** · Backlog: B-174 (Verlauf lesbar), B-173
(Rückgängig je Verlaufseintrag) · Bezug: B-133 S3 (BULK_EDIT.md §13), ARCHITECTURE Nr. 12, 24, 28

## 1. Ziel

- **B-174:** Der Verlauf eines Datensatzes zeigt heute technische Werte (`fach: 5; wochentag: do; freie_plaetze: 1`).
  Künftig steht dort „Fach: Schlagzeug · Wochentag: Donnerstag · Freie Plätze: 1“, mit Zahlen, Datum und Ja/Nein im
  Format der Anwendung. Das Protokoll selbst bleibt technisch (Rohwerte, Nr. 28).
- **B-173:** Am Ende jedes Eintrags „Geändert“ im Verlauf ein Symbol **„Rückgängig“**. Es öffnet eine Bestätigung mit
  einer Zeile je Feld: Häkchen · Feld · *jetzt → zurück auf*. Gespeichert wird über den `RecordWriter` mit allen
  Prüfungen.

Schon entschieden (Nutzer, 27.11.2026, BACKLOG B-173):
- Felder, die seither einen anderen Wert haben, erscheinen mit dem Vermerk „seither geändert“, ihr Häkchen ist
  vorgabemässig aus. Wer es setzt, überschreibt bewusst.
- „Seither geändert“ sperrt nichts. Die Prüfungen laufen immer, die Häkchen gelten je Feld.
- Felder, die schon den alten Wert haben, sind grau.
- Berechnete Felder, Summen, Nummernkreise und Dateien sind nicht wählbar, sie werden nur genannt.
- Ein heute ungültiger alter Wert verhindert das Speichern und nennt den Grund.
- Der Protokolleintrag bekommt den Vermerk „(Rückgängig)“ und einen Verweis auf den zurückgenommenen Eintrag.
- Recht *Bearbeiten*, keine Migration.

## 2. Befund im Code (27.11.2026)

- **Anzeige:** `App\Filament\Support\ActivityChanges` (`rows()`, `summary()`) formatiert Werte nur grob (∅,
  Ja/Nein, Dateinamen). Genutzt wird es von:
  - dem Verlauf eines Datensatzes (`DynamicRecordActions::history()`/`historyRows()`, letzte 25, Zeilen- und
    Detailaktion),
  - dem Verlauf von Systemobjekten (`modelHistoryRows()`, z. B. Felder im Table-Designer),
  - den Widgets „Letzte Änderungen“ (`RecentChangesWidget`), „Meine Änderungen“ (`MyChangesWidget`, nur Objekt) und
    „Änderungen am Konto“ (`AccountChangesWidget`),
  - dem Änderungsprotokoll der Administration (`ActivitiesTable`, `ActivityInfolist`).
- **Lesbare Werte** gibt es für die Detailansicht: `DynamicValueFormatter::display(Feld, Datensatz)`. Er schlägt
  Verknüpfungen je Wert einzeln nach (eine Abfrage je Feld) und braucht einen Datensatz, keinen Rohwert.
- **Protokolleintrag eines Datensatzes:** `subject_type` = `dynamic:<tabelle>`, `attribute_changes.old` und
  `.attributes` als Rohwerte (seit 0.9.0; ältere Datumswerte in UTC rechnet `LoggedValue` um). In `properties` stehen
  `via`, `run`, `overwrote`, `key`. Protokolliert sind alle Spalten ausser den Systemspalten (`logAll`), also auch
  berechnete Felder und Summen.
- **Verschachtelte Schreibvorgänge** (Summen im Master) stehen als eigener Eintrag „Geändert“ im Verlauf des Masters,
  nur mit Summenfeldern.
- **Schreibweg:** `RecordWriter::update()` mit `WriteOptions` (Herkunft, `expecting()`, `withRun()`); `WriteOptions`
  steht nicht im `ApiManifest` und lässt sich frei erweitern. `DynamicRecord::beforeActivityLogged()` schreibt die
  Eigenschaften.

## 3. Lösung (Vorschlag)

**Lesbare Änderungen (`LoggedChanges`):** Ein neuer Dienst bereitet die Einträge **einer Anzeige gemeinsam** auf:
- Tabelle aus `subject_type`, Feld über den Namen.
- Bezeichnung des Felds statt Spaltenname.
- Wert über eine neue Methode `DynamicValueFormatter::raw(Feld, Rohwert)`.
- Verknüpfte Datensätze werden gesammelt nachgeschlagen: alle Schlüssel aller Einträge, **eine Abfrage je verknüpfter
  Tabelle**, mit Papierkorb.

Sonderfälle:
| Fall | Anzeige |
|---|---|
| Gelöschter verknüpfter Datensatz | „#4 (gelöscht)“ |
| Verknüpfter Datensatz nicht mehr vorhanden | „#4“ |
| Wahl inzwischen entfernt | der gespeicherte Wert |
| Feld inzwischen entfernt | der technische Name |
| Kein Datensatz-Eintrag (Benutzer, Rollen …) | bleibt wie heute |

**Rückgängig je Eintrag (`HistoryUndo`):**
- **Einordnung je Feld:** wählbar oder nicht, schon zurück, seither geändert.
- **Bestätigung:** als verschachtelte Aktion des Verlaufsdialogs (`registerModalActions()`).
- **Speichern:** `RecordWriter::update()` mit Herkunft `ui`, der Version beim Öffnen der Bestätigung und neu
  `WriteOptions::reverting(<Eintrag>)`. Daraus wird `properties.reverts` im Protokoll, und der Verlauf zeigt
  „(Rückgängig)“.

## 4. Entscheidungen (27.11.2026: alle Empfehlungen angenommen)

1. **Wo wird lesbar?** *Empfehlung:*
   - Im Verlauf eines Datensatzes und in den Widgets „Letzte Änderungen“ bzw. „Meine Änderungen“.
   - Im Änderungsprotokoll der Administration zuerst lesbar, darunter die technischen Werte (eingeklappt, für die
     Fehlersuche).
   - Der Verlauf von Systemobjekten und „Änderungen am Konto“ bleiben wie heute: Dort sind die Spaltennamen
     sprechend, und es gibt keine Feldbeschreibungen.
2. **Verknüpfte Datensätze: heutiger oder damaliger Name?** *Empfehlung:* der **heutige** Name, wie im Backlog. Das
   Protokoll hält nur den Schlüssel. Den damaligen Namen festzuhalten hiesse, jedes Ändern um Abfragen zu
   verteuern.
3. **Lesestelle und Zeilenrechte.** *Empfehlung:*
   - Das Nachschlagen der verknüpften Datensätze ist eine neue Lesestelle mit `scope: person` in `read-sites.json`.
   - Mit Zeilenrechten (B-020) sieht eine Person bei einem Datensatz, den sie nicht sehen darf, nur „#4“, nie den
     Namen.
4. **Welche Einträge lassen sich zurücknehmen?** *Empfehlung:*
   - Nur Einträge **„Geändert“** von Datensätzen, unabhängig von der Herkunft (Oberfläche, Import, Plugin,
     Sammeländerung, Rücknahme).
   - **Nicht** „Angelegt“ (das wäre Löschen) und nicht „Gelöscht“ bzw. „Wiederhergestellt“, dafür gibt es eigene
     Knöpfe.
   - Hat ein Eintrag kein wählbares Feld (z. B. nur Summen im Master), erscheint kein Symbol.
   - Ein Eintrag einer **Sammeländerung** lässt sich einzeln zurücknehmen. Die Sammeländerung als Ganzes bleibt
     davon unberührt: Ihre Rücknahme zählt den Datensatz danach als „schon zurück“ oder „seither geändert“.
5. **Wählbare Felder.** *Empfehlung:* Felder des Eintrags, die heute aktiv sind und im Formular geändert werden
   könnten. Nicht wählbar sind:
   - berechnete Felder, Summen, Nummernkreise, Dateien,
   - nur lesbare Felder,
   - inzwischen entfernte Felder.

   Eindeutige Felder sind wählbar (ein einzelner Datensatz); ist der alte Wert inzwischen vergeben, meldet die
   Prüfung das. Die Verknüpfung zur Haupttabelle ist wählbar, weil der Verlauf am Datensatz selbst hängt, nicht an
   der Detailtabelle.
6. **„Seither geändert“ und „schon zurück“ – woran gemessen?** *Empfehlung:* wie bei der Rücknahme der
   Sammeländerung (§13 Frage 2), aber je Feld:
   - Aktueller Rohwert ≠ neuer Wert des Eintrags: „seither geändert“, Häkchen aus.
   - Aktueller Rohwert = alter Wert: „schon zurück“, grau.
   - Sonst: Häkchen an.
   - Verglichen wird mit `LoggedValue::same()`, damit Datums- und Dezimalwerte nicht scheinbar abweichen.
7. **Wer darf?** *Empfehlung:* jede Person mit *Bearbeiten* der Tabelle, auch bei Einträgen anderer Personen. Die
   Rücknahme ist ein gewöhnliches Bearbeiten mit allen Prüfungen. Wer bearbeiten darf, könnte denselben Wert auch von
   Hand eintragen. Die Rücknahme ganzer Sammeländerungen bleibt bei ihrer eigenen Regel (Nr. 28).
8. **Angebundene Tabellen?** *Empfehlung:* **ja**, wenn die Tabelle Bearbeiten erlaubt.
   - Es ist ein einzelner Schreibvorgang mit Version aus den Originalwerten (Nr. 13), ohne Probelauf.
   - Die Gründe gegen Sammeländerungen (Nr. 29) gelten hier nicht.
   - Den Verlauf zeigt SAFT dort ohnehin nur, wenn die Tabelle beschreibbar ist.
9. **Gleichzeitiges Bearbeiten.** *Empfehlung:*
   - Die Bestätigung merkt sich die Version beim Öffnen.
   - Hat jemand inzwischen gespeichert, kommt die Meldung „inzwischen geändert, bitte den Verlauf neu öffnen“, ohne
     „Trotzdem speichern“. Die Bestätigung zeigt Werte, die nicht mehr stimmen.
10. **Rücknahme einer Rücknahme; Kennung.** *Empfehlung:*
    - Der neue Eintrag ist ein gewöhnliches „Geändert“ mit `properties.reverts` und lässt sich selbst wieder
      zurücknehmen.
    - Keine Kennung `run` und kein Kopf in `bulk_changes`: Es ist ein einzelner Schreibvorgang.
    - Der Verlauf zeigt „(Rückgängig)“ und in der Bestätigung „nimmt den Eintrag vom … zurück“.
11. **Anzahl im Verlauf.** *Empfehlung:* weiter die letzten 25 Einträge. Ältere Einträge lassen sich über das
    Änderungsprotokoll der Administration finden, dort aber nicht zurücknehmen.
12. **Ort in der Oberfläche.** *Empfehlung:*
    - Im Dialog „Verlauf“ (Zeilenaktion und Detailansicht) bekommt jede Zeile „Geändert“ rechts ein Symbol „Rückgängig“
      (Pfeil zurück) mit Tooltip.
    - Der Verlauf zeigt die Änderungen künftig **je Feld untereinander** statt als eine lange Zeile mit Semikolons:
      *Feld: vorher → nachher*.
    - Das macht ihn lesbarer und passt zur Bestätigung.
13. **Plugin-API, Migration, Handbücher.** *Empfehlung:*
    - Plugin-API nicht betroffen (`WriteOptions`, `ActivityChanges` und der Verlauf stehen nicht im Manifest).
    - Keine Migration, keine neuen Rechte, keine `.env`-Variable.
    - Handbücher: Anwender (Verlauf, Rückgängig), FAQ, Admin (Änderungsprotokoll lesbar) in vier Sprachen.
    - Entwickler: `WriteOptions::reverting()`, `properties.reverts` in zwei Sprachen.
    - Architektur: neue Entscheidung Nr. 30 (lesbare Anzeige, technisches Protokoll; Rücknahme je Feld).
14. **Reihenfolge.** *Empfehlung:*
    - **H1 B-174** zuerst (lesbare Anzeige, auch in Widgets und Administration), mit eigenem Commit.
    - Danach **H2 B-173** (Rückgängig), aufbauend auf der lesbaren Form.
    - Oberfläche je Etappe vor den Browser-Tests zeigen.

## 5. Stand

**H1 B-174 (28.11.2026): umgesetzt.**
- `App\DynamicData\History\LoggedChanges` (scoped): lesbare Zeilen, `prime()` für alle Einträge einer Anzeige
  (eine Abfrage je verknüpfter Tabelle), neue Lesestelle `scope: person`.
- `DynamicValueFormatter::raw()` für Rohwerte ohne Datensatz.
- `ActivityChanges::rows()` lesbar bzw. technisch, `technicalRows()`, `lines()`, `eventText()` mit Herkunft.
- Verlauf je Feld eine Zeile. Widgets „Letzte/Meine Änderungen“ werden vorab vorbereitet (`PrimesLoggedChanges`).
- Änderungsprotokoll mit eingeklappten „Technischen Werten“.

Bei der Prüfung in der Demo ergänzt:
- Vermerk „(Sammeländerung)“ auch in Widgets und Änderungsprotokoll (vorher nur im Verlauf).
- Verlaufsdialog 7xl statt 4xl, Zellen oben ausgerichtet (`.saft-history`), ohne Autofokus. Vorher scrollte ein langer
  Verlauf zum Knopf „Schliessen“, und die neuesten Einträge waren nicht mehr zu sehen.

Tests: `HistoryReadableTest` (drei Datenbanken), Browser EIN-22, manueller Testfall EIN-22.

**H2 B-173 (28.11.2026): umgesetzt.**
- `App\DynamicData\History\HistoryUndo`: Einordnung je Feld (wählbar, seither geändert, schon zurück, nicht wählbar
  mit Grund), in der Reihenfolge des Formulars (MySQL ordnet JSON-Schlüssel selbst), alte Werte über `LoggedValue::raw()`.
  `offered()` braucht keine Anzeigewerte, das Symbol kostet also keine Abfragen je Eintrag.
- `App\DynamicData\History\RecordHistory`: Abfrage der Einträge eines Datensatzes (Verlauf und Rücknahme).
- `App\DynamicData\Ui\HistoryUndoActions::undo()`: Bestätigung als verschachtelte Aktion des Verlaufs
  (`registerModalActions()`), Häkchenliste von Filament (`CheckboxList` mit Vermerken, gesperrte Optionen), Prüfung mit
  `RecordChecker`, Schreiben mit Version und `WriteOptions::reverting()`. Meldet „inzwischen geändert“ ohne
  „Trotzdem speichern“ und schliesst die Bestätigung; der Verlauf darunter zeigt dann den neuen Stand.
- Verlaufsdialog als eigene Vorlage (`filament/data/record-history`) mit dem Symbol je Eintrag; der Inhalt entsteht bei
  jeder Antwort neu, nach dem Zurücknehmen steht der neue Eintrag sofort oben.
- `eventText()` zeigt „(Rückgängig)“ bei `properties.reverts`. `WriteOptions::nested()` lässt `reverts` fallen.
- ARCHITECTURE Nr. 30.

Tests: `HistoryUndoTest` und `TableConnectorWriteTest` (angebundene Tabelle), auf drei Datenbanken; Browser EIN-23,
manueller Testfall EIN-23.
