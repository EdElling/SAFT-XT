# SAFT XT

[English](README.md) · **Deutsch**

Modulares, metadatenbasiertes Datenbank-Applikations-Framework auf Basis von Laravel 13, FilamentPHP v5 und
PostgreSQL, MySQL oder MariaDB.

**Handbücher:** 
- [Übersicht](docs/manual/README.de.md)
- [Anwenderhandbuch](docs/manual/USER_GUIDE.de.md)
- [Administratorhandbuch](docs/manual/ADMIN_GUIDE.de.md)
- [Häufige Fragen](docs/manual/FAQ.de.md)
- [Systemadministration](docs/manual/SYSADMIN_GUIDE.de.md)
- [Entwicklerhandbuch: Plugins](docs/manual/DEVELOPER_GUIDE.de.md)

## Funktionen

### Tabellen und Daten
- **Table-Designer:** eigene Tabellen und Felder per Oberfläche anlegen (Schema `app_data`), mit sicheren
  Strukturänderungen; Feldtypen von Text über Zahl, Datum und Auswahlliste bis zur Verknüpfung; Pflicht- und
  Eindeutigkeitsprüfung, Prüfregeln je Feld und Tabelle; **berechnete Felder** mit Formeln, auch Summen über
  Kind-Datensätze; fortlaufende Nummern und „Übernehmen aus“ dem übergeordneten Datensatz; Datenmodell als Diagramm;
  Reiter **„Verwendet in“** zeigt, wo eine Tabelle in Auswertungen, Menüs, Rollen, Plugins und anderen Tabellen vorkommt
- **KI-Assistent im Table-Designer** (optional): neue Tabelle in eigenen Worten beschreiben oder bestehende anpassen –
  mit Feldern, Typen, Verknüpfungen und Bezeichnungen in vier Sprachen
- **Datenpflege:** Listen mit Suche, Filter, Sortierung, Paging und Spaltenauswahl; Master-Detail-Kaskade über mehrere
  Ebenen; Vorschau verknüpfter Datensätze; Papierkorb mit Wiederherstellen; Verlauf je Datensatz
- **Import:** neue Tabelle aus einer Excel- oder CSV-Datei anlegen; **Daten je Tabelle importieren** (leere Vorlage,
  Prüfung wie bei der Erfassung, alles oder nichts), je Tabelle freischaltbar und mit eigenem Recht
