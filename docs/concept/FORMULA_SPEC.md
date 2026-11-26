# Spezifikation der Formelsprache

Stand: 23.11.2026 · Status: **verbindlich für Feld- und Auswertungskontext** (Fundament 2, F4; Backlog B-143) · Prüfung: `tests/Fixtures/formula-conformance.json` mit `FormulaConformanceTest` auf PostgreSQL, MySQL und MariaDB; `tests/Unit/FormulaSpecTest.php` vergleicht die Funktionsmatrix (Abschnitt 2) mit dem Code.

## 1. Zweck und Geltung

SAFT hat **eine** Formelsprache mit **einem** Parser (`App\DynamicData\Formula\Parser`), aber mehrere Auswertungswege:

| Kontext | Wo | Auswertung | Klasse |
|---|---|---|---|
| **Feld** | berechnete Felder des Table-Designers | PHP, exakte Dezimalarithmetik (bcmath) je Datensatz beim Speichern | `Formula\FormulaAnalyzer` (Prüfung), `Formula\Evaluator` |
| **Auswertung** | berechnete Werte und Spalten in Auswertungen | SQL, die Datenbank rechnet je Zeile bzw. Gruppe | `Reports\ReportExpression` mit `Reports\Dialects` |
| **Regel** (geplant, B-142) | Bedingungen und Werte von Regeln | PHP wie Feld, plus Datums- und Benutzerfunktionen | – |
| **Zeilenrecht** (geplant, B-020) | Einschränkung je Rolle | SQL über den Query Builder (`RowFilterCompiler`) | – |
| **Nummernkreis** (B-135) | Bausteine einer Nummer: keine Formel, sondern ein Muster mit Platzhaltern (`NUMBERING.md` Abschnitt 5); die Bedingung „Vergeben, wenn“ (N3) wird eine Formel wie Feld | PHP | `Numbering\NumberPattern` |

Wer dieselbe Formel als Feld und als Auswertungsspalte sieht, erwartet dasselbe Ergebnis. Diese Spezifikation legt die Bedeutung fest; die **PHP-Auswertung ist die Referenz**. Wo SQL abweicht, wird das SQL angepasst oder die Funktion für den Kontext gesperrt (Abschnitt 2). **Neue Funktionen und Operatoren werden zuerst hier eingetragen** – mit Kontext, Leer-Regel und Fällen in der Konformitätstabelle – und erst dann gebaut.

Nicht Teil dieser Sprache: die *Tabellenregeln* (`Rules\TableRule`, Vergleich zweier Operanden mit festem Operator) und die *Feldregeln* der Plugins. Beide bleiben eigene, kleine Strukturen; die Regel-Engine (B-142) nutzt für Bedingungen und Werte diese Formelsprache.

## 2. Funktionsmatrix

Die Tabelle ist der Vertrag; `tests/Unit/FormulaSpecTest.php` liest sie und vergleicht sie mit `FormulaAnalyzer::FUNCTIONS`, `FormulaAnalyzer::TEXT_FUNCTIONS`, `FormulaAnalyzer::AGGREGATES`, den Schlüsselwörtern und den in `ReportExpression` erlaubten Knoten. Spalten: Argumente (mindestens–höchstens), Kontexte (✔ erlaubt, – nicht erlaubt, ◇ geplant).

