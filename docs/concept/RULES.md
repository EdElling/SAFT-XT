# Konzept: Regel-Engine (Ereignisregeln über mehrere Tabellen) in SAFT XT

Stand: 23.11.2026 · Status: **erster Vorschlag zur Diskussion**, ergänzt um Läufe (Abschnitt 8) · Backlog: B-142 (ersetzt B-025)

## 1. Ziel

Fachanwender bzw. App-Verantwortliche definieren **Geschäftsregeln über mehrere Tabellen** in der Oberfläche, ohne ein
Plugin zu schreiben. Eine Regel reagiert auf ein **Ereignis** in einer Tabelle, prüft eine **Bedingung** und führt
**Aktionen** aus, auch in anderen Tabellen. Beispiele:

- Wechselt in *Aufträge* der Status auf *bestätigt*, wird in *Lieferungen* ein Datensatz mit Kunde, Adresse und
  Auftrag angelegt.
- Eine *Anmeldung* lässt sich nicht speichern, wenn der Kurs schon voll ist (`count(kurs.anmeldungen) >= kurs.plaetze`).
- Fällt der *Bestand* unter das Minimum, erhält die Rolle *Einkauf* eine Benachrichtigung.
- Wird eine *Rechnung* bezahlt, wechselt der zugehörige *Auftrag* auf *abgeschlossen*.

Vorgaben aus dem Backlog:

- **Vorhandene Lösungen prüfen** (Abschnitt 3).
- **Generisch, aber an die App angepasst:** Tabellen, Felder, Verknüpfungen und Wertelisten kommen aus dem
  Table-Designer. Wer eine Regel bearbeitet, wählt aus und tippt keine Namen ab.
- **Für einen KI-Assistenten gut strukturiert:** Eine Regel ist eine prüfbare JSON-Definition nach einem festen Schema
  und lässt sich in einem Satz lesbar zusammenfassen (Abschnitt 10).

**Nicht Ziel:** eigene Formulare und Masken, komplexe Fachlogik (Planung, Optimierung, Anbindungen an Fremdsysteme),
lange Abläufe mit Wartezuständen und Genehmigungsschritten (Workflow/BPMN). Diese Dinge bleiben Aufgabe der Plugins.
Die Regel-Engine soll die **häufigen, einfachen 80 %** abdecken, die heute ein Plugin mit `RecordListener` brauchen.

## 2. Was es heute schon gibt

Die Regel-Engine setzt auf Vorhandenem auf und erfindet möglichst wenig neu:

| Baustein | Heute | Bedeutung für Regeln |
|---|---|---|
| Ereignisse an `DynamicRecord` | `RecordSaving`, `RecordSaved`, `RecordDeleting`, `RecordDeleted`. Sie laufen über alle Wege: Datenansicht, Import, Plugin-API (`Saft::table()`) und Aufgaben. | **Auslöser** für Regeln. Plugins hören schon darauf (`RecordListener`). |
| Formelsprache (`Formula\Parser`) | `round(menge * preis, 2)`, `if(typ = 'Ausgabe', -betrag, betrag)`, `and`/`or`, Vergleiche, Pfad `kunde.land`, Summen über Kind-Datensätze `sum(positionen.betrag)`, Textfunktionen, Formelfunktionen aus Plugins. Die Formel wird in einen **JSON-Baum** gelesen und geprüft (`FormulaCheck`). | **Bedingungen und Werte** von Regeln. Diese Sprache kennen Anwender bereits aus den Tabellenformeln. |
| Feldregeln, Tabellenregeln (`TableRule`) | Prüfungen innerhalb **eines** Datensatzes, z. B. „Ende ≥ Beginn“. | Bleiben, wie sie sind. Regeln decken nur ab, was darüber hinausgeht. |
| „Wert übernehmen aus“, fortlaufende Nummer, berechnete Felder | Werte im **selben** Datensatz, beim Anlegen oder Speichern | Bleiben. Regeln ergänzen sie um Werte, die in **andere** Tabellen geschrieben werden. |
| Ablehnen mit Meldung (`RecordRejected`) | Plugin lehnt eine Speicherung ab, SAFT zeigt die Meldung an | Gleiche Anzeige für die Aktion „Speichern verhindern“ |
| Geplante Aufgaben (`TaskType`, `TaskRunner`) | Zeitgesteuerte Aufgaben mit Laufprotokoll | Auslöser „Zeitplan“ (Etappe R4) |
| App-Definition `saft-app/1`, stabile Schlüssel | Export, Installieren und Aktualisieren je App | Regeln werden Teil der App-Definition |
| Änderungsprotokoll (`LogsActivity`) | Feldgenau mit `causer` | Änderungen durch Regeln sind als solche erkennbar |

