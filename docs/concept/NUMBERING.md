# Konzept: Nummernkreise in SAFT XT

Stand: 26.11.2026 · Status: **abgestimmt**, auf Fundament 2 nachgeführt; **Etappen N1 bis N4 umgesetzt** (Abschnitt 12) ·
Backlog: B-135

## 1. Ziel

Belege brauchen **eindeutige, formatierte Nummern**, die SAFT selbst vergibt: Rechnungs-, Bestell-, Vertrags-,
Mahn- und Lieferscheinnummern, z. B. `R-2026-0042`. Folgende Anforderungen gelten:

- **Muster** mit festem Text, Jahr, Monat und aufgefüllter laufender Nummer.
- **Neubeginn** je Jahr oder Monat, bezogen auf ein Datumsfeld oder das Anlegedatum.
- **Nie doppelt**, auch wenn mehrere Personen gleichzeitig anlegen.
- **Nie wieder vergeben**, auch nicht nach dem Löschen.
- **Nicht änderbar**, sobald die Nummer vergeben ist.
- Läuft auf PostgreSQL, MariaDB und MySQL gleich, ohne Datenbank-Sequenzen oder Raw-SQL.

Nummernkreise sind eine Voraussetzung für Rechnungs- und Mahnläufe (Konzept Regel-Engine, Abschnitt 8) und für
saubere Rechnungen mit QR-Rechnung (B-124 D4).

**Nicht Ziel der ersten Etappen:** ein Nummernkreis, den sich mehrere Tabellen teilen (Abschnitt 11), und
Nummernkreise in angebundenen (externen) Tabellen. Diese sind nur lesbar.

## 2. Heute

| Baustein | Verhalten | Grenze |
|---|---|---|
| „Fortlaufend vorschlagen“ (`SequenceSuggester`) | Für Ganzzahlfelder: höchster Wert + 1, optional je Wert einer Verknüpfung (`suggest_scope`); als Vorschlag im Formular, beim Anlegen ohne Wert gesetzt (`WritePhases::beforeSave`) | Kein Muster, kein Neubeginn. Die gelöschte höchste Nummer wird wieder vergeben. Gleichzeitiges Anlegen kann dieselbe Nummer ergeben; das verhindert nur ein eindeutiger Index, und dann scheitert das Speichern. Der Wert bleibt änderbar. |
| Eindeutig (`is_unique`) | Eindeutiger Index in der Datenbank | Verhindert Doppel, vergibt aber nichts |
| Schreibweg (Fundament 2) | `RecordWriter` öffnet für jeden Schreibvorgang eine Transaktion auf der Datenverbindung und auf der Hauptverbindung; Vorher-Phase in `WritePhases::beforeSave`; `batch()` für mehrere Vorgänge | – (Grundlage für die Vergabe, Abschnitt 6.2) |
| QR-Rechnung (B-124 D4a) | Die QR-Referenz nimmt nur die Ziffern des Felds (`QrBillFactory`), die RF-Referenz die Buchstaben und Ziffern | – (passt schon zu formatierten Nummern, Abschnitt 9) |
| Musikschule-Demo | `rechnungsnummer` als Textfeld, vom Seeder als `R-2026-001` befüllt | Neue Rechnungen bekommen keine Nummer |

„Fortlaufend vorschlagen“ **bleibt** für den einfachen Fall: Die Nummer ist nur ein Vorschlag und bleibt änderbar,
z. B. die Positionsnummer in einer Rechnung oder die Reihenfolge von Lektionen.

## 3. Entscheidungen

| Frage | Entscheidung |
|---|---|
| Form | **Eigener Feldtyp „Nummernkreis“** (`FieldType::Number`, Wert `number`) statt einer Eigenschaft an Text- oder Zahlfeldern. Wie bei „Berechnet“ erzeugt SAFT den Wert; das Feld ist immer schreibgeschützt, eindeutig und hat eigene Einstellungen. Name in den anderen Sprachen: *Number sequence*, *Plage de numéros*, *Intervallo di numerazione*. |
| Gespeicherter Wert | Die **formatierte Nummer als Text** (`string`, Länge aus dem Muster, mindestens 30). `valueType()` ist Text: Anzeige, Suche, Filter, Sortierung, Export, Formeln und Auswertungen behandeln das Feld wie ein Textfeld. Die laufende Nummer wird mit Nullen aufgefüllt; so sortieren die Nummern innerhalb eines Musters richtig. |
| Zähler | In einer eigenen Tabelle **`saft_number_sequences`** (Feld, Periode, Gruppe, letzter Wert), nicht als höchster Wert der Tabelle. Damit werden gelöschte Nummern nie wieder vergeben. |
| Ort des Zählers | Im **Namensraum `app_data`** auf der Datenverbindung, also in derselben Datenbank wie der Datensatz (Abschnitt 6.2). Die Tabelle entsteht bei Bedarf, wenn das erste Nummernkreis-Feld angelegt wird, wie die Kopien der Datenstände (`ds_…`). Das Präfix `saft_` ist für Tabellen des Table-Designers gesperrt. |
| Gleichzeitigkeit | Zähler **gesperrt lesen** (`lockForUpdate()` im Query Builder) und erhöhen, in der Vorher-Phase des `RecordWriter`, also in **derselben Transaktion** wie das Anlegen des Datensatzes. Scheitert das Speichern, rollt auch der Zähler zurück, und es entsteht keine Lücke. |
| Eindeutigkeit | Zusätzlich ein **eindeutiger Index** auf der Spalte, als letzte Sicherung. Die Vergabe überspringt eine Nummer, die es in der Tabelle schon gibt (auch im Papierkorb), z. B. aus einem Import oder nach einer Änderung des Musters. |
| Vergabe | **Beim Anlegen** (Etappe N1). **Erst bei einer Bedingung**, z. B. Status = *definitiv*, ab Etappe N3. |
| Änderbar | **Nie**, sobald vergeben. Durchgesetzt im Schreibweg (`WritePhases`), nicht nur in der Oberfläche: Plugin-API, Regeln und alle anderen Schreibstellen können sie nicht überschreiben. |
| Platzhalter im Muster | **Sprachneutral** (`{YYYY}`, `{MM}`, `{0000}`), weil SAFT vier Sprachen hat. Im Formular stehen sie als Bausteine mit übersetzter Beschriftung. Die Schreibweise aus dem Backlog (`{JJJJ}`) wird als Alias angenommen. |
| Gemeinsamer Kreis für mehrere Tabellen | **Vorerst nicht** (Abschnitt 11). Die Zählertabelle hängt am Feld; eine spätere Spalte für einen Kreis ist ohne Umbau möglich. |
| Zählerstand senken (N2) | **Nur ohne Konflikt**: wenn keine vorhandene Nummer der Periode höher ist, mit Bestätigung. |
| Import mit Nummern | **Übernehmen**, wenn eindeutig; der Zähler wird angehoben (Abschnitt 7). Kein eigener Schalter. |
| Gruppe aus einer Verknüpfung (N4) | **Erst bei Bedarf**; N4 beginnt mit Auswahlfeldern. |

