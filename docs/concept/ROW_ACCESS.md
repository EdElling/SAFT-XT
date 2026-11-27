# Konzept: Rechte auf Zeilenebene in SAFT XT

Stand: 27.11.2026 · Status: **erster Vorschlag zur Diskussion**, ergänzt um Hierarchien (Abschnitt 5); 27.11.2026 Datenstufen des KI-Agenten (Abschnitt 10) · Backlog: B-020 (Grundlage für B-134)

## 1. Ziel

Eine Rolle sieht bzw. bearbeitet in einer Tabelle **nur die Datensätze, die eine Bedingung erfüllen**:

- Eine Lehrperson sieht nur ihre eigenen Kurse und Lektionen und erfasst nur dort Präsenzen (B-134).
- Der Aussendienst sieht nur die Kunden seiner Region.
- Mitarbeitende bearbeiten nur Datensätze, die sie selbst angelegt haben, und sehen die übrigen nur.
- Eine Abteilung sieht nur ihre eigenen Aufträge.

Heute gelten die Rechte **je Tabelle und Aktion** (`data.<tabelle>.view|create|update|delete|export|import`):
Entweder sieht eine Rolle alle Datensätze einer Tabelle oder keinen.

**Grundsätze:**

- **Sicher von Grund auf:** Eine Einschränkung muss **überall** wirken, wo Datensätze gelesen werden: Liste,
  Detailtabelle, Auswahl einer Verknüpfung, Auswertung, Export, Dokument, Datei, Protokoll, Plugin-API, KI. Eine
  vergessene Stelle ist ein Datenleck.
- **In der Datenbank gefiltert:** Die Bedingung wird zu einer Abfrage (Query Builder, portabel), nicht zu einem Filter
  in PHP nach dem Laden. Sonst stimmen Seitenzahlen, Anzahl und Summen nicht, und grosse Tabellen werden langsam.
- **Dieselbe Formelsprache** wie bei Tabellenformeln, Regeln (B-142) und Nummernkreisen (B-135).

**Nicht Ziel:**

- Rechte auf **Feldebene** (einzelne Spalten ausblenden). Das ist ein eigenes Thema (Abschnitt 12).
- Rechte je einzelnem Datensatz, die von Hand vergeben werden („diesen Kunden darf auch Frau X sehen“).
  Gruppenzugehörigkeit lässt sich über Felder (Abschnitt 4.3) oder Hierarchien (Abschnitt 5) abbilden.

## 2. Heute

| Baustein | Verhalten |
|---|---|
| `DataPermissions`, `DataAccess::allows()` | Recht je Tabelle und Aktion, Tabelle aktiv, App aktiv (Super-Admin ausgenommen) |
| Rollenformular | Häkchen je Tabelle und Aktion, nach App gegliedert |
| Super-Admin | darf alles |
| „Ansicht als Rolle“ | Super-Admin sieht SAFT mit den Rechten einer Rolle |
| Datensätze | Spalten `created_by`, `updated_by` (Benutzer-ID) in Tabellen des Table-Designers |
| Benutzer | Name, E-Mail, Sprache, Rollen, Anmeldung über SSO mit Zuordnung von Rollen; **keine** Verbindung zwischen einem Benutzer und einem Datensatz einer App (z. B. Lehrperson) |
| Plugin-API | `Saft::table()` prüft die Rechte der Person, ausser nach `withoutAuthorization()`; `query()` liefert heute alle Datensätze |

## 3. Entscheidungen (Vorschlag)