## 3. Vorhandene Lösungen und Bibliotheken

| Kandidat | Was es ist | Bewertung |
|---|---|---|
| **Eigene Formelsprache** (vorhanden) | Parser, JSON-Baum, Prüfung gegen das Datenmodell, Pfade, Aggregate, Plugin-Funktionen; wird in PHP ausgewertet und ist damit unabhängig vom Datenbanksystem | **Empfohlen für Bedingungen und Werte.** Es gibt nur eine Syntax für Anwender und die KI, und sie ist an Tabellen und Felder gebunden. Es fehlen wenige Funktionen: `changed()`, `old()` und eine Benutzer- oder Rollenabfrage (Abschnitt 5). |
| `symfony/expression-language` | Ausgereifte Ausdruckssprache mit Caching und eigenen Funktionen | Technisch gut, aber es wäre **eine zweite Syntax** neben den Formeln (`a and b`, `a.b` greift auf Objekte zu). Für die Sicherheit müssten die verfügbaren Variablen genau abgegrenzt werden. Kein Mehrwert gegenüber der eigenen Sprache, darum nicht empfohlen. |
| JSON Logic (`jwadhams/json-logic-php`) | Regeln als reines JSON, z. B. `{">":[{"var":"betrag"},100]}` | Für Maschinen ideal, für Menschen schwer lesbar. Unser Parser erzeugt bereits einen JSON-Baum und liefert damit denselben Vorteil, darum nicht nötig. Das Paket wird zudem nur wenig gepflegt. |
| `hoa/ruler`, `nicoswd/php-rule-parser` | Kleine Regelparser | `hoa` wird nicht mehr gepflegt; beide bieten weniger als die eigene Sprache. Verworfen. |
| `symfony/workflow` | Zustandsautomaten (Status mit erlaubten Übergängen), Ereignisse bei Übergängen | Interessant für ein späteres **Statusmodell** („von *offen* nur nach *bestätigt* oder *storniert*“). Das ist eine Regel-Art für sich und keine Grundlage der Engine. Wir merken es vor (Abschnitt 13). |
| Business-Rule-Engines (Drools, DMN-Engines), BPMN-Engines (Camunda, Zeebe) | Java-Dienste, sehr mächtig | Sie brauchen einen eigenen Dienst und laufen nicht auf einem Webspace wie Strato. Für die Zielgruppe überdimensioniert. Verworfen. |
| Automatisierungsdienste (n8n, Node-RED, Zapier) | Externe Abläufe über Webhooks und APIs | Ausserhalb von SAFT: keine Transaktion, Rechte und Protokoll fehlen. Später über Webhooks anbindbar (B-060), nicht als Kern. |
| Laravel-Pakete für Workflows bzw. Automatisierung | Meist an feste Models gebunden, oft ohne Pflege | Passen nicht zu dynamischen Tabellen. Verworfen. |

**Empfehlung:** Wir bauen eine **schlanke, eigene Engine** auf den vorhandenen Ereignissen und der vorhandenen
Formelsprache. Eine externe Bibliothek würde vor allem eine zweite Syntax mitbringen. Den eigentlichen Aufwand machen
das Zusammenspiel mit Transaktion, Rechten, Protokoll und Apps sowie die Bedienung aus, und dabei hilft keine der
Bibliotheken.

## 4. Aufbau einer Regel

Eine Regel gehört zu einer **App** und einer **Grundtabelle** und hat:

1. **Auslöser:** wann sie läuft (Abschnitt 4.1).
2. **Bedingung** (optional): eine Formel, die wahr oder falsch ergibt.
3. **Aktionen:** eine oder mehrere, nacheinander (Abschnitt 6).
4. **Verwaltung:** Name, Beschreibung, aktiv ja oder nein, Reihenfolge, stabiler Schlüssel.

### 4.1 Auslöser

| Auslöser | Bedeutung |
|---|---|
| `created` | Datensatz angelegt |
| `updated` | Datensatz geändert, optional nur bei Änderung bestimmter Felder (`fields: ["status"]`) |
| `deleted` | Datensatz gelöscht (in den Papierkorb) |
| `scheduled` | Zeitplan, z. B. täglich um 6:00 für alle Datensätze, auf die die Bedingung zutrifft (Etappe R4) |
| `manual` | Knopf in der Datenzeile bzw. Sammelaktion für die ausgewählten Datensätze, z. B. „Lieferung erzeugen“ (Etappe R3). Für die Verarbeitung einer selbst gesuchten Menge mit Parametern und Vorschau gibt es den **Lauf** (Abschnitt 8). |

