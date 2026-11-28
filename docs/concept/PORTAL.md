# Konzept: Öffentliches Frontend (Portal je App) in SAFT XT

Stand: 28.11.2026 · Status: **erster Vorschlag zur Diskussion** · Backlog: noch ohne Nummer (Vorschlag B-189; B-188 ist das Release v0.10.0) · Bezug:
B-107 (Apps), B-020 (Zeilenrechte), B-023 (REST-API), B-134 (Personen als Benutzer)

## 1. Ziel

Eine App bekommt eine **öffentliche Seite für Menschen ausserhalb der Organisation**, ohne Zugang zum
Filament-Panel:

- Eine Musikschule zeigt ihr Kursangebot, und Interessierte melden sich über ein Formular zu einem Kurs an.
- Ein Lager zeigt den Bestand ausgewählter Artikel, und Kunden geben Bestellungen auf.
- Kunden melden sich an und sehen ihre eigenen Rechnungen, Anmeldungen oder Aufträge (Selbstbedienung).

Es gibt **ein Portal je App**. Es gehört zur App wie Tabellen, Menüs, Rollen und Auswertungen, reist in der
App-Definition `saft-app/1` mit und wird mit ihr aktiviert, deaktiviert, aktualisiert und entfernt.

**Grundsätze:**

- **Konfigurieren statt programmieren:** Seiten, freigegebene Felder, Filter und Formulare sind Daten. Programmiert
  wird nur, was sich so nicht abbilden lässt, als Plugin gegen die versionierte API.
- **Ausdrückliche Freigabe:** Nach aussen geht nur, was die App freigibt: Tabellen, Felder und Aktionen. Die
  Voreinstellung ist „nichts“. Das Vorbild sind die Schnittstellen zwischen Apps ([APPS.md](APPS.md) §5).
- **Ein Weg je Aufgabe:** Das Portal liest über einen eigenen, engen Zugang (`PortalData`) und schreibt über den
  `RecordWriter`. Feld- und Tabellenregeln, Nummernkreise, Plugin-Ereignisse und das Protokoll wirken dadurch ohne
  Sonderweg.
- **Betrieb ohne Sonderausstattung:** Ein Portal läuft auf demselben Webspace mit Cron, ohne Node-Server, Redis oder
  einen Queue-Worker.

**Nicht Ziel:**

- Ein CMS mit frei gestalteten Seiten, Blog oder Medienverwaltung. Freier Text ist nur als einfacher Textbaustein
  vorgesehen (Abschnitt 5).
- Shop mit Zahlungsabwicklung. Das bleibt ein Plugin (Abschnitt 13).
- Mandantenfähigkeit. Ein Portal ist eine Ansicht der App, keine eigene Organisation.

## 2. Heute

| Baustein | Verhalten |
|---|---|
| Routen | `/` leitet auf `/admin`; ausser Anmeldung, SSO und Logo verlangen alle Routen eine Anmeldung (`routes/web.php`) |
| Oberfläche | ein Filament-Panel für alle Apps; Datenansicht und Formulare werden aus den Metadaten zur Laufzeit gebaut (`DynamicData/Ui`) |
| Aktuelle App | `CurrentApp` aus der Sitzung bzw. den Rollen der angemeldeten Person |
| Freigaben | `AppInterfaces`: Tabellen und einzelne Felder für andere Apps freigeben, nur lesend, genutzte Freigaben sind Vertrag |
| Rechte | Spatie-Permissions für SAFT-Benutzer: `data.<tabelle>.<aktion>`, Rollen je App |
| Schreiben | `RecordWriter` mit `WriteOptions::origin` (`ui`, `plugin`, `import`, …); Protokoll mit `causer` und `via` |
| Dateien | `files/{uuid}` nur für angemeldete Personen |
| Zeilenrechte | Konzept B-020 (Entwurf); Lesestellen mit `scope` in `tests/Fixtures/read-sites.json` |
| Sicherheit | `SecurityHeaders` (ohne vollständige CSP wegen Filament), Throttling auf einzelnen Routen, 2FA und HIBP für Benutzer |

## 3. Entscheidungen (Vorschlag)