| Frage | Vorschlag |
|---|---|
| Wo festlegen | **Im Rollenformular**, je Tabelle neben den Häkchen: eine **Einschränkung** für *sehen* und optional eine engere für *ändern und löschen*. Das ist der Ort, an dem Rechte heute vergeben werden. |
| Schreibweise | **Formel** in der vorhandenen Sprache, z. B. `lehrperson.benutzer = user()` oder `created_by = user()`. Im Formular gibt es dazu Bausteine (Feld, Vergleich, Wert bzw. „ich“). |
| Mehrere Rollen | **Oder-Verknüpfung:** Eine Person sieht einen Datensatz, wenn **eine** ihrer Rollen mit dem Recht ihn zulässt. Eine Rolle mit Recht und ohne Einschränkung lässt alle zu. So addieren sich Rechte wie heute bei den Häkchen. |
| Durchsetzung | **Globaler Scope** an `DynamicRecord` (`RowAccessScope`), aktiv für angemeldete Personen mit Einschränkungen. SAFTs eigene Berechnungen schalten ihn ausdrücklich ab (Abschnitt 7). Damit ist die Einschränkung **voreingestellt**, und Ausnahmen müssen begründet werden, nicht umgekehrt. |
| Prüfung beim Anlegen und Ändern | Nach dem Speichern **in derselben Transaktion** prüfen, ob der Datensatz die Einschränkung für *ändern* erfüllt; sonst zurückrollen mit Meldung. Dafür braucht es dieselbe Transaktion beim Speichern wie bei Regel-Engine und Nummernkreisen (einmal gebaut). |
| Verbindung Person ↔ Datensatz | **Neuer Feldtyp „Benutzer“** (Verknüpfung zur Tabelle `users`) und die Funktion `user()`. Für Gruppen und Abteilungen kommt `me('tabelle')` dazu (Abschnitt 4.3). |
| Hierarchien | **Optional** je App ein oder mehrere **Bäume** (Organigramm, Vertriebsgebiete). Ein Datensatz führt je Baum einen Knoten, und Personen sehen ihre Knoten samt allem darunter. Im Rollenformular gibt es dafür eine Kurzwahl, die ohne Formel für alle Tabellen gilt (Abschnitt 5). Die Bäume sind eine weitere Art von Bedingung im selben Mechanismus, kein zweites System. |
| Super-Admin | Sieht immer alles. Mit „Ansicht als Rolle“ prüft er die Einschränkungen. |

## 4. Einschränkungen formulieren

### 4.1 Beispiele

| Fall | Tabelle | Einschränkung *sehen* | *ändern/löschen* |
|---|---|---|---|
| Lehrperson sieht eigene Kurse | *Kurse* | `lehrperson.benutzer = user()` | gleich |
| … und deren Lektionen | *Lektionen* | `kurs.lehrperson.benutzer = user()` | gleich |
| Eigene Datensätze bearbeiten, alle sehen | *Notizen* | – (alle) | `created_by = user()` |
| Region des Aussendiensts | *Kunden* | `region = me('mitarbeitende').region` | gleich |
| Nur offene Aufträge | *Aufträge* | `status <> 'archiviert'` | gleich |
| Teilnehmende sehen Kurse, in denen sie eingeschrieben sind | *Kurse* | `exists(anmeldungen, schueler.benutzer = user())` | – (nur sehen) |

### 4.2 Was in einer Einschränkung erlaubt ist

Die Einschränkung muss sich **vollständig in eine Abfrage übersetzen** lassen. Erlaubt ist deshalb ein Teil der
Formelsprache:

- Felder der Tabelle, Vergleiche (`= <> < <= > >=`), `and`, `or`, Klammern, Text, Zahl, leer (`= ''` bzw. `isblank()`)
- **Pfade über Verknüpfungen** zu Master-Datensätzen, **mehrere Ebenen** (`kurs.lehrperson.benutzer`). Die
  Formelsprache kennt heute nur eine Ebene; für Einschränkungen wird das erweitert, was auch Regeln nützt (Konzept
  Regel-Engine, offene Frage 4).
- `exists(kindtabelle, bedingung)` für Kind-Datensätze (wird zu `whereExists`)
- `user()`, `me('tabelle')`, `has_role('Name')`, `today()`, `within(knotenfeld)` (Hierarchien, Abschnitt 5)