| Name | Argumente | Feld | Auswertung | Ergebnis | Leer-Regel |
|---|---|---|---|---|---|
| `+` `-` `*` `/` | 2 | ✔ | ✔ | Zahl | leer, sobald eine Seite leer; `/ 0` leer |
| `-x` (Vorzeichen) | 1 | ✔ | ✔ | Zahl | leer, wenn x leer |
| `=` `<>` `<` `<=` `>` `>=` | 2 | ✔ | – | 1 oder 0 | **0**, sobald eine Seite leer – auch bei `<>` |
| `and` `or` | 2 | ✔ | – | 1 oder 0 | leer gilt als 0; kurzgeschlossen |
| `round` | 1–2 | ✔ | ✔ | Zahl | leer, wenn x leer |
| `abs` | 1 | ✔ | ✔ | Zahl | leer, wenn x leer |
| `min` | 2–∞ | ✔ | ✔ | Zahl | leer, sobald ein Wert leer |
| `max` | 2–∞ | ✔ | ✔ | Zahl | leer, sobald ein Wert leer |
| `coalesce` | 2–∞ | ✔ | ✔ | Zahl | erster nicht leerer Wert; leer, wenn alle leer |
| `if` | 2–3 | ✔ | – | wie der gewählte Zweig | leere Bedingung gilt als 0; ohne „sonst“ leer |
| `contains` `startswith` `endswith` | 2 | ✔ | – | 1 oder 0 | 0, sobald eine Seite leer |
| `sum` `avg` `min` `max` (Aggregat) | 1–2 | ✔ | – | Zahl | `sum` ohne Kinder 0, übrige leer; leere Kindwerte zählen nicht |
| `count` (Aggregat) | 1–2 | ✔ | – | Zahl | ohne Kinder 0 |
| Plugin-Funktionen | je Plugin | ✔ | – | Zahl | leer, sobald ein Argument leer (Funktion wird nicht aufgerufen) |
| `today` `now` `days_between` `add_days` | 0 bzw. 2 | ◇ | ◇ | Datum/Zahl | – (B-142) |
| `changed` `old` | 1 | ◇ | – | 1/0 bzw. Wert | – (B-142, nur Regelkontext) |
| `user` `has_role` `me` `exists` | 0–2 | ◇ | ◇ | Verweis/1/0 | – (B-020, B-142) |

Die Funktionsnamen sind in allen Sprachen gleich und werden klein geschrieben; Gross-/Kleinschreibung im Quelltext spielt keine Rolle (`Round` = `round`).

## 3. Syntax

Grammatik (aus `Parser`):

```
ausdruck   = und ( 'or' und )*
und        = vergleich ( 'and' vergleich )*
vergleich  = summe [ ( '=' | '<>' | '<' | '<=' | '>' | '>=' ) summe ]
summe      = term ( ( '+' | '-' ) term )*
term       = vorzeichen ( ( '*' | '/' ) vorzeichen )*
vorzeichen = '-' vorzeichen | primär
primär     = Zahl | 'Text' | Name | Name '.' Name | Name '(' [ ausdruck ( ',' ausdruck )* ] ')' | '(' ausdruck ')'
```

- **Zahl:** Ziffern mit optionalem Dezimalpunkt (`12`, `0.081`). Kein Komma, kein Tausendertrennzeichen, kein Exponent. Negative Zahlen entstehen durch das Vorzeichen (`-5`).
- **Text:** in einfachen Anführungszeichen; ein Anführungszeichen im Text wird verdoppelt (`'l''Ourson'`). Text ist nur in Vergleichen und in den Textfunktionen erlaubt.
- **Name:** Buchstabe oder `_`, dann Buchstaben, Ziffern, `_`; bezeichnet ein Feld der Tabelle (technischer Name) oder eine Funktion. `tabelle.feld` ist ein Pfad in eine Kind-Tabelle (nur in Aggregaten).
- **Trennzeichen** in Funktionen: Komma; ein Semikolon gilt gleich (Auswertungen schreiben mit Semikolon, SAFT speichert mit Komma). Auswertungen schreiben Felder als `[Bezeichnung]` und erlauben `×`, `÷`, `−` als Rechenzeichen; der Editor setzt sie in `*`, `/`, `-` um.
- **Vorrang** (schwach nach stark): `or` · `and` · Vergleich · `+ -` · `* /` · Vorzeichen · Funktionsaufruf, Klammern. Vergleiche sind nicht verkettbar (`a < b < c` ist ein Fehler).
- **Grenzen:** höchstens 100 Knoten je Formel in Auswertungen (`ReportExpression::MAX_NODES`); `round` mit fester ganzer Stellenzahl 0–10.

## 4. Typen und Werte

