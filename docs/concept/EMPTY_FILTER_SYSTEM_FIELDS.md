# Konzept: Filter „leer/gefüllt“ in der Datenansicht und Systemspalten in Auswertungen

Stand: 28.11.2026 · Status: **entschieden (§3, §4: Empfehlungen angenommen 28.11.2026) und umgesetzt 28.11.2026 (§6), Release 0.10.0 offen** · Backlog: B-158 (Filter leer bzw. gefüllt),
B-183 (Systemspalten in Auswertungen) · Bezug: B-159 (8dccfd5), BULK_EDIT.md §14 (vorübergehende Ansicht),
AI_AGENTS.md V5 und §12, ARCHITECTURE Nr. 1, 3, 24, 25

## 1. Ziel

- **B-158:** In der Datenansicht lassen sich Datensätze finden, bei denen ein Feld **leer** bzw. **gefüllt** ist,
  z. B. Rechnungen ohne Nummer („Vergeben, wenn“), Buchungen ohne Beleg, Datensätze ohne „angelegt von“. Die Bedeutung
  ist dieselbe wie `is_empty`/`not_empty` der Auswertungen, damit die Filterdefinition für Sprunglinks (AI_AGENTS.md V5)
  später nur ein Übersetzer ist.
- **B-183:** Angelegt/geändert am und von (B-159) gibt es auch in Auswertungen: als Listenspalte, Gruppe (auch mit
  Datumsstufe), Wert (Anzahl, Minimum, Maximum) und Filter, mit Namen statt Benutzer-ID.

Die beiden gehören zusammen: Beide brauchen dieselbe Antwort auf „was ist leer?“ und „wie filtert man nach einer
Person?“, und „angelegt von“ ist das Feld, das in Datenansicht und Auswertung gleich aussehen soll.

## 2. Befund im Code (28.11.2026)

**Datenansicht**

- `DynamicTableBuilder::filters()` baut je aktivem Feld einen Filter nach `valueType()`: Text und mehrzeiliger Text
  (auch Nummernkreise) „enthält“; Zahlen (auch berechnete) und Datum „von/bis“; Auswahl als Mehrfachauswahl;
  Verknüpfung als durchsuchbare Einfachauswahl (200 Einträge); Ja/Nein als Ja/Nein; Dateien „mit/ohne Dateien“
  (`TernaryFilter::nullable()`, also schon leer/gefüllt). Systemspalten: Zeitraum bzw. Person (`UserNames::all()`).
- Die Filter gibt es nur auf der Datenansicht (`DynamicDataPage`), nicht in Detailtabellen.
- Der Zustand der Filter liegt je Person in `user_table_states` (`RemembersTableState`); die vorübergehende Ansicht
  (BULK_EDIT.md §14) speichert nichts.
- Ja/Nein: „Nein“ ist `= false` und findet Datensätze mit NULL nicht (z. B. vor dem Anlegen des Felds entstandene,
  importierte). Solche Datensätze findet heute kein Filter.

**Auswertungen**

- `ReportQuery::filter()`: `is_empty` = NULL, bei Art `text` NULL oder Leertext; `not_empty` das Gegenteil. Beide gibt
  es für jede Art (`DefinitionResolver::ANY_OPERATORS`).
- Felder stehen in der Definition als `{key, path, field, bucket, label}` mit `field` = ID des `CustomField`; der
  Editor referenziert sie als Text `"57/63:31"` (`FieldCatalog::ref()`/`parse()`, beides Ganzzahlen). Die App-Definition
  `saft-app/1` schreibt stattdessen `tabelle.feld` (`References`, `DefinitionParts::report()`, `AppInstaller`).
- Die Abfrage läuft mit dem Query Builder auf der Verbindung `app_data` (`ReportQuery`), **nicht** über
  `DynamicRecord`; Auswertungen stehen deshalb nicht in `read-sites.json`.
- Verknüpfungsfelder zeigen Namen über eine Zuordnung Schlüssel → Anzeige am `ReportColumn` (`withDisplay()`), gefüllt
  in `ReportResult::from()`; `ValueFormatter`, Kreuztabelle, Diagramm und Export lesen sie von dort.
- **Datenstände** gibt es nur für angebundene Tabellen (`ds_<tabelle>`); Designer-Tabellen werden immer live gelesen.
  Angebundene Tabellen haben keine Systemspalten (`SystemFields::for()` liefert nichts).