**Nicht erlaubt:** Rechnen mit Summen (`sum()`), Textfunktionen, die die Datenbank unterschiedlich behandelt, und
Formelfunktionen aus Plugins. Diese lassen sich nicht portabel in SQL übersetzen.

Ein **`RowFilterCompiler`** übersetzt den JSON-Baum der Formel in Aufrufe des Query Builders. Pfade werden zu
`whereIn(fk, Unterabfrage)`, `exists()` zu `whereExists`. Es entsteht kein Raw-SQL und kein Dialekt-Code. Der
`FormulaCheck` prüft beim Speichern der Rolle, ob die Formel gültig ist und nur diesen Teil nutzt.

### 4.3 Person und Datensatz verbinden

**Feldtyp „Benutzer“:** ein Feld, das auf einen SAFT-Benutzer verweist, z. B. `benutzer` in *Lehrpersonen*,
`verantwortlich` in *Aufträgen*. Auswahl aus den aktiven Benutzern mit Name und E-Mail. In der Datenbank eine Ganzzahl
mit der Benutzer-ID. Wegen der Portabilität und weil `users` in einer anderen Verbindung liegen kann, gibt es **keinen
Fremdschlüssel**. Wird ein Benutzer gelöscht, bleibt die ID stehen (Anzeige „gelöschter Benutzer“).

**`user()`:** die ID der angemeldeten Person.

**`me('tabelle')`:** der Datensatz einer Tabelle der App, dessen Benutzer-Feld auf die angemeldete Person zeigt, z. B.
`me('mitarbeitende')`. Damit lassen sich Abteilung, Region, Filiale usw. als **normale Felder der App** pflegen, ohne
die Benutzerverwaltung zu erweitern: `region = me('mitarbeitende').region`. Hat die Tabelle mehrere Benutzer-Felder,
gibt man das Feld an: `me('mitarbeitende', 'benutzer')`. Gibt es keinen solchen Datensatz, ist die Bedingung falsch,
und die Person sieht nichts.

`created_by` und `updated_by` sind Benutzer-Felder von SAFT selbst. Sie lassen sich ohne Weiteres verwenden.

## 5. Hierarchien (Bäume)

### 5.1 Idee

Viele Organisationen vergeben Zeilenrechte über **Bäume**:

- **Organigramm:** Unternehmen → Bereich → Abteilung → Team
- **Vertriebsgebiete:** Schweiz → Region Ost → Kanton St. Gallen
- **Personenkreise:** Schule → Fachschaft → Klasse

Jeder Datensatz gehört je Baum zu einem **Knoten**. Eine Person ist einem oder mehreren Knoten zugeordnet und sieht die
Datensätze dieser Knoten. Wahlweise sieht sie auch die Datensätze **aller Knoten darunter**: Eine übergeordnete Stelle
erbt damit die Rechte der untergeordneten (Aufwärts-Vererbung).

Was das gegenüber Formeln allein bringt:

- **Vorgesetzte sehen alles darunter**, über beliebig viele Ebenen. Mit einer Formel ist das ohne Rekursion nicht
  formulierbar.
- **Eine Einstellung statt einer Formel je Tabelle:** Führt jede Tabelle den Knoten mit, genügt im Rollenformular die
  Kurzwahl „nach Organigramm“. Sie gilt für alle Tabellen der App mit diesem Knotenfeld.
- **Mehrere Dimensionen** (Organigramm **und** Gebiet) ohne zusätzlichen Mechanismus.

Hierarchien sind **optional**. Eine App ohne Baum (ein Verein, eine kleine Musikschule) merkt davon nichts.

### 5.2 Aufbau