## 4. Einstellungen des Felds

| Einstellung | Option | Bedeutung | Beispiel |
|---|---|---|---|
| **Muster** | `pattern` | Fester Text und Platzhalter (Abschnitt 5) | `R-{YYYY}-{0000}` |
| **Neubeginn** | `reset` | `never`, `yearly`, `monthly` | jährlich |
| **Bezugsdatum** | `date_field` | leer = Anlegedatum (Vorgabe) oder ein Feld vom Typ Datum bzw. Datum/Zeit der Tabelle; bestimmt Jahr und Monat im Muster und die Periode | Feld `datum` (Rechnungsdatum) |
| **Geschäftsjahr beginnt im** | `fiscal_start` | Monat 1–12 (Vorgabe Januar); nur bei jährlichem Neubeginn | Juli: `{YYYY}` = Jahr, in dem das Geschäftsjahr beginnt |
| **Startwert** | `start` | erste Nummer je Periode (Vorgabe 1); gilt für Perioden, die noch keinen Zähler haben | 1 |
| **Gruppe** (Etappe N4) | `group_field`, `group_codes` | eigener Zähler je Wahl eines Auswahlfelds (aktives Pflichtfeld); je Wahl ein Kurzzeichen für `{GROUP}`, Vorgabe der Wert | je Belegart: Einnahme → `V`, Ausgabe → `E` |
| **Vergeben, wenn** (Etappe N3) | `assign_when` | Bedingung als Formel; leer = beim Anlegen | `status = 'definitiv'` |

Im Formular steht unter dem Muster eine **Vorschau** mit der nächsten Nummer, z. B. „Nächste Nummer: R-2026-0043“.

Ist ein **Datumsfeld** das Bezugsdatum, muss es ein Pflichtfeld sein; der Table-Designer lehnt die Einstellung sonst
mit einer Meldung ab. Ist es beim Anlegen trotzdem leer (z. B. über die Plugin-API), scheitert das Speichern mit einer
fachlichen Meldung (`BusinessRuleException`).

## 5. Muster

| Platzhalter | Ergebnis | Hinweis |
|---|---|---|
| `{YYYY}` | 2026 | Jahr des Bezugsdatums bzw. Beginn des Geschäftsjahrs |
| `{YY}` | 26 | |
| `{MM}` | 11 | |
| `{0000}` | 0042 | laufende Nummer, so viele Stellen wie Nullen. Bei Überlauf wird die Nummer länger (`10000`) und nie abgeschnitten. |
| `{#}` | 42 | laufende Nummer ohne Auffüllen; wird dann nicht mehr richtig als Text sortiert |
| `{GROUP}` (N4) | AN | Kurzzeichen der Gruppe: 1–10 Zeichen aus Buchstaben, Ziffern und `- / . _`; Vorgabe der gespeicherte Wert der Wahl |
| übriger Text | `R-`, `/` | wörtlich |

**Prüfung des Musters** (eine Klasse `NumberPattern`, dieselbe Prüfung im Table-Designer, in der App-Definition und
für Vorschläge der KI):

- genau **ein** Platzhalter für die laufende Nummer
- bei jährlichem Neubeginn ein Jahr (`{YYYY}` oder `{YY}`), bei monatlichem Jahr und Monat, mit Gruppe `{GROUP}`.
  Sonst ergäbe der Neubeginn doppelte Nummern.
- höchstens 50 Zeichen Ergebnis
- erlaubte Zeichen: Buchstaben, Ziffern, `- / . _` und Leerzeichen. Für die QR-Referenz zählen nur die Ziffern
  (Abschnitt 9).

`NumberPattern` kann eine Nummer auch **zurücklesen** (Periode und laufende Nummer aus `R-2026-0042`). Das brauchen der
Import (Zähler anheben) und das Umstellen bestehender Felder (N2).