| Typ | Herkunft | Darstellung in der Formel |
|---|---|---|
| **Zahl** | Ganzzahl, Dezimalzahl, berechnetes Feld, Zahl-Literal, Ergebnis von Rechnung, Vergleich und Logik | Dezimalzahl als Text, z. B. `12.35`, `-3`, intern ohne Rundungsfehler |
| **Wahrheitswert** | Ja/Nein-Feld, Vergleich, `and`/`or`, Textfunktionen | `1` (wahr) oder `0` (falsch); jede Zahl ungleich 0 gilt als wahr |
| **Text** | Text-, mehrzeiliges Text-, Auswahlfeld, Text-Literal | Zeichenkette; Auswahlfelder liefern den **gespeicherten Wert**, nicht die Bezeichnung |
| **Datum** | Datum, Datum und Uhrzeit | Text `JJJJ-MM-TT` bzw. `JJJJ-MM-TT HH:MM:SS` (Uhrzeit nur, wenn nicht 00:00:00); Vergleich als Text, damit chronologisch |
| **Verweis** | Verknüpfungsfeld | der Schlüssel des verknüpften Datensatzes als Zahl bzw. Text |
| **Leer** | leeres Feld, `null`, leerer Text, nicht numerischer Text an einer Zahl-Stelle | kein Wert |
| Datei | Dateifeld | **nicht** in Formeln erlaubt |

Rechnen (`+ - * /`, Zahlfunktionen) ist nur mit Zahl-Feldern erlaubt: Ganzzahl, Dezimalzahl, Ja/Nein (als 1/0). Vergleiche und Textfunktionen nehmen zusätzlich Text-, Auswahl-, Datums- und Verknüpfungsfelder. Ein Text-Literal ausserhalb eines Vergleichs ist ein Fehler bei der Prüfung; zur Laufzeit wäre es leer.

## 5. Leer

Leer breitet sich aus: Jede Rechnung, jedes Vorzeichen und jede Zahlfunktion (`round`, `abs`, `min`, `max`, Plugin-Funktionen) mit einem leeren Operanden ergibt leer. Ausnahmen:

- `coalesce(a, b, …)` liefert den ersten nicht leeren Wert – der einzige Weg, Leer zu ersetzen.
- Vergleiche mit einer leeren Seite sind **nicht erfüllt (0)**, auch `<>`. `feld <> 'x'` ist bei leerem Feld 0, nicht 1. Wer „leer oder ungleich“ meint, schreibt `coalesce(feld, 0) <> 1` bzw. prüft gezielt.
- `and`/`or` behandeln leer wie 0.
- `if(bedingung, dann, sonst)`: eine leere Bedingung gilt als 0 und wählt „sonst“; ohne „sonst“ ist das Ergebnis leer. Nur der gewählte Zweig wird gerechnet.
- Textfunktionen mit leerer Seite ergeben 0.
- In Aggregaten zählen Kind-Datensätze, deren Wert bzw. Ausdruck leer ist, nicht mit (wie `SUM` in SQL); `sum` und `count` ohne passende Kinder sind 0, `avg`, `min`, `max` leer.

Das Ergebnis eines berechneten Felds ist leer, wenn die Formel leer ergibt; die Spalte wird dann `NULL`.

## 6. Arithmetik und Genauigkeit

- Gerechnet wird **exakt dezimal** (bcmath), nicht mit Gleitkommazahlen. `0.1 + 0.2 = 0.3`.
- **Zwischenergebnisse** haben 12 Nachkommastellen; weitere Stellen werden **abgeschnitten** (nicht gerundet). Die Division `10 / 3` ergibt intern `3.333333333333`.
- **Rundung auf das Ziel** geschieht einmal am Ende: auf die Nachkommastellen des berechneten Felds bzw. der Spalte der Auswertung, kaufmännisch (Abschnitt 7). Ein Feld „Ganzzahl“ hat 0 Nachkommastellen.
- **Division durch 0** ergibt leer, kein Fehler.
- **Grosse Zahlen:** PHP rechnet beliebig lang; die Datenbank mit `numeric` (PostgreSQL) bzw. `DECIMAL(65,10)` (MySQL, MariaDB). Ergebnisse, die nicht ins Zielfeld passen, lehnt die Datenbank beim Speichern ab.

**Entscheidung 23.11.2026 (Fundament 2, F4):** In Auswertungen rechnet die Datenbank Zwischenergebnisse mit **mehr** Stellen als 12 (PostgreSQL `numeric` mit mindestens 16 Stellen, MySQL/MariaDB Dividend-Skala + 4). SQL wird nicht künstlich abgeschnitten. Abweichungen jenseits der zwölften Nachkommastelle sind hingenommen; sie werden nach der Rundung auf höchstens 10 Stellen nur sichtbar, wenn ein Zwischenergebnis genau auf einer Rundungsgrenze liegt und zugleich nicht abbrechend ist. Die Konformitätstabelle enthält solche Grenzfälle; wo einer sichtbar wird, entscheidet der Fall (SQL anpassen oder Fall als bekannte Abweichung markieren).