| Baustein | Inhalt |
|---|---|
| **Baum** | gehört zu einer App; Name, Schlüssel, z. B. *Organigramm*, *Vertriebsgebiete*. Die Knoten aller Bäume stehen in **einer** selbstreferenzierenden Tabelle `hierarchy_nodes` (Baum, übergeordneter Knoten, Name, Kurzzeichen, Sortierung, aktiv). |
| **Closure-Tabelle** `hierarchy_paths` | alle Paare Vorfahr–Nachfahr mit Abstand. SAFT führt sie bei jeder Änderung im Baum nach. So bleibt die Abfrage „alle Knoten unter X“ ein einfaches `whereIn` über den Query Builder, ohne rekursives SQL, und läuft auf allen drei Datenbanken gleich. |
| **Zuordnung Person → Knoten** | Person, Knoten, **„inklusive untergeordnete“** (Vorgabe an), optional „Hauptknoten“ (für die Vorbelegung beim Anlegen), gültig ab/bis. Je Baum mehrere Zuordnungen möglich (z. B. Stellvertretung). |
| **Knotenfeld** | Feldtyp „Knoten“ mit Angabe des Baums, in jeder Tabelle, die nach diesem Baum geschützt werden soll. Beim Anlegen wird es mit dem **Hauptknoten der anlegenden Person** vorbelegt. Ändern darf es nur, wer auch den neuen Knoten sieht (Abschnitt 6, „aus der eigenen Sicht schieben“). |
| **Formelfunktion `within(feld)`** | wahr, wenn der Knoten im Feld zu einem der Knoten der Person gehört, bzw. darunter liegt, wo „inklusive untergeordnete“ gesetzt ist. Übersetzt zu `whereIn(feld, Knoten der Person samt Nachfahren)`. Die Knotenliste wird **einmal je Anfrage** ermittelt. |
| **Kurzwahl im Rollenformular** | „Zeilen nach Baum: ☐ Organigramm ☐ Vertriebsgebiete“, je App. Angehakt heisst: In allen Tabellen dieser App mit einem Knotenfeld dieses Baums gilt `within(knotenfeld)`. Tabellen ohne Knotenfeld bleiben davon unberührt; dort gilt, was die Rolle sonst festlegt. Eine Formel für einzelne Tabellen ist weiterhin möglich und kommt mit **Und** dazu. |

Ob die Knoten in einer eigenen Systemtabelle (`hierarchy_nodes`) oder in einer normalen Tabelle der App mit
Selbstverweis stehen, ist zu entscheiden (Abschnitt 13). Für die Systemtabelle spricht, dass Closure-Tabelle,
Zuordnung und Kurzwahl dann ohne Sonderfälle funktionieren. Für die App-Tabelle spricht, dass Knoten eigene Felder
haben könnten (Kostenstelle, Leitung).

### 5.3 Mehrere Bäume

Ist eine Rolle nach mehreren Bäumen eingeschränkt, muss ein Datensatz **alle** erfüllen (**Und**): „Organigramm
**und** Gebiet“ heisst, die Person sieht Aufträge ihres Teams in ihrem Gebiet. Das ist der übliche Fall. Wer
stattdessen „Team **oder** Gebiet“ braucht, schreibt es als Formel: `within(team) or within(gebiet)`. Zwischen
**Rollen** bleibt es beim Oder (Abschnitt 3).

Ein Datensatz mit **leerem** Knotenfeld ist für nach diesem Baum eingeschränkte Rollen nicht sichtbar. Mit einer
Einstellung je Baum („leere sieht jeder“) lässt sich das umkehren, etwa für allgemeine Stammdaten.

### 5.4 Pflege und Reorganisation

Weil Datensätze auf **Knoten** zeigen und die Teilbäume erst bei der Abfrage ermittelt werden, ist die meiste
Reorganisation billig:

| Änderung | Aufwand |
|---|---|
| Team in einen anderen Bereich verschieben | nur der übergeordnete Knoten ändert sich; SAFT führt die Closure-Tabelle nach. **Kein Datensatz** wird angepasst. |
| Person wechselt das Team | nur ihre Zuordnung. Ihre bisherigen Datensätze bleiben beim alten Team, was meist fachlich richtig ist. |
| Knoten umbenennen | nichts |
| Knoten **auflösen** oder zwei **zusammenlegen** | Werkzeug „Knoten auflösen“: Datensätze aller Tabellen mit diesem Knoten an einen anderen übergeben, als Sammeländerung in einer Transaktion mit Änderungsprotokoll und vorheriger Vorschau („312 Aufträge, 45 Kunden“). Danach wird der Knoten deaktiviert, nicht gelöscht. |
| Neue Dimension einführen | Knotenfeld in die Tabellen aufnehmen und **einmalig befüllen**, z. B. per Formel aus vorhandenen Feldern (Kanton → Gebiet) oder über den Hauptknoten der anlegenden Person (`created_by`). Das ist eine Sammeländerung (B-133). |