## 6. Vergabe

### 6.1 Ablauf

In `WritePhases::beforeSave` beim Anlegen, nach den Vorgaben, „Übernehmen aus“ und den fortlaufenden Vorschlägen und
**vor** dem Plugin-Ereignis `RecordSaving` und den berechneten Feldern. Für jedes Feld vom Typ Nummernkreis, dessen
Wert leer ist:

1. Periode bestimmen: `2026` bzw. `2026-11` aus dem Bezugsdatum, `-` ohne Neubeginn.
2. Zeile in `saft_number_sequences` für Feld, Periode und Gruppe gesperrt lesen. Gibt es noch keine, wird sie mit dem
   Startwert − 1 per Upsert angelegt und dann gesperrt gelesen. Nicht vorher gesperrt lesen: Ein `SELECT … FOR UPDATE`
   auf eine fehlende Zeile nimmt in MySQL/MariaDB eine Lückensperre, und zwei Vorgänge, die danach beide einfügen,
   blockieren sich (Deadlock, im Test mit vier Prozessen nachgewiesen). Der Upsert nimmt gleich die exklusive Sperre;
   gleichzeitige Vorgänge warten der Reihe nach.
3. Wert + 1, Nummer nach dem Muster formatieren; gibt es sie in der Tabelle schon, weiterzählen. Zählerstand
   schreiben und die Nummer im Datensatz setzen.

Plugins, Formeln und später Regeln sehen die Nummer also schon. Die Phase ist Teil des Vertrags mit Plugins
(Kommentar in `WritePhases`, Abschnitt 5 der Architektur).

### 6.2 Transaktion

Zähler und Datensatz liegen in **einer Transaktion auf derselben Verbindung**. Der `RecordWriter` öffnet sie für jeden
Schreibvorgang, auch für das Formular der Datenansicht, den Import (eine Transaktion je Block) und `batch()`. Ein
eigener Baustein „Transaktion beim Speichern“ ist damit nicht mehr nötig.

Warum der Zähler auf der Datenverbindung liegt und nicht in der Verwaltungsdatenbank: Zwischen den beiden
Verbindungen gibt es keine verteilte Transaktion; der Writer committet die Daten zuerst und dann die
Hauptverbindung (Architektur, Abschnitt 3). Läge der Zähler auf der Hauptverbindung, bliebe bei einem Fehler zwischen
den beiden Commits ein Datensatz mit Nummer, aber ohne erhöhten Zähler zurück, und jede weitere Vergabe würde an der
Eindeutigkeit scheitern. Auf der Datenverbindung ist das ausgeschlossen. Einen Fremdschlüssel auf `custom_fields`
gibt es deshalb nicht; `DynamicSchemaService` löscht die Zähler mit dem Feld.

Die Tabelle legt nicht eine Migration an, sondern `NumberSequences::ensureTable()` über den Schema Builder der
Datenverbindung, sobald das erste Nummernkreis-Feld entsteht. Eine Migration ginge verloren, weil
`migrate:fresh` den Namensraum nach den Migrationen neu anlegt (`ResetAppDataNamespace`). `app-data:verify` meldet
`saft_…`-Tabellen nicht als verwaist.

Die Sperre gilt nur für die eine Zeile des Zählers und nur bis zum Ende der Transaktion. Beim Import hält jede
Block-Transaktion die Sperre bis zu ihrem Commit; gleichzeitige Anlagen warten so lange. Das ist gewollt.

### 6.3 Lücken

- **Abgebrochenes Speichern:** keine Lücke, der Zähler rollt mit zurück.
- **Löschen:** Die Nummer bleibt vergeben. Im Papierkorb bleibt sie beim Datensatz und kehrt beim Wiederherstellen
  zurück. Nach dem endgültigen Löschen entsteht eine Lücke, die im Änderungsprotokoll nachvollziehbar ist.
- **Vergabe bei Bedingung** (N3): Entwürfe ohne Nummer erzeugen keine Lücken, wenn sie verworfen werden.

Ob eine **lückenlose** Nummerierung vorgeschrieben ist, hängt vom Land und vom Beleg ab. Nach unserem Verständnis
verlangen Schweiz und Deutschland eindeutige Rechnungsnummern, Lücken müssen aber erklärbar sein. Das ist **nicht
rechtlich geprüft**. SAFT sorgt für Eindeutigkeit und Nachvollziehbarkeit, nicht für eine Garantie ohne Lücken.

### 6.4 Vergabe bei einer Bedingung (Etappe N3)

Ist „Vergeben, wenn“ gesetzt, bleibt das Feld beim Anlegen leer. SAFT vergibt die Nummer beim ersten Speichern, bei
dem die Formel wahr ist, z. B. `status = 'definitiv'`, auch beim Ändern. Danach gilt die Nummer für immer, auch wenn
der Status später zurückgeht. Als Bezugsdatum gilt dann der Zeitpunkt der Vergabe bzw. das gewählte Datumsfeld zu
diesem Zeitpunkt (beim Nachnummerieren wie in N2 das Anlegedatum).

Ausgewertet wird die Bedingung **nach** `RecordSaving` und vor den berechneten Feldern: Sie sieht Werte, die Plugins
setzen, und Formeln sehen die Nummer. Deshalb darf sie keine berechneten Felder und keine Summen über Kind-Tabellen
verwenden; Plugins sehen eine so vergebene Nummer erst in `RecordSaved`.