| Frage | Vorschlag |
|---|---|
| Technik | **Eigener Bereich mit Blade, Livewire und Tailwind** neben Filament, ohne Filament-Panel. Filament ist auf Verwaltung ausgelegt; ein öffentliches Portal braucht eigene Gestaltung, schlanke Seiten und eine strenge CSP. Livewire ist schon da, ein zusätzlicher Stack (Inertia, SPA) nicht nötig (Abschnitt 4). |
| Zuordnung | **Höchstens ein Portal je App**, Systemtabelle `portals` mit `app_id`. Mehrere Portale je App erst bei Bedarf. |
| Adresse | Vorgabe `/p/<präfix>` mit dem Präfix der App; optional **eigene Domain** je Portal (`Route::domain`). Die App ergibt sich aus der Route, nicht aus der Sitzung. |
| Inhalt | **Seiten als Daten:** Liste, Detail, Formular, Auswertung, Text und Plugin-Seite (Abschnitt 5), angeordnet in einem eigenen Menü des Portals. |
| Freigabe | Eine Seite zeigt nur **freigegebene Felder** einer Tabelle der eigenen App. Die Freigabe ist Vertrag wie bei Schnittstellen: Felder, die ein veröffentlichtes Portal nutzt, lassen sich nicht löschen, umbenennen oder unverträglich ändern. |
| Besucher | **Anonym** (Etappen P1, P2) und **Portal-Konten** (P3). Portal-Konten sind keine SAFT-Benutzer: eigene Tabelle, eigener Guard, kein Zugang zu `/admin` (Abschnitt 7). |
| Rechte anonym | Die Freigabe der Seite **ist** das Recht. Keine Gastrolle, weil Spatie-Rollen an Konten hängen und ein anonymer Besucher keines hat. |
| Rechte mit Konto | **Portal-Rollen** der App (Spatie, Guard `portal`) und **Zeilenrechte** aus B-020, damit Kunden nur ihre eigenen Datensätze sehen. |
| Lesen | Nur über `PortalData`, nie direkt über `DynamicRecord` in Views oder Controllern (Architekturtest, Abschnitt 14). |
| Schreiben | Über den `RecordWriter` mit neuer Herkunft `WriteOptions::PORTAL`; Wirkungen nach aussen (Bestätigungs-E-Mail) über `afterCommit()`. |
| Sprachen | Wie die Oberfläche: `de`, `en`, `fr`, `it`; Bezeichnungen von Tabellen und Feldern aus den vorhandenen Übersetzungen, Texte des Portals je Sprache. |

## 4. Varianten, die geprüft wurden

| Variante | Bewertung |
|---|---|
| **A Blade + Livewire im Kern, Seiten als Daten** (Vorschlag) | passt zu „Konfigurieren statt programmieren“, nutzt vorhandene Bausteine (Feldtypen, Formeln, Regeln, Writer), Webspace genügt |
| **B Zweites Filament-Panel je App** | schnell für angemeldete Kunden (Tabellen, Formulare fertig), aber Verwaltungsoptik, schwere Seiten, keine anonyme Nutzung ohne Umwege, CSP schwierig. Denkbar später als Darstellungsart für P3, nicht als Grundlage |
| **C Headless: REST-API (B-023) + fremdes Frontend** | maximale Freiheit, aber zweiter Stack, zweite Auslieferung, Rechte und Freigaben müssten trotzdem in SAFT entstehen. Ergänzt A später: Dieselben Freigaben (Abschnitt 6) sollen B-023 dienen |
| **D Portal nur als Plugin je App** | volle Freiheit, aber jede App programmiert Lesen, Rechte und Formulare neu; widerspricht dem Grundsatz. Bleibt als Erweiterung (Plugin-Seiten, Abschnitt 13) |

## 5. Seitenarten

| Art | Inhalt | Einstellungen |
|---|---|---|
| **Liste** | Datensätze einer Tabelle als Tabelle oder Karten | Tabelle, freigegebene Spalten, Filterformel (z. B. `status = 'offen' and start >= today()`), Sortierung, Suche und Filter für Besucher, Seitengrösse, Verweis auf eine Detailseite |
| **Detail** | ein Datensatz | freigegebene Felder, Abschnitte, Detailtabellen (nur freigegebene Kind-Tabellen), Knopf zu einem Formular mit Vorbelegung (z. B. „Anmelden“ übernimmt den Kurs) |
| **Formular** | Datensatz anlegen, mit Konto auch ändern | Tabelle, Felder mit Reihenfolge und Pflicht, feste Werte (z. B. `status = 'neu'`), Vorbelegung aus Detailseite oder Adresse, Bestätigungstext, Bestätigungs-E-Mail, optional Dokumentvorlage als Anhang |
| **Auswertung** | Ergebnis einer Auswertung der App | Auswertung, nur Spalten aus freigegebenen Feldern; Kennzahlen, Tabelle oder Diagramm |
| **Text** | einfacher Text je Sprache | Markdown, ohne HTML; für Impressum, Datenschutz, Einleitung |
| **Plugin-Seite** | vom Plugin geliefert | Vertrag `PortalPage` (Abschnitt 13) |