Der häufigste Fall heisst „**Feld wechselt auf Wert**“. Dafür gibt es keinen eigenen Auslöser: Er wird mit `updated`
(bzw. `created`) und der Bedingung `changed(status) and status = 'bestätigt'` umgesetzt. Die Oberfläche bietet ihn als
Vorlage an.

### 4.2 Zeitpunkt: vorher oder nachher

| Phase | Läuft | Erlaubte Aktionen |
|---|---|---|
| **vor dem Speichern** (`before`) | in `RecordSaving` bzw. `RecordDeleting`, nach Vorgaben, „Wert übernehmen“ und fortlaufenden Nummern, vor den Formeln | Felder **dieses** Datensatzes setzen, Speichern bzw. Löschen **verhindern** |
| **nach dem Speichern** (`after`) | in `RecordSaved` bzw. `RecordDeleted`, nach SAFTs eigenen Nacharbeiten (Summen nachführen, Dateien) | Datensätze in anderen Tabellen anlegen oder ändern, benachrichtigen, später Dokument und Webhook |

Durch diese Trennung kann eine Prüfung nie eine halbe Änderung zurücklassen. Folgeaktionen sehen zudem den fertig
gespeicherten Datensatz mit Schlüssel und berechneten Werten.

## 5. Bedingungen und Werte: die Formelsprache

Bedingungen und Werte in Aktionen sind **Formeln der Tabellenformeln**, ausgewertet für den auslösenden Datensatz:

- Felder: `status`, `betrag`
- Verknüpfte Datensätze (Master): `kunde.land`, `kurs.plaetze`
- Kind-Datensätze: `count(anmeldungen)`, `sum(positionen.betrag)`
- Logik: `and`, `or`, `if(…)`, Vergleiche, `contains()` usw.

**Neu für Regeln** (nur im Regelkontext gültig, `FormulaCheck` lehnt sie sonst ab):

| Funktion | Bedeutung |
|---|---|
| `changed(feld)` | Das Feld wurde bei diesem Speichern geändert (beim Anlegen: ist gefüllt) |
| `old(feld)` | Wert vor der Änderung (beim Anlegen leer) |
| `today()`, `now()` | Datum bzw. Zeitpunkt. Die Formelsprache kennt heute **keine Datumsfunktionen**; sie kommen mit `days_between(a, b)` und `add_days(datum, n)` hinzu (auch für Fälligkeiten in Läufen). |
| `param(name)` | Parameter eines Laufs, z. B. `param(stichtag)` (nur in Läufen, Abschnitt 8) |
| `user()`, `has_role('Einkauf')` | Wer die Änderung auslöst, für Regeln wie „nur Einkauf darf auf *bestellt* setzen“ |

Mehrere Ebenen über Verknüpfungen (`auftrag.kunde.land`) lässt der Parser heute nicht zu. Wir prüfen, ob Regeln das
brauchen. Bis dahin lässt sich der Wert über „Wert übernehmen“ in der Zwischentabelle bereitstellen.

## 6. Aktionen (fester Katalog)

Jede Aktion ist ein **Typ mit festen Parametern**. Es gibt keinen frei programmierbaren Code. Werte sind immer Formeln
(Abschnitt 5).

| Typ | Phase | Parameter | Beispiel |
|---|---|---|---|
| `reject` | before | Meldung je Sprache | „Der Kurs ist voll ({{kurs.plaetze}} Plätze).“ |
| `set` | before | Feld ← Formel (mehrere) | `erledigt_am ← today()` |
| `create` | after | Zieltabelle, Feld ← Formel (mehrere), optional „nur wenn noch keiner verknüpft ist“ | *Lieferungen*: `auftrag ← id`, `adresse ← kunde.adresse`, `status ← 'offen'` |
| `update` | after | Ziel: **Master** über ein Verknüpfungsfeld (`auftrag`) oder **Kinder** über eine Verknüpfung (`positionen`, optional gefiltert), Feld ← Formel | Master `auftrag`: `status ← 'abgeschlossen'` |
| `notify` | after | Empfänger (Personen, Rollen, Benutzer aus einem Feld), Text mit Platzhaltern, Glocke und optional E-Mail | Rolle *Einkauf*: „Bestand {{artikel.name}} unter Minimum“ |
| `document` | after | Dokumentvorlage, Ablage im Dateifeld (B-124) | Rechnung erzeugen, sobald der Status *fakturiert* ist (Etappe R3) |
| `webhook` | after | Adresse, Inhalt (B-060) | später |
| `plugin` | before/after | Aktion, die ein Plugin bereitstellt (Plugin-API, Minor) | Erweiterungspunkt für Fälle, die der Katalog nicht abdeckt (Etappe R5) |

Absichtlich **nicht** dabei: Datensätze löschen (zu gefährlich, nicht umkehrbar über die Regel selbst) und Schleifen
über beliebige Abfragen. Schleifen gibt es nur über „Kinder über eine Verknüpfung“.