- **Schnittstellen** anderer Apps geben einzelne Felder frei (`AppInterfaces::fieldShared()`); Systemspalten gehören
  nicht dazu.
- Die Namen `created_at`, `updated_at`, `created_by`, `updated_by` sind für Felder gesperrt
  (`IdentifierValidator::SYSTEM_COLUMNS`); `tabelle.created_at` ist als Verweis also eindeutig.
- Der KI-Assistent der Auswertungen bekommt das Datenmodell aus `FieldCatalog::structure()` (nur Struktur) und
  antwortet mit Feldreferenzen wie der Editor (`ProposalSchema`).

## 3. B-158: Fragen mit Empfehlung

1. **Für welche Felder?** *Empfehlung:* für alle, bei denen „leer“ vorkommen kann und noch nicht filterbar ist:

   | Feld | heute | neu |
   |---|---|---|
   | Text, mehrzeiliger Text, Nummernkreis | enthält | + leer/gefüllt |
   | Ganzzahl, Dezimalzahl, berechnetes Feld | von/bis | + leer/gefüllt |
   | Datum, Datum/Uhrzeit | von/bis | + leer/gefüllt |
   | Auswahl | Mehrfachauswahl | + Einträge „(leer)“ und „(gefüllt)“ |
   | Verknüpfung | Einfachauswahl | + Einträge „(leer)“ und „(gefüllt)“ |
   | Ja/Nein | Ja / Nein | + „(leer)“ (Frage 4) |
   | Datei | mit/ohne Dateien | unverändert (ist schon leer/gefüllt) |
   | angelegt/geändert von | Person | + „(leer)“ und „(gefüllt)“ (Datensätze aus Import, Aufgaben, Seed) |
   | angelegt/geändert am | Zeitraum | unverändert (nie leer) |

   **Nicht für Pflichtfelder einer Designer-Tabelle** (geändert bei der Durchsicht der Oberfläche, 28.11.2026): Sie
   sind nie leer, und der Filterdialog wird sonst unnötig lang. Bei angebundenen Tabellen bleibt der Filter auch für
   Pflichtfelder, weil sie sich nicht an SAFTs Pflicht halten.

2. **Eigener Filter oder im bestehenden?** *Empfehlung:* **im bestehenden Filter je Feld**, in zwei Formen:
   - Filter mit Eingabe (Text, Zahl, Datum): eine zusätzliche Auswahl **„Inhalt“** mit *alle* (Vorgabe) · *nur leere* ·
     *nur gefüllte* neben den vorhandenen Eingaben.
   - Filter mit Auswahlliste (Auswahl, Verknüpfung, Person, Ja/Nein): zwei bzw. ein zusätzlicher Eintrag zuoberst in
     der Liste, **„(leer)“** und **„(gefüllt)“**.

   *Warum nicht ein eigener Filter* „Leer: [Felder …] / Gefüllt: [Felder …]“ für die ganze Tabelle: Er wäre ein zweiter
   Ort für dasselbe Feld, und wer „Rechnungsnummer“ filtern will, sucht beim Feld. Der Preis der Empfehlung: Das
   Filterformular wird je Eingabe-Feld um ein Element länger.

3. **Zusammenspiel mit den übrigen Eingaben desselben Felds.** *Empfehlung:* schlicht **UND**, ohne Ausblenden:
   „nur gefüllte“ + „enthält 2026“ ist „enthält 2026“; „nur leere“ + „enthält 2026“ ergibt nichts. In der
   Mehrfachauswahl gilt wie bisher ODER zwischen den Einträgen: „(leer)“ + „offen“ = leer oder offen; „(gefüllt)“
   allein = alle mit Wert. Keine Sonderregeln, die man erklären müsste.