- **Export** der aktuellen Ansicht als Excel oder CSV, je Tabelle freischaltbar und mit eigenem Recht
- **Zahlenformat je Person:** wie die Sprache, automatisch nach Browser oder Schweiz (1'234.50)

### Auswertungen
- **Auswertungs-Editor** per Drag & Drop: Listen, Gruppierungen, Kreuztabellen und Kennzahlen über verknüpfte
  Tabellen hinweg, mit Filtern und Sortierung; Vorschau während der Bearbeitung
- **Grafiken:** Balken-, Linien- und Kreisdiagramm
- **Formeln:** berechnete Werte, berechnete Spalten und Kennzahlen aus Werten (z. B. Budget je Publikation)
- **KI-Assistent für Auswertungen** (optional): Auswertung in eigenen Worten beschreiben, Vorschlag wird wie jede Eingabe
  geprüft; übertragen wird nur das Datenmodell, nie Datensätze (Anthropic, Amazon Bedrock oder Google Vertex AI)
- Auswertungen teilen, ins Menü und aufs Dashboard bringen; **Tabellenverknüpfungen** verbinden Tabellen ohne
  Fremdschlüssel, auch über Datenbanken hinweg; **Datenstände** externer Tabellen für schnelle Auswertungen

### Externe Datenbanken
- **Datenbankverbindungen** zu PostgreSQL, MySQL, MariaDB und **Microsoft SQL Server** – mit Verbindungstest,
  verschlüsseltem Passwort, TLS und Nur-lesen-Modus
- **Table-Connector:** Tabellen und Views externer Datenbanken einzeln oder massenweise anbinden und wie
  Designer-Tabellen nutzen (Menü, Rechte, Liste, Details, Export, Auswertungen); Fremdschlüssel werden zu
  Verknüpfungen, CHECK-Regeln zu Prüfregeln; Schreiben je Tabelle freischaltbar; Abgleich mit dem Datenbankschema

### Rechte, Anmeldung und Betrieb
- **Rechte:** Rollen mit Funktionsrechten und Datenrechten je Tabelle (spatie/laravel-permission); „Ansicht als Rolle“
  zum Prüfen der Rechte
- **Anmeldung:** lokal, Single Sign-On (Microsoft Entra ID, SAML 2.0) oder beides; Rollen-Mapping, Single Logout,
  Notfall-Anmeldung
- **Dynamisches Menü** aus der Datenbank, gefiltert nach Rechten, inkl. Menüeinträgen für Datentabellen mit eigener
  Spaltenauswahl
- **Änderungsprotokoll** für alle Änderungen, An- und Abmeldungen, Exporte, Importe und KI-Anfragen
  (spatie/laravel-activitylog)
- **Geplante Aufgaben:** Datenstände aktualisieren und Protokolle bereinigen nach Zeitplan, mit Laufprotokoll und
  Benachrichtigung bei Fehlern
- **Dashboard** mit rollenabhängigen Widgets, z. B. eigene Auswertungen, letzte Änderungen und Zustand der geplanten
  Aufgaben (Hintergrundaufgaben)
- **Datenbanken für SAFT selbst:** PostgreSQL, MySQL oder MariaDB

### Sprachen und Handbücher
- **Oberfläche** auf Deutsch, Englisch, Französisch und Italienisch, je Person wählbar – auch Menüs, Tabellen- und
  Feldnamen (weitere Sprachen über `config/locales.php`); deutsche Texte in Schweizer Rechtschreibung
- **Online-Handbücher in vier Sprachen:** Anwenderhandbuch, Häufige Fragen und Administratorhandbuch mit Abbildungen,
  direkt in der Anwendung; Systemadministration und Entwicklerhandbuch auf Deutsch und Englisch

### Erweiterbarkeit
- **Plugins:** eigene Seiten und Fachfunktionen als Composer-Pakete, ohne den Kern zu ändern – mit Rechten,
  Menüvorschlägen, stabiler Schnittstelle (Datenzugriff mit Rechten und Prüfregeln, Ereignisse beim Speichern,
  Knöpfe in Datenansichten) und Schutz der benötigten Tabellen und Felder; Verwaltung unter **Administration › Anwendung ›
  Plugins**, Beispiel-Plugins „Lager-Umbuchung“, „Lager-Tafel“ und „CH-Prüfungen“ ([Entwicklerhandbuch](docs/manual/DEVELOPER_GUIDE.de.md))
- **Plugins erweitern auch Dashboard und Table-Designer:** eigene Kacheln, Formelfunktionen für berechnete Felder
  (z. B. Rundung auf 5 Rappen) und Prüfregeln für Felder (z. B. AHV-Nummer, IBAN)
- **Für Entwicklerinnen, Entwickler und KI-Agenten:** Plugin-Gerüst (`saft:make-plugin`), Prüfung vor der Auslieferung
  (`saft:plugins --check`), Test-Basis und **Entwickler-Kontext** (Regeln, API-Referenz und Schema der Fachtabellen als
  Dateien, z. B. für Claude Code oder Cursor)

### Sonstiges
- **Apps übertragen:** eine App (Tabellen, Auswertungen, Menüs, Rollen, Aufgaben, Handbuch) als Datei exportieren und in
  einer anderen Installation installieren, z. B. von Test nach Produktion – mit Vorschau und Diagramm, auch als Kopie
  mit neuem Präfix
- **Globale Einstellungen:** Anzeigename und Farbschema der Anwendung (Branding); Anbindung externer KI-Modelle für die
  Assistenten (Anbieter, Modell, verschlüsselter API-Schlüssel, Tageslimit, Verbindungstest); persönliche Einstellungen
  wie Sprache, Zahlenformat und Kontrastmodus unter „Meine Einstellungen“
- **Zentrales Fehler-Handling:** Datenbank-, Prüf- und Regelfehler erscheinen als verständliche Meldung statt als
  technischer Fehler (bzw. als einheitliche JSON-Struktur)
- **Datensicherung** per Befehl (`php artisan db:backup`) für PostgreSQL, MySQL und MariaDB

## Voraussetzungen

- PHP 8.4+ mit den Erweiterungen `pdo_pgsql` bzw. `pdo_mysql`, `intl`, `zip`, `openssl`
- Composer
- PostgreSQL 16+ (der Datenbankbenutzer muss Schemata anlegen dürfen) **oder** MySQL 8.0.16+ (der Benutzer braucht
  zusätzlich Rechte auf die Datenbank `<DB>_app_data` für die Tabellen des Table-Designers); Auswahl über
  `DB_CONNECTION` und die Blöcke `PGSQL_*` / `MYSQL_*` in der `.env`

## Installation

Schritt-für-Schritt-Anleitungen: [Entwickler-Rechner](docs/manual/INSTALL_DEVELOPMENT.de.md) ·
[Produktionsserver](docs/manual/INSTALL_PRODUCTION.de.md). Kurzfassung:

```bash
composer install
cp .env.example .env          # DB_* und ADMIN_* eintragen
php artisan key:generate
php artisan migrate
php artisan db:seed           # Rollen, Rechte, Menü, Super-Admin (ADMIN_EMAIL/ADMIN_PASSWORD)
php artisan serve             # http://localhost:8000 -> /admin
```

Optional Demo-Daten (Artikel, Kunden, Bestellungen, Bestellpositionen in der Menügruppe „Shop (Demo)“, dazu die
geteilte Beispiel-Auswertung „Umsatz je Monat und Kategorie“ als Balkendiagramm):

```bash
php artisan db:seed --class=DemoDataSeeder
```

Zwei weitere Demos als Tabellen in SAFT selbst (ohne Datenbankverbindung), je mit eigener Menügruppe, Datenmodell
und Beispiel-Auswertungen – dieselben Daten wie die externen Demos:

```bash
php artisan db:seed --class=LagerDemoSeeder       # „Lager (Demo)“: Lieferanten, Artikel, Lagerorte, Bestände, Bewegungen …
php artisan db:seed --class=ForschungDemoSeeder   # „Forschung (Demo)“: Organisationen, Personen, Projekte, Publikationen …
```

Optional externe Demo-Datenbank für die Datenbankverbindungen (Schema `forschung` mit Personen, Projekten,
Publikationen und Organisationen, auf demselben Server wie SAFT):

```bash
createdb -h 127.0.0.1 -U <benutzer> SAFT2_ext
php artisan external-demo:install      # Tabellen, Daten, Verbindung „Forschung (Demo)“ (nur lesen) und sechs angebundene Tabellen
```

### Produktivbetrieb

Installiere auf einem produktiven Server ohne die Entwicklungspakete und setze `APP_ENV=production` in der `.env`:

```bash
composer install --no-dev --optimize-autoloader
```

**Das ist sicherheitsrelevant:** Laravel Dusk registriert in jeder Umgebung ausser `production` die Route
`/_dusk/login/{userId}`, über die man sich ohne Passwort als beliebiger Benutzer anmelden kann. Ohne die
Entwicklungspakete gibt es diese Route nicht.

Die Demo-Daten stehen dort nicht zur Verfügung, weil `DemoDataSeeder` das Entwicklungspaket Faker braucht. Auch die
Tests laufen nur mit einer vollständigen Installation.

## Betrieb

| Aufgabe | Befehl |
|---|---|
| Lokale Anmeldung erzwingen (Notausgang) | `AUTH_FORCE_LOCAL=true` in der `.env` |
| Anmeldemodus per Konsole setzen | `php artisan auth:mode local` |
| Table-Designer mit Datenbank abgleichen | `php artisan app-data:verify` |
| Datenbank sichern (nach `storage/backups`, Dateien aus Dateifeldern als ZIP daneben) | `php artisan db:backup` |
| Verwaiste Dateien aus Dateifeldern aufräumen (`--dry-run` zählt nur) | `php artisan files:prune` |
| Berechnete Felder neu berechnen | `php artisan computed:recompute` |
| Angebundene Tabellen mit ihren externen Datenbanken abgleichen (`--apply` übernimmt Abweichungen) | `php artisan table-connector:verify` |
| Höchstzahl der Datensätze je Export ändern (Vorgabe 50.000) | `APP_DATA_EXPORT_MAX_ROWS` in der `.env` |
| Zeitzone und Vorgabesprache | `APP_TIMEZONE`, `APP_LOCALE` in der `.env` |

Eine Sicherung lässt sich in eine **neue, leere** Datenbank einspielen (Datenbankname und Datei anpassen):

```bash
createdb -h 127.0.0.1 -U <benutzer> SAFT2_wiederhergestellt
pg_restore -h 127.0.0.1 -U <benutzer> --no-owner -d SAFT2_wiederhergestellt storage/backups/<datei>.dump
```

Bei MySQL enthält die `.sql`-Datei Hauptdatenbank und `…_app_data` mit ihren ursprünglichen Namen
(`mysql -h 127.0.0.1 -u <benutzer> -p < storage/backups/<datei>.sql`); für andere Namen die Zeilen `CREATE DATABASE`
und `USE` vorher anpassen.

Die Dateien aus Dateifeldern liegen in der ZIP-Datei mit gleichem Namen und `_files.zip`; nach dem Einspielen der
Datenbank entpacken: `unzip storage/backups/<datei>_files.zip -d storage/app/private`.

Sicherungen enthalten alle Daten und bleiben ausserhalb des Repositorys. Bewahre zusätzlich eine Kopie ausserhalb
dieses Rechners auf.

## Tests

Die Tests laufen gegen eine eigene PostgreSQL-Datenbank `SAFT2_test` und die externe Testdatenbank `SAFT2_ext_test`
(siehe `phpunit.xml`; das Schema `forschung` legen die Tests dort selbst an). `composer test:mysql` führt dieselben
Tests gegen MySQL aus (`phpunit.mysql.xml`, Datenbank `SAFT2_test` auf dem MySQL-Server), `composer test:mariadb` gegen
MariaDB (`phpunit.mariadb.xml`); die Tests für MySQL als
externe Quelle nutzen dort `SAFT2_ext_test` und werden übersprungen, wenn kein MySQL-Server erreichbar ist:

```bash
createdb -h 127.0.0.1 -U <benutzer> SAFT2_test
createdb -h 127.0.0.1 -U <benutzer> SAFT2_ext_test
php artisan test
```

### Browser-Tests (Laravel Dusk)

Die Browser-Tests laufen headless gegen einen eigenen Server (Port 8010) und die Datenbank `SAFT2_dusk`.
**Jeder Test leert diese Datenbank.** `tests/DuskTestCase.php` bricht deshalb ab, sobald eine andere Datenbank
angesprochen würde. `php artisan dusk` nicht verwenden: Es tauscht vorübergehend die `.env` aus.

Einmalige Einrichtung:

```bash
createdb -h 127.0.0.1 -U <benutzer> SAFT2_dusk
createdb -h 127.0.0.1 -U <benutzer> SAFT2_ext_dusk
npx @puppeteer/browsers install chrome@stable --path ~/.cache/saft-dusk
npx @puppeteer/browsers install chromedriver@stable --path ~/.cache/saft-dusk
npm install   # axe-core für die Kontrastprüfung (WCAG)
```

Ausserdem die Vorlage `.env.browser.example` nach `.env.browser` kopieren (nicht im Repository), die
Zugangsdaten `PGSQL_*` eintragen und `php artisan key:generate --env=browser` ausführen.

Ausführen:

```bash
composer test:browser          # SAFT auf PostgreSQL
composer test:browser:mysql    # dieselben Tests mit SAFT auf MySQL
composer test:browser:mariadb  # dieselben Tests mit SAFT auf MariaDB
```

**MySQL:** Auf dem MySQL-Server die Datenbank `SAFT2_dusk` anlegen (`SAFT2_dusk_app_data` legt SAFT selbst an; der
Benutzer braucht Rechte auf beide), `.env.browser-mysql.example` nach `.env.browser-mysql` kopieren, die Zugangsdaten
`MYSQL_*` und `PGSQL_*` eintragen und `php artisan key:generate --env=browser-mysql` ausführen. Die externe
Testdatenbank des Table-Connectors (`SAFT2_ext_dusk`) bleibt auf PostgreSQL.

**MariaDB:** Auf dem MariaDB-Server die Datenbanken `SAFT2_dusk` und `SAFT2_dusk_app_data` anlegen und dem Benutzer
Rechte darauf geben, `.env.browser-mariadb.example` nach `.env.browser-mariadb` kopieren, die Zugangsdaten
`MARIADB_*` und `PGSQL_*` eintragen und `php artisan key:generate --env=browser-mariadb` ausführen.

**Wann laufen sie?** Die komplette Suite (etwa eine halbe Stunde je Datenbank) läuft unmittelbar vor der
Veröffentlichung eines Releases, auf allen drei Datenbanken – am einfachsten auf GitHub (siehe unten). Nach
Änderungen genügt der betroffene Test bzw. die betroffene Testklasse, z. B.
`vendor/bin/phpunit -c phpunit.dusk.xml tests/Browser/ListTest.php`.

### Tests auf GitHub Actions

Die Pipeline `.github/workflows/tests.yml` läuft **nur auf Knopfdruck**, nie automatisch bei einem Push: auf GitHub
unter **Actions → Tests → Run workflow** oder auf der Konsole:

```bash
gh workflow run tests.yml                                         # Vorgabe: Feature- und Browser-Tests, alle drei Datenbanken
gh workflow run tests.yml -f suites=feature -f databases=mariadb  # nur ein Teil
gh workflow run tests.yml -f sqlserver=true                       # zusätzlich SQL Server als externe Quelle
gh run watch                                                      # Fortschritt verfolgen
```

Je Testart und Datenbank läuft ein eigener Job, alle parallel; PostgreSQL, MySQL und MariaDB (und auf Wunsch SQL
Server) sind Wegwerf-Container mit Testpasswörtern aus der Workflow-Datei, Geheimnisse sind nicht nötig. Bei Fehlern
hängen Screenshots, Browser-Konsole und Laravel-Log als Artefakt am Lauf (`gh run download <lauf>`); mit
`gh run rerun <lauf> --failed` laufen nur die fehlgeschlagenen Jobs erneut. Ein kompletter Lauf braucht etwa
145 Minuten Rechenzeit (bei privaten Repositories im Kontingent von GitHub), nur die Feature-Tests etwa 25.

Screenshots fehlgeschlagener Tests liegen in `tests/Browser/screenshots`.

### Screenshots für das Benutzerhandbuch

Die Abbildungen im Benutzerhandbuch entstehen mit denselben Werkzeugen, auf Englisch und Deutsch:

```bash
composer manual:screenshots
```

Der Befehl nutzt die Einrichtung der Browser-Tests und leert ebenfalls `SAFT2_dusk`. Die Bilder landen in
`docs/manual/images/<sprache>/`; Einzelheiten stehen in Anhang A der Systemadministration.

## Entwicklung

Architektur- und Arbeitsregeln für die Weiterentwicklung stehen in [CLAUDE.md](CLAUDE.md).

## Lizenz

SAFT XT steht unter der **PolyForm Noncommercial License 1.0.0**. Verbindlich ist der Text in
[LICENSE](LICENSE); kurz gefasst:

- **Erlaubt, kostenlos:** Nutzung für nicht kommerzielle Zwecke – persönliche Nutzung (Forschung, Studium, Tests,
  Hobbyprojekte) und Nutzung durch gemeinnützige Organisationen, Bildungseinrichtungen, öffentliche
  Forschungseinrichtungen, Organisationen für öffentliche Sicherheit, Gesundheit oder Umweltschutz sowie Behörden;
  Quellcode ansehen, ändern und für diese Zwecke weitergeben.
- **Pflicht:** Wer eine Kopie erhält, bekommt den Lizenztext (oder dessen URL) und die Zeile mit `Required Notice:`
  aus [LICENSE](LICENSE) mit.
- **Nur mit eigener schriftlicher Lizenz:** jede kommerzielle Nutzung, auch die interne Nutzung in Unternehmen,
  Verkauf oder Weitergabe gegen Entgelt und Betrieb als kostenpflichtiger Dienst. Kontakt: siehe [LICENSE](LICENSE).
- **Keine Gewährleistung und keine Haftung**, soweit gesetzlich zulässig.

Komponenten von Drittanbietern behalten ihre eigenen Lizenzen, aufgeführt in
[THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

**Vorgänger SAFT2:** Das Vorgängerprojekt SAFT2 (Versionen bis 2.8.0) wurde unter einer anderen Lizenz (EULA)
veröffentlicht. Es ist zurückgezogen, wird nicht mehr unterstützt und darf nicht verwendet werden; verwenden Sie SAFT XT.

**Lizenztext:** [PolyForm Noncommercial 1.0.0](https://polyformproject.org/licenses/noncommercial/1.0.0#changes-and-new-works-license) auf der Webseite des PolyForm Project.