Die Bedingung nutzt die Formelsprache (wie Tabellenformeln und Regeln) und hängt **nicht** von der Regel-Engine ab.
Die Regel-Engine braucht daher keine eigene Aktion „Nummer ziehen“. Eine Sammeländerung des Status (B-133) löst die
Vergabe je Datensatz aus, in Schlüsselreihenfolge.

## 7. Zusammenspiel

| Bereich | Verhalten |
|---|---|
| **Formular** | Nur Anzeige; vor dem ersten Speichern „wird beim Speichern vergeben“, mit Bedingung „wird vergeben, wenn …“ |
| **Datenansicht** | Text: Sortieren, Suchen und Filtern wie bei Text |
| **Schreibweg** | `WritePhases` lehnt ab: einen mitgegebenen Wert beim Anlegen, ausser bei Herkunft `import` und `seed`; jede Änderung einer vergebenen Nummer. Meldung als `BusinessRuleException` mit Code. |
| **Plugin-API** (`DataTable::create`, `update`) | vergibt automatisch; mitgegebene Werte werden wie oben abgelehnt. Ein Listener auf `RecordSaving` darf eine Nummer weder setzen noch ändern (Prüfung nach dem Ereignis). Keine neue API-Version: Die Klassen im `ApiManifest` ändern sich nicht, das Verhalten steht im Entwicklerhandbuch. |
| **Import** | **Ohne** Wert: Nummer wird vergeben. **Mit** Wert (z. B. Altdaten): wird übernommen, wenn eindeutig. Passt er zum Muster, wird der Zähler seiner Periode auf mindestens diesen Wert angehoben, sodass spätere Nummern nicht kollidieren. Werte, die nicht zum Muster passen, werden übernommen. Sie im Ergebnis des Imports zu melden, ist zurückgestellt (B-154): Der Import kennt heute nur Fehler, keine Hinweise. |
| **Datensatz kopieren** (`RecordCopier`) | Die Kopie bekommt eine neue Nummer (eindeutige Felder werden schon heute nicht kopiert); mit Bedingung erst, wenn sie für die Werte der Kopie zutrifft (N3). |
| **Formeln, Auswertungen, Regeln** | Wert vom Typ Text (`valueType` = String) |
| **Dokumentvorlagen** | Platzhalter wie jedes Feld (`{{rechnungsnummer}}`) |
| **Änderungsprotokoll** | Die Vergabe erscheint als Teil der Anlage durch die auslösende Person. |
| **App-Definition** | Exportiert werden die Einstellungen (Muster, Neubeginn, Bezugsdatum über den Feldnamen wie `suggest_scope`), **nicht** die Zählerstände. Eine Installation (auch als Kopie) beginnt beim Startwert. Ein Aktualisieren, das das Muster ändert, gilt als **heikel**: Vorhandene Nummern bleiben, neue erscheinen im neuen Format, und die Prüfung der Eindeutigkeit läuft mit. |
| **Demo-Reset, „App-Daten zurücksetzen“** | Die Zähler der betroffenen Felder werden mit den Daten zurückgesetzt. |
| **Wiederherstellen einer App** (B-141) | Zähler gehen nie zurück (Konzept Wiederherstellen). |
| **Sammeländerung** (B-133) | Nummernkreis-Felder sind nicht änderbar und erscheinen nicht im Dialog. |
| **KI** (Tabellen-Assistent, App-Designer) | kennen den Typ und schlagen ihn für Belegnummern vor. Das Muster steht im vorhandenen Feld `formula` des Vorschlags (das JSON-Schema liegt an der Grössengrenze der Anthropic-Grammatik); den Neubeginn leitet SAFT aus dem Muster ab: mit Monat monatlich, mit Jahr jährlich, sonst nie. Das Bezugsdatum bleibt das Anlegedatum. Der `DraftCompiler` prüft das Muster über den Normalizer mit `NumberPattern`. |

## 8. Bestehende Felder umstellen (Etappe N2)

Viele Apps haben schon ein Nummernfeld als Text oder Ganzzahl, z. B. `rechnungsnummer` in der Musikschule. Der
Table-Designer erlaubt deshalb den **Typwechsel nach „Nummernkreis“** von Text, Ganzzahl und Auswahl:

- **Voraussetzung:** Alle vorhandenen Werte sind eindeutig. Leere Werte sind erlaubt.
- **Vorschau vor dem Umstellen:** Wie viele Werte passen zum Muster, wie hoch ist der höchste Wert je Periode, und
  welche Werte passen nicht (die ersten zehn als Liste).
- **Zähler:** je Periode der höchste passende Wert. Neue Nummern schliessen daran an.
- **Werte, die nicht passen,** bleiben unverändert, z. B. eine alte Nummer mit drei Stellen bei einem Muster mit vier
  Stellen. Wer will, setzt die Auffüllung im Muster so, dass sie zu den alten Nummern passt.
- **Leere Werte:** wahlweise leer lassen oder **jetzt nummerieren**, in der Reihenfolge des Bezugsdatums und dann des
  Anlegedatums.
- **Ganzzahl → Nummernkreis:** Die Spalte wird zu Text; die Werte werden nach dem Muster formatiert, wenn es nur aus
  `{0000}` bzw. `{#}` besteht, sonst unverändert übernommen.