4. **Was ist leer?** *Empfehlung:* genau wie `ReportQuery`:
   - Text, mehrzeiliger Text, Nummernkreis, Auswahl: **NULL oder Leertext `''`**. Nur Leerzeichen gilt als gefüllt
     (wie in den Auswertungen; ein `trim` in der Abfrage nähme den Index).
   - Zahl, Datum, Verknüpfung, Person: NULL. `0` ist gefüllt.
   - **Verknüpfung:** leer = kein Schlüssel. Zeigt der Schlüssel auf einen Datensatz im Papierkorb, gilt das Feld als
     gefüllt (die Spalte zeigt ihn ja an).
   - **Datei:** bleibt „mit/ohne Dateien“ (SAFT speichert leere Dateifelder als NULL).
   - **Ja/Nein:** „Ja“ und „Nein“ bleiben `= true` bzw. `= false` wie `is_true`/`is_false`; neu **„(leer)“** = NULL.
     Die Alternative, „Nein“ auch NULL finden zu lassen, wiche von den Auswertungen ab und V5 wäre kein reiner
     Übersetzer mehr. Technisch wird aus dem Ja/Nein-Filter eine Auswahl mit drei Einträgen.

5. **Hinweis-Kärtchen.** *Empfehlung:* „Rechnungsnummer: leer“ bzw. „Rechnungsnummer: gefüllt“, zusätzlich zum
   Kärtchen der Eingabe, falls beides gesetzt ist. Entfernen über das Kärtchen wie gewohnt.

6. **Gespeicherter Zustand und vorübergehende Ansicht.** *Empfehlung:* nichts Besonderes. Der neue Zustand liegt im
   Filterzustand des Felds und wird wie die anderen gespeichert; gespeicherte Zustände ohne den neuen Schlüssel
   bedeuten „alle“ (keine Migration). In der vorübergehenden Ansicht gilt er wie jeder Filter und wird nicht
   gespeichert.

7. **Angebundene Tabellen.** *Empfehlung:* dieselben Filter, auch bei Wertelisten aus einer CHECK-Regel. *Zu prüfen
   bei der Umsetzung:* SQL Server vergleicht die alten Typen `text`/`ntext` nicht mit `=`; falls der Table-Connector
   solche Spalten als mehrzeiligen Text anbindet, prüft „leer“ dort nur NULL.

8. **Folgen ohne Zutun.** Export, „Auswahl exportieren“ und Sammeländerung arbeiten auf der gefilterten Abfrage:
   „alle ohne Rechnungsdatum auswählen → Ändern …“ geht damit sofort. Detailtabellen haben keine Filter und bekommen
   keine.

9. **Filterdefinition (V5).** *Empfehlung:* jetzt **kein** Übersetzer und kein URL-Parameter; nur die Semantik und
   eine Zuordnung im Konzept: `is_empty`/`not_empty` ↔ „Inhalt“ bzw. die Einträge „(leer)“/„(gefüllt)“, `is_true`/
   `is_false` ↔ Ja/Nein. Der Übersetzer kommt mit A1 (AI_AGENTS.md).

10. **Lesestellen, Rechte, Plugin-API.** Gefiltert wird die Abfrage der Datenansicht (`scope: person`, schon
    eingetragen); keine neue Lesestelle, kein neues Recht, keine Änderung der Plugin-API, keine Migration.

## 4. B-183: Fragen mit Empfehlung

1. **Wie steht eine Systemspalte in der Definition?** *Empfehlung:* `field` bleibt die Feld-ID; eine Systemspalte hat
   `field: 0` und zusätzlich **`system: "created_at"`** (einer der vier Namen). Der Pfad bleibt wie bei Feldern, eine
   Systemspalte der verknüpften Tabelle („Kunde › angelegt am“) geht also auch.
   - Editor-Referenz: `"57/63:created_at"` bzw. `":created_at"` (Name statt Nummer hinter dem Doppelpunkt).
   - App-Definition `saft-app/1`: `tabelle.created_at`, wie ein Feld; eindeutig, weil die Namen gesperrt sind. Kein
     neues Format, `interface_version` bleibt.
   - Bestehende Auswertungen bleiben unverändert gespeichert (`system` nur, wenn gesetzt).

   *Warum nicht negative oder reservierte Nummern:* Negative Nummern bedeuten im Pfad schon „Verknüpfung für
   Auswertungen“, und eine Nummer ohne Zeile in `custom_fields` wäre in jeder Stelle, die Feld-IDs liest, ein
   Sonderfall ohne Namen.