Zuordnungen mit **gültig ab/bis** erlauben eine Reorganisation zum Stichtag: Die neue Struktur wird vorbereitet und
gilt ab dem Datum.

### 5.5 Beispiel

Baum *Organigramm*: Firma → Verkauf (→ Team Nord, Team Süd), Technik. Die Tabellen *Kunden*, *Aufträge* und *Offerten*
haben ein Knotenfeld `einheit` (Organigramm). Rolle *Verkauf* mit Kurzwahl „nach Organigramm“:

- Ruth ist **Team Nord** zugeordnet und sieht Kunden, Aufträge und Offerten von Team Nord.
- Die Verkaufsleitung ist **Verkauf** zugeordnet, inklusive untergeordnete, und sieht Team Nord und Team Süd.
- Wechselt Team Süd zur Technik, sieht die Verkaufsleitung dessen Datensätze ab sofort nicht mehr, und die
  Technikleitung sieht sie. Kein Datensatz wird geändert.

## 6. Wirkung je Aktion

| Aktion | Wirkung der Einschränkung |
|---|---|
| **sehen** | Liste, Detailtabellen, Suche, Anzahl, Seitenzahlen, Datensatz öffnen (Adresse von Hand → 404), Papierkorb |
| **ändern** | Knopf nur bei zulässigen Datensätzen; nach dem Speichern muss der Datensatz die Einschränkung **weiter** erfüllen. Man kann einen Datensatz also nicht „aus der eigenen Sicht schieben“, etwa indem man ihn einer anderen Lehrperson zuweist. Zu klären ist, ob das so gewollt ist (Abschnitt 13). |
| **anlegen** | Der neue Datensatz muss die Einschränkung für *ändern* erfüllen (z. B. Präsenz nur zu eigenen Lektionen). Vorgabewerte im Formular helfen: Ein Benutzer-Feld lässt sich mit „ich“ vorbelegen. |
| **löschen** | wie *ändern*, geprüft vor dem Löschen |
| **exportieren** | genau die Datensätze, die man sieht |
| **importieren** | jeder importierte Datensatz wie *anlegen*; verletzt einer die Einschränkung, wird der ganze Import zurückgerollt (wie heute bei Prüfregeln) |

Die Einschränkung für *ändern/löschen* gilt **zusätzlich** zu der für *sehen*. Man kann nur ändern, was man auch sieht.

## 7. Durchsetzung: wo gefiltert wird und wo nicht

Der globale Scope filtert **jede** Abfrage auf Datentabellen für die angemeldete Person. Die folgenden Stellen
schalten ihn **ausdrücklich** ab (`withoutRowAccess()`), weil sie SAFTs eigene Regeln umsetzen und nicht im Namen der
Person lesen:

| Stelle | Begründung |
|---|---|
| Berechnete Felder, Summen in Master-Datensätzen (`Recalculator`) | Eine Summe ist eine Eigenschaft des Datensatzes, nicht der Person. |
| Löschsperre (`DeleteGuard`), eindeutige Werte, fortlaufend, Nummernkreise | Sonst entstünden doppelte Werte bzw. verwaiste Datensätze. |
| „Wert übernehmen aus“ (`CopySource`) | Der Wert kommt aus einem Datensatz, den die Person gewählt hat. |
| Regel-Engine, geplante Aufgaben, Demo-Reset, Sicherung | laufen als System |
| Plugin-API nach `withoutAuthorization()` | Hintergrundarbeit, wie heute |