Jede Seite hat einen **Pfad** (`/p/musik/kurse`, `/p/musik/kurse/{schlüssel}`), eine Bezeichnung je Sprache, einen
Platz im Menü des Portals und einen Zustand *Entwurf* oder *veröffentlicht*. Entwürfe sieht nur, wer in SAFT das
Recht *Portal: bearbeiten* der App hat (Vorschau aus dem Panel).

**Adressen von Datensätzen:** Detailseiten verwenden nicht die fortlaufende `id`, damit Besucher nicht durchzählen
können. Vorschlag: eine öffentliche Kennung je Tabelle, wählbar als ein eindeutiges Feld der Tabelle (z. B.
Kursnummer aus einem Nummernkreis) oder eine automatisch angelegte Spalte `public_key` (UUID).

## 6. Freigabe von Tabellen und Feldern

- Im Table-Designer erhält jede Tabelle neben „Für andere Apps freigeben“ den Schalter **„Für das Portal freigeben“**
  mit Auswahl der Felder und der erlaubten Aktionen (*anzeigen*, *anlegen*, mit Konto *ändern*).
- Die Freigabe ist **unabhängig** von der Freigabe für andere Apps: Was eine Nachbar-App lesen darf, darf nicht
  automatisch ins Internet.
- **Vertrag:** Ein Feld, das eine veröffentlichte Seite verwendet, ist geschützt wie ein genutztes Schnittstellenfeld
  (nicht löschen, umbenennen, abwählen, unverträglich ändern). „Verwendet in“ zeigt die Seite.
- **Verknüpfte Datensätze:** Eine Liste darf die Bezeichnung eines verknüpften Datensatzes nur zeigen, wenn dessen
  Bezeichnungsfeld ebenfalls freigegeben ist. Sonst bleibt die Spalte leer. Pfade führen nicht in andere Apps.
- **Formeln** einer Seite (Filter, feste Werte) dürfen nur freigegebene Felder und die Funktionen `today()`, `now()`
  und später `portal_account()` (Abschnitt 7) verwenden. Die Prüfung läuft beim Speichern der Seite.
- **Dateifelder** sind nur mit eigener Freigabe öffentlich. Ausgeliefert werden sie über signierte, befristete
  Adressen, nicht über `files/{uuid}`.
- Die Freigaben sind so gebaut, dass die REST-API (B-023) sie später wiederverwenden kann: dieselbe Frage „welche
  Felder darf ein Leser ausserhalb von SAFT sehen“.

## 7. Besucher und Anmeldung

### 7.1 Anonym

Anonyme Besucher sehen veröffentlichte Seiten und können über Formulare **nur anlegen**, nie ändern oder löschen. Es
gibt kein Konto und keine Sitzungsdaten ausser CSRF-Schutz und Sprache.

### 7.2 Portal-Konten (Etappe P3)

- **Eigene Tabelle `portal_accounts`** je Portal (E-Mail, Passwort oder Anmeldelink, Sprache, bestätigt am,
  gesperrt), **eigener Guard `portal`** mit eigenem Sitzungs-Cookie. Ein Konto gilt nur für sein Portal.
- **Keine SAFT-Benutzer:** Kunden erscheinen nicht in der Benutzerverwaltung, haben keine SAFT-Rollen und erreichen
  `/admin` nicht. `User::isSuperAdmin()` bleibt unberührt; ein Architekturtest stellt sicher, dass Portal-Konten nie
  über `Gate::after` Rechte erhalten.
- **Verknüpfung mit einem Datensatz der App:** Ein Konto gehört zu genau einem Personendatensatz der App (z. B.
  *Schüler*, *Kunde*). Die Personentabelle und das E-Mail-Feld legt das Portal fest; Selbstregistrierung legt den
  Datensatz an oder verknüpft einen vorhandenen über die bestätigte E-Mail.
- **Portal-Rollen** der App (Spatie, Guard `portal`) bündeln die Freigaben für angemeldete Besucher, z. B. „Kunde“
  und „Firmenkunde“. Die Vorgabe ist eine Rolle je Portal.