2. **Wo gibt es Systemspalten?** *Empfehlung:* bei jeder **Designer-Tabelle** der Auswertung (Grundtabelle und
   verknüpfte). **Nicht** bei angebundenen Tabellen (es gibt die Spalten nicht, also auch nicht im Datenstand) und
   **nicht** hinter einer Schnittstelle einer anderen App (dort gelten nur freigegebene Felder; wer wann in der
   fremden App geändert hat, ist nicht freigegeben). Im Editor stehen sie je Tabelle am Ende der Feldliste.

3. **Angelegt/geändert am.** *Empfehlung:* wie ein Feld vom Typ Datum/Uhrzeit: Listenspalte, Gruppe mit Stufe (Tag bis
   Jahr), Minimum/Maximum, Anzahl, alle Datumsfilter, Sortierung. Kein neuer Dialekt-Code.

4. **Angelegt/geändert von: Namen ohne Verknüpfung in der Abfrage.** Die Benutzer liegen auf der Hauptverbindung, die
   Daten in `app_data` (Nr. 1, verschiedene Server erlaubt). *Empfehlung:* Die Abfrage liest und gruppiert die
   **Benutzer-ID**; die Namen setzt `ReportResult` danach über `UserNames` ein (einmal je Anfrage alle Namen), in
   dieselbe Zuordnung Schlüssel → Anzeige, die Verknüpfungsfelder schon nutzen. Tabelle, Kreuztabelle, Diagramm,
   Export und Dashboard-Karte zeigen damit den Namen, ohne eigene Stelle. Gelöschte Benutzer erscheinen als „#5“, leer
   bleibt leer (wie B-159).
   - Als **Wert**: Anzahl und Anzahl verschiedener; keine Summe, kein Minimum/Maximum.
   - In **Formeln** nicht verwendbar (keine Zahl).

5. **Sortieren nach Person.** Die Datenbank kann nur nach der ID sortieren. *Empfehlung:*
   - **Gruppiert** (Zeilen je Person): Die Abfrage sortiert nach ID; ist das Ergebnis vollständig (nicht an der
     Zeilengrenze abgeschnitten), sortiert `ReportResult` die Zeilen in PHP nach dem Namen. Das ist der Normalfall,
     weil es höchstens so viele Zeilen wie Benutzer gibt.
   - **Liste:** Die Spalte ist nicht sortierbar (wie in der Datenansicht, B-159); `checkSort` meldet es verständlich.

6. **Filtern nach Person.** *Empfehlung:* neue Art **`person`** mit den Operatoren *ist*, *ist nicht*, *ist eine von*
   (Auswahl der Benutzer wie im Filter der Datenansicht), *leer*, *nicht leer* und neu **„bin ich“** (`is_me`): die
   Person, die die Auswertung ausführt. Damit geht „Meine heute angelegten Datensätze“ als geteilte Auswertung und
   als Dashboard-Karte.
   - `is_me` ohne angemeldete Person (Aufruf im Hintergrund) trifft nichts.
   - Der Beschreibungssatz nennt Namen bzw. „ich“.

7. **Personen in der App-Definition.** Benutzer-IDs gelten nur in einer Installation. *Empfehlung:* Beim Export einer
   App bleiben Filter mit *leer*, *nicht leer* und *bin ich*; Filter mit **bestimmten Personen** werden weggelassen,
   und der Export nennt die betroffene Auswertung als Hinweis. Eine Zuordnung über E-Mail-Adressen wäre
   personenbezogen und im Paket fehl am Platz.

8. **KI-Assistent der Auswertungen.** *Empfehlung:* Das Datenmodell nennt die Systemspalten je Tabelle (Art `date`
   bzw. `person`), damit „Wie viele Rechnungen wurden diese Woche angelegt?“ geht. **Namen von Benutzern gehen nicht
   an das Modell**; für Personen kennt der Assistent nur *bin ich*, *leer*, *nicht leer* und das Gruppieren. Das passt
   zu „nur Struktur“ (AI_AGENTS.md, Datenstufe 0).

9. **Datenstände.** Keine Änderung: Designer-Tabellen werden live gelesen und haben die Spalten; angebundene Tabellen
   haben weder Spalten noch Systemfelder (Frage 2). Eine Auswertung, die beides mischt, bietet die Systemspalten nur
   an den Designer-Tabellen an.

