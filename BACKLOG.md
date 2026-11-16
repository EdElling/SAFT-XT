# SAFT XT – Backlog

Themen, Anforderungen und Ideen, die nicht sofort umgesetzt werden. Neue Einträge bekommen die nächste freie Nummer;
erledigte bleiben mit Status und Bezug (Commit, Version) stehen.

**Status:** `offen` – noch nicht begonnen · `geplant` – Umsetzung beschlossen · `in Arbeit` · `umgesetzt` ·
`zurückgestellt` – bewusst später · `verworfen` – wird nicht gemacht (mit Grund)

## Als Nächstes (Prio 1)

1. **B-027** Filter und Sortierung je Person und Tabelle merken
2. **B-021** Feldtyp Datei/Bild

## Konfiguration übertragen

| Nr. | Thema | Beschreibung | Status | Bezug |
|---|---|---|---|---|
| B-001 | Bestehende Einträge aktualisieren (Etappe 9) | Abweichende Tabellen, Auswertungen, Menüs und Rollen beim Einspielen aktualisieren statt überspringen; feldgenaue Vorschau der Unterschiede (z. B. „Feld *rabatt* neu“, „Regel entfernt“), Auswahl was übernommen wird; heikle Änderungen über die Schutzregeln des Table-Designers. Für den Erstaufbau einer Produktion nicht nötig, für spätere Updates in dieselbe Richtung schon – bis dahin Änderungen direkt in der Produktion. | zurückgestellt | baut auf B-105 auf |
| B-002 | Stammdaten mitnehmen | Je Tabelle wählbar die Datensätze kleiner Nachschlagetabellen (z. B. Lagerorte, Kategorien) ins Paket aufnehmen. | offen | – |
| B-003 | Geplante Aufgaben mitnehmen | Datenstand-Aufgaben je Tabelle im Paket (Tabelle per Name). | offen | – |

## Datenmodell und Table-Designer

| Nr. | Thema | Beschreibung | Status | Bezug |
|---|---|---|---|---|
| B-010 | Externe Datenbank als interne Tabellen übernehmen | DDL-Schema oder Datenbank-Dump bzw. angebundene Tabellen (Table-Connector) als Tabellen des Table-Designers übernehmen, mit Verknüpfungen und optional Daten. Empfohlener Weg: „Als interne Tabellen übernehmen“ aus dem Table-Connector. | offen | – |
| B-011 | KI-Assistent für mehrere verknüpfte Tabellen | Der Assistent im Table-Designer schlägt ein ganzes Datenmodell (mehrere Tabellen mit Verknüpfungen) vor. | offen | – |

## Fehlende Grundfunktionen

| Nr. | Thema | Beschreibung | Status | Bezug |
|---|---|---|---|---|
| B-020 | Rechte auf Zeilenebene | Datensätze nur sehen bzw. bearbeiten, wenn eine Bedingung erfüllt ist (z. B. eigene Abteilung, eigene Datensätze). | offen | – |
| B-021 | Feldtyp Datei/Bild | Anhänge wie Verträge, Fotos, PDFs an Datensätzen. Speicherort Laravel-Dateisystem (lokal privat, wahlweise S3), nie öffentlich – Herunterladen über geprüfte Adresse mit den Datenrechten des Datensatzes. Je Feld: erlaubte Typen, Höchstgrösse, eine oder mehrere Dateien. Vorschau (Vorschaubilder in Listen, Bild/PDF im Detail). Löschen: Papierkorb behält Dateien, endgültiges Löschen entfernt sie, Feld löschen warnt mit Anzahl/Grösse. Änderungsprotokoll; Excel-Export nur Dateinamen (ZIP später); Sicherung muss die Dateien einschliessen (Systemadministration); Konfiguration übertragen ohne Dateien; optional Virenprüfung. | geplant (Prio 1) | – |
| B-022 | Gespeicherte Ansichten | Filter, Sortierung und Spalten einer Datenansicht unter einem Namen speichern, persönlich oder für eine Rolle. | offen | – |
| B-023 | REST-API | Zugriff von aussen auf Tabellen des Table-Designers mit Token, Rechten und Prüfregeln (wie `Saft::table()`). | offen | – |
| B-024 | Massenbearbeitung | Mehrere Datensätze auf einmal ändern (ein Feld auf einen Wert setzen). | offen | – |
| B-025 | Ereignisregeln ohne Code | Einfache Regeln in der Oberfläche („wenn Status = erledigt, dann Datum setzen / Meldung senden“). | offen | – |
| B-027 | Filter und Sortierung merken | Filter, Sortierung und Suchbegriff einer Datenansicht je Person und Tabelle dauerhaft speichern (heute nur für die Sitzung), ohne Weitergabe; Knopf „Filter zurücksetzen“. Spaltenauswahl wird schon je Person gespeichert (user_table_columns). Vorstufe zu B-022. | geplant (Prio 1) | – |
| B-026 | PDF und Dokumentvorlagen | Datensätze bzw. Auswertungen als PDF nach Vorlage ausgeben (z. B. Lieferschein, Rechnung). | offen | – |