- **Zeilenrechte:** Was ein Konto sieht und ändern darf, schränkt eine Bedingung je Seite bzw. Portal-Rolle ein, in
  der Formelsprache aus B-020, z. B. `schueler = portal_account()` oder `kunde.firma = portal_account().firma`. Das
  setzt den Mechanismus von B-020 Z2 (globaler Scope, Abfrageübersetzung) voraus und erweitert ihn um eine zweite
  Art Person.
- **Anmeldung:** Passwort mit denselben Regeln wie für Benutzer (12 Zeichen, HIBP) oder Anmeldelink per E-Mail
  (Vorschlag als Vorgabe, weil Kunden selten kommen). SSO für Portale nicht in der ersten Fassung.

## 8. Lesen und Schreiben

### 8.1 Lesen

- `App\Portal\PortalData` ist der einzige Zugang des Portals zu Datensätzen. Er nimmt eine Seite und den Besucher,
  wendet Freigabe, Filterformel und (mit Konto) Zeilenrechte an und liefert nur freigegebene Felder.
- Jede Lesestelle steht in `tests/Fixtures/read-sites.json` mit dem neuen Scope **`public`**: liest im Namen eines
  Besuchers ausserhalb von SAFT, nur freigegebene Felder.
- Anzahl, Seiten und Summen kommen aus der Datenbank (Query Builder), nicht aus einem Filter in PHP, wie bei B-020.
- Auswertungen laufen über `ReportQuery` mit einer Sperre auf nicht freigegebene Felder.

### 8.2 Schreiben

- Formulare schreiben über `RecordWriter::create()` bzw. `update()` mit `WriteOptions` Herkunft **`portal`**.
  Die Vorher-Phase setzt feste Werte, Vorgaben und Nummern; Feld- und Tabellenregeln prüfen wie in der Datenansicht;
  Plugin-Ereignisse laufen wie gewohnt.
- `created_by`/`updated_by` bleiben leer (kein SAFT-Benutzer). Das Protokoll erhält `via = portal`, die Seite und,
  wenn vorhanden, das Portal-Konto als Eigenschaft.
- **Bestätigungs-E-Mail** und optional ein Dokument (z. B. Anmeldebestätigung als PDF) laufen über
  `WriteContext::afterCommit()` und den vorhandenen `DocumentMailer`.
- **Fehler** aus Regeln (`BusinessRuleException`) erscheinen als Meldung am Formular in der Sprache des Besuchers;
  technische Fehler nur allgemein, Einzelheiten ins Protokoll.
- **Moderation (optional je Formular):** Datensätze aus dem Portal erhalten einen festen Status (z. B. *neu*), den
  die Organisation in SAFT bearbeitet. Ein eigener Freigabe-Mechanismus ist nicht nötig.

## 9. Oberfläche und Gestaltung

- Blade-Layouts und Livewire-Komponenten unter `resources/views/portal` und `app/Portal/Livewire`; Feldtypen bringen
  je eine Anzeige- und Eingabe-Komponente für das Portal mit (`FieldType`), damit neue Feldtypen nicht vergessen werden.
- Gestaltung je Portal: Logo, Name, zwei Farben, Schrift aus einer festen Auswahl, Kopf- und Fusstext. Kein eigenes
  CSS in der ersten Fassung (Sicherheit, Pflege).
- Barrierefreiheit als Anforderung, geprüft mit `axe-core` in den Browser-Tests (ist bereits Entwicklungsabhängigkeit).
- Für Suchmaschinen: Titel und Beschreibung je Seite, `robots` je Portal wählbar (Vorgabe: nicht indexieren, bis die
  Organisation es einschaltet).
- Online-Hilfe und KI-Hilfe gibt es im Portal nicht.

## 10. Adressen und Auslieferung

- `routes/portal.php`, eingebunden mit Präfix `p/{portal}` bzw. als Domain-Route für Portale mit eigener Domain.
  Ein Middleware `ResolvePortal` setzt Portal und App; `CurrentApp` erhält dafür eine zweite Quelle (Route statt
  Sitzung).
- Ein deaktiviertes Portal oder eine deaktivierte App antwortet mit 404 bzw. einer Wartungsseite der Organisation.
- Eigene Domains brauchen DNS und Zertifikat beim Hosting; das Sysadmin-Handbuch beschreibt es. `SESSION_DOMAIN` bleibt
  für `/admin`; das Portal hat ein eigenes Cookie.
- Zwischenspeicher: Listen- und Detailseiten anonymer Besucher dürfen kurz (Vorgabe 60 s, je Seite wählbar) im Cache
  liegen; ein Schreibvorgang auf die Tabelle leert ihn.