Der **Rückweg** Nummernkreis → Text ist immer möglich. Die Werte bleiben, die Zähler werden gelöscht. Beides nutzt die
Schutzlogik des Table-Designers für Typwechsel (`SAFE_CONVERSIONS`, `fieldChangeProblem`). In N1 sind Typwechsel von
und zu „Nummernkreis“ nur möglich, solange das Feld in keinem Datensatz einen Wert hat, auch nicht im Papierkorb
(Rückmeldung 26.11.2026: bei leeren Tabellen kein Grund zu sperren); zurück zu einem anderen Typ entfallen die
Zähler.

**Zählerstand ansehen und setzen:** Im Feldformular steht je Periode (und Gruppe) der letzte Wert. Ihn zu **erhöhen**
ist jederzeit möglich, z. B. „Rechnungen 2026 beginnen bei 1000“. Ihn zu **senken** ist nur möglich, wenn keine
vorhandene Nummer der Periode höher ist, und erfordert eine Bestätigung. Jede Änderung steht im Änderungsprotokoll.

## 9. QR-Rechnung

Die QR-Rechnung (B-124 D4a) bildet die QR-Referenz aus den **Ziffern** des gewählten Felds (`QrBillFactory`), bei
`R-2026-0042` also aus `20260042`, und ergänzt die Prüfziffer; die RF-Referenz nimmt Buchstaben und Ziffern. Das passt
schon zu formatierten Nummern; N1 ändert daran nichts. Zu beachten bleibt: Ein Muster ohne Jahr ergibt über mehrere
Jahre dieselben Ziffern nur dann, wenn es ohne Neubeginn läuft, also nie doppelt.

## 10. Etappen

| Etappe | Inhalt | Umfang |
|---|---|---|
| **N1 Feldtyp** | `FieldType::Number`, Einstellungen (Muster, Neubeginn jährlich oder monatlich, Bezugsdatum, Geschäftsjahr, Startwert), `NumberPattern`, Vorschau „nächste Nummer“, Tabelle `saft_number_sequences` auf der Datenverbindung, Vergabe in `WritePhases` mit Sperre, eindeutiger Index, Schreibschutz im Schreibweg, Import mit und ohne Wert, Kopieren, App-Definition, Demo-Reset bzw. App-Daten zurücksetzen, KI-Assistenten, Handbücher in vier Sprachen. Test der Gleichzeitigkeit auf allen drei Datenbanken | mittel |
| **N2 Umstellen** | Typwechsel von Text, Ganzzahl und Auswahl mit Vorschau, „jetzt nummerieren“, Rückweg zu Text; Zählerstand ansehen und setzen; Musikschule-Demo: `rechnungsnummer` als Nummernkreis `R-{YYYY}-{000}` | mittel |
| **N3 Vergabe bei Bedingung** | „Vergeben, wenn“ als Formel; Vergabe beim ersten Speichern, bei dem sie wahr ist | klein |
| **N4 Gruppen** | eigener Zähler je Wert eines Auswahlfelds, Platzhalter `{GROUP}` | klein |

Am meisten Nutzen bringen N1 und N2 zusammen. Mit beiden ist die Musikschule-Rechnung vollständig, und der
Rechnungslauf der Regel-Engine hat seine Voraussetzung.

## 11. Entschiedene Fragen (26.11.2026)

1. **Name des Feldtyps:** „Nummernkreis“; *Number sequence*, *Plage de numéros*, *Intervallo di numerazione*.
2. **Gemeinsamer Kreis für mehrere Tabellen** (z. B. Rechnungen und Gutschriften): vorerst nicht. Später als eigenes
   Objekt „Nummernkreis“ (Administration der App), auf das mehrere Felder verweisen; die Zählertabelle bekommt dann
   eine Spalte für den Kreis statt des Felds.
3. **Zählerstand senken:** nur ohne Konflikt, mit Bestätigung (N2).
4. **Import mit Nummern:** immer übernehmen, wenn eindeutig, und den Zähler anheben; kein eigener Schalter.
5. **Gruppe aus einer Verknüpfung:** erst bei Bedarf.

Neu beim Nachführen auf Fundament 2 entschieden: Zähler auf der Datenverbindung in `saft_number_sequences`, bei Bedarf
angelegt (Abschnitt 6.2), Präfix `saft_` gesperrt, Schreibschutz im Schreibweg statt in der Oberfläche, Vergabe
überspringt vorhandene Nummern. Das ist Entscheidung 26 in `docs/ARCHITECTURE.de.md`/`.md`.

## 12. Umsetzungsplan N1

Geplant 26.11.2026. Reihenfolge der Teilschritte, je mit eigenen Tests:

1. **Kern:** `FieldType::Number` (Spalte `string` mit Länge aus dem Muster, `valueType()` = String),
   `NumberPattern` (prüfen, formatieren, Periode, zurücklesen), `NumberSequences` (Tabelle anlegen, gesperrt ziehen,
   anheben, löschen), Präfix `saft_` im `IdentifierValidator`, `SchemaVerifier` ohne `saft_…`.
2. **Schreibweg:** `TableDefinition::numbers`, Vergabe und Schreibschutz in `WritePhases::beforeSave`, Anheben beim
   Import mit Wert; Test der Gleichzeitigkeit mit mehreren Prozessen auf drei Datenbanken.
3. **Table-Designer:** `FieldDefinitionNormalizer` (immer eindeutig, nie Pflicht, Optionen), `DynamicSchemaService`
   (Tabelle anlegen, Zähler beim Entfernen löschen, Typwechsel sperren), `FieldForm` mit Mustern als Bausteinen,
   Vorschau und Bezugsdatum, Feldliste.