## Plugins

| Nr. | Thema | Beschreibung | Status | Bezug |
|---|---|---|---|---|
| B-030 | Plugins ein- und ausschalten | Installiertes Plugin auf der Seite „Plugins“ oder mit `saft:plugins --disable/--enable` ausschalten, ohne es zu entfernen: Seiten und Kacheln 404, Menü ausgeblendet, Listener/Knöpfe/Regeln/Formelfunktionen ruhen, Aufgaben übersprungen; protokolliert. Plugin-API 1.4 (`isEnabled()`, `withoutPlugin()`). | umgesetzt | API 1.4 |
| B-031 | Installation über die Oberfläche | Plugin-Paket hochladen und vorbereitet installieren, mit Kompatibilitäts- und KI-Prüfbericht (Sicherheit, nur Plugin-API). | offen | – |
| B-032 | Abhängigkeiten zwischen Plugins | Benötigte Plugins aus `require` der `composer.json`; ist eines ausgeschaltet oder ruht es, ruht das abhängige Plugin mit (Anzeige unter „Abhängigkeiten“, `saft:plugins --check`). Umgesetzt mit B-030. | umgesetzt | API 1.4 |
| B-033 | MCP-Server für den Entwickler-Kontext | KI-Agenten fragen Schema und API live ab statt über Dateien. | offen | baut auf B-104 auf |
| B-034 | Anonymisierte Beispieldaten im Kontext | Optional einige anonymisierte Beispielzeilen je Tabelle im Entwickler-Kontext. | zurückgestellt | Entscheid 2026-11-15: vorerst keine Beispieldaten (Datenschutz) |
| B-035 | Aufgaben-Ergebnis mit Hinweis | Eine Aufgabe kann im `TaskResult` angeben, dass ihr Ergebnis Aufmerksamkeit braucht (z. B. „12 Bestände nicht gezählt“): Meldung nach „Jetzt ausführen“ orange statt grün, Markierung im Laufprotokoll. Erweiterung der Plugin-API (Minor). | offen | – |
| B-036 | Arten geplanter Aufgaben in Plugins | `TaskType`, `TaskResult`, `TaskSkipped` in der Plugin-API; Beispiele „Inventur-Erinnerung“ und „Mindestbestand melden“ in Lager-Tafel, Anhang A.8 im Entwicklerhandbuch. | umgesetzt | API 1.5 |
| B-037 | Handbücher aus Plugins | Wissensquelle für Online-Hilfe und Hilfe-Assistent (`KnowledgeSource`): ein Plugin liefert Markdown-Handbücher je Sprache mit dem Recht, das sie sichtbar macht (z. B. `SaftPlugin::manuals()`); ruht und ist aus mit dem Plugin. Plugin-API (Minor). **Anmerkung:** als erstes Beispiel ein Benutzerhandbuch für die Lager-Demo (Bestände, Bewegungen, Umbuchen, Lagertafel, Inventur, Mindestbestand, Artikelnummer) in vier Sprachen. | umgesetzt | API 1.6 |
| B-038 | Beschreibung für Prüfregeln aus Plugins | Optionale Beschreibung bei `FieldRule` (z. B. „ein Buchstabe, Bindestrich, vier Ziffern“): im Table-Designer, im Datenmodell für den Hilfe-Assistenten und im Entwickler-Kontext. Anlass: Der Assistent kannte die Regel für die Artikelnummer nur dem Namen nach. | umgesetzt | API 1.6 |
| B-039 | Handbuchseiten ohne Plugin | In der Administration gepflegte Seiten als Wissensquelle (für Anwendungen nur aus Konfiguration), per Konfigurationstransfer übertragbar. | offen | baut auf B-037 auf |