10. **Lesestellen und Rechte.** Auswertungen lesen mit dem Query Builder, nicht über `DynamicRecord`: **keine neue
    Lesestelle** in `read-sites.json`. `UserNames` liest Benutzer auf der Hauptverbindung (kein Datensatz). Wer eine
    Tabelle lesen darf, sieht auch, wer angelegt und geändert hat, wie in der Datenansicht (B-159) und im Verlauf;
    kein neues Recht. Zeilenrechte für Auswertungen bleiben Sache von B-020.

11. **Abfragebudget.** Eine zusätzliche Abfrage je Anfrage (alle Namen), nur wenn eine Personenspalte im Ergebnis
    oder Filterformular vorkommt; `UserNames` ist scoped (Nr. 25).

## 5. Umsetzung (28.11.2026)

- **Keine Migration, keine `.env`-Variable, kein neues Recht, Plugin-API unverändert (1.11).**
- **B-158:** `App\DynamicData\Ui\EmptyFilter` ist die eine Stelle für „leer“; `DynamicTableBuilder::filters()` baut
  Eingabe-Filter mit der Auswahl `content` und Listen-Filter mit den Einträgen `__empty`/`__filled`. Ja/Nein ist eine
  Auswahl Ja · Nein · (leer).
- **B-183:** `ReportDefinition` (Feld mit `system`), `DefinitionResolver` (Auflösung, Art `person`, `is_me`,
  `person_sort`), `ReportQuery` (Filter, ausführende Person), `ReportResult` (Namen, Sortierung nach Namen),
  `FieldCatalog`/`DesignerState` (Referenz `":created_at"`), `ReportDesigner` (Benutzer-Auswahl im Filter, kein
  Sortieren nach Person in Listen), `ReportDescriber`, `ReportAssistant` (Anleitung), `DefinitionParts` (Export).
- **Architekturentscheidung Nr. 31** (DE/EN).
- **Tests:** `EmptyFilterTest`, `ReportSystemFieldsTest` (pgsql, MySQL, MariaDB); Browser `ListTest` LIS-22 und
  `ReportsTest` RPT-20.

## 6. Abweichungen und Ergänzungen gegenüber §3 und §4

Bei der Umsetzung und der Durchsicht der Oberfläche durch den Nutzer (28.11.2026):

1. **Pflichtfelder** von Designer-Tabellen haben kein „leer/gefüllt“ (§3 Frage 1 geändert); angebundene Tabellen
   behalten es.
2. **Filterdialog neu angeordnet** (Wunsch des Nutzers, weil der Dialog zu gross wurde und Felder bei umbrechenden
   Bezeichnungen nicht auf einer Höhe standen): zwei Spalten statt drei, Breite 86 rem, Bezeichnung links neben der
   Eingabe und oben ausgerichtet; je Feld **eine** Bezeichnung, die Eingaben rechts verbunden (`FusedGroup`: „enthält …“
   und Auswahl bzw. „von“/„bis“ und darunter die Auswahl). Die vier Systemspalten stehen am Ende in einem eingeklappten
   Abschnitt „Angelegt und geändert“, ebenfalls zweispaltig. Auf schmalen Bildschirmen stehen alle Felder
   untereinander. Die Breite setzt eine Regel in der CSS-Ebene `components` (`PanelAppearance`): Filaments eigene Regel
   ist dort `!important` und schlägt jede Regel ausserhalb der Ebene.
3. **App-Definition** (§4 Frage 1): Eine Systemspalte steht dort als `field: null` mit `system: "created_at"`, nicht als
   `tabelle.created_at`. Die Tabelle ergibt sich aus dem Pfad, der Import brauchte keine Änderung.
4. **Kein Hinweis beim App-Export** (§4 Frage 7): Filter auf bestimmte Personen werden weggelassen, der Export hat aber
   keinen Ort für Hinweise. Beschrieben im Administrator- und Entwicklerhandbuch.
5. **Verknüpfung auf einen Datensatz im Papierkorb** (§3 Frage 4): Die Löschsperre verhindert den Fall; die Abfrage
   behandelt ihn als gefüllt, ein Test dafür entfällt.
6. **Zuordnung für V5** (§3 Frage 9): `is_empty`/`not_empty` ↔ Zustand `content` = `__empty`/`__filled` bzw. die
   gleichnamigen Einträge in `value`/`values`; `is_true`/`is_false` ↔ `value` = `1`/`0`.