4. **Oberfläche und Datenwege:** `DynamicFormBuilder` (Anzeige), `RecordChecker`, `RecordImporter` (Meldung bei
   fremden Werten), `RecordCopier`, Demo-Reset bzw. App-Daten zurücksetzen.
5. **App-Definition und KI:** Export/Import und Prüfung der Optionen (`DefinitionParts`, `AppDefinitionValidator`,
   `ChangePlanner` mit „heikel“ bei geändertem Muster), `TableProposalSchema`, `TableAssistant`, `DraftCompiler`,
   `SchemaMarkdown`/`SchemaImporter` für den Plugin-Kontext.
6. **Dokumentation:** Anwender-, Admin- und FAQ-Handbuch in vier Sprachen, Entwicklerhandbuch DE/EN (Schreibschutz in
   der Plugin-API), Entscheidung 26 in der Architektur, `FORMULA_SPEC.md`, Konzept und Backlog nachführen.

### 12.1 Umsetzung N1 (26.11.2026)

- **Kern:** `App\DynamicData\Numbering\NumberPattern` (prüfen, Periode, formatieren, zurücklesen),
  `NumberSequences` (Tabelle `saft_number_sequences` mit Primärschlüssel Feld/Periode/Gruppe, Upsert statt gesperrtem
  Lesen einer fehlenden Zeile), `NumberAssigner` (Vergabe, Übernahme bei `import`/`seed`, Schutz vor und nach
  `RecordSaving`). Optionen am Feld: `pattern`, `reset`, `date_field`, `fiscal_start`, `start`, dazu `length` = 60
  für die Spalte. Ohne Angabe gilt im Normalizer „nie“, im Formular ist „jährlich“ vorgewählt.
- **Table-Designer:** Abschnitt „Nummernkreis“ im Feldformular mit Bausteinen zum Einfügen, Vorschau der nächsten
  Nummer (mit dem gespeicherten Zählerstand); Bezugsdatum nur aus aktiven Pflichtfeldern vom Typ Datum; das
  Bezugsdatum bleibt ein solches Feld, Umbenennen führt den Verweis nach; Typwechsel von und zu „Nummernkreis“ nur
  bei leerer Spalte (auch Papierkorb); Zähler gehen mit Feld, Tabelle und Typwechsel.
- **Datenwege:** Formular zeigt „wird beim Speichern vergeben“; Import mit und ohne Nummern; Kopie bekommt eine neue
  Nummer; Demo-Reset setzt die Zähler zurück; App-Definition trägt die Einstellungen, nicht die Zähler; geändertes
  Muster beim Aktualisieren gilt als heikel; Plugin-Kontext (`SchemaMarkdown`, `SchemaImporter`).
- **Tests:** `NumberPatternTest` (Unit), `NumberSequenceTest` (25 Fälle), `NumberSequenceConcurrencyTest` (vier
  Prozesse je 15 Datensätze ohne Test-Transaktion, lokal auch mit acht Prozessen auf PostgreSQL, MySQL und MariaDB),
  `AppInstallerTest`. Larastan-Baseline um vier Meldungen kleiner (319).
- **Zurückgestellt:** Meldung fremder Nummern im Importergebnis (B-154).

### 12.2 Plan N2 (26.11.2026)

Ziel: vorhandene Nummernfelder ohne Datenverlust umstellen, Zählerstände pflegen, Musikschule-Rechnungen mit
Nummernkreis. Reihenfolge der Teilschritte:

1. **Umstellen im Kern** (`DynamicSchemaService::updateField`): Typwechsel Text, Ganzzahl, Auswahl → Nummernkreis
   auch mit Werten, wenn die nicht gelöschten Werte eindeutig sind (wie der eindeutige Index). Spalte wird Text (60);
   Ganzzahlen werden nach dem Muster formatiert, wenn es nur aus `{0000}` bzw. `{#}` besteht, sonst als Text
   übernommen. Danach je Periode der höchste passende Wert als Zählerstand (`NumberPattern::parse`,
   `NumberSequences::raise`); Werte, die nicht passen, bleiben. Rückweg Nummernkreis → Text immer, Zähler entfallen.
   Neue Klasse `NumberConversion` (Analyse und Ausführung), seitenweise über die Spalte, ohne Raw-SQL.
2. **Vorschau im Feldformular:** Wird der Typ eines gefüllten Felds auf „Nummernkreis“ gestellt, zeigt der Abschnitt
   „Nummernkreis“: Anzahl Werte, davon passend, höchster Wert je Periode, die ersten zehn nicht passenden, Anzahl leerer
   Werte; Doppel sperren „Speichern“ mit Meldung. Dieselbe Analyse liefert die Notiz im Änderungsplan beim
   Aktualisieren einer App (`ChangePlanner`, heikel).
3. **Jetzt nummerieren:** Schalter „Leere Werte jetzt nummerieren“ beim Umstellen und als Aktion am Feld. Läuft als
   Arbeit `number_backfill` (`App\Work\WorkType`, `WritesRecords`): Reihenfolge Bezugsdatum, dann `created_at`, dann
   `id` (Keyset-Cursor), je Block über den `RecordWriter` mit eigener Herkunft `numbering`, die eine leere Nummer füllen
   darf (`NumberAssigner`), mit Protokoll je Datensatz.