## KI-Assistenz

| Nr. | Thema | Beschreibung | Status | Bezug |
|---|---|---|---|---|
| B-050 | KI-Assistent für die Handbücher | Hilfe-Assistent: Knopf „?“ auf jeder Seite und Reiter in der Dokumentation; Antworten aus den Handbüchern der Online-Hilfe nach Rolle (auch in der Rollen-Simulation), mit geprüften Verweisen; Handbücher vollständig mit Prompt-Caching statt Vektordatenbank; optional Datenmodell mit Leserecht (Schalter); Unterhaltungen gespeichert; Recht `help.assistant`. Schnittstellen `ChatClient` (für ein lokales Modell) und `KnowledgeSource` (für weitere Quellen). Ohne Systemadministrations- und Entwicklerhandbuch (Entscheid 2026-11-16). | umgesetzt | – |
| B-051 | KI-Assistenz in der Anwendung | Stufen auf B-050 aufbauend: 2. Abfragen in Worten → Filter bzw. Auswertungsdefinition (kein SQL, Auswertungs-Engine mit Rechten); 3. Handeln: Vorschläge (z. B. Lieferschein-PDF erfassen, Massenänderung) mit Bestätigung, gespeichert über Saft::table() mit Regeln und Protokoll „via KI“; 4. Automatisieren: KI erzeugt geplante Aufgaben bzw. Ereignisregeln (B-025), Ausführung ohne KI. Nötig: Werkzeugschicht mit den Rechten der Person, Bestätigung vor dem Schreiben, Obergrenzen, Schutz vor Prompt-Injection, Kostenlimit; nach aussen über MCP (B-033) und REST-API (B-023). | offen | – |
| B-052 | Lokal betriebenes Sprachmodell | Zweite Umsetzung von `ChatClient` für OpenAI-kompatible Server (Ollama, vLLM) mit Adresse und Modell in den KI-Einstellungen; bei kleinem Kontextfenster Abschnitte nachschlagen statt ganze Handbücher. | offen | – |
| B-053 | KI liest Datensätze (Hilfe-Assistent, Etappe 3) | Werkzeuge zählen, summieren, kurze Listen – lesend, mit den Datenrechten der Person, Obergrenzen, jeder Zugriff im Änderungsprotokoll; Schalter „KI darf Datensätze sehen“ nur mit eigenem Modell (B-052). | offen | baut auf B-052 auf |
| B-054 | Aufbewahrung der Hilfe-Unterhaltungen | Gespeicherte Unterhaltungen nach einer einstellbaren Frist automatisch löschen (geplante Aufgabe). | offen | – |

## Schnittstellen

| Nr. | Thema | Beschreibung | Status | Bezug |
|---|---|---|---|---|
| B-060 | REST-Anbindungen (Import/Export, Webhooks) | SAFT als Client: Daten aus fremden REST-APIs holen bzw. senden – Adresse, Anmeldung (API-Schlüssel/OAuth2, verschlüsselt), Seitenweise, Zuordnung JSON → Felder mit Vorschau, Abgleich über Schlüsselfeld, von Hand oder als geplante Aufgabe mit Protokoll; Webhooks bei Ereignissen (B-025). Komplexe Anbindungen (ERP, Buchhaltung) als Plugins. SAFT als Server: B-023. | offen | – |

## Technik und Betrieb

