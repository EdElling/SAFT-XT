# Konzept: Nummernkreise in SAFT XT

Stand: 23.11.2026 · Status: **erster Vorschlag zur Diskussion** · Backlog: B-135

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
| „Fortlaufend vorschlagen“ (`SequenceSuggester`) | Für Ganzzahlfelder: höchster Wert + 1, optional je Wert einer Verknüpfung (`suggest_scope`); als Vorschlag im Formular, beim Anlegen ohne Wert gesetzt | Kein Muster, kein Neubeginn. Die gelöschte höchste Nummer wird wieder vergeben. Gleichzeitiges Anlegen kann dieselbe Nummer ergeben; das verhindert nur ein eindeutiger Index, und dann scheitert das Speichern. Der Wert bleibt änderbar. |
| Eindeutig (`is_unique`) | Eindeutiger Index in der Datenbank | Verhindert Doppel, vergibt aber nichts |
| Musikschule-Demo | `rechnungsnummer` als Textfeld, vom Seeder als `R-2026-001` befüllt | Neue Rechnungen bekommen keine Nummer |

„Fortlaufend vorschlagen“ **bleibt** für den einfachen Fall: Die Nummer ist nur ein Vorschlag und bleibt änderbar,
z. B. die Positionsnummer in einer Rechnung oder die Reihenfolge von Lektionen.

## 3. Entscheidungen (Vorschlag)

| Frage | Vorschlag |
|---|---|
| Form | **Eigener Feldtyp „Nummernkreis“** (`FieldType::Number`, Wert `number`) statt einer Eigenschaft an Text- oder Zahlfeldern. Wie bei „Berechnet“ erzeugt SAFT den Wert; das Feld ist immer schreibgeschützt, eindeutig und hat eigene Einstellungen. |
| Gespeicherter Wert | Die **formatierte Nummer als Text** (`string`, Länge aus dem Muster, mindestens 30). Die laufende Nummer wird mit Nullen aufgefüllt; so sortieren die Nummern innerhalb eines Musters richtig. |
| Zähler | In einer eigenen Tabelle **`number_sequences`** (Feld, Periode, Gruppe, letzter Wert), nicht als höchster Wert der Tabelle. Damit werden gelöschte Nummern nie wieder vergeben. |
| Gleichzeitigkeit | Zähler **gesperrt lesen** (`lockForUpdate()` im Query Builder) und erhöhen, in **derselben Transaktion** wie das Anlegen des Datensatzes. Scheitert das Speichern, rollt auch der Zähler zurück, und es entsteht keine Lücke. |
| Eindeutigkeit | Zusätzlich ein **eindeutiger Index** auf der Spalte, als letzte Sicherung, z. B. falls ein Import eine Nummer mitbringt. |
| Vergabe | **Beim Anlegen** (Etappe N1). **Erst bei einer Bedingung**, z. B. Status = *definitiv*, ab Etappe N3. |
| Änderbar | **Nie**, sobald vergeben. Die Oberfläche zeigt die Nummer nur an; Plugin-API, Import und Regeln können sie nicht überschreiben. |
| Platzhalter im Muster | **Sprachneutral** (`{YYYY}`, `{MM}`, `{0000}`), weil SAFT vier Sprachen hat. Im Formular stehen sie als Bausteine mit übersetzter Beschriftung. Die Schreibweise aus dem Backlog (`{JJJJ}`) wird als Alias angenommen. |

## 4. Einstellungen des Felds

| Einstellung | Bedeutung | Beispiel |
|---|---|---|
| **Muster** | Fester Text und Platzhalter (Abschnitt 5) | `R-{YYYY}-{0000}` |
| **Neubeginn** | nie, jährlich, monatlich | jährlich |
| **Bezugsdatum** | Anlegedatum (Vorgabe) oder ein Datumsfeld der Tabelle; bestimmt Jahr und Monat im Muster und die Periode | Feld `datum` (Rechnungsdatum) |
| **Geschäftsjahr beginnt im** | Monat 1–12 (Vorgabe Januar); nur bei jährlichem Neubeginn | Juli: `{YYYY}` = Jahr, in dem das Geschäftsjahr beginnt |
| **Startwert** | erste Nummer je Periode (Vorgabe 1) | 1 |
| **Gruppe** (Etappe N4) | eigener Zähler je Wert eines Auswahlfelds | je Belegart |
| **Vergeben, wenn** (Etappe N3) | Bedingung als Formel; leer = beim Anlegen | `status = 'definitiv'` |

Im Formular steht unter dem Muster eine **Vorschau** mit der nächsten Nummer, z. B. „Nächste Nummer: R-2026-0043“.

Ist ein **Datumsfeld** das Bezugsdatum, muss es beim Vergeben gefüllt sein. Der Table-Designer macht es deshalb zum
Pflichtfeld bzw. verlangt das.

## 5. Muster