## 7. Rundung

- `round(x)` rundet auf ganze Zahlen, `round(x, n)` auf n Nachkommastellen, n fest zwischen 0 und 10.
- **Kaufmännisch, ab 5 weg von 0** (Half away from zero): `round(2.5) = 3`, `round(-2.5) = -3`, `round(2.675, 2) = 2.68`, `round(1.005, 2) = 1.01`. Weil dezimal gerechnet wird, gibt es keine Überraschungen wie bei binären Gleitkommazahlen.
- Die Datenbanken runden `numeric`/`DECIMAL` gleich (PostgreSQL `ROUND(numeric)`, MySQL/MariaDB `ROUND(DECIMAL)`); `float` würden sie anders runden, darum werden Spalten in Auswertungen vorher auf `numeric` bzw. `DECIMAL` umgewandelt (`QueryDialect::decimal()`).
- Die Rundung auf das Zielfeld folgt derselben Regel. Zwischenergebnisse werden nicht gerundet, nur abgeschnitten (Abschnitt 6).

## 8. Vergleiche und Logik

- **Zahl gegen Zahl** (beide Seiten als Zahl lesbar): exakt dezimal. `'10' = 10` ist wahr, `1.0 = 1` ist wahr.
- **Sonst Text gegen Text**: byteweiser Vergleich (`strcmp`), **gross-/kleinschreibungsabhängig**, ohne Kollation der Datenbank. `'Abc' = 'abc'` ist 0. Datums-Texte `JJJJ-MM-TT` vergleichen damit chronologisch.
- **Leer** auf einer Seite: 0 (Abschnitt 5).
- Auswahlfelder vergleichen den gespeicherten Wert (`status = 'offen'`), Ja/Nein-Felder 1/0 (`bezahlt = 1`, kürzer: `bezahlt`), Verknüpfungen den Schlüssel.
- `and`/`or` sind **kurzgeschlossen**: Bei `a and b` wird b nicht gerechnet, wenn a 0 ist; bei `a or b` nicht, wenn a wahr ist. Das Ergebnis ist 1 oder 0.
- **Wahr** ist jede Zahl ungleich 0; leer ist falsch.
- `contains(text, teil)`, `startswith(text, anfang)`, `endswith(text, ende)`: **ohne** Gross-/Kleinschreibung (`mb_strtolower`), Teiltext als Text-Literal oder Feld; beide Seiten dürfen Text-, Auswahl-, Datums- oder Zahl-Felder sein.

In Auswertungen gibt es Vergleiche, Logik, `if` und Textfunktionen heute nicht (Matrix). Sie kommen mit den Zeilenrechten (B-020) als SQL-Übersetzung mit denselben Regeln: Textvergleich binär (`COLLATE`/`BINARY` je Dialekt), Leer ergibt 0 (`COALESCE(…, 0)` um den Vergleich), `contains` über `LOWER(...) LIKE`.

## 9. Aggregate (Feldkontext)

- `sum(kind.feld)`, `avg(kind.feld)`, `min(kind.feld)`, `max(kind.feld)`, `count(kind)` über die Kind-Datensätze, die per Verknüpfung auf den Datensatz zeigen. Hat die Kind-Tabelle mehrere Verknüpfungen auf diese Tabelle, nennt der zweite Wert die gewünschte: `sum(positionen.betrag, bestellung_id)`.
- Statt eines Felds darf ein **Ausdruck je Kind-Datensatz** stehen: `sum(positionen.menge * positionen.preis)`, `sum(if(positionen.art = 'Zoll', positionen.betrag, 0))`, `sum(positionen.menge > 10)`. Darin nur Felder dieser einen Kind-Tabelle, keine Felder des Master-Datensatzes, keine verschachtelten Aggregate. Ein Feld summiert die Datenbank; einen Ausdruck rechnet PHP je Kind-Datensatz exakt und fasst dann zusammen.
- **Gelöschte** Kind-Datensätze (Papierkorb) zählen nicht.
- Leere Werte zählen nicht; `sum` und `count` ohne Kinder 0, `avg`/`min`/`max` leer (Abschnitt 5).
- `min`/`max` mit zwei oder mehr Werten sind die normalen Zahlfunktionen; mit einem Pfad das Aggregat.
- Über die **eigene Tabelle** (Baum) sind berechnete Felder und das Feld selbst ausgeschlossen (Kreis).
- Nachführung: beim Anlegen, Ändern, Löschen und Wiederherstellen eines Kind-Datensatzes rechnet SAFT den alten und den neuen Master-Datensatz neu, und weiter nach oben (`Recalculator::propagate`, höchstens 20 Ebenen).