**Gefiltert** wird dagegen (eine Auswahl, vollständige Liste bei der Umsetzung):

| Stelle | Hinweis |
|---|---|
| Datenansicht, Detailtabellen, Kaskade (`CascadeResolver`) | |
| **Auswahl einer Verknüpfung** (`DynamicFormBuilder`) | Man wählt nur aus Datensätzen, die man sieht. Die **Bezeichnung** eines bereits verknüpften, aber nicht sichtbaren Datensatzes wird angezeigt (z. B. der Kunde einer Bestellung), aber nicht verlinkt. Zu entscheiden in Abschnitt 13. |
| Auswertungen (`ReportRunner`) | Jede beteiligte Tabelle mit ihrer Einschränkung, auch Summen und Gruppen. Freigegebene Tabellen anderer Apps: siehe Abschnitt 13. |
| Export, Dokumente (`DocumentGenerator`, `RecordContext`), Dateien (`RecordFileController`) | Dokument und Datei nur für sichtbare Datensätze. Positionen im Dokument werden ebenfalls gefiltert. |
| Änderungsprotokoll (Reiter am Datensatz, Widgets „Letzte Änderungen“, Seite *Protokoll*) | Einträge zu nicht sichtbaren Datensätzen werden ausgeblendet, ausser für Personen mit dem Recht auf das ganze Protokoll. |
| Dashboard-Kacheln mit Anzahl bzw. Summen | |
| Plugin-API `DataTable::query()` und `find()` | gefiltert, solange `authorizing` an ist. Das ist eine **Änderung der Plugin-API** (Minor, im Changelog), weil `query()` heute alles liefert. |
| KI-Hilfe, die Datensätze liest (B-053) | mit den Rechten der Person |

**Absicherung gegen Vergessenes:**

- Eine **Architektur-Prüfung** (Test) sucht Aufrufe von `withoutRowAccess()` und gleicht sie mit einer Liste
  erlaubter Stellen ab.
- Ein Satz **Sicherheitstests** prüft jede gefilterte Stelle mit einer eingeschränkten Rolle.

Bewusst **in Kauf genommen:**

- Eine **Summe im Master-Datensatz** enthält auch Kind-Datensätze, die die Person nicht sieht.
- Die Meldung „Wert schon vergeben“ verrät, dass es einen Datensatz mit diesem Wert gibt.
- Die Löschsperre nennt die Anzahl auch nicht sichtbarer Kind-Datensätze.

Diese drei Punkte gehören ins Admin-Handbuch.

## 8. Bedienung

**Rollenformular:** Je Tabelle mit Recht *sehen* gibt es eine aufklappbare Zeile „**Nur Datensätze, für die gilt …**“
mit dem Formeleditor (Feldauswahl inklusive Pfade über Verknüpfungen) und optional „**Ändern und Löschen nur, wenn …**“.
Neben jeder Einschränkung stehen:

- eine **Zusammenfassung in Worten**: „Nur Kurse, deren Lehrperson ich bin“
- ein **Zähler zur Probe**: „Für *Anna Muster* wären 12 von 340 Kursen sichtbar“, für eine wählbare Person mit dieser
  Rolle

**Datenansicht:** Ein kleiner Hinweis über der Liste „Eingeschränkte Ansicht (Rolle *Lehrperson*)“, damit niemand
fehlende Datensätze für einen Fehler hält.

**„Ansicht als Rolle“** wendet die Einschränkungen an. Da es dort keine echte Person gibt, sind `user()` und `me()`
leer. Der Super-Admin kann deshalb zusätzlich eine **Person wählen**, als die er schaut („Ansicht als Rolle und
Person“).

## 9. Leistung

- Felder in Einschränkungen (Verknüpfungen, Benutzer-Felder, Status) brauchen einen **Index**. Der Table-Designer legt
  ihn für Verknüpfungs- und Benutzer-Felder ohnehin an. Bei anderen Feldern weist das Rollenformular darauf hin.