## 11. Sicherheit und Datenschutz

| Gefahr | Massnahme |
|---|---|
| Zu viel nach aussen | Voreinstellung „nichts freigegeben“; Freigabe pro Feld; Vorschau „So sieht ein Besucher die Seite“; Architekturtest gegen direkten Zugriff auf `DynamicRecord` |
| Durchzählen von Datensätzen | öffentliche Kennung statt `id` (Abschnitt 5) |
| Spam und Missbrauch von Formularen | Throttling je IP und Formular, Honeypot-Feld, Mindestzeit zwischen Laden und Absenden; optional ein Captcha ohne Drittanbieter |
| Angriffe über Eingaben | Validierung über Feldtypen und Regeln, keine HTML-Felder im Portal, Textbausteine als Markdown ohne HTML |
| Skripte und Einbettung | strenge CSP für das Portal (ohne Filament möglich), `SecurityHeaders` wie im Panel |
| Uploads | eigene Grenzen je Formular (Grösse, Typen), Ablage wie andere Dateien, Auslieferung nur signiert |
| Konten übernehmen | Anmeldelink mit kurzer Gültigkeit, Throttling, Sperre, Benachrichtigung bei neuer Anmeldung |
| Personendaten | Hinweis- und Einwilligungsfeld je Formular, Verweis auf die Datenschutzseite, Aufbewahrung wie die übrigen Daten der App |

## 12. Datenmodell (Systemtabellen, Hauptverbindung)

| Tabelle | Inhalt |
|---|---|
| `portals` | `app_id`, `key` (stabiler Schlüssel), Pfad, Domain, aktiv, Gestaltung (JSON), Sprachen, Anmeldeart, Personentabelle und E-Mail-Feld (P3), Indexierung |
| `portal_pages` | `portal_id`, `key`, Art, Pfad, Bezeichnung je Sprache, `custom_table_id` bzw. `report_id`, Einstellungen (JSON: Felder, Filter, Sortierung, feste Werte, Bestätigung), Menüplatz, Zustand |
| `portal_table_shares` | `custom_table_id`, freigegebene Felder, erlaubte Aktionen, öffentliche Kennung |
| `portal_accounts` (P3) | `portal_id`, E-Mail, Passwort (optional), Sprache, bestätigt, gesperrt, verknüpfter Datensatz (`record_id`) |

Models im Base-Model-Muster (`Models/Base/*` mit Reliese, Logik in `Models/*`), `HasAuditLog`, `BelongsToApp`,
`HasStableKey`, `HasTranslatedAttributes` wie bei Menüs und Auswertungen. Migrationen in der fortlaufenden
Nummernfolge.

## 13. Plugins

- Neuer Vertrag **`App\Plugins\Api\PortalPage`**: Schlüssel, Bezeichnung, Einstellungsfelder, Livewire-Komponente.
  Angemeldet über `SaftPlugin::portalPages()`. Lesen und Schreiben nur über `Saft::table()` mit einem
  Besucher-Kontext (`Saft::portalVisitor()`), der dieselben Freigaben anwendet wie `PortalData`.
- Neue API-Version (heute 1.11) und neuer Schnappschuss `tests/Feature/Plugins/plugin-api.json`.
- Beispiele: Stundenplan der Musikschule öffentlich (B-131), Bezahlen einer Rechnung über einen Zahlungsanbieter.

## 14. Apps, App-Definition, KI, Architektur

- **App-Definition:** `saft-app/1` erhält den Teil `portal` (Portal, Seiten, Freigaben, Portal-Rollen, ohne Konten).
  Installieren legt das Portal **deaktiviert** an; Aktualisieren behandelt Seiten und Freigaben im Änderungsplan
  (Entfernen einer veröffentlichten Seite: heikel; Wegnehmen einer genutzten Freigabe: zerstörend).
- **Rechte in SAFT:** `portal.view_any`, `portal.update` (Seiten und Gestaltung), `portal.publish`, `portal.accounts`
  je App; Policies unter `app/Policies`.
- **KI:** Der KI-App-Designer kann ein Portal vorschlagen (Seiten aus vorhandenen Tabellen); die Freigabe von Feldern
  bestätigt immer ein Mensch.
- **Architektur:** neue nummerierte Entscheidung in `docs/ARCHITECTURE.de.md` und `ARCHITECTURE.md` (Portal als
  zweite Oberfläche, eigener Guard, Freigabe als Recht, Herkunft `portal`, Scope `public`).