Die Platzhalter in Texten nutzen dieselbe Mustache-Schreibweise wie die Dokumentvorlagen (`{{feld}}`, `{{kunde.name}}`).

## 7. Ausführung

### 7.1 Transaktion

Speichern und **alle** Regeln vorher und nachher laufen in **einer Transaktion** der Datenverbindung. Scheitert eine
Aktion (Regelverstoss, Prüfregel der Zieltabelle, Datenbankfehler), wird die **ganze Änderung zurückgerollt** und die
Meldung wie bei `RecordRejected` angezeigt, mit dem Namen der Regel. Heute laufen nicht alle Speicherwege in einer
Transaktion (z. B. das Formular in der Datenansicht). Die Engine muss deshalb sicherstellen, dass eine Transaktion
offen ist, sobald für die Tabelle aktive Regeln mit Phase `after` bestehen.

Benachrichtigungen, E-Mails und Webhooks gehen erst **nach dem Commit** hinaus (`afterCommit` bzw. Warteschlange).
Wird die Änderung zurückgerollt, entsteht also keine Benachrichtigung.

### 7.2 Ketten und Endlosschleifen

Aktionen speichern Datensätze und lösen damit wieder Regeln aus. Das ist gewollt (Kette Auftrag → Lieferung →
Benachrichtigung). Damit daraus keine Endlosschleife wird, gilt:

- **Höchsttiefe** der Kette, z. B. 5 Ebenen. Darüber hinaus bricht die Engine ab und rollt die ganze Änderung zurück.
- **Kreis-Erkennung:** Dieselbe Regel für denselben Datensatz läuft in einer Kette nur einmal.
- Beim Speichern einer Regel prüft der **Regelgraph**, ob Regeln sich gegenseitig auslösen können (A schreibt in
  Tabelle T, B hört auf T, B schreibt in die Grundtabelle von A), und zeigt einen Hinweis. Verboten wird das nicht,
  weil es fachlich sinnvoll sein kann.

### 7.3 Reihenfolge

Je Tabelle und Phase laufen Regeln in einer festen **Reihenfolge** (Sortierfeld, dann Schlüssel). Regeln laufen **vor
den Listenern der Plugins**: Ein Plugin sieht damit die Werte, die eine Regel gesetzt hat, und kann sie noch ablehnen.

### 7.4 Rechte

- **Regeln verwalten:** eigenes Recht *Regeln: verwalten* je App, wie bei Auswertungen und Dokumentvorlagen.
- **Ausführen:** Aktionen laufen **im Namen der auslösenden Person, aber ohne deren Tabellenrechte zu prüfen**
  (`withoutAuthorization()`). Sonst bräuchte etwa jede Person im Verkauf Schreibrechte auf *Lieferungen*. Wer die
  Regel anlegt, hat diese Wirkung bewusst freigegeben. **Prüfregeln** (Pflicht, Feldregeln, Tabellenregeln, Plugins)
  der Zieltabelle gelten dagegen immer.
- **Grenzen zwischen Apps:** Bedingungen dürfen **freigegebene** Tabellen anderer Apps lesen (wie Auswertungen).
  Aktionen schreiben nur in Tabellen der **eigenen** App, weil Freigaben nur lesend gelten.
- **Angebundene (externe) Tabellen:** Sie sind nur lesbar und damit kein Ziel. Als Auslöser zählen nur Änderungen über
  SAFT, Änderungen direkt in der fremden Datenbank lösen keine Regel aus.

### 7.5 Protokoll

- Im **Änderungsprotokoll** bleibt `causer` die auslösende Person. Zusätzlich steht in den Eigenschaften
  `via: rule` und der Schlüssel der Regel. Die Oberfläche zeigt dann „durch Regel *Lieferung anlegen*“.
- Ein **Regelprotokoll** (`rule_runs`) hält je Ausführung fest: Regel, Datensatz, Ergebnis (ausgeführt, Bedingung
  falsch, abgelehnt, Fehler), Kette und Dauer. Es lässt sich nach Wochen kürzen, wie die Läufe der Aufgaben
  (geplante System-Aufgabe). Erfolgreiche Läufe werden nur bei eingeschaltetem „Protokoll ausführlich“ festgehalten,
  damit Massenimporte die Tabelle nicht füllen.

### 7.6 Import, Sammeländerung, Demo-Daten

- **Import** und **Sammeländerung** (B-133): Regeln laufen wie bei jeder Änderung. Im Import-Dialog gibt es für
  Administratoren einen Schalter „Regeln nicht ausführen“, z. B. für eine Übernahme von Altdaten.
