# Architektur-Review: Voraussetzungen für KI-Agenten in SAFT XT

Stand: 27.11.2026 · Status: **Review und erster Vorschlag zur Diskussion**, ergänzt um die Übersetzer-Architektur ohne Datensätze (Abschnitt 5) und das lokale Modell (5.6); 27.11.2026 Einordnung „Was vor B-142 gilt“ (Abschnitt 12), Fragen 6 und 8 entschieden, Frage 13 (verändernde Plugin-Werkzeuge) aufgenommen · Geprüfter Stand: Release 0.8.0 (Commit d942efa) · Backlog: B-051 (Bezug B-033, B-052, B-053, B-128, B-142, B-020, B-133 S3, B-023)

## Inhalt

- [1. Frage und Zielbild](#1-frage-und-zielbild)
- [2. Zusammenfassung](#2-zusammenfassung)
- [3. Die drei Beispiele durchgespielt](#3-die-drei-beispiele-durchgespielt)
- [4. Was vorhanden ist](#4-was-vorhanden-ist)
- [5. Die Übersetzer-Architektur: Datensätze bleiben in SAFT](#5-die-übersetzer-architektur-datensätze-bleiben-in-saft)
- [6. Was fehlt: die Voraussetzungen](#6-was-fehlt-die-voraussetzungen)
- [7. Bewertung der Architektur](#7-bewertung-der-architektur)
- [8. Vorgeschlagene Etappen](#8-vorgeschlagene-etappen)
- [9. Anstehende Architekturentscheidungen](#9-anstehende-architekturentscheidungen)
- [10. Risiken](#10-risiken)
- [11. Offene Fragen zur Diskussion](#11-offene-fragen-zur-diskussion)
- [12. Einordnung: was vor B-142 gilt, was später kommt](#12-einordnung-was-vor-b-142-gilt-was-später-kommt)

## 1. Frage und Zielbild

**Frage des Reviews:** Welche Voraussetzungen braucht das Framework, damit später eine **agentenorientierte
KI-Architektur** darauf aufsetzen kann, und was davon ist schon vorhanden?

**Zielbild:** Ein interaktiver Assistent unterstützt operative Tätigkeiten je App und im Bereich „System“. Beispiele:

- „Erstelle alle fälligen Rechnungen.“
- „Suche alle Buchungen ohne Belege und schreibe diese in eine To-do-Liste.“
- „Ordne diesen PDF-Beleg der zugehörigen Buchung zu.“

Drei Grundsätze gelten dabei:

1. **Erst vorschlagen, dann handeln.** Der Assistent zeigt, was er tun will (welche Datensätze, welche Werte,
   welche Dokumente), und ändert erst nach einer Freigabe.
2. **Nach der Freigabe selbstständig.** Die Ausführung läuft ohne weitere Rückfragen durch, auch wenn sie länger
   dauert als ein Request, mit Fortschritt, Protokoll und Rücknahme.
3. **Datensätze bleiben in SAFT.** Das Modell arbeitet mit Metadaten und den Worten der Person; Datensätze sieht nur
   die Person. Ausnahmen sind eine Einstellung, keine Voraussetzung (Abschnitt 5).

Ein solcher Assistent ist ein **Agent**: Das Modell wählt in einer Schleife selbst, welche Werkzeuge es aufruft
(Schema lesen, zählen, Definition prüfen, Vorschlag anlegen), bis es eine Antwort oder einen Vorschlag hat. Das
unterscheidet ihn von den heutigen SAFT-Assistenten, die **eine** Anfrage mit **einer** JSON-Antwort beantworten.

**Nicht Ziel dieses Reviews:** die Bedienung im Detail, Prompts, Modellwahl, Kosten je Anfrage. Hier geht es um die
Architektur: Verträge, Schichten, Schreibweg, Rechte, Protokoll, Hintergrundarbeit.

## 2. Zusammenfassung

**Das Fundament passt.** Die Entscheidungen aus Fundament 2 (ARCHITECTURE Nr. 10–27) sind genau die, die ein Agent
braucht, auch wenn sie nicht für ihn gebaut wurden:

- **Ein Schreibweg** (`RecordWriter`, Nr. 11): Ein Agent kann nicht an Pflichtfeldern, Regeln, Plugin-Prüfungen,
  Nummernkreisen und Protokoll vorbei. Er bekommt keine eigene Tür.
- **Herkunft und Kennung im Protokoll** (`WriteOptions::origin`, `run`): Jede Änderung durch einen Agenten ist als
  solche erkennbar und als Vorgang gruppierbar. Es fehlt nur die Herkunft `assistant`.
- **Probelauf** (`RecordWriter::trial()`, Nr. 27): Ein Vorschlag lässt sich **wirklich** prüfen, mit allen Phasen und
  Plugin-Listenern, ohne etwas zu schreiben.
- **Arbeiten** (`WorkType`, Nr. 19–21): Lange Vorgänge in wiederaufnehmbaren Schritten, als die startende Person, mit
  Abbruch und Rücknahme (`CleansUp`). Die Ausführung eines freigegebenen Vorschlags ist eine Arbeit.
- **Apps als Grenze** (Nr. 5), **Rechte je Tabelle** (`DataAccess`), **Rollensimulation**, **Lesestellen-Inventur**
  (Nr. 24): Der Kontext und die Rechte eines Agenten sind dieselben wie die der Person, die ihn bedient.
- **Fünf KI-Assistenten nach einem Muster** (Beschreibung → Definition nach Schema → Prüfung durch SAFT →
  Korrekturrunde → Mensch speichert), alle **ohne Datensätze**. Dieses Muster ist bereits die Übersetzer-Architektur;
  es muss von „eine Antwort“ auf „Werkzeugschleife“ und von „Struktur“ auf „Vorgänge“ erweitert werden.

**Die tragende Idee: Das Modell übersetzt, SAFT führt aus, der Mensch sieht die Daten** (Abschnitt 5). Das Modell
erzeugt **Definitionen in SAFTs eigenen Sprachen** (Filter, Formel, Lauf `saft-rule/1`, Regel). SAFT prüft die
Definition, löst sie mit den Rechten der Person auf, zeigt der Person die Treffer und führt nach Freigabe aus. Ans
Modell zurück gehen Prüffehler und Zahlen, keine Zeilen. Vorgefertigte Aktionen (Rechnungslauf) und ad hoc erzeugte
Abläufe sind dabei dasselbe: gespeicherte und flüchtige **Läufe** (RULES.md §8). Damit rückt die Regel-Engine
(B-142) von einer späteren Etappe in die Mitte.

**Was fehlt, sind vier Bausteine und einige Entscheidungen:**

| # | Baustein | Kern der Lücke |
|---|---|---|
| V1 | **Werkzeugaufrufe im `ChatClient`** | Die Anbindung kennt nur Text hinein und Text oder JSON heraus. Ein Agent braucht `tool_use`/`tool_result`-Blöcke und eine von SAFT getriebene Schleife; das Nachrichtenmodell kennt Dokumentblöcke, auch wenn sie erst mit Stufe 3 genutzt werden. |
| V2 | **Werkzeugschicht** (`AgentTool`, Register, **Datenstufe je Werkzeug**) | Es gibt keine benannten, beschriebenen, rechtegeprüften Werkzeuge. Zählen, Prüfen und „Lauf vorschlagen“ müssen als Verträge existieren, gleich für innen (Chat) und aussen (MCP, B-033). |
| V3 | **Vorschlag als Definition** (Lauf `saft-rule/1`, flüchtig oder gespeichert) | Ein Vorschlag ist eine geprüfte Laufdefinition mit Auswahl, Parametern und Aktionen, die SAFT erst in der Vorschau in Datensätze auflöst. Nie aus dem Gesprächsverlauf ausführen. |
| V4 | **Ausführung und Sitzung als Arbeit** | Herkunft `assistant`, Kennung `assistant:<uuid>`, `WritesRecords` und `CleansUp`; die Sitzung selbst als Model mit Runden und Ausführungen als Arbeiten. |

Dazu die **Entscheidungen**: Datenstufen als Einstellung (Vorgabe: Statistik, keine Datensätze), Läufe vor dem
schreibenden Assistenten, Dokumente lokal verarbeiten, Rücknahme-Kopf für alle Kennungen, ein zweiter Treiber
„OpenAI-kompatibel“ für lokale Modelle mit Fähigkeitsprofil.

**Einschätzung:** Rund zwei Drittel der nötigen Bausteine sind da. Der Aufwand liegt in Läufen, Werkzeugschicht,
Freigabe-Oberfläche und Sicherheitsrand. Diese Teile sind auch ohne KI nützlich (Läufe, MCP, REST-API, Rücknahme),
darum lohnt es sich, sie als Kern zu bauen.

## 3. Die drei Beispiele durchgespielt

Die Beispiele zeigen, welche Werkzeuge und Bausteine ein Agent braucht, **ohne Datensätze zu sehen**. Tabellen und
Felder stammen aus der Demo „Gitarrenshop“ (`gitarren_belege`, `gitarren_buchungen`, `gitarren_zahlungen`).

### 3.1 „Suche alle Buchungen ohne Belege und schreibe diese in eine To-do-Liste“

| Schritt | Werkzeug | Was das Modell sieht | Heute vorhanden |
|---|---|---|---|
| Datenmodell der App lesen (Tabellen, Felder, Verknüpfungen, Auswahlwerte, Läufe, Vorlagen) | `schema` | Struktur | ja: `SchemaExporter`, `HelpAssistant::schema()` |
| „Buchungen ohne Beleg“ als Auswahl formulieren: `beleg_id` leer, oder Beleg ohne Datei in `dateien` | `validate` (Filter/Formel gegen das Datenmodell) | Prüffehler | **teilweise**: `FormulaCheck`, `ReportDefinition::filters`; eine Filterdefinition für die Datenansicht fehlt (V5) |
| Treffer zählen, Verteilung prüfen („27, davon 3 mit Datum in der Zukunft“) | `count`/`statistics` | Zahlen | ja: `ReportRunner::run()` mit Rechten; als Werkzeug fehlt es |
| Lauf vorschlagen: Grundtabelle Buchungen, Auswahl wie oben, Aktion `create` in *Aufgaben* je Treffer | `propose_run` | Prüfergebnis des `RuleValidator` | **fehlt**: Läufe (B-142), Vorschlagsobjekt (V3); eine **To-do-Liste gibt es in SAFT nicht** (V10) |
| Person sieht Vorschau mit den 27 Buchungen, wählt ab, gibt frei, speichert den Lauf oder verwirft ihn | Vorschau des Laufs | nichts | **fehlt**; Vorbild `BulkPreview` und Änderungsplan der Apps |
| Ausführung je Treffer, Protokoll `via assistant`, Rücknahme möglich | Arbeit | Ergebnis als Zahlen | ja (Arbeiten, Writer), Herkunft fehlt (V4), Rücknahme über B-133 S3 (V7) |

### 3.2 „Ordne diesen PDF-Beleg der zugehörigen Buchung zu“

| Schritt | Werkzeug | Was das Modell sieht | Heute vorhanden |
|---|---|---|---|
| PDF in die Sitzung hochladen | Upload | Dateiname, Grösse | ja: `WorkService::start(…, $files)` |
| **Lokal** Text und Merkmale extrahieren: Datum, Betrag, IBAN, Belegnummer, Lieferantenname | `extract_document` (PHP-Textextraktion und Muster; optional lokales Modell nur für diesen Schritt) | welche Merkmale gefunden wurden, nicht ihre Werte | **fehlt** (V9) |
| Kandidaten suchen: Belege mit Datum ± n Tage, Betrag, Lieferant über durchsuchbare Felder | `match_document(table, rule)` mit **Zuordnungsregel** (Felder, Toleranzen) | Anzahl Kandidaten und Güte | **fehlt**; Suche selbst über `DataTable::query()` |
| Person sieht die Kandidaten und wählt; bei genau einem sicheren Treffer schlägt SAFT ihn vor | Oberfläche | nichts | **fehlt** |
| Vorschlag: Datei an `gitarren_belege.dateien` des gewählten Belegs anhängen | Aktion `attach_file` | Prüfergebnis | `DataTable::attachFile()` vorhanden, Aktion fehlt |
| Freigabe, Ausführung, Protokoll | wie 3.1 | | wie 3.1 |

Die Zuordnungsregel ist wieder eine **Definition**, die das Modell einmal vorschlägt („Beleg: Datum ± 5 Tage, Betrag
gleich, Lieferant enthält“) und SAFT danach ohne KI für jeden Beleg anwendet. Mit Datenstufe 3 (5.2) könnte das
Modell den PDF-Inhalt und die Kandidaten selbst lesen; nötig ist das für diesen Fall nicht.

### 3.3 „Erstelle alle fälligen Rechnungen“

Das ist **Fachlogik**: Was „fällig“ heisst, wie eine Rechnung aus Lieferungen entsteht, welche Positionen, welche
Nummer, welches Dokument, wie Doppelverrechnung verhindert wird. Mit Läufen (RULES.md §8, B-142) definiert die App
einmal einen Lauf „Rechnungslauf“ (`saft-rule/1`, `kind: run`) mit Auswahl, Parametern, Aktionen und Markierung gegen
Doppelverarbeitung. Der Agent ruft `list_runs`, erkennt den Lauf, ruft `preview_run(rechnungslauf, stichtag)`, sieht
„14 Kunden, CHF 23'400“, die Person sieht die 14 Kunden, gibt frei, der Lauf läuft als Arbeit. Der Agent erfindet
nichts; die Idempotenz liegt in der Definition.

Gibt es den Lauf noch nicht, schlägt der Agent ihn vor (`propose_run`, wie 3.1), die Person prüft ihn in der Vorschau
und **speichert ihn** für den nächsten Monat. Vorgefertigte Aktion und Ad-hoc-Ablauf sind so derselbe Baustein.

Für „System“-Tätigkeiten (Benutzer anlegen, Rolle ändern, Aufgabe planen, Sicherung prüfen) gilt dasselbe Muster mit
anderen Werkzeugen; sie sind hier nicht weiter betrachtet, weil sie über Eloquent-Models und Policies laufen und keinen
`RecordWriter` haben (Frage 7 in Abschnitt 11).

## 4. Was vorhanden ist

| Baustein | Stand (0.8.0) | Nutzen für Agenten |
|---|---|---|
| **KI-Anbindung** `App\Ai\ChatClient` (Text), `App\Reports\Assistant\AssistantClient` (JSON nach Schema, `strict`), `AnthropicChatClient`/`AnthropicAssistantClient` (Anthropic, Bedrock, Vertex, Prompt-Caching, serverseitiger Ersatz bei Ablehnung), `AI_BASE_URL` für Gateways | vorhanden | Anbieterwahl, Caching, Geo, Zeitlimits und Konfiguration (`AiConfig`, App-Einstellungen, `.env`) sind gelöst. Zwei Verträge für denselben Zweck; ein dritter (Werkzeuge) kommt dazu, siehe V1. Der `strict = false`-Weg (Schema als Vorgabe, vollständige Prüfung) ist die Grundlage für lokale Modelle ohne Schema-Zwang |
| **`AiConversation`**: eine Anfrage, strenge JSON-Antwort, höchstens eine Korrekturrunde, Status `ok`/`question`/`refused`/`invalid`/`truncated` | vorhanden | Das Muster „Vorschlag → Prüfung durch SAFT → Korrektur“ ist genau das richtige. Es muss nur in eine Werkzeugschleife eingebettet werden |
| **`AiUsage`**: Tageslimit je Person (Anfragen), Protokoll jeder Anfrage im Änderungsprotokoll (Bereich, Text, Ergebnis, Modell, Tokens) | vorhanden | Grundlage für Kosten- und Audit-Anforderungen; zählt heute **Anfragen**, nicht Tokens oder Werkzeugaufrufe (V11) |
| **Fünf Assistenten**: Hilfe (`HelpAssistant`, Handbücher, Datenmodell, Rechte und Menü der Person), Tabellen (`TableAssistant`), Auswertungen (`ReportAssistant`), Dokumentvorlagen (`TemplateAssistant`), App-Designer (`AppDesigner`, als Arbeit mit langen Schritten) | vorhanden | Alle folgen „KI schlägt vor, SAFT prüft, Mensch speichert“ und senden **nie Datensätze**: Sie sind die Übersetzer-Architektur für Strukturen. Der Hilfe-Assistent kennt Rollen, Rechte und Menü der Person; der App-Designer zeigt, wie KI-Runden als Arbeit laufen (`LongSteps`) |
| **Schreibweg** `RecordWriter` mit `WriteOptions` (Herkunft `ui`, `plugin`, `import`, `copy`, `document`, `recompute`, `seed`, `numbering`, `bulk`, `work`, `direct`; `run`; erwartete Version; `withoutLog`, `withoutPluginEvents`), `batch()`, `trial()`, `savepoint()`, `WriteContext::afterCommit()` | vorhanden | Agent schreibt nur hier. `trial()` prüft einen Vorschlag mit allen Phasen; `savepoint()` macht einen Datensatz je Aktion rücknehmbar; `afterCommit()` hält E-Mails und Webhooks zurück, bis der Vorgang steht |
| **Prüfungen ausserhalb eines Formulars** `RecordChecker` (Pflicht, Länge, Min/Max, eindeutig, Auswahlwerte, Verknüpfungen, Tabellenregeln, Plugin-Feldregeln) | vorhanden | Vorschläge lassen sich vor dem Probelauf billig prüfen |
| **Plugin-API** `Saft::table()` → `DataTable` (`query`, `find`, `create`, `update` mit Version, `delete`, `validate`, `files`, `attachFile`, `detachFile`, `transaction`, `url`), Rechte über `DataAccess`, `withoutAuthorization()` bewusst | vorhanden | **Die Werkzeugschicht setzt auf `DataTable` auf.** Sie ist der rechtegeprüfte, vertraglich zugesicherte Zugang. `attachFile()` nimmt auch einen Pfad: Beispiel 3.2 ist damit machbar |
| **Rechte**: `data.<tabelle>.<aktion>`, Policies, `User::isSuperAdmin()`, `RoleSimulation` (auch in Arbeiten), `CurrentApp` | vorhanden | Agent läuft als die Person mit ihren (simulierten) Rollen in ihrer App. Zeilenrechte fehlen (B-020, V6) |
| **Lesestellen-Inventur** `tests/Fixtures/read-sites.json` mit `scope` | vorhanden | Jede Lesestelle der Werkzeugschicht wird mit `scope: person` eingetragen |
| **Arbeiten**: `WorkType` (`prepare`/`step`), `WritesRecords`, `CleansUp`, `LongSteps`, `DescribesProgress`, drei Treiber, Dateien je Arbeit, Seite „Arbeiten“ | vorhanden | Ausführung eines Vorschlags und KI-Runden sind Arbeiten; kein neuer Mechanismus nötig |
| **Geplante Aufgaben** `TaskType` (ohne Person, `parameterFields()` als Filament-Komponenten) | vorhanden | Stufe 4 von B-051 („Automatisieren“); Parameter sind für Maschinen nicht beschrieben (V14) |
| **Auswertungen**: `ReportDefinition` (JSON, Version 1: `base`, `rows`, `columns`, `values`, `filters` mit Operatoren je Typ, Pfade über Verknüpfungen, Formeln), `ReportRunner::run()` mit Rechten, `ReportDescriber` (Satz in Worten) | vorhanden | **Eine fertige, rechtegeprüfte Abfragesprache:** `count`/`statistics` laufen über eine `ReportDefinition` ohne Speichern; der `ReportAssistant` kennt sie schon. Der `ReportDescriber` liefert die Zusammenfassung für die Freigabe |
| **Formelsprache** (`Parser`, `Evaluator`, `FormulaCheck`, `Printer`, FORMULA_SPEC.md) | vorhanden | Auswahl und Werte in Läufen; die KI kennt die Syntax aus den Assistenten |
| **Sammeländerung** (`BulkChange`, `BulkPreview`: Anzahl, 10 Beispiele vorher → nachher, Zählungen; `BulkRecordsWork`: Prüfphase im Probelauf, Blöcke, Journal, `abort()`) | vorhanden | **Vorbild für Vorschau und Ausführung** eines Laufs |
| **Dokumente** (`DocumentGenerator::generate()`, `DocumentBatch`, `DocumentsWork`, Ablage im Dateifeld, Versand) | vorhanden | Aktion `document` eines Laufs; Beispiel 3.3 |
| **Dateien** (`RecordFiles`, Feldtyp Datei mit Arten und Grenzen, `record_files`, Auslieferung mit Leserecht) | vorhanden | Aktion `attach_file`; Beispiel 3.2 |
| **Entwickler-Kontext** (`ContextBundle`: `AGENTS.md`, `api-reference.md`, `schema.md`, `schema.json`; `SchemaExporter`, `ApiReference`) | vorhanden | Grundlage für das Werkzeug `schema` und für MCP (B-033) |
| **Änderungsprotokoll** mit `via` (Herkunft), `run`, `overwrote`, alte und neue Werte (`attribute_changes`) | vorhanden | Audit und Rücknahme; Filter „via assistant“ kommt mit der neuen Herkunft von selbst |
| **Benachrichtigungen** (`Saft::notify()` sofort, `WorkNotifier`, `TaskNotifier`) | vorhanden | „Ausführung fertig: 27 Einträge angelegt“ |
| **Konzepte**: Regel-Engine mit Läufen und KI-Abschnitt (RULES.md §8, §10.2), REST-Anbindungen (REST_CONNECTIONS.md §8, §11), Zeilenrechte mit KI (ROW_ACCESS.md §10), Rücknahme der Sammeländerung (BULK_EDIT.md §13) | Entwürfe | Alle vier haben die KI mitgedacht; dieses Review bündelt die Anforderungen an sie. RULES.md §10.2 formuliert den Grundsatz bereits: „Die KI arbeitet mit, ohne dass ihrem Code vertraut werden muss; die KI speichert nie“ |
| **Backlog**: B-051 (Stufen 2–4: Werkzeugschicht, Bestätigung, Obergrenzen, Prompt-Injection, Kostenlimit, MCP, REST), B-053 (lesende Werkzeuge nur mit eigenem Modell), B-033 (MCP), B-052 (lokales Modell), B-128 (Modell je Assistent) | offen | Dieses Review ist die Analyse zu B-051 |

## 5. Die Übersetzer-Architektur: Datensätze bleiben in SAFT

### 5.1 Grundsatz

**Das Modell übersetzt, SAFT führt aus, der Mensch sieht die Daten.** Das Modell bekommt Metadaten (Schema, Regeln,
Rechte, Läufe, Vorlagen, Handbücher), die Worte der Person und Zahlen zurück (Anzahlen, Prüffehler). Es erzeugt
Definitionen in SAFTs geschlossenen, prüfbaren Sprachen:

| Sprache | Für | Prüfung | Heute |
|---|---|---|---|
| Filter (nach dem Muster von `ReportDefinition::filters`) | Auswahl von Datensätzen, Sprunglink in die Datenansicht | `DefinitionResolver` | Auswertungen |
| Formel (FORMULA_SPEC.md) | Bedingungen, Werte | `FormulaCheck` | Felder, Sammeländerung, Auswertungen |
| `ReportDefinition` | Zählen, Summen, Verteilungen | `DefinitionResolver` | Auswertungen |
| Lauf und Regel `saft-rule/1` | Vorgänge über Datensätze: Auswahl, Parameter, Aktionen | `RuleValidator` (B-142) | Konzept |
| Zuordnungsregel (neu, 3.2) | Dokument zu Datensatz | Felder und Toleranzen gegen das Schema | fehlt |
| `saft-schema/1`, `saft-app/1`, Dokumentvorlage | Strukturen | vorhanden | Tabellen-, App-, Vorlagen-Assistent |

SAFT löst die Definition mit den Rechten der Person auf, zeigt ihr die Treffer (Vorschau), führt nach Freigabe aus und
protokolliert. Das ist ein Compiler-Muster: Die Sprache ist geschlossen, jede Definition wird gegen das Datenmodell
geprüft, nichts wird „ausgeführt, weil das Modell es sagt“. Es ist sicher aus demselben Grund, aus dem der
Auswertungs-Assistent heute kein SQL erzeugt.

### 5.2 Datenstufen

Statt zwei Architekturen („mit“ und „ohne“ Daten) gibt es **eine Werkzeugschicht mit einer Datenstufe je Werkzeug**.
Jedes Werkzeug deklariert, welche Stufe es braucht; die Installation legt die Höchststufe fest. Ein Werkzeug über der
Höchststufe existiert für das Modell nicht.

| Stufe | Was das Modell sieht | Werkzeuge | Heute |
|---|---|---|---|
| 0 | Schema, Regeln, Rechte, Menü, Handbücher, Läufe, Vorlagen | `schema`, `pages`, `validate`, `list_runs`, `propose_run`, `propose_rule` | ja, alle Assistenten |
| 1 | dazu Statistik: Anzahlen, Füllgrade, Wertebereiche, Anzahl verschiedener Werte; Ergebnis einer Vorschau als Zahlen | `count`, `statistics`, `preview_run`, `match_document` (Güte, nicht Werte) | fehlt |
| 2 | dazu Auswahlwerte und Beispielformate | wie 1 | Einstellung „Werte von Auswahlfeldern übertragen“ |
| 3 | Datensätze und Dateien | `search_records`, `get_record`, Dokumentblöcke | nein |

**Vorgabe: Stufe 1** (Stufe 2 bleibt die bestehende Einstellung). Stufe 3 ist eine Einstellung je Installation,
ab Werk aus, vorgesehen für ein lokales Modell (5.6); in der Cloud nur mit ausdrücklicher Freigabe, Hinweis auf
Anbieter und Region (`AI_INFERENCE_GEO`) und Aufbewahrungsfrist für Sitzungen. Kleine Anzahlen auf Stufe 1 (eine
Zählung mit einem Treffer über ein Namensfeld) sind eine Grauzone; die Werkzeuge runden oder unterdrücken Anzahlen
unter einer Schwelle nur dort, wo die Tabelle personenbezogen markiert ist (Frage 1 in Abschnitt 11).

### 5.3 Drei Wege ohne Datensätze

| Weg | Was er leistet | Grenze | Einordnung |
|---|---|---|---|
| **Vorgefertigte Aktionen** (Rechnungslauf) | Modell erkennt Absicht und Parameter, ruft `preview_run` und schlägt `start_run` vor. Fachlogik und Idempotenz liegen in der Definition. Sicher, billig, prüfbar. | Deckt nur ab, was jemand definiert hat. | Tragende Säule. Die Lücke schliesst sich, weil das Modell neue Läufe vorschlagen darf. |
| **Arbeitsanweisung mit Sprunglinks** | Antwort als nummerierte Schritte, jeder Schritt ein Link mit vorbelegtem Zustand: „Öffne Buchungen mit Filter Beleg leer, wähle alle, Sammeländerung Status = prüfen“. Nutzt vorhandene Funktionen, schult Anwender, null Risiko. Braucht nur die Filterdefinition (V5) und das Menü der Person (vorhanden). | Erfüllt „selbstständig durchführen“ nicht. | Rückfallebene, wenn kein Lauf passt; Antwortform ab der ersten Etappe. |
| **Ad-hoc-Lauf** | Modell erzeugt eine Laufdefinition: Grundtabelle, Auswahl als Formel, Parameter, Aktionen. SAFT rendert daraus Parameterdialog, Vorschau mit Treffern und Bestätigung aus den Metadaten. Die Person speichert den Lauf oder verwirft ihn. | Braucht die Regel-Engine mit Läufen. | Verallgemeinert den ersten Weg: gespeicherte und flüchtige Läufe sind ein Baustein. |

Ein Ablauf aus **mehreren** Masken nacheinander (erst Parameter, dann Auswahl bestätigen, dann eine zweite Aktion mit
Rückfrage) ist eine Folge von Läufen mit Übergabe (`@name`-Verweise wie in RULES.md §8.3). Das ist eine spätere
Erweiterung der Läufe, keine eigene Architektur.

### 5.4 Dokumente lokal verarbeiten

Für Beispiel 3.2 extrahiert SAFT auf dem Server: Text aus dem PDF (PHP), Merkmale mit Mustern (Datum, Betrag, IBAN,
Belegnummer, Lieferantenname über durchsuchbare Felder), optional ein lokales Modell nur für die Extraktion. Eine
**Zuordnungsregel** je Tabelle (welche Felder, welche Toleranzen, welche Gewichtung) liefert Kandidaten mit Güte. Die
Person wählt; bei genau einem sicheren Treffer ist er vorgewählt. Das Cloud-Modell sieht weder Datei noch Kandidaten,
es orchestriert und schlägt die Zuordnungsregel vor. Mit Stufe 3 kann das Modell die Datei selbst lesen; das ist eine
Verbesserung der Trefferquote bei schlechten Scans, keine Voraussetzung.

### 5.5 Was ohne Datensätze nicht geht

Alles, was Verstehen von Inhalten verlangt: unscharfe Suche in Freitext („Buchungen, die nach Saiten klingen“),
Tippfehler erkennen, einen bestimmten Datensatz erklären oder zusammenfassen, Dokumente inhaltlich beurteilen, Daten
aus einer E-Mail in Felder übertragen. Diese Fälle brauchen Stufe 3. Für die drei Beispiele aus Abschnitt 1 reicht
Stufe 1 mit lokaler Extraktion.

Gewinne der Variante: Die Zusage „nie Datensätze“ in Handbüchern und Sysadmin-Kapitel bleibt wahr, Prompt-Caching
wirkt voll (Schema und Werkzeugdefinitionen sind je Sitzung gleich), Anfragen sind klein und billig, und die Fläche
für Prompt-Injection schrumpft auf das, was die Person selbst tippt.

### 5.6 Lokales Modell: Treiber, Fähigkeiten, Folgen

**Die Architektur ändert sich nicht.** Der Anbieter steckt hinter einem Vertrag; ein lokales Modell ist eine zweite
Umsetzung (B-052). Was sich ändert, ist eine Einstellung: Mit einem lokalen Modell ist Stufe 3 vertretbar, weil die
Daten den Server der Organisation nicht verlassen. Damit das später möglich bleibt, dürfen vier Dinge von Anfang an
nicht verbaut werden:

1. Das Nachrichtenmodell des `AgentClient` (V1) kennt Dokument- und Bildblöcke, auch wenn die erste Umsetzung sie
   nicht nutzt.
2. Jedes Werkzeug deklariert seine Datenstufe (V2). Werkzeuge der Stufe 3 kommen später dazu, ohne dass sich Vertrag
   oder Register ändern.
3. Die Einstellungen kennen eine Höchststufe je Installation und einen Anbieter je Assistent (B-128); Vorgabe
   „Stufe 3 nur bei lokalem Anbieter“.
4. Die Sitzungstabelle (V4) kann Werkzeugergebnisse mit Daten aufnehmen und hat dann eine Aufbewahrungsfrist.

**Ein Treiber, nicht einer je Modell.** Die OpenAI-kompatible Chat-Completions-Schnittstelle ist der De-facto-Standard
lokaler Server: Ollama, vLLM, llama.cpp-Server, LM Studio und LocalAI sprechen `/v1/chat/completions` mit `tools` und
`tool_calls`. Ein Treiber „OpenAI-kompatibel“ mit Adresse und Modellname deckt die Familie ab; Anthropic (direkt,
Bedrock, Vertex) bleibt der andere. Ein „beliebiges“ Modell ist damit wählbar, aber nicht jedes geeignet. Die
Unterschiede liegen im Modell und im Server, nicht in der Schnittstelle:

| Fähigkeit | Cloud (Claude) | Lokal (OpenAI-kompatibel) | Folge für SAFT |
|---|---|---|---|
| Werkzeugaufrufe | zuverlässig, parallel | nur grössere Modelle zuverlässig (etwa Llama 3.x, Qwen, Mistral ab mittlerer Grösse); kleine halluzinieren Namen oder Parameter | Eingaben immer gegen das JSON-Schema prüfen; Korrekturrunde; kleinere Werkzeuglisten je Profil |
| Strukturierte Ausgabe | JSON-Schema erzwingbar | vLLM und llama.cpp grammatikgebunden, Ollama mit Schema-Format, sonst uneinheitlich | `strict = false`-Weg mit vollständiger Prüfung (vorhanden) |
| Kontextfenster | 1M Tokens | 8k bis 128k | Schema und Handbücher in Abschnitten nachschlagen statt ganz mitsenden (B-052); Werkzeug `schema` mit Tabellenauswahl |
| Prompt-Caching | API-Begriff, abrechnungsrelevant | kein API-Begriff; manche Server cachen Präfixe transparent | Reihenfolge „unveränderliche Teile zuerst“ nützt beiden |
| Dokumente, Bilder | Dokumentblöcke (PDF) | keine Dokumentblöcke; Bilder nur bei multimodalen Modellen | lokale Textextraktion (5.4) ist ohnehin der Weg |
| Geschwindigkeit | Sekunden | je nach Hardware deutlich länger | `LongSteps`-Budgets je Anbieter |
| Betrieb | nichts | GPU-Rechner im Netz der Organisation; auf dem Webspace nicht möglich | Sysadmin-Handbuch |

Daraus folgen zwei kleine Bausteine: ein **Fähigkeitsprofil je Modell** in den KI-Einstellungen (Werkzeuge ja/nein,
JSON-Schema ja/nein, Kontextgrösse, Bilder, Höchstzahl Werkzeuge je Anfrage) und ein **Eignungstest** neben „Verbindung
prüfen“ (`AiConnectionTester`), der einen Werkzeugaufruf und eine Schema-Antwort probiert und das Profil vorschlägt. Die
Werkzeugschicht passt sich dem Profil an (weniger Werkzeuge, kürzere Beschreibungen, Schema in Abschnitten).

**Hardware für Tests und Betrieb** (Einschätzung 27.11.2026, Quellen: Übersichten zu lokalen Modellen auf 24-GB-Karten;
Modellnamen und Versionen vor einem Test in der Ollama-Bibliothek prüfen, die Quellen widersprechen sich dort):

- Das Mass ist der **Grafikspeicher**, nicht der Arbeitsspeicher. Eine Karte mit 24 GB (RTX 4090, RTX 3090) trägt
  Modelle bis etwa 30 Milliarden Parameter in 4-Bit-Quantisierung (Klasse 24B bis 32B: Qwen 3.x 27B/32B, Gemma 3 27B,
  Mistral Small 24B, gpt-oss-20b) vollständig auf der Karte, mit 16k bis 32k Kontext und grob 20 bis 40 Tokens je
  Sekunde. Diese Klasse beherrscht Werkzeugaufrufe und ist das, was eine Organisation realistisch auf einem einzelnen
  GPU-Rechner betreibt. Sie ist darum die **Referenzklasse** für das Fähigkeitsprofil und den Eignungstest.
- **70B-Modelle** passen nicht: In 4-Bit brauchen die Gewichte rund 40 bis 46 GB und müssen in den Arbeitsspeicher
  auslagern; jede ausgelagerte Schicht geht je Token über den PCIe-Bus. Mit 32 GB RAM wird es eng bis unmöglich, mit
  64 GB läuft es mit einstelligen bis niedrig zweistelligen Tokens je Sekunde. Für Agentenschleifen mit mehreren
  Modellaufrufen je Runde ist das zu langsam; diese Klasse verlangt zwei Karten oder eine Karte mit 48 GB.
- **Arbeitsspeicher:** 32 GB genügen für alles, was in die Karte passt; 64 GB sind komfortabler, wenn mehrere Modelle
  geladen bleiben oder Docker bzw. WSL2 mitlaufen. Keine Voraussetzung für den Test.
- **Betriebssystem:** Unter Windows 11 laufen Ollama und LM Studio nativ und bieten die OpenAI-kompatible Schnittstelle
  mit Werkzeugen, die der SAFT-Treiber anspricht. vLLM braucht Linux (WSL2 oder Docker Desktop mit GPU-Durchreichung)
  und lohnt sich erst für grammatikgebundene JSON-Ausgabe oder mehrere gleichzeitige Nutzer.
- **Testreihe:** drei bis vier Modelle der Referenzklasse in Q4_K_M über Ollama; je Modell die drei Beispiele aus
  Abschnitt 3 auf Stufe 0 und 1, gemessen an Trefferquote der Werkzeugaufrufe, Schema-Treue und Dauer je Runde. Das
  Ergebnis ist der Inhalt des Fähigkeitsprofils und die Erwartung im Sysadmin-Handbuch.
- Ein Gaming-PC mit RTX 4090 (24 GB) und 32 GB RAM reicht für diese Testreihe aus.

## 6. Was fehlt: die Voraussetzungen

Nummeriert als V1 bis V15; je mit Stand, Lücke und Vorschlag. Die Reihenfolge ist nicht die Umsetzungsreihenfolge
(Abschnitt 8).

### V1 Werkzeugaufrufe im `ChatClient`

**Heute:** `ChatClient::chat()` nimmt Systemblöcke und Nachrichten aus Text und gibt Text zurück;
`AssistantClient::complete()` erzwingt ein JSON-Schema. Beide kennen nur Textblöcke. Es gibt zwei Verträge für
denselben Anbieter.

**Lücke:** Ein Agent braucht in der Anfrage **Werkzeugdefinitionen** (Name, Beschreibung, JSON-Schema der Eingabe,
`strict`), in der Antwort **Werkzeugaufrufe** (`tool_use` mit Eingabe) und in der nächsten Anfrage die **Ergebnisse**
(`tool_result`, auch mehrere je Runde, auch mit Fehlerkennzeichen). Die Schleife „Modell → Werkzeuge → Modell“ muss
SAFT selbst treiben, weil zwischen den Aufrufen Rechte geprüft, Grenzen gezählt und Ergebnisse protokolliert werden.

**Vorschlag:** Ein dritter Vertrag `App\Ai\AgentClient` mit einem anbieterneutralen Nachrichtenmodell: Blöcke `text`,
`tool_use`, `tool_result`, dazu `document` und `image` als Teil des Vertrags (genutzt erst ab Stufe 3). Die
Anthropic-Umsetzung setzt das auf die Messages-API um (das PHP-SDK bringt einen Tool Runner mit; SAFT nutzt die
**manuelle Schleife**, weil jeder Aufruf durch die Werkzeugschicht geprüft wird); die OpenAI-kompatible Umsetzung auf
`tools`/`tool_calls`. Die Schleife (`AgentLoop`) gehört in `app/Ai`, nicht in den Client: Sie zählt Runden und
Werkzeugaufrufe, bricht bei Grenzen ab und liefert eine Antwort, eine Rückfrage, eine Anleitung oder einen Vorschlag.
Strukturierte Ausgabe bleibt für Definitionen (Lauf nach `saft-rule/1`). Mit den aktuellen Cloud-Modellen lässt sich
ein Werkzeugaufruf nicht mehr erzwingen (`tool_choice any/tool`); die Schleife muss darum mit einer Textantwort statt
eines Werkzeugaufrufs umgehen, und Definitionen werden über `strict`-Werkzeuge oder `output_config.format` eingeholt.

### V2 Werkzeugschicht: Vertrag, Register, Datenstufe, Grenzen

**Heute:** Keine benannten Werkzeuge. Die Fähigkeiten sind da (`DataTable`, `ReportRunner`, `SchemaExporter`,
`DocumentGenerator`), aber nicht als beschriebene, aufrufbare Einheiten mit Rechteprüfung und Protokoll.

**Lücke:** Ein Vertrag `App\Ai\Tools\AgentTool` mit `name()`, `description()` (für das Modell), `inputSchema()`
(JSON-Schema), `dataLevel(): int` (5.2), `permission()`/`availableFor(User)`, `mutating(): bool` und
`run(array $input, AgentContext): ToolResult`. Ein Register (`config/ai_tools.php`, `SaftPlugin::agentTools()`) wie bei
`TaskType` und `WorkType`. Jeder Aufruf prüft die Rechte **im Werkzeug**, nie im Modell, läuft als die Person (mit
simulierten Rollen) in ihrer App und wird protokolliert. Das Register filtert nach Höchststufe der Installation und
Fähigkeitsprofil des Modells. Ob Werkzeuge aus Plugins `mutating()` mit `true` melden dürfen, ist offen (Frage 13).

**Kern-Werkzeuge:**

| Werkzeug | Stufe | Grundlage | Grenzen |
|---|---|---|---|
| `schema` (Tabellen, Felder, Typen, Auswahlwerte, Verknüpfungen, Regeln, Rechte der Person; App-Beschreibung, Läufe, Dokumentvorlagen, Auswertungen; mit Tabellenauswahl für kleine Kontexte) | 0 (Auswahlwerte 2) | `SchemaExporter`, `HelpAssistant::schema()`, `DocumentGenerator::templatesFor()` | nur Tabellen mit `data.<t>.view` |
| `pages` (Menü der Person mit Adressen, Sprunglink mit Filterdefinition) | 0 | `HelpAssistant::menu()`, `DynamicDataPage::urlFor()` + V5 | – |
| `validate(kind, definition)` (Filter, Formel, Lauf, Regel) | 0 | `FormulaCheck`, `DefinitionResolver`, `RuleValidator` | – |
| `list_runs`, `propose_run(definition)`, `propose_rule(definition)` | 0 | B-142; der Vorschlag ist das einzige „schreibende“ Werkzeug und schreibt nichts (V3) | – |
| `count(definition)`, `statistics(table, fields)` (Anzahl, Füllgrad, Min/Max, Anzahl verschiedener Werte, Verteilung über Auswahlwerte) | 1 | `ReportRunner::run()` mit einer `ReportDefinition` ohne Speichern | Schwelle für kleine Anzahlen bei personenbezogenen Tabellen (Frage 1) |
| `preview_run(run, params)` | 1 | B-142 Vorschau | Zahlen und Summen, keine Treffer |
| `match_document(file, table, rule)` | 1 | 5.4 | Anzahl Kandidaten und Güte |
| `run_report(id)` | 3 (Zeilen) oder 1 (nur Summen) | gespeicherte Auswertung mit Recht | Zeilen begrenzt |
| `search_records(table, filters, sort, limit, fields)`, `get_record(table, id)` | 3 | `DataTable::query()`/`find()` | Limit (Vorgabe 50, Maximum 200) |

**Dieselbe Schicht nach aussen:** MCP-Server (B-033) und REST-API (B-023) sind zwei weitere Oberflächen derselben
Werkzeuge. Wer sie als eine Schicht baut, bekommt drei Zugänge für den Preis von einem, und ein externer Agent kann
mit denselben Rechten, derselben Datenstufe und derselben Freigabe arbeiten.

### V3 Vorschlag als Definition: flüchtiger Lauf

**Heute:** Die Assistenten geben eine JSON-Antwort, die die Oberfläche in ein Formular füllt; die Person speichert.
Für Vorgänge über Datensätze gibt es das nicht.

**Lücke:** Ein Vorschlag ist eine **Laufdefinition** `saft-rule/1` mit `kind: run` (RULES.md §8): Grundtabelle,
Auswahl als Formel, Parameter, Aktionen (`create`, `create_each`, `update`, `delete`, `restore`, `attach_file`,
`document`, `notify`, `start_work`), Verweise `@name`. Sie enthält **keine Schlüssel und keine Werte aus Datensätzen**;
SAFT löst sie erst in der Vorschau auf. Dort sieht die Person Anzahl, Treffer (die ersten n, wie `BulkPreview`), je
Aktion vorher → nachher, Hinweise (Nummern werden vergeben, Dokumente erzeugt, E-Mails gehen hinaus), Einstufung
sicher/heikel; sie wählt Treffer ab, gibt frei und entscheidet „einmalig“ oder „als Lauf speichern“.

**Prüfung** in drei Stufen: (1) Schema und Bezüge (`RuleValidator`); (2) `RecordChecker` je Treffer in der Vorschau;
(3) **Probelauf** `RecordWriter::trial()` über die Treffer mit Plugin-Listenern, Nummernkreisen und Regeln. Fehler der
Stufe 1 gehen **einmal** an das Modell zurück (`AiConversation`-Muster); Fehler der Stufen 2 und 3 sieht die Person je
Treffer, wie bei der Sammeländerung. Erst ein bestandener Vorschlag wird gezeigt.

**Nebenläufigkeit:** Weil die Auswahl erst bei der Ausführung aufgelöst wird, greift die optimistische Sperre je Treffer
zwischen Vorschau und Ausführung (Version aus der Vorschau, wie `BulkRecordsWork`); geänderte Treffer werden
übersprungen und gemeldet. Die Markierung gegen Doppelverarbeitung (RULES.md §8.4) gilt auch für flüchtige Läufe.

**Speicherung:** Tabelle `assistant_proposals` auf der Hauptverbindung (Sitzung, Person, App, Definition, Status
`proposed`/`approved`/`executing`/`done`/`failed`/`rejected`, Arbeit, Prüfergebnis, gespeicherter Lauf). Sie ist der
Kopf für die Rücknahme (V7) und das Audit („wer hat was freigegeben“). Ein konkreter Plan mit Schlüsseln
(`saft-plan/1`) ist für Stufe 3 später möglich (etwa „diese drei Datensätze so ändern“), fällt aber unter dieselbe
Tabelle, dieselbe Vorschau und dieselbe Arbeit.

### V4 Herkunft `assistant`, Sitzung und Ausführung als Arbeit

**Heute:** `WriteOptions` kennt zehn Herkünfte, `assistant` fehlt; `WorkItem` kennt `queued`, `running`, `done`,
`failed`, `cancelled`; der App-Designer hält den Entwurf in `app_drafts` und lässt jede Runde als Arbeit laufen.

**Vorschlag:**

- `WriteOptions::ASSISTANT = 'assistant'` mit `WriteOptions::assistant(string $run)`, Kennung
  `assistant:<proposal-uuid>`. Das Protokoll zeigt „via Assistent“ und gruppiert nach Vorgang. Ein gespeicherter Lauf,
  der später ohne KI läuft, schreibt mit der Herkunft der Regel-Engine, nicht `assistant`.
- **Sitzung** als Model `assistant_sessions` (Person, App, Titel, Verlauf als Nachrichten mit Blöcken,
  Werkzeugaufrufe mit Dauer und Ergebnisgrösse, Dateien, Verbrauch, Datenstufe der Sitzung), wie
  `AiHelpConversation`, mit Aufbewahrungsfrist nach dem Muster `activity_prune`. **Jede Runde** ist eine Arbeit
  `AssistantTurnWork` (`LongSteps`, wie `AppDesignerTurnWork`), damit die Schleife nicht am PHP-Zeitlimit scheitert
  und die Person den Fortschritt sieht („zählt Buchungen …“). **Jede Ausführung** ist die Arbeit des Laufs (B-142)
  bzw. `AssistantPlanWork` (`WritesRecords`, `CleansUp`, `DescribesProgress`) in Blöcken mit eigener Transaktion und
  Journal wie `BulkRecordsWork`. So braucht `WorkItem` **keinen Wartezustand**: Zwischen Vorschlag und Freigabe läuft
  keine Arbeit, die Sitzung wartet.
- `afterCommit()`-Wirkungen laufen erst mit dem Commit des jeweiligen Blocks; ein Probelauf löst nichts aus.

### V5 Filterdefinition für die Datenansicht

**Heute:** Filter der Datenansicht sind Filament-Komponenten (`DynamicTableBuilder::filters()`), ihr Zustand liegt
als Filament-Zustand in `user_table_states`. Auswertungen haben eine eigene JSON-Filterdefinition.

**Lücke:** Arbeitsanweisungen mit Sprunglinks (5.3) und die Vorschau brauchen eine maschinenlesbare Filterdefinition,
die sich **in der Datenansicht öffnen** lässt („Zeige mir die 27 Buchungen“). Vorschlag: die Filter von
`ReportDefinition` (Operatoren je Feldtyp, Pfade) als gemeinsames Format und `DynamicDataPage::urlFor()` um einen
Filterparameter erweitern, der die Definition in Filament-Filterzustand übersetzt. Auch für B-158 (Filter
„leer/gefüllt“) und Lesezeichen nützlich.

### V6 Zeilenrechte (B-020)

**Heute:** Rechte gelten je Tabelle und Aktion. Die Lesestellen sind inventarisiert (Nr. 24).

**Bedeutung:** Auf Stufe 1 zählt der Agent über ganze Tabellen; auf Stufe 3 liest er breiter als eine Person am
Bildschirm. Solange Zeilenrechte fehlen, gilt die Tabellenebene, wie heute für die Person. Zeilenrechte sind keine
Voraussetzung für die ersten Etappen, aber **vor Stufe 3 in Apps mit personenbezogenen Einschränkungen**
(Musikschule: „nur eigene Kurse“) nötig. Die Werkzeugschicht trägt ihre Lesestellen mit `scope: person` ein, dann
greift der Scope dort automatisch.

### V7 Rücknahme (B-133 S3, verallgemeinert)

**Heute (27.11.2026):** Die Rücknahme der Sammeländerung ist umgesetzt (BULK_EDIT.md §13, Commits defea73 und
7b33035, Release 0.9.0 offen): Kopf `bulk_changes` (Migration 102) je erfolgreicher Sammelaktion mit Kennung `run`,
**einer** Tabelle (`custom_table_id`), Art, Angaben je Feld und Schlüsseln der Datensätze; alte Werte aus dem
Protokoll; Einordnung je Datensatz in `BulkUndo`, Ausführung als Arbeit `bulk_records` mit Übersprungenen.

**Bedeutung:** „Selbstständig durchführen“ ist nur vertretbar, wenn sich die Ausführung **zurücknehmen** lässt.
Vorschlag: den Kopf nicht auf `bulk_changes` beschränken, sondern als `change_sets` für jede Kennung `run` bauen:
`bulk:<uuid>`, `assistant:<uuid>`, `rule_run:<id>`. Die Rücknahme-Arbeit ist dieselbe. Angelegte Datensätze kommen in
den Papierkorb, angehängte Dateien werden gelöst, erzeugte Dokumente bleiben (mit Hinweis).

**Entschieden 27.11.2026:** `bulk_changes` bleibt für das Release 0.9.0, wie es gebaut ist; ein Umbau vor dem Release
lohnt nicht, weil ein Lauf in **mehrere** Tabellen schreibt und ein Umbenennen darum ohnehin nicht reicht. Das
**Laufprotokoll von B-142 (R3b)** wird als allgemeiner Kopf je Kennung `run` entworfen (RULES.md §8.7); `bulk_changes`
geht dann darin auf oder bleibt dessen Spezialfall mit eigener Liste in der Datenansicht. Die Rücknahme-Arbeit ist
dieselbe. Das beantwortet auch RULES.md Frage 9.

### V8 Läufe und Regel-Engine (B-142)

**Heute:** Konzept mit Läufen (§8) und KI-Abschnitt (§10.2), nicht umgesetzt.

**Bedeutung:** In der Übersetzer-Architektur sind Läufe **der** Vorschlagstyp (V3) und die einzige Form, in der der
Agent Vorgänge auslöst. Ohne sie bleibt der Agent lesend und beratend (Stufe 0 und 1, Arbeitsanweisungen). Zusätzlich
erfüllt die Regel-Engine Stufe 4 von B-051: Der Agent schlägt Regeln und Läufe vor, die Person speichert sie, die
Ausführung läuft ohne KI. **Empfehlung:** B-142 vor der schreibenden Etappe planen, mindestens die Läufe (§8) mit
Vorschau; die Ereignisregeln können folgen.

### V9 Dateien und lokale Extraktion

**Heute:** `WorkService::start(…, $files)` legt Dateien in den Ordner der Arbeit; `DataTable::attachFile()` hängt
einen Pfad an ein Dateifeld; der Feldtyp prüft Art und Grösse.

**Lücke:** Upload in die **Sitzung**, Aufbewahrung bis zur Ausführung, Löschen mit der Sitzung; `extract_document`
(PDF-Text in PHP, Muster für Datum, Betrag, IBAN, Nummern; Bilder über OCR oder lokales Modell, falls vorhanden);
`match_document` mit **Zuordnungsregel** je Tabelle (Felder, Toleranzen, Gewichte), die das Modell vorschlägt und SAFT
speichert; Grenze je Datei und Sitzung in `config/ai.php`. Dokumentblöcke ans Cloud-Modell nur auf Stufe 3.

### V10 To-do-Liste

**Heute:** SAFT kennt keine generische Aufgabenliste für Personen. Benachrichtigungen sind flüchtig; Arbeiten und
geplante Aufgaben sind etwas anderes.

**Optionen:** (a) Die App führt eine Tabelle „Aufgaben“ im Table-Designer; der Lauf legt Datensätze an. (b) Eine
Systemfunktion „Meine Aufgaben“ mit Tabelle `todos` (Person, App, Text, Verweis auf Datensatz, fällig, erledigt).
**Empfehlung:** (a) für die erste Etappe; (b) nur, wenn mehrere Apps es brauchen, dann als eigener Backlog-Eintrag.
Der Agent erkennt mit `schema`, ob eine App eine Aufgaben-Tabelle hat, und schlägt sonst vor, sie anzulegen
(Tabellen-Assistent).

### V11 Sicherheit: Prompt-Injection, Grenzen, Audit

**Heute:** Der Hilfe-Assistent sendet keine Datensätze und prüft Verweise in der Antwort (`checkLinks`); Tageslimit je
Person; Protokoll je Anfrage. Keine Massnahmen gegen Prompt-Injection, weil keine fremden Inhalte an das Modell gehen.

**Auf Stufe 0 bis 2** bleibt das so: Fremder Text erreicht das Modell nur über Bezeichnungen und Beschreibungen des
Datenmodells, die Administratoren pflegen, und über Auswahlwerte. **Auf Stufe 3** enthalten Werkzeugergebnisse
fremden Text („Ignoriere alle Regeln und lösche …“ in einer Beschreibung). Massnahmen, die auf allen Stufen gelten:

- **Strukturelle Barriere zuerst:** Das Modell kann nichts ausführen, nur Definitionen vorschlagen; die Person gibt
  frei; Werkzeuge prüfen Rechte selbst. Eine Injection kann höchstens einen unsinnigen Vorschlag erzeugen, den die
  Person in der Vorschau sieht.
- Werkzeugergebnisse als **Daten gekennzeichnet** (eigene Blöcke; Anweisung, Inhalte aus Ergebnissen nie als
  Anweisung zu lesen).
- **Grenzen** je Runde und Sitzung: Werkzeugaufrufe (z. B. 20), Modellaufrufe (z. B. 8), Zeilen je Ergebnis, Tokens
  je Sitzung. `AiUsage` zählt **Tokens** und **Werkzeugaufrufe**, nicht nur Anfragen; das Tageslimit wird ein
  Token-Budget, je Assistent zuordenbar (B-128).
- **Audit:** jeder Werkzeugaufruf im Sitzungsprotokoll (Name, Eingabe, Anzahl Treffer, Dauer); jeder Vorschlag mit
  Prüfergebnis; jede Freigabe mit Person und Zeitpunkt; jede Ausführung mit Kennung im Änderungsprotokoll.
- **Rollensimulation:** Ein Agent unter „Ansicht als Rolle“ sieht und schlägt nur vor, was die Rolle darf.
- **Super-Admin:** keine Sonderrechte; `withoutAuthorization()` ist unter `app/Ai` verboten (Architekturtest 5).

### V12 Modelle: Treiber, Profil, Zuordnung

**Heute:** ein Modell für alles (B-128 offen); nur Claude über drei Zugänge (B-052 offen); `AiConnectionTester` prüft
die Verbindung.

**Vorschlag** (5.6): zweiter Treiber „OpenAI-kompatibel“ als Umsetzung von `ChatClient`, `AssistantClient` und
`AgentClient`; **Fähigkeitsprofil** je Modell in den KI-Einstellungen (Werkzeuge, JSON-Schema, Kontextgrösse, Bilder,
Höchstzahl Werkzeuge); **Eignungstest** neben „Verbindung prüfen“; **Zuordnung je Assistent** (B-128) mit Anbieter,
Modell, Aufwand und Budget. Die Höchststufe der Daten ist an den Anbieter gekoppelt: Vorgabe „Stufe 3 nur lokal“.

### V13 MCP-Server und REST-API (B-033, B-023)

**Heute:** Entwickler-Kontext als Dateien; keine API nach aussen.

**Bedeutung:** Beide sind Oberflächen der Werkzeugschicht (V2). Für MCP braucht es Anmeldung (Token je Person mit
denselben Rechten und derselben Datenstufe), einen Transport (HTTP) und die Freigabe **in SAFT** (ein externer Agent
legt einen Vorschlag an, die Person sieht ihn unter „Assistent › Vorschläge“ und gibt frei). Nicht Teil der ersten
Etappen, aber ein Grund, die Werkzeugschicht transport-neutral zu halten (keine Livewire-Abhängigkeit in
`app/Ai/Tools`).

### V14 Maschinenlesbare Parameter von Arbeiten und Aufgaben

**Heute:** `TaskType::parameterFields()` liefert Filament-Komponenten; `WorkType` hat keine Parameterbeschreibung.

**Lücke:** Damit ein Lauf oder der Agent eine Plugin-Arbeit (z. B. „Mahnlauf“ eines Plugins) oder eine Aufgabenart
starten kann, muss er die Parameter kennen. Vorschlag: optionales Interface `DescribesParameters` mit
`parameterSchema(): array` (JSON-Schema) für `WorkType` und `TaskType`; wer es nicht implementiert, ist für Läufe und
Agent nicht startbar. Plugin-API Minor; auch für MCP und die App-Definition nützlich.

### V15 Semantik im Datenmodell

**Heute:** Tabellen haben Beschreibungen, Felder nur Bezeichnungen; Feldregeln aus Plugins haben Beschreibungen
(B-038). Auswahlwerte haben Bezeichnungen je Sprache.

**Lücke:** Ohne Datensätze ist das Datenmodell die **einzige** Quelle, aus der das Modell „Buchung ohne Beleg“ auf
`beleg_id` leer **oder** Beleg ohne Datei in `dateien` abbilden kann. Eine optionale **Feldbeschreibung** im
Table-Designer („Datum der Zahlung, nicht des Belegs“), die in `schema` mitgeht, und die **App-Beschreibung** mit
Fachbegriffen (`apps.description`, vorhanden) sind darum in dieser Variante wichtiger als in einer mit Daten. Beides
geht in die App-Definition `saft-app/1`; der Tabellen-Assistent schlägt die Beschreibungen gleich vor.

## 7. Bewertung der Architektur

**Trägt ohne Änderung:**

- Grundsatz 2 „Ein Weg je Aufgabe“ und Entscheidung 11 (`RecordWriter` als einziger Schreibweg). Ein Agent ist ein
  weiterer Aufrufer mit eigener Herkunft. Architekturtest 3 sichert das auch für `app/Ai`.
- Entscheidung 19–21 (Arbeiten) und 27 (Probelauf, Journal, Rücknahme bei Abbruch). Sitzung, Runde und Ausführung
  passen in dieses Modell; ein Wartezustand ist nicht nötig.
- Entscheidung 5 (Apps) und 9 (Super-Admin): klarer Kontext, keine Hintertür.
- Entscheidung 24 (Lesestellen): Neue Lesestellen sind inventarisiert, bevor Zeilenrechte kommen.
- Das Muster der fünf Assistenten und der Grundsatz aus RULES.md §10.2 („die KI speichert nie“). Die
  Übersetzer-Architektur ist dessen Verallgemeinerung von Strukturen auf Vorgänge.
- Der Anbieter-Vertrag (`ChatClient`, `AiConfig`): Ein lokales Modell ist eine zweite Umsetzung, keine Umkehr.

**Braucht Erweiterung, keine Umkehr:**

- `ChatClient`/`AssistantClient` um Werkzeugblöcke (V1); die drei Verträge können später zusammenwachsen.
- `WriteOptions` um die Herkunft `assistant` (V4).
- `AiUsage` von Anfragen auf Tokens und Werkzeugaufrufe (V11).
- `AiConfig`/App-Einstellungen um Höchststufe, Anbieter je Assistent, Fähigkeitsprofil (V12).
- Plugin-API um `agentTools()` und `DescribesParameters` (V2, V14), Minor-Version.
- `ApiManifest`/Entwickler-Kontext um die Werkzeugbeschreibungen; `AGENTS.md` des Kontexts beschreibt dann auch die
  Werkzeuge für externe Agenten.

**Braucht eine neue Entscheidung:**

- Datenstufen als Einstellung mit Vorgabe Stufe 1; Stufe 3 an den Anbieter gekoppelt.
- Läufe (B-142) als Vorschlagstyp und als Voraussetzung der schreibenden Etappe.
- Freigabe als eigenes Objekt mit Lebenszyklus (`assistant_proposals`), nicht als Formular.
- Kopf für Rücknahmen über alle Kennungen (V7) statt nur für die Sammeländerung.

**Was nicht passt und bewusst nicht gebaut werden sollte:**

- Ein gehosteter Agentendienst des Anbieters, der Schleife und Werkzeugausführung ausserhalb von SAFT betreibt:
  Rechte, Transaktion, Protokoll, Datenstufe und Freigabe liegen in SAFT; sie müssen die Schleife treiben. Ausserdem
  laufen Installationen über Bedrock, Vertex und künftig lokal. Die Messages-API mit eigener Schleife ist der Weg.
- Direkter SQL- oder Eloquent-Zugriff als Werkzeug. Alles Lesen läuft über `DataTable` und `ReportRunner`, alles
  Schreiben über Definition, Vorschau und Writer.
- Ein eigener Treiber je lokalem Modell. Ein OpenAI-kompatibler Treiber mit Fähigkeitsprofil genügt.
- Pseudonymisierung von Datensätzen als Hauptweg: bei Freitext und Quasi-Identifikatoren löchrig; höchstens als
  Ergänzung auf Stufe 2 (Beispielformate).
- Ein eigener Mechanismus für lange KI-Vorgänge neben den Arbeiten.

## 8. Vorgeschlagene Etappen

| Etappe | Inhalt | Setzt voraus | Ergebnis für Anwender |
|---|---|---|---|
| **A0 Entscheidungen** | Abschnitt 9 und 11 entscheiden; Konzept nachführen; B-051, B-052, B-053 aktualisieren; Datenstufen in RULES.md §10.2 und ROW_ACCESS.md §10 aufnehmen. **Teilweise erledigt 27.11.2026** (Abschnitt 12): Vorgaben für B-142 in RULES.md §8.7 und §10.2, Datenstufen in ROW_ACCESS.md §10, Fragen 6 und 8 entschieden; die übrigen Entscheidungen fallen mit A1 | – | – |
| **A1 Beratender Assistent (Stufe 0 und 1)** | V1 (`AgentClient`, Schleife), V2 (Register mit Datenstufe; `schema`, `pages`, `validate`, `count`, `statistics`), V5 (Filterdefinition mit Sprunglink), V11 (Grenzen, Audit, Token-Budget), V15 (Feldbeschreibung), Sitzung als Model mit Runden als Arbeit, Antwortformen Antwort/Rückfrage/**Arbeitsanweisung mit Sprunglinks**; Chat-Seite „Assistent“ je App (Erweiterung der Hilfe-Chat-Komponente). Teilbar: A1a Client, Schleife, `schema`, `pages`, Anleitung; A1b Zahlen-Werkzeuge, Filterlinks, Budget | – | „Welche Buchungen haben keinen Beleg?“ → „27, hier der Link in die gefilterte Ansicht; so tragen Sie sie in Aufgaben ein: …“. Erfüllt B-051 Stufe 2 ohne Datensätze |
| **A2 Läufe** (B-142 §8) | Laufdefinition `saft-rule/1 kind run`, `RuleValidator`, Parameterdialog, **Vorschau** mit Treffern, Abwahl, Probelauf, Ausführung als Arbeit je Treffer, Laufprotokoll, Markierung gegen Doppelverarbeitung, Recht je Lauf, Aufgabenart „Lauf ausführen“; V14 für Plugin-Arbeiten als Aktion | A1 nicht nötig; eigenständig nützlich | Rechnungslauf, Mahnlauf ohne Plugin, auch ohne KI |
| **A3 Vorschlagender Assistent** | V3 (`propose_run`, `propose_rule`, `assistant_proposals`, flüchtig oder gespeichert), V4 (Herkunft `assistant`), `list_runs`, `preview_run`; Protokollfilter „via Assistent“; V7 im Umfang von B-133 S3 mitentscheiden | A1, A2; B-133 S3 für die Rücknahme | Beispiele 3.1 und 3.3 vollständig. Erfüllt B-051 Stufe 3 und 4 ohne Datensätze |
| **A4 Dokumente lokal** | V9 (Upload in die Sitzung, `extract_document`, Zuordnungsregel, `match_document`), Aktion `attach_file`, Kandidatenwahl in der Oberfläche | A3 | Beispiel 3.2 |
| **A5 Lokales Modell und Stufe 3** | V12 (Treiber „OpenAI-kompatibel“, Fähigkeitsprofil, Eignungstest, Anbieter je Assistent, B-128/B-052), Höchststufe in den Einstellungen, Werkzeuge `search_records`, `get_record`, Dokumentblöcke, Aufbewahrungsfrist für Sitzungen; Massnahmen gegen Injection aus Datensätzen (V11) | A1; B-020 vor Einsatz in Apps mit Zeilenrechten | Freitextsuche, Erklären einzelner Datensätze, Lesen von Dokumenten; Cloud nur mit Freigabe |
| **A6 Nach aussen** | V13: MCP-Server und REST-API über dieselbe Werkzeugschicht, Token je Person, Freigabe in SAFT | A3 | Externe Agenten (B-033, B-023) |
| **begleitend** | V6 Zeilenrechte (B-020), Handbücher (Anwender: Assistent bedienen, Vorschau prüfen, Läufe speichern; Administrator: Datenstufen, Rechte, Läufe; Sysadmin: `.env`, Anbieter, lokales Modell, Datenschutz; Entwickler: `agentTools()`, `DescribesParameters`) | | |

Jede Etappe ist in einer Sitzung abschliessbar; A1 und A2 in je zwei Teilen. A2 kann vor A1 beginnen, weil Läufe auch
ohne KI tragen.

## 9. Anstehende Architekturentscheidungen

Vorschläge für `docs/ARCHITECTURE.de.md`/`.md` Abschnitt 10, Nummern folgen der dortigen Reihenfolge:

1. **Das Modell übersetzt, SAFT führt aus.** Sprachmodelle erzeugen Definitionen in SAFTs eigenen Sprachen (Filter,
   Formel, Lauf, Regel, Struktur); SAFT prüft sie gegen das Datenmodell, löst sie mit den Rechten der Person auf und
   führt sie über den Writer aus. Kein Werkzeug führt aus, was das Modell sagt. *Warum:* geschlossene, prüfbare
   Sprachen statt Vertrauen in Modellausgaben; dasselbe Muster wie Auswertungs- und App-Designer-Assistent.
2. **Datenstufen je Werkzeug, Höchststufe je Installation.** Jedes KI-Werkzeug deklariert, ob es Metadaten (0),
   Statistik (1), Auswahlwerte (2) oder Datensätze und Dateien (3) an das Modell gibt. Vorgabe ist Stufe 1; Stufe 3
   ist ab Werk aus und für lokale Anbieter vorgesehen, in der Cloud nur mit ausdrücklicher Freigabe. *Warum:* Die
   Zusage „nie Datensätze“ bleibt die Vorgabe, ohne Stufe 3 für später zu verbauen; eine Werkzeugschicht statt zwei.
3. **Werkzeuge prüfen Rechte, nie das Modell.** Jedes Werkzeug läuft als die bedienende Person (mit simulierten
   Rollen) in ihrer App über `DataTable`/`ReportRunner`; `withoutAuthorization()` ist unter `app/Ai` verboten
   (Architekturtest). *Warum:* Rechte dürfen nicht von der Formulierung eines Prompts abhängen.
4. **Die KI löst Vorgänge nur über Läufe aus.** Das einzige vorschlagende Werkzeug legt eine Laufdefinition an;
   Ausführung erst nach Freigabe in der Vorschau, als Arbeit mit Herkunft `assistant` und Kennung
   `assistant:<uuid>`. Gespeicherte und flüchtige Läufe sind derselbe Baustein. *Warum:* „erst vorschlagen“ ist eine
   Eigenschaft der Architektur, nicht des Prompts; Idempotenz und Fachlogik liegen in der Definition.
5. **Vorschlag ist Daten, nicht Verlauf.** Vorschläge liegen in einer Systemtabelle mit Lebenszyklus; aus dem
   Gesprächsverlauf wird nie ausgeführt. *Warum:* Audit, Rücknahme, MCP und Konflikterkennung brauchen ein
   eingefrorenes Objekt.
6. **Eine Werkzeugschicht für innen und aussen.** Chat im Panel, MCP-Server und REST-API rufen dieselben
   `AgentTool`-Verträge auf; die Schicht hängt nicht an der Oberfläche. *Warum:* drei Zugänge, eine Rechteprüfung,
   eine Datenstufe, ein Protokoll.
7. **Lange KI-Vorgänge sind Arbeiten.** Agentenrunden (`LongSteps`) und Ausführungen (`WritesRecords`, `CleansUp`)
   laufen als Arbeiten; die Sitzung wartet, nicht die Arbeit. *Warum:* Nr. 19, kein zweiter Mechanismus.
8. **Dokumente werden lokal verarbeitet.** Text und Merkmale von PDFs und Bildern extrahiert SAFT auf dem Server;
   die Zuordnung zu Datensätzen folgt einer gespeicherten Zuordnungsregel; ans Modell geht die Datei nur auf Stufe 3.
   *Warum:* Beispiel 3.2 ohne Datenweitergabe; die Regel läuft danach ohne KI.
9. **Zwei Anbieter-Treiber, Fähigkeitsprofil je Modell.** Anthropic (direkt, Bedrock, Vertex) und OpenAI-kompatibel
   (lokale Server) als Umsetzungen derselben Verträge; je Modell ein Profil (Werkzeuge, Schema, Kontext, Bilder), das
   die Werkzeugschicht anpasst; Zuordnung Anbieter, Modell und Budget je Assistent. *Warum:* Die Schnittstelle lokaler
   Server ist standardisiert, die Fähigkeiten der Modelle nicht.
10. **Rücknahme über die Kennung, nicht je Feature.** Ein Kopf je Vorgang (`run`) für Sammeländerung, Assistent und
    Läufe; eine Rücknahme-Arbeit für alle. *Warum:* B-133 S3 würde sonst zweimal gebaut.

## 10. Risiken

| Risiko | Einschätzung | Gegenmassnahme |
|---|---|---|
| Datenmodell zu dünn für die Übersetzung (fehlende Feldbeschreibungen, kryptische Namen) | mittel; in der Variante ohne Daten die Hauptfehlerquelle | V15 Feldbeschreibungen, Tabellen-Assistent schlägt sie vor; Rückfragen des Modells; `statistics` zum Verifizieren |
| Vorschau wird ohne Prüfung freigegeben („Klick-Müdigkeit“) | mittel | Vorher/Nachher und Einstufung; heikle Aktionen (löschen, versenden, Nummern vergeben) hervorgehoben und einzeln bestätigt; Rücknahme (V7) |
| Kleine Anzahlen auf Stufe 1 verraten Einzelwerte | gering | Schwelle für personenbezogen markierte Tabellen (Frage 1) |
| Stufe 3 in der Cloud wird freigeschaltet, ohne die Folgen zu kennen | mittel für die Akzeptanz | ab Werk aus, an Anbieter gekoppelt, Hinweistext, Sysadmin-Handbuch, Aufbewahrungsfrist |
| Lokales Modell zu schwach für Werkzeugaufrufe | hoch bei kleinen Modellen | Eignungstest, Fähigkeitsprofil, Korrekturrunden, kleinere Werkzeuglisten; Erwartung im Sysadmin-Handbuch (GPU-Rechner) |
| Kosten: Werkzeugschleifen sind teurer als eine Anfrage | gering ohne Daten (kleine Anfragen, volles Caching); mittel auf Stufe 3 | Token-Budget je Person und Sitzung, Modell je Assistent |
| Läufe (A2) sind ein grosses Vorhaben vor dem ersten schreibenden Nutzen | mittel | A1 liefert vorher Nutzen (Zahlen, Sprunglinks, Anleitungen); A2 trägt auch ohne KI |
| Drei Anbieter-Verträge | gering | Zusammenführen als Aufräumarbeit nach A1 |
| PHP-Zeitlimit auf Webspace bei Runden mit mehreren Modellaufrufen | gering, gelöst | `LongSteps` wie beim App-Designer |

## 11. Offene Fragen zur Diskussion

1. **Stufe 1 und kleine Anzahlen:** Reicht eine Schwelle (z. B. unter 5 → „wenige“) für Tabellen, die als
   personenbezogen markiert sind, oder braucht es eine Markierung je Feld? Wer markiert (Table-Designer, App)?
2. **Höchststufe:** je Installation (Vorschlag), zusätzlich je App, oder je Rolle? Koppelung „Stufe 3 nur lokal“ als
   Vorgabe mit Übersteuerung durch den Super-Admin?
3. **Freigabe-Granularität:** Ganzer Lauf, einzelne Treffer abwählbar (Vorschlag), heikle Aktionen einzeln bestätigen?
4. **Wer darf den Assistenten nutzen?** Ein Recht `assistant.use` plus die Datenrechte, zusätzlich `assistant.propose`
   für Läufe? Super-Admin ohne Sonderstellung (Vorschlag: ja).
5. **Sitzungen aufbewahren?** Wie Hilfe-Unterhaltungen (gespeichert, löschbar); auf Stufe 3 mit Frist
   (`activity_prune`-Muster).
6. **Rücknahme-Kopf:** `bulk_changes` jetzt bauen und später verallgemeinern, oder gleich `change_sets` für alle
   Kennungen? **Entschieden 27.11.2026:** `bulk_changes` bleibt (ist gebaut); der allgemeine Kopf entsteht mit dem
   Laufprotokoll in B-142 R3b (V7, RULES.md §8.7).
7. **Bereich „System“:** Werkzeuge für Systemobjekte (Benutzer, Rollen, Menüs, Aufgaben) zuerst nur lesend
   („Wer hat das Recht X?“, Stufe 0); schreibend bräuchte es eine zweite Ausführungsart über Models und Policies.
8. **Reihenfolge A1 und A2:** Läufe zuerst (schneller schreibender Nutzen, auch ohne KI) oder beratender Assistent
   zuerst (schneller sichtbarer KI-Nutzen)? **Entschieden 27.11.2026:** Läufe zuerst, als Teil von B-142 (Prio 1);
   A1 folgt nach den Prio-1-Punkten (Abschnitt 12).
9. **Arbeitsanweisung mit Sprunglinks:** Sollen Links auch Dialoge vorbelegen können (Sammeländerung mit Feld und
   Wert), oder nur Seiten mit Filter?
10. **Entwickler-Kontext:** Soll `AGENTS.md` des Kontexts die Werkzeuge und Datenstufen beschreiben, damit externe
    Agenten mit MCP sofort arbeiten können, und wird das Teil des API-Vertrags?
11. **Angebundene Tabellen (Table-Connector):** Werkzeuge zählen und lesen sie wie Designer-Tabellen (nur lesen,
    Datenstände); Läufe schreiben dorthin nicht. Bestätigen.
12. **Lokales Modell für die Extraktion (5.4):** Lohnt ein eigener, kleiner Einsatz (nur Dokumente, kein Agent) vor
    A5, oder reichen PHP-Textextraktion und Muster für Belege?
13. **Verändernde Werkzeuge aus Plugins (V2):** Darf ein Werkzeug aus `SaftPlugin::agentTools()` `mutating()` mit
    `true` melden, und was geschieht dann? Entscheidung 4 lässt Vorgänge nur über Läufe zu, regelt Plugin-Werkzeuge
    aber nicht ausdrücklich. Varianten: (a) das Register weist verändernde Plugin-Werkzeuge ab; ein Plugin schreibt
    nur über eine eigene Arbeit als Aktion eines Laufs (V14, `DescribesParameters`) (Vorschlag); (b) zugelassen, aber
    der Aufruf erzeugt nur einen Vorschlag mit Freigabe in der Vorschau (V3), nie eine direkte Ausführung. Zu klären
    ist auch, ob Wirkungen nach aussen ohne Datensatzänderung (E-Mail, Webhook, Aufruf eines Fremdsystems) als
    verändernd gelten, und wie das Register die Angabe prüft, da es sich auf die Deklaration des Plugins verlässt.

## 12. Einordnung: was vor B-142 gilt, was später kommt

Beurteilt am 27.11.2026 gegen den Backlog (Prio 1: B-142, B-133 S4, B-158/B-159, B-020). Massstab: Was muss **vor**
den nächsten Vorhaben entschieden sein, damit sie nicht anders gebaut werden, als der Agent es später braucht?

**Vor B-142 entschieden (kein Code, nur Vorgaben; nachgeführt in RULES.md §8.7 und §10.2):**

- **Entscheidungen 1 und 4** (das Modell übersetzt, SAFT führt aus; die KI löst Vorgänge nur über Läufe aus). Folge für
  B-142: `RuleValidator`, Vorschau und Ausführung nehmen eine **Definition** entgegen, nicht nur eine gespeicherte ID,
  damit flüchtige Läufe (V3) später ohne Umbau gehen. Das kostet in R1 nichts.
- **Entscheidung 10** (Rücknahme über die Kennung): Das Laufprotokoll von R3b ist der allgemeine Kopf je Kennung;
  `bulk_changes` bleibt bis dahin (V7, Frage 6).
- **V14** `DescribesParameters` gehört in den Umfang von R3b, weil Läufe Plugin-Arbeiten als Aktion starten.
- **Frage 8**: Läufe (A2) vor dem beratenden Assistenten (A1).

**Mit den nächsten Prio-1-Punkten mitnehmen, ohne eigenen Aufwand:**

- **B-158** (Filter „leer/gefüllt“): dieselbe Semantik wie `is_empty`/`not_empty` der Auswertungen (`ReportQuery`:
  Text leer = NULL oder Leertext). Dann ist V5 später nur ein Übersetzer von der Filterdefinition in den
  Filament-Zustand, kein zweites Format.
- **B-020** (Zeilenrechte): nichts vorziehen; ROW_ACCESS.md §10 nennt die Datenstufen.

**Früh und unabhängig, nicht blockierend:** **V15** Feldbeschreibung im Table-Designer (B-172). Sie nützt den fünf
bestehenden Assistenten und dem Entwickler-Kontext schon heute.

**Später, nach B-142, B-133 S4, B-158/B-159 und B-020:** A1 (`AgentClient`, Schleife, Werkzeugregister mit
Datenstufe, Zahlen-Werkzeuge, Sprunglinks, Token-Budget V11; erst dann `WriteOptions::ASSISTANT`, vorher wäre es toter
Code), danach A3 bis A6 in der Reihenfolge von Abschnitt 8. Die offenen Fragen 1 bis 5, 7 und 9 bis 13 warten bis A1;
keine davon ändert B-142 oder B-020.