- Pfade über mehrere Ebenen und `exists()` werden zu Unterabfragen. Bei normalen Datenmengen ist das unkritisch, bei
  sehr grossen Tabellen ist es zu messen.
- `me('tabelle')` und die Knoten der Person für `within()` werden **einmal je Anfrage** aufgelöst und als Werte in die
  Abfrage gesetzt, nicht als Unterabfrage.
- Die Closure-Tabelle wächst mit Knotenzahl × Tiefe. Bei Organigrammen mit einigen hundert Knoten ist das
  unerheblich.

## 10. Apps, App-Definition, KI

- Einschränkungen gehören zu den **Rechten einer Rolle** und gehen mit der Rolle in die App-Definition `saft-app/1`.
  Die Felder werden über stabile Schlüssel aufgelöst. Beim Aktualisieren gilt eine geänderte Einschränkung als
  **heikel** (Rechte ändern sich).
- **Freigegebene Tabellen anderer Apps** (B-107 Etappe 5): Die freigebende App bestimmt, was andere lesen. Eine
  Einschränkung in der fremden App wirkt dort nicht, weil die lesende Person dort keine Rolle hat. Vorschlag: Eine
  freigegebene Tabelle kann eine **Einschränkung für Leser anderer Apps** haben (Abschnitt 13).
- **Bäume** gehören zur App: Struktur und Knotenfelder gehen in die App-Definition, die Knoten selbst als Beispieldaten
  (B-002) oder gar nicht, die Zuordnungen von Personen nie.
- **KI:** Der App-Designer schlägt bei Rollen wie „Lehrperson“ das Benutzer-Feld und die Einschränkung vor. Ein
  Assistent im Rollenformular übersetzt „nur eigene Kurse“ in die Formel; die Person prüft sie mit dem Zähler zur
  Probe.
- **KI-Agent und Datenstufen** ([AI_AGENTS.md](AI_AGENTS.md) 5.2, V6): Die Werkzeuge des Agenten lesen über
  `DataTable` und `ReportRunner` und tragen ihre Lesestellen mit `scope: person` ein; die Einschränkungen wirken dort
  also wie für die Person am Bildschirm, sobald Z2 umgesetzt ist. Bis dahin gilt für den Agenten die Tabellenebene wie
  heute. Für Stufe 0 und 1 (Metadaten, Anzahlen) ist das vertretbar; **Stufe 3** (Datensätze ans Modell) ist in Apps
  mit personenbezogenen Einschränkungen erst nach Z2 freizuschalten. Zeilenrechte sind darum keine Voraussetzung der
  ersten KI-Etappen, aber Voraussetzung vor Stufe 3.

## 11. Etappen

| Etappe | Inhalt | Umfang |
|---|---|---|
| **Z1 Benutzer-Feld** | Feldtyp „Benutzer“ (Auswahl, Anzeige, Filter, Import über E-Mail, App-Definition, KI), Vorgabe „ich“, Funktion `user()` in Formeln | klein |
| **Z2 Sehen** | Einschränkung *sehen* je Rolle und Tabelle, `RowFilterCompiler` mit Pfaden über mehrere Ebenen und `exists()`, globaler Scope mit Ausnahmen, alle Lesestellen aus Abschnitt 7, Hinweis in der Datenansicht, „Ansicht als Rolle und Person“, Zähler zur Probe, App-Definition, Sicherheitstests auf allen drei Datenbanken | gross |
| **Z3 Ändern, Anlegen, Löschen** | Einschränkung *ändern/löschen*, Prüfung nach dem Speichern in der Transaktion (gemeinsamer Baustein mit B-142 und B-135), Import | mittel |
| **Z4 Personen-Tabellen** | `me('tabelle')`, `has_role()`; Musikschule: *Lehrpersonen* mit Benutzer-Feld, Rolle „Lehrperson“ (B-134) als Prüfstein | mittel |
| **Z5 Über App-Grenzen** | Einschränkung für Leser anderer Apps auf freigegebenen Tabellen; KI-Assistent im Rollenformular | klein |
| **Z6 Hierarchien** | Bäume und Knoten (`hierarchy_nodes`), Closure-Tabelle, Zuordnung Person → Knoten mit „inklusive untergeordnete“, Hauptknoten und gültig ab/bis, Feldtyp „Knoten“ mit Vorbelegung, `within()`, Kurzwahl im Rollenformular, Werkzeug „Knoten auflösen“, App-Definition. Baut auf Z2 und Z3 auf; Reihenfolge zu Z4/Z5 nach Bedarf | gross |