- **Architekturtests:** `app/Portal` verwendet weder `DynamicRecord::forTable()` noch Filament; Lesestellen mit Scope
  `public` nur in `app/Portal`; Portal-Konten nie in `User::superAdmins()`.
- **Handbücher:** Administratorhandbuch (vier Sprachen): Portal einrichten, Freigaben, Seiten. Sysadmin-Handbuch:
  Domains, Zertifikate, Cache, neue `.env`-Variablen. Entwicklerhandbuch: `PortalPage`.

## 15. Etappen

| Etappe | Inhalt | Umfang |
|---|---|---|
| **P1 Anzeigen** | `portals`, `portal_pages`, `portal_table_shares`; Freigabe im Table-Designer mit Vertrag; Seiten *Liste*, *Detail*, *Text*; `PortalData`, Scope `public`, öffentliche Kennung; Adresse `/p/<präfix>`; Gestaltung; Vorschau aus dem Panel; Architekturtests; Musikschule „Kursangebot“ als Prüfstein | gross |
| **P2 Formulare** | Seite *Formular* (nur anlegen), Herkunft `portal`, Vorbelegung aus Detailseite, Bestätigung per E-Mail und Dokument, Spamschutz, Uploads; Musikschule „Anmeldung“ | mittel |
| **P3 Konten** | `portal_accounts`, Guard `portal`, Anmeldelink, Personentabelle, Portal-Rollen, Zeilenrechte mit `portal_account()`, ändern eigener Datensätze; setzt B-020 Z2 und Z3 voraus | gross |
| **P4 Auswertungen und Domains** | Seite *Auswertung*, eigene Domain je Portal, Cache, App-Definition mit Änderungsplan | mittel |
| **P5 Plugins** | `PortalPage`, Besucher-Kontext in der Plugin-API, Beispiel im Musikschule-Plugin | mittel |

P1 und P2 hängen nicht von B-020 ab und lassen sich vorher umsetzen.

## 16. Abgrenzung

- **REST-API (B-023)** ist SAFT als Server für Programme; das Portal ist SAFT als Server für Menschen. Beide sollen
  dieselben Freigaben nutzen, werden aber getrennt gebaut.
- **Rechte auf Feldebene** für SAFT-Benutzer sind ein eigenes Thema (ROW_ACCESS.md §12). Die Feldfreigabe des Portals
  gilt nur für Besucher.
- **Externe Frontends** (eigene Website der Organisation) können Seiten des Portals verlinken; Einbetten per iframe
  ist wegen der CSP (`frame-ancestors`) zunächst nicht vorgesehen.

## 17. Offene Fragen zur Diskussion

1. **Ein Portal je App oder mehrere?** Vorschlag: eines. Fälle mit zwei Zielgruppen (Kunden und Lieferanten) lösen
   Portal-Rollen in P3.
2. **Portal über mehrere Apps?** Eine Organisation möchte vielleicht ein gemeinsames Portal für Musikschule und
   Shop. Vorschlag: nein, je App ein Portal; ein Plugin oder eine Startseite kann verlinken.
3. **Freigabe beim Feld oder bei der Seite?** Vorschlag: Freigabe beim Feld (Table-Designer) als Obergrenze, Auswahl
   bei der Seite. Dann sieht man an der Tabelle, was öffentlich sein kann.
4. **Portal-Konten oder SAFT-Benutzer mit Portal-Rolle?** Vorschlag: eigene Konten, damit Kunden nie in der
   Benutzerverwaltung, im SSO-Abgleich und in den Lizenz- und Rechtefragen des Panels auftauchen. Nachteil: zweite
   Kontenverwaltung.
5. **`portal_account()` oder allgemeines `me()`?** B-020 sieht `me('tabelle')` für SAFT-Benutzer vor. Vorschlag: im
   Portal liefert `me()` den verknüpften Personendatensatz des Kontos, damit Bedingungen in beiden Welten gleich
   aussehen.
6. **Bestätigung per E-Mail für anonyme Formulare** (Double-Opt-in), bevor der Datensatz entsteht? Vorschlag: je
   Formular wählbar; dann entsteht der Datensatz erst nach dem Klick.
7. **Cache und Zeilenrechte:** Mit Konto wird nicht zwischengespeichert. Reicht das, oder braucht es einen Cache je
   Konto?
8. **Gestaltung:** Reichen Logo, Farben und eine Schriftauswahl, oder braucht es Vorlagen (Layouts) zur Auswahl?