| Nr. | Thema | Beschreibung | Status | Bezug |
|---|---|---|---|---|
| B-042 | Lizenz PolyForm Noncommercial 1.0.0 | PolyForm Noncommercial statt der EULA des Vorgängers SAFT2; kommerzielle Nutzung (auch intern in Firmen) nur mit eigener Lizenz. SAFT2 (bis 2.8.0) ist zurückgezogen (Hinweis in LICENSE und README). | umgesetzt | 0.1.0 |
| B-043 | Neues Repository SAFT XT | Neubeginn ohne die Historie von SAFT2 (die bleibt privat als Archiv), Name SAFT XT, Version 0.1.0; technische Bezeichner (`saft:…`, `Saft::table()`, `config/saft.php`) und Datenbanknamen bleiben. Repository zunächst privat, nach Durchsicht öffentlich geschaltet (Ruleset für `main`, Secret Scanning, private Meldung von Lücken). | umgesetzt | 0.1.0 |
| B-044 | Release v0.1.0 | Erstes Release von SAFT XT; Feature-Tests auf GitHub (3 Datenbanken), betroffene Browser-Tests lokal (PostgreSQL). | umgesetzt | v0.1.0 |
| B-045 | Hoster mit einem Benutzer je Datenbank (Strato) | Zweite Datenbank (`app_data`) mit eigenem Server, Port, Benutzer und Passwort (`DB_APP_DATA_*`) und vom Hoster vergebenem Namen (`APP_DATA_SCHEMA`); `migrate` legt vorhandene Datenbanken nicht an; `db:backup` sichert in zwei Teilen. Bei Strato erprobt (BTR-08, 16.11.2026; Sicherung dort mit B-046). | umgesetzt | – |
| B-046 | Sicherung in PHP für Webspaces | `db:backup --php` bzw. `DB_BACKUP_METHOD=php` sichert MySQL/MariaDB ohne mysqldump (SQL-Skript, berechnete Spalten ohne Werte, ungepuffert) – für Webspaces mit fehlendem oder zu altem mysqldump (Strato: 5.6, „Error in field count“ bei unsichtbaren Spalten). Verständliche Meldungen, wenn das Programm fehlt. | umgesetzt | – |
| B-055 | Sicherheits-Header | Middleware für alle Antworten: `X-Content-Type-Options`, `X-Frame-Options` und `frame-ancestors`, `Referrer-Policy`, `Permissions-Policy`, `base-uri`/`object-src`; HSTS ein Jahr, nur über HTTPS, abschaltbar (`SECURITY_HSTS`). | umgesetzt | – |
| B-056 | Strengere Passwortregel | Mindestens 12 Zeichen und Abgleich mit geleakten Passwörtern (k-Anonymität, abschaltbar für Server ohne Internet). | geplant | – |
| B-057 | Zwei-Faktor-Anmeldung | Authenticator-App (Filament) mit Wiederherstellungscodes; App-Einstellung freiwillig / Pflicht für Administration (Vorgabe) / Pflicht für alle; SSO-Konten ausgenommen; Zurücksetzen durch die Administration und per Befehl. | geplant | – |
| B-058 | Strenge Content-Security-Policy | Vollständige CSP mit Nonces für Skripte und Stile; heute nicht möglich, weil Filament und Livewire Inline-Skripte brauchen. Erst prüfen, wenn Filament CSP-Nonces unterstützt. | offen | baut auf B-055 auf |
| B-040 | OpenSpout 5 | Erst mit Filament 6 (Filament verlangt OpenSpout ^4). | zurückgestellt | – |

## Umgesetzt (Übersicht)

| Nr. | Thema | Status | Bezug |
|---|---|---|---|
| B-100 | Plugin-Mechanismus (Etappen 1–4: Rahmen, API, Abhängigkeiten, API-Versionen) | umgesetzt | in 0.1.0 enthalten |
| B-101 | Plugins für externe Entwickler: Gerüst, Test-Basis, Prüfung (Etappe 5) | umgesetzt | in 0.1.0 enthalten |
| B-102 | Dashboard-Kacheln, Formelfunktionen, Prüfregeln aus Plugins (Etappe 6) | umgesetzt | in 0.1.0 enthalten |
| B-103 | Table-Designer: Reiter „Verwendet in“, Warnung beim Löschen | umgesetzt | in 0.1.0 enthalten |
| B-104 | Entwickler-Kontext für externe Entwicklung und KI-Agenten (Etappe 7) | umgesetzt | in 0.1.0 enthalten |
| B-105 | Konfiguration übertragen: Export und Einspielen von Neuem (Etappe 8) | umgesetzt | in 0.1.0 enthalten |