- **Demo-Daten** setzen Regeln still, so wie heute die Plugin-Ereignisse (`withoutPluginEvents`).

## 8. Läufe (Verarbeitungsläufe)

Eine Regel reagiert auf **einen** Datensatz, der gerade gespeichert wird. Ein **Lauf** arbeitet eine **Menge** ab, die
er selbst sucht, und wird von einer Person gestartet oder nach Zeitplan ausgeführt. Typische Beispiele sind der
**Rechnungslauf** und der **Mahnlauf**. Ein Lauf nutzt dieselben Bausteine wie die Regeln: Formelsprache,
Aktionskatalog, `RuleValidator`, Protokoll, App-Definition und KI-Assistent. Er bekommt aber einen eigenen Ablauf.

### 8.1 Unterschied zur Regel

| | Regel | Lauf |
|---|---|---|
| Auslöser | Datensatz gespeichert, geändert, gelöscht | Person startet ihn aus dem Menü, oder Zeitplan (geplante Aufgabe) |
| Welche Datensätze | der eine, der gespeichert wird | **Auswahl** über eine Bedingung auf der Grundtabelle, optional mit Sortierung |
| Eingaben | keine | **Parameter** im Startdialog (Datum, Zahl, Auswahl aus einer Werteliste, Datensatz), z. B. Stichtag, Periode |
| Vor dem Ausführen | Probelauf zum Testen | **Vorschau** als fester Schritt: Trefferliste mit Anzahl und Summe, einzelne Treffer abwählbar |
| Ausführung | sofort, in der Transaktion des Speicherns | im **Hintergrund** mit Fortschritt; eine Transaktion **je Treffer**, damit ein Fehler nicht den ganzen Lauf kippt |
| Ergebnis | Änderungsprotokoll, `rule_runs` | **Laufprotokoll** mit Lauf-Nummer: verarbeitet, übersprungen, Fehler mit Grund; erzeugte Dokumente gesammelt |

### 8.2 Aufbau eines Laufs

1. **Grundtabelle und Auswahl:** eine Bedingung als Formel, z. B. für den Mahnlauf auf *Rechnungen*:
   `bezahlt = 0 and mahnstufe < 3 and days_between(faellig_am, param(stichtag)) > 30`.
2. **Parameter:** Name, Art, Vorgabe (Formel, z. B. `today()`), Pflicht. In Formeln über `param(name)` verfügbar.
3. **Aktionen je Treffer:** derselbe Katalog wie bei den Regeln (Phase „nachher“): `create`, `update`, `notify`,
   `document`. Neu dazu kommt `create_each` (8.3).
4. **Abschluss** (optional): Ausgabe der gesammelten Dokumente als ZIP bzw. als ein Sammel-PDF zum Drucken,
   Benachrichtigung der startenden Person mit der Zusammenfassung.

Speichern die Aktionen eines Laufs Datensätze, laufen deren **Regeln** wie bei jeder Änderung. Ein Lauf kann also
Regeln auslösen, und für diese Ketten gelten dieselben Grenzen (Abschnitt 7.2).

### 8.3 Gruppieren: ein Beleg aus mehreren Datensätzen

Ein Rechnungslauf macht oft **eine Rechnung je Kunde** aus mehreren offenen Lieferungen oder Aufträgen. Dafür läuft
der Lauf über die **Master-Tabelle** (*Kunden*), und die Auswahl prüft die Kinder:
`count(lieferungen, verrechnet = 0) > 0`. Die Aktion **`create_each`** legt je Kind-Datensatz einen Datensatz an und
verknüpft ihn mit dem in derselben Ausführung angelegten Beleg:

```json
{
  "format": "saft-rule/1",
  "kind": "run",
  "table": "kunden",
  "params": [{ "name": "stichtag", "type": "date", "default": "today()" }],
  "select": "count(lieferungen, verrechnet = 0) > 0",
  "actions": [
    { "type": "create", "as": "rechnung", "table": "rechnungen",
      "values": { "kunde": "id", "datum": "param(stichtag)", "status": "'offen'" } },
    { "type": "create_each", "children": "lieferungen", "where": "verrechnet = 0",
      "table": "rechnungspositionen",
      "values": { "rechnung": "@rechnung", "lieferung": "id", "betrag": "betrag" },
      "then": { "verrechnet": "1" } },
    { "type": "document", "template": "rechnung", "record": "@rechnung", "store": "pdf" }
  ]
}
```

`as` gibt einem angelegten Datensatz einen Namen, auf den spätere Aktionen mit `@name` verweisen. `then` setzt Felder
am verarbeiteten Kind-Datensatz, hier die Markierung gegen Doppelverrechnung. Ob `count()` mit Bedingung schon geht,
ist zu prüfen (der Parser kennt ein `via`-Argument bei Aggregaten).