4. **Zählerstand ansehen und setzen:** Im Abschnitt „Nummernkreis“ eines bestehenden Felds die Zählerstände je Periode
   (`NumberSequences::periods()`), Aktion „Zählerstand setzen“ (Periode, Wert): erhöhen jederzeit, senken nur, wenn
   keine vorhandene Nummer der Periode höher ist, mit Bestätigung; Eintrag im Änderungsprotokoll des Felds.
5. **App-Aktualisierung:** `ChangeExecutor::createFields` legt einen Nummernkreis mit Bezugsdatum nach dem Datumsfeld
   an (wie „Übernehmen aus“); Typwechsel zu Nummernkreis im Änderungsplan mit der Analyse aus Schritt 2.
6. **Musikschule 1.5:** `rechnungsnummer` als Nummernkreis `R-{YYYY}-{000}`, jährlich, Bezugsdatum `datum`; Seeder
   gibt keine Nummern mehr vor, SAFT vergibt sie (Reihenfolge der Rechnungen wie bisher, also dieselben Nummern);
   Aktualisierung 1.4 → 1.5 stellt vorhandene Installationen (Strato-Demo) mit Schritt 1 um.
7. **Tests, Handbücher, Testliste:** Feature-Tests je Schritt (auch MySQL/MariaDB lokal für die Spaltenänderung),
   Browser-Fall TDE-38 (Umstellen mit Vorschau, Zählerstand setzen), Handbücher in allen Sprachen, Konzept und Backlog.

Entschieden 26.11.2026: N2 in einer eigenen Sitzung; danach **Release 0.7.0** mit N1 und N2 und Aktualisierung der
Strato-Demo.

### 12.3 Umsetzung N2 (26.11.2026)

- **Umstellen:** `NumberConversion` (Analyse als `NumberAnalysis`, Übernahme nach dem Typwechsel) im Lock des
  `DynamicSchemaService`; `assertNumberChange` gilt auch für `fieldChangeProblem` (Änderungsplan). Quellen mit Werten:
  Text, mehrzeiliger Text (bis 60 Zeichen), Ganzzahl, Auswahl (Auswahl → kürzerer Text neu, wenn alle Werte passen);
  zurück zu Text bzw. mehrzeiligem Text immer. Doppel zählen nur unter nicht gelöschten Datensätzen (wie der Index),
  höchste Werte auch im Papierkorb. Das Auffüllen von Ganzzahlen ist Teil der Strukturänderung und läuft über den Query
  Builder, nicht über den `RecordWriter`.
- **Jetzt nummerieren:** Arbeit `number_backfill` (`NumberBackfillWork`): je Block die ersten leeren Datensätze nach
  Bezugsdatum, `created_at`, `id` (nummerierte fallen heraus, deshalb ohne Cursor; Übersprungene im Zustand).
  Herkunft `WriteOptions::NUMBERING`, ohne Plugin-Ereignisse, mit Protokoll je Datensatz und Laufkennung `run`
  (`number_backfill:<uuid>`), Sicherungspunkt je Datensatz. Ohne Datumsfeld gilt das Anlegedatum des Datensatzes.
  Papierkorb und Datensätze ohne Bezugsdatum bleiben leer. Anders als die Sammeländerung (BULK_EDIT.md) nicht „alles
  oder nichts“: Scheitert ein Datensatz, wird er übersprungen und gemeldet; ein zweiter Lauf nimmt den Rest.
- **Zählerstand:** `NumberCounter` (Übersicht je Periode mit höchster vorhandener Nummer, setzen mit Prüfung),
  `NumberSequences::periods()`/`set()`; Eintrag `number_counter` im Verlauf des Felds.
- **Oberfläche:** Vorschau und Schalter „Leere Werte jetzt nummerieren“ im Abschnitt „Nummernkreis“, Prüfung auf Doppel
  am Muster, Zählerstände mit Aktion „Zählerstand setzen“, Zeilenaktion „Jetzt nummerieren“ in der Feldliste.
- **App-Aktualisierung:** Notiz `number_conversion` im Änderungsplan. Behoben aus N1: Ein Nummernkreis mit Bezugsdatum
  entsteht beim Installieren (`SchemaImporter`) und Aktualisieren (`ChangeExecutor`) direkt nach seinem Datumsfeld,
  vor den Verknüpfungen, die ihn anzeigen dürfen.
- **Musikschule 1.5:** `rechnungsnummer` als Nummernkreis `R-{YYYY}-{000}`, jährlich, Bezugsdatum `datum`; der Seeder
  gibt keine Nummern mehr vor; Aktualisierung 1.4 → 1.5 übernimmt vorhandene Nummern.
- **Tests:** `NumberConversionTest` (15 Fälle, Spaltenänderung auch auf MySQL und MariaDB), `DemoSeedersTest`
  (1.4 → 1.5). `CustomField::$type` als `FieldType` beschrieben: Larastan-Baseline 319 → 277.

### 12.4 Plan N3 (26.11.2026)

Entschieden 26.11.2026: (A) Auswertung nach `RecordSaving`, ohne berechnete Felder und Summen; (B) das Nachnummerieren
beachtet die Bedingung; (C) mitgebrachte Nummern beim Import werden ohne Prüfung der Bedingung übernommen, dazu ein
Abschnitt „Nummern migrieren“ im Administratorhandbuch; eine Import-Option „Nummern nicht vergeben“ ist als Randfall
zurückgestellt (B-156); (D) Musikschule 1.6 mit `status <> 'entwurf'`, einem Entwurf im Seeder, „offen“ und den
Rechnungsauswertungen nur für versendete Rechnungen.