## 12. Abgrenzung

- **Rechte auf Feldebene** (z. B. Lohn nur für die Personalabteilung) sind ein eigenes Backlog-Thema. Sie berühren
  Formulare, Spalten, Export, Auswertungen und Dokumente ähnlich breit und sollten erst nach Z2 angegangen werden,
  damit die Durchsetzung dort schon steht.
- **Rechte in angebundenen Tabellen:** Einschränkungen wirken auch dort (nur sehen), weil der Query Builder dieselbe
  Abfrage erzeugt. Die fremde Datenbank selbst bleibt ungeschützt.
- **Datenbank-eigene Zeilenrechte** (Row Level Security in PostgreSQL) sind nicht portabel zu MariaDB und MySQL und
  arbeiten mit Datenbank-Benutzern statt mit SAFT-Benutzern. Verworfen.

## 13. Offene Fragen zur Diskussion

1. **Bezeichnung nicht sichtbarer verknüpfter Datensätze:** anzeigen (Vorschlag, sonst sind Bestellungen ohne Kunde
   unverständlich) oder als „nicht sichtbar“ verbergen?
2. **Aus der eigenen Sicht schieben:** Darf eine Person einen Datensatz so ändern, dass sie ihn danach nicht mehr
   sieht (z. B. Kunde an eine andere Region übergeben)? Vorschlag: nein, Übergaben macht eine Rolle ohne Einschränkung.
3. **Freigegebene Tabellen anderer Apps:** Einschränkung für fremde Leser wie in Z5, oder lesen andere Apps nur
   ungefiltert bzw. gar nicht, sobald eine Tabelle Einschränkungen hat?
4. **Protokoll:** Einträge zu nicht sichtbaren Datensätzen ausblenden (Vorschlag) oder die ganze Protokollseite nur
   Rollen ohne Einschränkungen zeigen?
5. **Personenmerkmale aus dem SSO** (z. B. Abteilung aus einem OIDC-Claim) zusätzlich zu `me('tabelle')`? Vorschlag:
   erst bei Bedarf, denn `me()` deckt das mit App-Daten ab.
6. **Und statt Oder:** Braucht es Fälle, in denen eine Rolle eine andere **einschränkt** (z. B. „Praktikant“ zusätzlich
   zu „Verkauf“ sieht weniger)? Vorschlag: nein. Das wäre schwer zu durchschauen; stattdessen eigene Rollen.
7. **Knoten als Systemtabelle oder als Tabelle der App?** Vorschlag: Systemtabelle `hierarchy_nodes` mit festen
   Feldern; eigene Felder für Knoten (Kostenstelle, Leitung) erst bei Bedarf.
8. **Und/Oder über mehrere Bäume:** Und als Vorgabe der Kurzwahl (Vorschlag), Oder nur über eine Formel, oder je
   Rolle wählbar?
9. **Vorbelegung des Knotens beim Anlegen:** Hauptknoten der anlegenden Person (Vorschlag), oder je Tabelle aus einem
   anderen Feld ableitbar (z. B. Auftrag erbt den Knoten des Kunden über „Wert übernehmen aus“)? Beides wäre möglich;
   das zweite hält zusammengehörige Datensätze im selben Knoten.
10. **Bäume über App-Grenzen:** Ein Organigramm gilt meist für die ganze Organisation. Soll ein Baum für alle Apps gelten
    (installationsweit) statt je App?