### 8.4 Kein Doppelverarbeiten

Ein Lauf muss **wiederholbar** sein, ohne doppelt zu verarbeiten. Zum Beispiel darf ein abgebrochener Lauf ein zweites
Mal gestartet werden. Darum gilt:

- Verarbeitete Datensätze werden **markiert**: Feld `rechnung` bzw. `verrechnet` gesetzt, `mahnstufe + 1`,
  `gemahnt_am`. Die Auswahl schliesst sie aus. Der `RuleValidator` warnt, wenn ein Lauf seine eigene Auswahl nicht
  verändert, wenn also kein Feld aus der Auswahl in den Aktionen gesetzt wird.
- Während ein Lauf läuft, kann derselbe Lauf nicht noch einmal gestartet werden (Sperre je Lauf).
- Jeder Treffer läuft in einer eigenen Transaktion. Ein Fehler bei einem Kunden lässt die anderen Rechnungen bestehen.
  Das Laufprotokoll zeigt, was fehlte.

### 8.5 Rechte, Bedienung, Zeitplan

- **Recht je Lauf** (*Lauf ausführen: Mahnlauf*), wie bei Auswertungen. Läufe verwalten gehört zu *Regeln: verwalten*.
- **Starten:** Die Läufe einer App stehen als Menüeinträge bzw. auf einer Seite *Läufe*. Nach dem Start folgen Dialog
  mit den Parametern, Vorschau, Bestätigen, Fortschritt, Ergebnis und Download.
- **Zeitplan:** Eine Aufgabenart „Lauf ausführen“ führt denselben Lauf mit den Vorgaben der Parameter aus, ohne
  Vorschau, z. B. am Monatsersten. Das Ergebnis erscheint im Laufprotokoll der Aufgaben, mit Benachrichtigung.
- **Ohne Queue-Worker** (einfacher Webspace): Kleine Läufe laufen direkt mit Zeitlimit, grosse in Teilen über
  wiederholte Aufrufe (vgl. B-117). Das ist zu klären.

### 8.6 Voraussetzungen und Grenzen

**Voraussetzungen:**

- **Nummernkreise** (B-135) für Rechnungs- und Mahnnummern, ohne Doppel bei gleichzeitigem Anlegen
- Datumsfunktionen (Abschnitt 5)
- Aggregate mit Bedingung
- Dokumente aus Vorlagen (B-124, vorhanden) und deren Versand per E-Mail (`DocumentMailer`)

**Gut abdeckbar:**

- **Mahnlauf:** Auswahl, Mahnstufe erhöhen, Gebühr setzen, Mahnung erzeugen und senden
- **Einfacher Rechnungslauf** je Auftrag oder je Kunde aus offenen Positionen
- Periodische Läufe wie Abo- oder Mitgliederbeiträge

**Bleibt beim Plugin:**

- komplexe Preislogik (Staffeln, anteilige Beträge, Rabatte über mehrere Positionen)
- Buchungen in eine Buchhaltung
- Gutschriften und Storno mit Gegenbuchung

Ein **Prüfstein** ist der Rechnungslauf der Musikschule (B-137): Wie viel davon schafft ein Lauf ohne Plugin?

## 9. Datenmodell und Format

Tabelle `rules` (Base-Model-Muster, `LogsActivity`):

| Spalte | Inhalt |
|---|---|
| `id`, `key` | Schlüssel, stabiler Schlüssel (UUID) für die App-Definition |
| `app_id`, `custom_table_id` | App, Grundtabelle |
| `name` (je Sprache), `description` | Anzeige |
| `definition` | JSON nach `saft-rule/1` (unten) |
| `active`, `sort_order` | Aktiv, Reihenfolge |
| `created_by`, `updated_by`, Zeitstempel | wie üblich |

Tabelle `rule_runs`: `rule_id`, `table`, `record_key`, `trigger`, `outcome`, `message`, `chain_id`, `depth`,
`duration_ms`, `user_id`, `created_at`.

**Format `saft-rule/1`** (Beispiel). Felder stehen hier mit ihrem Namen. In der App-Definition werden sie wie bei den
Auswertungen über stabile Schlüssel aufgelöst.

```json
{
  "format": "saft-rule/1",
  "table": "auftraege",
  "trigger": { "on": ["updated"], "fields": ["status"] },
  "phase": "after",
  "condition": "changed(status) and status = 'bestätigt'",
  "actions": [
    {
      "type": "create",
      "table": "lieferungen",
      "unless_linked": "auftrag",
      "values": {
        "auftrag": "id",
        "kunde": "kunde",
        "adresse": "kunde.adresse",
        "status": "'offen'"
      }
    },
    {
      "type": "notify",
      "roles": ["Versand"],
      "message": { "de": "Neuer Auftrag {{nummer}} für {{kunde.name}} bereit zur Lieferung" }
    }
  ]
}
```