### 12.5 Umsetzung N3 (26.11.2026)

- **Kern:** `NumberCondition` (prüfen mit `FormulaAnalyzer`, Option `assign_when` mit Baum `assign_when_ast`,
  auswerten mit `Evaluator`), geprüft im `FieldDefinitionNormalizer` und damit auch in App-Definition und KI (die KI
  schlägt keine Bedingung vor: Das Feld `formula` des Vorschlags trägt schon das Muster).
- **Schreibweg:** `NumberAssigner::applyConditional` in `WritePhases::beforeSave` nach `RecordSaving`, vor den Formeln;
  gleiche Sperre und Transaktion. Import mit Nummer: übernommen ohne Prüfung der Bedingung.
- **Schema:** Umbenennen führt die Bedingung nach; verwendete Felder bleiben aktiv und werden kein Dateifeld.
  Behoben aus N1/N2: Ein geändertes Muster (Neubeginn, Geschäftsjahr) hebt die Zähler auf die höchste vorhandene
  passende Nummer; die Vorschau rechnet beim ungespeicherten Muster damit.
- **Nachnummerieren:** nur wo die Bedingung zutrifft; die übrigen werden gezählt, der nächste Block beginnt um ihre
  Anzahl versetzt (statt Keyset-Cursor: Reihenfolge über Datum, Anlegedatum und Schlüssel ist eindeutig, und
  `created_at` kann leer sein).
- **Oberfläche:** Formelfeld „Vergeben, wenn“ mit Prüfung beim Tippen, Bausteinen und Hilfe wie beim berechneten Feld;
  Hinweis im Datenformular; Bestätigung „Jetzt nummerieren“ mit Bedingung.
- **Musikschule 1.6:** wie in 12.4 (D); Aktualisierung 1.5 → 1.6 lässt vergebene Nummern stehen.
- **Tests:** `NumberConditionTest` (15 Fälle), Gleichzeitigkeit beim Ändern auf drei Datenbanken,
  `DemoSeedersTest` (1.5 → 1.6), Browser-Fall TDE-39.

### 12.6 Plan N4 (26.11.2026)

Entschieden 26.11.2026: (A) `{GROUP}` ist ein **Kurzzeichen je Wahl** (Option `group_codes` am Nummernkreis), Vorgabe der
gespeicherte Wert, wenn er eines sein kann; 1–10 Zeichen aus Buchstaben, Ziffern und `- / . _`. (B) Der Zähler hängt am
**Kurzzeichen**, nicht am Wert: Zurücklesen ohne Wissen um den Datensatz, geteilte Zähler gewollt, keine Doppel; ein
geändertes Kurzzeichen wirkt wie ein geändertes Muster (Zähler angehoben). (C) Mit Gruppe muss `{GROUP}` genau einmal im
Muster stehen, ohne Gruppe nie. (D) Das Gruppenfeld bleibt ein aktives Pflichtfeld vom Typ Auswahl (wie das
Bezugsdatum); neue Wahlen brauchen ein gültiges Kurzzeichen, Kurzzeichen entfernter Wahlen bleiben. (E) Die Nummer
bleibt fest, auch wenn sich die Gruppe später ändert; mit „Vergeben, wenn“ gilt die Gruppe bei der Vergabe. (F) Demo:
Gitarrenshop-Belege `{GROUP}-{YYYY}-{000}` mit Einnahme → `V`, Ausgabe → `E` (dieselben Nummern wie bisher).

### 12.7 Umsetzung N4 (26.11.2026)

- **Kern:** `NumberPattern` mit Platzhalter `{GROUP}`, Kurzzeichen (`groups`, längste zuerst beim Zurücklesen),
  `parse()` liefert die Gruppe; `NumberSequences::counters()`; `NumberAssigner` zieht je Kurzzeichen, ein Import hebt den
  Zähler des Kurzzeichens in der Nummer; `NumberAnalysis::key()` („V 2026“) für Vorschau, Umstellen und Zählerstände;
  `NumberCounter::set(…, group:)`.
- **Schema:** `FieldDefinitionNormalizer` prüft Gruppenfeld und Kurzzeichen und ergänzt fehlende; `DynamicSchemaService`
  sperrt Deaktivieren, Typwechsel, „freiwillig“ und Löschen des Gruppenfelds, führt Umbenennen nach, prüft neue Wahlen
  und trägt ihren Wert als Kurzzeichen nach.
- **App-Definition:** Nummernkreis nach seinem Gruppenfeld angelegt (`SchemaImporter`, `ChangeExecutor`), beim
  Aktualisieren Nummernkreise vor Auswahlfeldern geändert; geänderte Gruppe bzw. Kurzzeichen heikel (`number_group`).
  Die KI schlägt keine Gruppe vor.
- **Oberfläche:** Auswahl „Gruppe“, Tabelle „Kurzzeichen für {GROUP}“ (vorbelegt), Baustein `{GROUP}`, Vorschau je
  Kurzzeichen, Zählerstände „E · 2026“, „Zählerstand setzen“ mit Gruppe.
- **Gitarrenshop:** Belegnummer als Nummernkreis je Typ; der Seeder stellt bestehende Installationen um.
- **Tests:** `NumberGroupTest` (14 Fälle), `NumberPatternTest`, Gleichzeitigkeit in zwei Gruppen auf drei Datenbanken,
  `AppInstallerTest`, `AppUpdateTest`, `DemoSeedersTest`, Browser-Fall TDE-40.