| Platzhalter | Ergebnis | Hinweis |
|---|---|---|
| `{YYYY}` | 2026 | Jahr des Bezugsdatums bzw. Beginn des Geschäftsjahrs |
| `{YY}` | 26 | |
| `{MM}` | 11 | |
| `{0000}` | 0042 | laufende Nummer, so viele Stellen wie Nullen. Bei Überlauf wird die Nummer länger (`10000`) und nie abgeschnitten. |
| `{#}` | 42 | laufende Nummer ohne Auffüllen; wird dann nicht mehr richtig als Text sortiert |
| `{GROUP}` (N4) | AN | Wert bzw. Kurzzeichen der Gruppe |
| übriger Text | `R-`, `/` | wörtlich |

**Prüfung des Musters** (gleiche Prüfung im Table-Designer, in der App-Definition und für Vorschläge der KI):

- genau **ein** Platzhalter für die laufende Nummer
- bei jährlichem Neubeginn ein Jahr (`{YYYY}` oder `{YY}`), bei monatlichem Jahr und Monat, mit Gruppe `{GROUP}`.
  Sonst ergäbe der Neubeginn doppelte Nummern.
- höchstens 50 Zeichen Ergebnis
- erlaubte Zeichen: Buchstaben, Ziffern, `- / . _` und Leerzeichen. Für die QR-Rechnung zählen nur die Ziffern
  (Abschnitt 9).

## 6. Vergabe

### 6.1 Ablauf

Beim Anlegen eines Datensatzes (`creating`) mit einem Feld vom Typ Nummernkreis, dessen Wert leer ist:

1. Periode bestimmen: `2026` bzw. `2026-11` aus dem Bezugsdatum, `-` ohne Neubeginn.
2. Zeile in `number_sequences` für Feld, Periode und Gruppe gesperrt lesen. Gibt es noch keine, wird sie mit dem
   Startwert − 1 angelegt. Legen zwei Vorgänge gleichzeitig die erste Zeile an, scheitert einer am eindeutigen
   Schlüssel; dieser liest dann erneut.
3. Wert + 1 schreiben, Nummer nach dem Muster formatieren und im Datensatz setzen.

Das geschieht vor den Formeln und vor den Ereignissen für Plugins und Regeln. Diese sehen die Nummer also schon.

### 6.2 Transaktion

Zähler und Datensatz müssen in **einer Transaktion auf derselben Verbindung** liegen. Darum liegt `number_sequences`
in der **Verbindung der Datentabellen** (`dynamic_data.connection`) und nicht in der Verwaltungsdatenbank. Die
Migration legt die Tabelle dort an, wie die anderen Tabellen der Datenschicht.

Nicht jeder Speicherweg öffnet heute eine Transaktion, z. B. das Formular der Datenansicht. Für Tabellen mit
Nummernkreis-Feld schliesst SAFT das Anlegen deshalb selbst in eine Transaktion ein (`DynamicRecord`). Die Regel-Engine
braucht denselben Baustein (Konzept Regel-Engine, Abschnitt 7.1); er wird **einmal** gebaut.

Die Sperre gilt nur für die eine Zeile des Zählers und nur bis zum Ende der Transaktion. Bei einem Import mit vielen
Datensätzen hält eine einzige Transaktion die Sperre über den ganzen Import. Das ist gewollt: Gleichzeitige
Anlagen warten, bis der Import fertig ist.

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
dem die Formel wahr ist, z. B. `status = 'definitiv'`. Danach gilt die Nummer für immer, auch wenn der Status später
zurückgeht. Als Bezugsdatum gilt dann der Zeitpunkt der Vergabe bzw. das gewählte Datumsfeld zu diesem Zeitpunkt.

Die Bedingung nutzt die Formelsprache (wie Tabellenformeln und Regeln) und hängt **nicht** von der Regel-Engine ab.
Die Regel-Engine braucht daher keine eigene Aktion „Nummer ziehen“.

## 7. Zusammenspiel

| Bereich | Verhalten |
|---|---|
| **Formular** | Nur Anzeige; vor dem Speichern „wird beim Speichern vergeben“ |
| **Datenansicht** | Text: Sortieren, Suchen und Filtern wie bei Text |
| **Plugin-API** (`DataTable::create`) | vergibt automatisch. Ein mitgegebener Wert wird abgelehnt, ausser beim Import (unten). `update` lehnt eine Änderung ab. |
| **Import** | **Ohne** Wert: Nummer wird vergeben. **Mit** Wert (z. B. Altdaten): wird übernommen, wenn eindeutig. Passt er zum Muster, wird der Zähler seiner Periode auf mindestens diesen Wert angehoben, sodass spätere Nummern nicht kollidieren. Werte, die nicht zum Muster passen, werden übernommen und im Ergebnis des Imports gemeldet. |
| **Datensatz kopieren** (`RecordCopier`) | Die Kopie bekommt eine neue Nummer, ausser bei Vergabe bei Bedingung. |
| **Formeln, Auswertungen, Regeln** | Wert vom Typ Text (`valueType` = String) |
| **Dokumentvorlagen** | Platzhalter wie jedes Feld (`{{rechnungsnummer}}`) |
| **Änderungsprotokoll** | Die Vergabe erscheint als normale Änderung des Felds durch die auslösende Person. |
| **App-Definition** | Exportiert werden die Einstellungen (Muster, Neubeginn, Bezugsdatum über den stabilen Schlüssel), **nicht** die Zählerstände. Eine Installation (auch als Kopie) beginnt beim Startwert. Ein Aktualisieren, das das Muster ändert, gilt als **heikel**: Vorhandene Nummern bleiben, neue erscheinen im neuen Format, und die Prüfung der Eindeutigkeit läuft mit. |
| **Demo-Reset, „App-Daten zurücksetzen“** | Die Zähler der betroffenen Felder werden mit den Daten zurückgesetzt. |
| **KI** (Tabellen-Assistent, App-Designer) | kennen den Typ mit Muster und Neubeginn und schlagen ihn für Beleg-Nummern vor. Der `DraftCompiler` prüft das Muster. |

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
Schutzlogik des Table-Designers für Typwechsel (`SAFE_CONVERSIONS`, `fieldChangeProblem`).