Ein **`RuleValidator`** prüft die Definition vollständig gegen das Datenmodell:

- Tabellen und Felder vorhanden und in der eigenen App
- Formeln gültig (`FormulaCheck` mit Regelfunktionen)
- Ergebnistypen passen zu den Zielfeldern
- Pflichtfelder der Zieltabelle bei `create` belegt oder mit Vorgabe
- Aktion zur Phase erlaubt

Derselbe Prüfer läuft beim Speichern in der Oberfläche, beim Installieren einer App und für Vorschläge der KI. Wird
im Table-Designer ein Feld umbenannt oder gelöscht, das eine Regel nutzt, gilt das wie bei Formeln und Auswertungen
als **Nutzung** (Usages): Umbenennen führt die Regel nach, Löschen wird verhindert.

## 10. Bedienung und KI

### 10.1 Oberfläche

*Administration › Regeln* (Liste je App, wie die anderen Admin-Listen mit App-Auswahl). Das Formular folgt dem Satz
„**Wenn** … **und** … **dann** …“:

1. **Wenn:** Tabelle wählen, dann Ereignis (angelegt, geändert, gelöscht), optional „nur wenn sich diese Felder
   ändern“. Vorlage „Feld wechselt auf Wert“: Feld und Wert aus der Werteliste wählen, daraus entsteht die Bedingung.
2. **Und** (Bedingung): Formeleditor mit Feldauswahl wie bei den Tabellenformeln, auch Felder verknüpfter Tabellen
   und Kind-Tabellen.
3. **Dann:** Aktionen als Liste (Repeater). Je Typ ein eigenes Formular, bei `create` und `update` die Felder der
   Zieltabelle mit je einer Formel.

Oben steht laufend die **Zusammenfassung in einem Satz**: „Wenn in *Aufträge* der Status auf *bestätigt* wechselt,
lege in *Lieferungen* einen Datensatz an (Auftrag, Kunde, Adresse, Status = offen) und benachrichtige die Rolle
*Versand*.“

**Probelauf:** Einen vorhandenen Datensatz wählen und die Regel so ausführen, als würde er gespeichert. SAFT zeigt, ob
die Bedingung zutrifft und was jede Aktion täte (welche Datensätze mit welchen Werten, an wen eine Nachricht ginge).
Danach wird alles zurückgerollt, und es geht keine Nachricht hinaus.

**Datensatz:** Im Änderungsprotokoll eines Datensatzes stehen Änderungen durch Regeln mit dem Namen der Regel. In der
Regel selbst gibt es einen Reiter „Letzte Ausführungen“ aus `rule_runs`.

### 10.2 KI-Assistent

Mit dieser Struktur kann die KI mitarbeiten, ohne dass ihr Code vertraut werden muss:

- **Regel aus Worten:** „Wenn ein Auftrag bestätigt wird, soll eine Lieferung entstehen und der Versand Bescheid
  bekommen.“ Der Assistent kennt das Datenmodell der App (wie der Tabellen-Assistent) und das Schema `saft-rule/1` und
  liefert eine Definition. Der `RuleValidator` prüft sie; Fehler gehen einmal zur Korrektur zurück (wie bei den
  Dokumentvorlagen). Die Person sieht Zusammenfassung und Formular und speichert selbst, die KI speichert nie.
- **Regel erklären:** Die Zusammenfassung in Worten ergibt sich ohne KI aus der Definition. Die KI kann auf Wunsch
  ausführlicher erklären.
- **Später:** Der App-Designer schlägt beim Entwurf einer App Regeln vor (wie Auswertungen und Dokumentvorlagen, B-139).
  Der Tabellen-Assistent erkennt, wenn ein Wunsch eine Regel statt einer Feldregel braucht (B-110). Stufe 4 von B-051
  („Automatisieren“) erzeugt Regeln.

## 11. Abgrenzung: was wofür

| Wunsch | Werkzeug |
|---|---|
| Wert eines Felds aus Feldern desselben Datensatzes | berechnetes Feld (Formel) |
| Vorgabe aus einem verknüpften Datensatz beim Anlegen | „Wert übernehmen aus“ |
| Prüfung innerhalb eines Datensatzes | Feldregel, Tabellenregel |
| Prüfung über mehrere Tabellen, Folgeaktion in anderer Tabelle, Benachrichtigung | **Regel** |
| Menge verarbeiten mit Parametern und Vorschau (Rechnungslauf, Mahnlauf), von Hand oder nach Zeitplan | **Lauf** |
| Regelmässige Prüfung oder Massenänderung nach Zeitplan | **Regel** mit Zeitplan, bei komplexer Logik eine Aufgabenart im Plugin |
| Eigene Maske, Planung, Optimierung, Fremdsystem, mehrstufige Genehmigung | **Plugin** |