## 10. Plugin-Funktionen

- Ein Plugin liefert `FormulaFunction` mit Name, Argumentzahl und Auswertung (`SaftPlugin::formulaFunctions()`). Nur im **Feldkontext**; Auswertungen kennen sie nicht.
- Argumente und Ergebnis sind **Zahlen als Text**; ist ein Argument leer, wird die Funktion nicht aufgerufen und das Ergebnis ist leer. Ein Fehler in der Funktion ergibt leer und einen Eintrag im Log; das Speichern scheitert nicht.
- Fehlt das Plugin oder ruht es, ist das Ergebnis leer.
- Namen: Kleinbuchstaben, Ziffern, `_`; eingebaute Namen und die reservierten Namen aus Abschnitt 2 lassen sich nicht belegen.

## 11. Reservierte Namen (noch nicht gebaut)

| Name | Für | Kontext | Bedeutung |
|---|---|---|---|
| `today()`, `now()` | B-142 | Feld, Regel, Auswertung | Datum bzw. Zeitpunkt in der Zeitzone der Anwendung, als Datums-Text |
| `days_between(a, b)` | B-142 | Feld, Regel, Auswertung | ganze Tage von a bis b (negativ, wenn b vor a) |
| `add_days(datum, n)` | B-142 | Feld, Regel, Auswertung | Datum plus n Tage |
| `changed(feld)`, `old(feld)` | B-142 | Regel | hat sich das Feld in dieser Änderung geändert; Wert vor der Änderung |
| `user()` | B-020, B-142 | Regel, Zeilenrecht | Schlüssel der angemeldeten Person (Feldtyp „Benutzer“) |
| `has_role('name')` | B-142 | Regel | 1, wenn die auslösende Person die Rolle hat |
| `me('tabelle')` | B-020 | Zeilenrecht | Datensätze, die über ein Benutzer-Feld zur Person gehören |
| `exists(kind, bedingung)` | B-020 | Zeilenrecht | 1, wenn ein Kind-Datensatz die Bedingung erfüllt |

Wer eine davon baut, ergänzt zuerst die Matrix (Abschnitt 2), die Leer-Regel und Fälle in der Konformitätstabelle; `FormulaSpecTest` und `FormulaConformanceTest` sichern das ab.

## 12. Konformitätstabelle

`tests/Fixtures/formula-conformance.json` enthält Fälle der Form

```json
{"formula": "round(a / b, 2)", "fields": {"a": "10", "b": "3"}, "expected": "3.33", "scale": 2, "contexts": ["field", "report"], "note": "Division, Rundung"}
```

- `fields`: Werte der Hilfstabelle `formel_konformitaet` (Spalten `a`, `b`, `c` Dezimal 18,6; `i` Ganzzahl; `t` Text; `s` Auswahl; `d` Datum; `j` Ja/Nein). Fehlende Felder sind leer.
- `expected`: erwartetes Ergebnis nach Rundung auf `scale` Nachkommastellen (Vorgabe 2), `null` für leer.
- `contexts`: `field` (PHP über `FormulaAnalyzer` und `Evaluator`), `report` (SQL über `ReportExpression::sql()` auf der Hilfstabelle, Rundung durch die Datenbank). Vorgabe: beide, wenn die Formel in Auswertungen erlaubt ist.
- `FormulaConformanceTest` rechnet jeden Fall in jedem genannten Kontext und vergleicht exakt als Dezimalzahl. Er läuft in der CI auf PostgreSQL, MySQL und MariaDB.

Regel: Jede Änderung an `Evaluator`, `ReportExpression` oder einem Dialekt, die ein Ergebnis verändert, braucht einen Fall, der sie zeigt.