**Zählerstand ansehen und setzen:** Im Feldformular steht je Periode (und Gruppe) der letzte Wert. Ihn zu **erhöhen**
ist jederzeit möglich, z. B. „Rechnungen 2026 beginnen bei 1000“. Ihn zu **senken** ist nur möglich, wenn keine
vorhandene Nummer der Periode höher ist, und erfordert eine Bestätigung. Jede Änderung steht im Änderungsprotokoll.

## 9. QR-Rechnung

Die QR-Rechnung (B-124 D4a) bildet eine RF- bzw. QR-Referenz aus einem Feld. Bei einer Nummer wie `R-2026-0042`
zählen dafür nur die Ziffern (`20260042`), und die QR-Referenz ergänzt die Prüfziffer. Zu prüfen ist, ob D4a das
heute schon so macht; sonst wird es mit N1 angepasst.

## 10. Etappen

| Etappe | Inhalt | Umfang |
|---|---|---|
| **N1 Feldtyp** | `FieldType::Number`, Einstellungen (Muster, Neubeginn jährlich oder monatlich, Bezugsdatum, Geschäftsjahr, Startwert), Prüfung des Musters, Vorschau „nächste Nummer“, Tabelle `number_sequences` in der Datenverbindung, Vergabe beim Anlegen mit Sperre in einer Transaktion, eindeutiger Index, Schreibschutz (Oberfläche, Plugin-API), Import mit und ohne Wert, Kopieren, App-Definition, Demo-Reset bzw. App-Daten zurücksetzen, KI-Assistenten, Handbücher in vier Sprachen. Test der Gleichzeitigkeit auf allen drei Datenbanken | mittel |
| **N2 Umstellen** | Typwechsel von Text, Ganzzahl und Auswahl mit Vorschau, „jetzt nummerieren“, Rückweg zu Text; Zählerstand ansehen und setzen; Musikschule-Demo: `rechnungsnummer` als Nummernkreis `R-{YYYY}-{000}` | mittel |
| **N3 Vergabe bei Bedingung** | „Vergeben, wenn“ als Formel; Vergabe beim ersten Speichern, bei dem sie wahr ist | klein |
| **N4 Gruppen** | eigener Zähler je Wert eines Auswahlfelds, Platzhalter `{GROUP}` | klein |

Am meisten Nutzen bringen N1 und N2 zusammen. Mit beiden ist die Musikschule-Rechnung vollständig, und der
Rechnungslauf der Regel-Engine hat seine Voraussetzung.

## 11. Offene Fragen zur Diskussion

1. **Name des Feldtyps:** „Nummernkreis“ (Fachbegriff aus dem ERP-Umfeld) oder „Belegnummer“ bzw. „Laufnummer“?
   In den anderen Sprachen: *Number sequence*, *Plage de numéros*, *Intervallo di numerazione*.
2. **Gemeinsamer Kreis für mehrere Tabellen,** z. B. Rechnungen und Gutschriften mit einer Nummernfolge: Vorschlag:
   vorerst nicht. Später ginge es als eigenes Objekt „Nummernkreis“ (Administration der App), auf das mehrere Felder
   verweisen. Die Tabelle `number_sequences` würde dafür statt am Feld an einem Kreis hängen; mit einem Fremdschlüssel
   auf den Kreis wäre die Umstellung später machbar.
3. **Zählerstand senken:** zulassen wie vorgeschlagen (nur ohne Konflikt) oder nie?
4. **Import mit Nummern** in eine Tabelle mit Nummernkreis: immer übernehmen (Vorschlag) oder nur mit einem
   ausdrücklichen Schalter „Nummern aus der Datei übernehmen“?
5. **Gruppe aus einer Verknüpfung** (z. B. Kürzel der Filiale aus der verknüpften Filiale) zusätzlich zum Auswahlfeld?
   Vorschlag: erst bei Bedarf.