## 12. Etappen

| Etappe | Inhalt | Umfang |
|---|---|---|
| **R1 Grundlage** | Tabelle `rules`, Format `saft-rule/1`, `RuleValidator`, Engine auf `RecordSaving`/`RecordSaved`, Phase vorher (`reject`, `set`), Funktionen `changed()`/`old()`, Recht *Regeln: verwalten*, Admin-Liste und Formular mit Zusammenfassung, Protokoll im Änderungsprotokoll, Nutzung im Table-Designer | mittel |
| **R2 Folgeaktionen** | Phase nachher: `create`, `update` (Master, Kinder), `notify`; Transaktion über alles; Ketten mit Höchsttiefe und Kreis-Erkennung; `rule_runs` mit Kürzen; Probelauf | gross |
| **R3 Apps, Knopf, Dokument** | Regeln in der App-Definition (Export, Installieren, Aktualisieren); Auslöser `manual` als Knopf bzw. Sammelaktion; Aktion `document` (B-124); Datumsfunktionen in der Formelsprache | mittel |
| **R3b Läufe** | Läufe mit Auswahl, Parametern (`param()`), Vorschau, Ausführung im Hintergrund je Treffer, Laufprotokoll, Sammel-PDF bzw. ZIP; Aktion `create_each` mit Verweisen `@name`; Recht je Lauf; Aufgabenart „Lauf ausführen“. Voraussetzung: Nummernkreise (B-135). Prüfstein: Mahnlauf und Rechnungslauf Musikschule (B-137) | gross |
| **R4 Zeitplan, KI** | Auslöser `scheduled` für Regeln (über die Aufgabenart aus R3b); KI-Assistent für Regeln und Läufe | mittel |
| **R5 Erweiterung** | Plugin-API: Aktionen und Bedingungsfunktionen aus Plugins; `webhook` (B-060); gegebenenfalls Statusmodell (Abschnitt 13) | offen |

Ab R2 sollte sich ein Teil der Beispiel-Plugins nachbauen lassen und als **Prüfstein** dienen:

- Musikschule: Raumkapazität und Warteliste als Hinweis
- Lager: Mindestbestand mit Benachrichtigung

Was weiter Plugin bleibt, zeigt die Grenze, z. B. die Konfliktprüfung im Stundenplan mit Zeitüberschneidungen.

## 13. Offene Fragen zur Diskussion

1. **Syntax:** Ist die eigene Formelsprache für Bedingungen und Werte gesetzt (Empfehlung), oder soll zusätzlich eine
   „Bausteine“-Oberfläche ohne Formeln entstehen (Feld, Vergleich und Wert aus Listen, wie die Tabellenregeln)?
   Vorschlag: Bausteine für einfache Bedingungen, die eine Formel erzeugen; die Formel bleibt die Wahrheit.
2. **Rechte beim Ausführen:** Ohne Tabellenrechte der auslösenden Person (Vorschlag) oder mit deren Rechten? Mit
   Rechten wäre es sicherer, aber für Folgeaktionen meist unbrauchbar.
3. **Statusmodell:** Erlaubte Statusübergänge („von *offen* nur nach *bestätigt* oder *storniert*“) als eigene,
   einfache Feldeigenschaft im Table-Designer statt als Regel? Das wäre für Anwender verständlicher; möglich mit
   `symfony/workflow` oder ohne Bibliothek.
4. **Mehrere Ebenen** in Pfaden (`auftrag.kunde.land`): jetzt im Parser ergänzen oder warten, bis Regeln es brauchen?
5. **Warteschlange:** Benachrichtigungen und E-Mails erst nach dem Commit, synchron oder über die Queue? Auf einfachem
   Webspace gibt es oft keinen Queue-Worker (vgl. B-117).
6. **Import:** Regeln beim Import standardmässig ausführen (Vorschlag) oder ausschalten?
7. **Wer bearbeitet Regeln?** Nur Administratoren oder auch Fachverantwortliche einer App (eigenes Recht je App,
   Vorschlag)?
8. **Läufe ohne Queue-Worker:** Reicht für einfachen Webspace „direkt mit Zeitlimit, sonst in Teilen“, oder setzen
   grosse Läufe einen Worker voraus?
9. **Rückgängig machen eines Laufs:** Soll ein Lauf als Ganzes zurückgenommen werden können (angelegte Belege löschen,
   Markierungen zurücksetzen), solange nichts versendet ist? Oder gilt das bewusst nicht, wie in der Buchhaltung
   (Storno statt Löschen)?
