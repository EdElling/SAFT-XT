# SAFT XT – Systemadministration

[English](SYSADMIN_GUIDE.md) · **Deutsch**

**Stand:** Version 0.1 · **Zielgruppe:** Betrieb und Installation (Server, Datenbank, Updates)

**Weitere Handbücher:** [Anwenderhandbuch](USER_GUIDE.de.md) · [Administratorhandbuch](ADMIN_GUIDE.de.md) · [Häufige Fragen](FAQ.de.md)

---

## Inhalt

- [1. Für den Betrieb](#1-für-den-betrieb)
- [2. Online-Hilfe und Version](#2-online-hilfe-und-version)
- [3. Updates und Sicherheit](#3-updates-und-sicherheit)
- [4. Geplante Aufgaben und Cron](#4-geplante-aufgaben-und-cron)
- [5. KI-Funktionen](#5-ki-funktionen)
- [6. Befehlsreferenz (php artisan)](#6-befehlsreferenz-php-artisan)
   - [6.1 SAFT-eigene Befehle](#61-saft-eigene-befehle)
   - [6.2 Grundausstattung und Demo-Daten (Seeder)](#62-grundausstattung-und-demo-daten-seeder)
   - [6.3 Laravel-Befehle, die SAFT braucht](#63-laravel-befehle-die-saft-braucht)
   - [6.4 Befehle im laufenden Betrieb](#64-befehle-im-laufenden-betrieb)
- [7. Plugins](#7-plugins)
- [Anhang A – Screenshots aktualisieren](#anhang-a--screenshots-aktualisieren)
- [Weitere Anleitungen](#weitere-anleitungen)

---
## 1. Für den Betrieb

Wie Sie SAFT Schritt für Schritt installieren, beschreiben [Anhang B](INSTALL_DEVELOPMENT.de.md) (Entwickler-Rechner)
und [Anhang C](INSTALL_PRODUCTION.de.md) (Produktionsserver, z. B. ein Managed Server).

| Aufgabe | Befehl / Einstellung |
|---|---|
| Lokale Anmeldung erzwingen (Notausgang, z. B. bei IdP-Ausfall) | `AUTH_FORCE_LOCAL=true` in der `.env` |
| Anmeldemodus per Konsole setzen | `php artisan auth:mode local` (oder `sso`, `hybrid`) |
| Einstellungen des Table-Designers mit der Datenbank abgleichen | `php artisan app-data:verify` |
| Angebundene Tabellen mit dem Schema ihrer externen Datenbanken abgleichen (Exit-Code 1 bei Abweichungen; `--apply` übernimmt sie) | `php artisan table-connector:verify` |
| Massenverknüpfung auf der Konsole: alle noch nicht angebundenen Tabellen (`--all`) oder einzelne (`--table=…`), mit `--prefix=`, `--menu-group=<ID>` bzw. `none`, `--role=<Name>`, `--export`, `--create`, `--update`, `--delete`, `--inactive`, `--schema=`, `--no-details` | `php artisan table-connector:connect "<Verbindung>" --all` |
| Datenbank wählen: PostgreSQL, MySQL oder MariaDB, mit den Zugangsdaten `PGSQL_*`, `MYSQL_*` bzw. `MARIADB_*` | `DB_CONNECTION=pgsql`, `mysql` bzw. `mariadb` in der `.env` |
| Datenbank sichern (nach `storage/backups`; MySQL/MariaDB: Hauptdatenbank und `…_app_data` in einer `.sql`-Datei; Pfad zum Programm bei Bedarf in `PGSQL_DUMP_BINARY`, `MYSQL_DUMP_BINARY` bzw. `MARIADB_DUMP_BINARY`) | `php artisan db:backup` |
| Berechnete Felder neu berechnen (nach Änderungen an SAFT vorbei; optional Tabellennamen) | `php artisan computed:recompute` |
| Höchstzahl der Datensätze je Export ändern (Vorgabe 50.000) | `APP_DATA_EXPORT_MAX_ROWS=50000` in der `.env` |
| Höchstzahl der Zeilen je Datenimport in eine bestehende Tabelle ändern (Vorgabe 10'000) | `APP_DATA_IMPORT_MAX_RECORD_ROWS=10000` in der `.env` |
| Zeitzone der Anwendung (Vorgabe `Europe/Zurich`) | `APP_TIMEZONE` in der `.env` |
| Dem Load Balancer des Hosters vertrauen, wenn HTTPS vor dem Server endet (Adressen des Proxys, kommagetrennt) | `TRUSTED_PROXIES` in der `.env` |
| Vorgabesprache, wenn weder Benutzer noch Browser eine angebotene Sprache festlegen | `APP_LOCALE=de` in der `.env` |
| Demo-Daten (Artikel, Kunden, Bestellungen, Positionen) einspielen (nicht bei Installation mit `--no-dev`) | `php artisan db:seed --class=DemoDataSeeder` |
| Externe Demo-Datenbank (Schema `forschung`; die leere Datenbank `SAFT2_ext` muss existieren) mit Verbindung „Forschung (Demo)“ und den sechs angebundenen Tabellen unter „Forschungsdatenbank“ (technische Namen `fdb_…`, damit sie neben der internen Forschungs-Demo `forschung_…` bestehen; belegt eine andere Tabelle einen dieser Namen, bricht der Befehl ab, ohne etwas anzulegen; bereits angebundene bleiben unverändert; nur die Verbindung: `--without-tables`). Mit `--driver=mysql` als MySQL-Fassung (Zugangsdaten `MYSQL_*`), mit `--driver=mariadb` als MariaDB-Fassung (Zugangsdaten `MARIADB_*`), mit `--driver=sqlsrv` als SQL-Server-Fassung im Schema `forschung` einer vorhandenen Datenbank (Zugangsdaten `MSSQL_*`) | `php artisan external-demo:install` |
| Grundausstattung (Rollen, Rechte, Menü) | `php artisan db:seed` |

Hinweise:

- **Produktivbetrieb:** Installieren Sie mit `composer install --no-dev --optimize-autoloader` und setzen Sie
  `APP_ENV=production`. Sonst steht eine Test-Route bereit, über die man sich ohne Passwort anmelden kann.
- **Sicherungen** enthalten alle Daten. Bewahren Sie zusätzlich eine Kopie ausserhalb des Servers auf. Wie Sie eine
  Sicherung einspielen, steht in der [README](../../README.de.md#betrieb).
- Der **technische Name** `APP_NAME` in der `.env` bestimmt Cookie- und Cache-Namen. Der sichtbare Name wird
  in den App-Einstellungen gepflegt; eine Änderung dort meldet niemanden ab.
- Die Tabellen des Table-Designers liegen getrennt von den Systemtabellen: bei PostgreSQL im Schema `app_data`, bei
  MySQL in einer eigenen Datenbank `<Datenbank>_app_data`.
- **Zeitzone:** Alle Zeiten werden in der Zeitzone aus `APP_TIMEZONE` angezeigt.

**Eine weitere Sprache ergänzen** (Entwicklung, nicht über die Oberfläche):

1. In `config/locales.php` einen Eintrag mit Namen, Datumsformaten und CSV-Schreibweise anlegen.
2. Die Standardtexte von Laravel mit `php artisan lang:add <sprache>` einspielen.
3. Die eigenen Texte übersetzen: je Datei in `lang/de` eine gleichnamige Datei in `lang/<sprache>`.
4. Die Tests zeigen, ob Texte fehlen – auch solche, die in Filaments eigener Übersetzung fehlen. Diese ergänzen
   Sie unter `lang/vendor/<paket>/<sprache>/`.

Die neue Sprache erscheint danach automatisch im Sprach-Symbol und als Eingabefeld bei mehrsprachigen
Bezeichnungen.

---

## 2. Online-Hilfe und Version

Die Online-Hilfe „Dokumentation“ liest das Anwenderhandbuch, die häufigen Fragen und das Administratorhandbuch direkt
aus `docs/manual` (Deutsch für deutsche Oberfläche, sonst Englisch); Änderungen an diesen Dateien erscheinen ohne
weiteren Schritt. Dieses Handbuch und die Installationsanleitungen erscheinen dort bewusst nicht. Die unter
„Über SAFT XT“ angezeigte Version steht in `config/saft.php` (`version`) und wird bei jedem Release angehoben; daneben steht
die Version der Plugin-Schnittstelle (`api_versions`, siehe [Entwicklerhandbuch, Kapitel 17](DEVELOPER_GUIDE.de.md#17-api-versionen-und-änderungsprotokoll)).

## 3. Updates und Sicherheit

SAFT besteht aus eigenem Code und rund 135 PHP-Paketen sowie einigen JavaScript-Bibliotheken (vollständige Liste:
`THIRD_PARTY_NOTICES.md`). Für ihre Sicherheit sind drei Ebenen zuständig:

| Ebene | Beispiele | Zuständig | Wie |
|---|---|---|---|
| Server | Betriebssystem, Webserver, PHP, PostgreSQL/MySQL/MariaDB, TLS | Betrieb | Sicherheitsupdates des Betriebssystems (möglichst automatisch), PHP-Patchversionen zeitnah einspielen |
| Abhängigkeiten | Laravel, Filament, weitere Pakete, Mermaid | Entwicklung von SAFT | Überwachung und Updates wie unten; ausgeliefert nur mit einem getesteten SAFT-Release |
| SAFT | eigener Code | Entwicklung von SAFT; Betrieb spielt Releases ein | Releases auf GitHub (Pre-Release bzw. Release mit Notes) |

**Feste Versionen:** `composer.lock` legt jede Abhängigkeit exakt fest. Ein Server bekommt neue Pakete daher nie von
selbst, sondern mit dem nächsten SAFT-Release (`composer install --no-dev` installiert genau die geprüften Versionen).

**Überwachung:**

- **Dependabot alerts** (GitHub) melden bekannte Sicherheitslücken in Abhängigkeiten per E-Mail und im Reiter
  *Security* des Repositorys.
- **Dependabot security updates** öffnen dazu sofort einen Pull-Request mit der korrigierten Version;
  `.github/dependabot.yml` bündelt ausserdem wöchentlich kompatible Updates (Patch/Minor) in einem Pull-Request.
- Der Workflow **Security audit** prüft jede Woche und bei jeder Änderung von `composer.lock` bzw.
  `package-lock.json` mit `composer audit` und `npm audit` (nur, was auf dem Server läuft).

**Rhythmus:**

| Art | Wann | Tests vor dem Release |
|---|---|---|
| Sicherheitskorrektur, die SAFT betrifft | innerhalb weniger Tage, als Patch-Release (z. B. 2.4.1), in den Notes als Sicherheitsupdate gekennzeichnet | Feature-Tests auf allen Datenbanken; Browser-Tests der betroffenen Bereiche |
| kompatible Updates (Patch/Minor) | monatlich gesammelt | Feature-Tests auf allen Datenbanken und komplette Browser-Tests |
| neue Hauptversionen (Laravel, Filament, OpenSpout …) | geplant als eigene Etappe | vollständig, mit Anpassungen |

Nicht jede gemeldete Lücke betrifft SAFT: Massgeblich ist, ob der betroffene Teil eines Pakets im Betrieb genutzt
wird. Im Zweifel wird aktualisiert.

**Beim Betreiber:** Releases auf GitHub beobachten (*Watch → Custom → Releases*), Updates zuerst auf einem
Testsystem einspielen, dann produktiv – mit Sicherung vorher (`php artisan db:backup`) und dem vorherigen Release als
Rückweg. Ablauf: [Anhang C, Abschnitt 9](INSTALL_PRODUCTION.de.md#9-updates-einspielen).

**Unterstützte Versionen** der Grundlagen: Laravel 13 erhält etwa zwei Jahre Sicherheitskorrekturen, PHP 8.4 bis
Ende 2028; rechtzeitig vorher auf die nächste Version wechseln. Meldung von Sicherheitslücken in SAFT selbst: siehe
`SECURITY.md` im Programmverzeichnis.

## 4. Geplante Aufgaben und Cron

[Geplante Aufgaben](ADMIN_GUIDE.de.md#12-geplante-aufgaben) (z. B. Datenstände aktualisieren) führt der
Laravel-Scheduler aus. Er braucht **einen** Cron-Eintrag, der ihn jede Minute aufruft; einen Queue-Worker braucht SAFT
dafür nicht. Die Aufgaben laufen im Cron-Prozess, ohne das Zeitlimit des Webservers, und nacheinander.

**Eigener Server oder VPS** (als der Benutzer, unter dem SAFT läuft – meist der des Webservers):

1. Cron-Tabelle öffnen: `crontab -e` (für den Webserver-Benutzer z. B. `sudo crontab -u www-data -e`). Beim ersten
   Mal fragt das System nach einem Editor; `nano` ist am einfachsten.
2. Diese Zeile anfügen (Pfade anpassen; `which php` zeigt den Pfad zu PHP):

   ```cron
   * * * * * cd /pfad/zu/saft && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
   ```

   Pfade ausschreiben: Cron startet mit einer minimalen Umgebung. Die Ausgabe wird verworfen; Ergebnisse stehen in
   SAFT unter *Geplante Aufgaben → Läufe*.
3. Speichern (in nano `Strg+O`, `Enter`, `Strg+X`); es erscheint `crontab: installing new crontab`. `crontab -l` zeigt
   den Eintrag.
4. Prüfen: Nach ein bis zwei Minuten zeigt `php artisan tasks:list` unter „Scheduler zuletzt gelaufen“ eine aktuelle
   Zeit, und der Hinweis „Geplante Aufgaben werden zurzeit nicht ausgeführt“ in der Verwaltung verschwindet.

**Webhosting mit Kundenportal:** Cron-Jobs gehören bei den meisten Hosting-Paketen dazu (Bereich „Cron-Jobs“ oder
„Geplante Aufgaben“). Als Befehl `cd /pfad/zu/saft && php artisan schedule:run` eintragen, Intervall jede Minute.
Erlaubt das Paket nur gröbere Intervalle (z. B. alle 15 Minuten), funktioniert SAFT trotzdem: Fällige Aufgaben laufen
dann bis zu diesem Intervall später. Ohne Cron-Möglichkeit laufen keine geplanten Aufgaben; Datenstände lassen sich
dann nur von Hand aktualisieren.

**Ausprobieren ohne Cron** (Entwickler-Rechner): `php artisan schedule:work` ruft den Scheduler jede Minute auf, solange
das Terminal offen ist.

**Konsole:** `php artisan tasks:list` (Aufgaben, nächster und letzter Lauf, letzter Aufruf des Schedulers),
`php artisan tasks:run <ID>` (eine Aufgabe sofort), `php artisan tasks:run-due` (fällige Aufgaben; das ruft der
Scheduler auf). Optionale Einstellungen in der `.env`: `TASKS_HEARTBEAT_MINUTES` (ab wann die Verwaltung warnt,
Vorgabe 15), `TASKS_HANGING_HOURS` (ab wann ein Lauf als abgebrochen gilt, Vorgabe 6).

## 5. KI-Funktionen

Die KI-Assistenten für Auswertungen und Tabellen (siehe [Administratorhandbuch, Kapitel 13](ADMIN_GUIDE.de.md#13-ki-funktionen))
sind ohne Einrichtung **aus**. Anbieter, Modell und API-Schlüssel lassen sich in den App-Einstellungen eintragen; was
dort leer bleibt, kommt aus der `.env`:

| Variable | Bedeutung |
|---|---|
| `AI_PROVIDER` | `none` (Vorgabe), `anthropic`, `bedrock` oder `vertex` |
| `AI_MODEL` | Modell-ID, Vorgabe `claude-opus-5-5`; bei Bedrock mit Präfix, z. B. `anthropic.claude-opus-5-5` |
| `AI_EFFORT` | Denktiefe `low`, `medium` (Vorgabe), `high` |
| `AI_DAILY_LIMIT` | Anfragen je Person und Tag (Vorgabe 50) |
| `AI_MAX_TOKENS`, `AI_TIMEOUT` | Obergrenze der Antwort (Vorgabe 16000) und Sekunden je Anfrage (Vorgabe 120) |
| `ANTHROPIC_API_KEY` | Schlüssel für `anthropic`; besser in den App-Einstellungen (dort verschlüsselt gespeichert) |
| `AI_INFERENCE_GEO` | Optional: Region der Verarbeitung bei Anthropic, z. B. `us` |
| `AI_BASE_URL` | Nur für Proxys/Gateways; leer = `api.anthropic.com` |
| `AI_AWS_REGION` | Amazon Bedrock: Region; Zugangsdaten wie üblich aus der Umgebung (`AWS_ACCESS_KEY_ID` …) oder der Rolle der Instanz |
| `AI_VERTEX_PROJECT`, `AI_VERTEX_LOCATION` | Google Vertex AI: Projekt und Region; Anmeldung über Application Default Credentials, dafür einmalig `composer require google/auth` |

- Der Server braucht **ausgehenden HTTPS-Zugriff** zum Anbieter (z. B. `api.anthropic.com:443`); **Verbindung testen**
  in den App-Einstellungen meldet Netzwerk- und Firewallprobleme getrennt von falschen Schlüsseln.
- Nach Änderungen an der `.env` bei gecachter Konfiguration `php artisan config:cache` erneut ausführen.
- Eine Anfrage dauert je nach Modell und Denktiefe einige Sekunden bis etwa eine Minute; sie läuft im Webserver-Prozess.
  `max_execution_time` von PHP bzw. Zeitlimits eines vorgeschalteten Proxys sollten daher über `AI_TIMEOUT` liegen.
- Fehler stehen im Protokoll der Anwendung (`storage/logs`), jede Anfrage zusätzlich im Änderungsprotokoll.
- Ausschalten: in den App-Einstellungen den Hauptschalter aus bzw. Anbieter „Aus“, oder `AI_PROVIDER=none`.

## 6. Befehlsreferenz (php artisan)

Alle Befehle im Hauptverzeichnis von SAFT ausführen, auf einem Produktionsserver mit dem Benutzer des Webservers.
`php artisan list` zeigt alle verfügbaren Befehle, `php artisan help <befehl>` die Optionen eines Befehls.

### 6.1 SAFT-eigene Befehle

| Befehl | Zweck | Wichtige Optionen |
|---|---|---|
| `php artisan db:backup` | Sicherung der Datenbank von SAFT (PostgreSQL, MySQL oder MariaDB) | `--path=` Zielordner (Vorgabe `storage/backups`) |
| `php artisan app-data:verify` | Prüft, ob die Metadaten des Table-Designers und die Tabellen im Namensraum `app_data` übereinstimmen; Exit-Code 1 bei Abweichungen (für die Überwachung) | – |
| `php artisan auth:mode [local\|sso\|hybrid]` | Zeigt oder setzt den Anmeldemodus, z. B. als Notausgang `auth:mode local`, wenn kein Identity Provider erreichbar ist | – |
| `php artisan computed:recompute [tabelle …]` | Berechnet die berechneten Felder neu (ohne Angabe: alle Tabellen mit berechneten Feldern) | interne Tabellennamen |
| `php artisan table-connector:verify [tabelle …]` | Vergleicht angebundene Tabellen mit dem Schema der externen Datenbank; Exit-Code 1 bei Abweichungen | `--apply` übernimmt Abweichungen wie „Neu einlesen“ |
| `php artisan table-connector:connect <verbindung>` | Massenverknüpfung auf der Konsole (ohne Zeitlimit des Webservers) | `--all` bzw. `--table=` (mehrfach), `--schema=`, `--prefix=`, `--menu-group=` (ID oder `none`), `--role=` (mehrfach), `--export`, `--create`, `--update`, `--delete`, `--inactive`, `--no-details` |
| `php artisan tasks:list` | Geplante Aufgaben mit Zeitplan, nächstem und letztem Lauf; letzter Aufruf des Schedulers | – |
| `php artisan tasks:run <ID>` | Eine geplante Aufgabe sofort ausführen (der Zeitplan bleibt) | – |
| `php artisan tasks:run-due` | Fällige geplante Aufgaben ausführen; ruft der Scheduler jede Minute auf | – |
| `php artisan saft:plugins` | Listet die installierten Plugins mit Version und Kompatibilität und legt ihre Rechte an (läuft auch nach jedem `migrate`) | – |
| `php artisan saft:plugins --check [id]` | Prüft Plugins vor der Auslieferung (Kennung, API, Rechte, Menü, nur Plugin-API); Exit-Code 1 bei Fehlern | – |
| `php artisan saft:plugin-context` | Entwickler-Kontext für Plugins (AGENTS.md, API-Referenz, Schema; ohne Daten) | `--output=`, `--tables=` (mehrfach), `--without-external`, `--zip` |
| `php artisan saft:config-export <datei>` | Konfigurationspaket schreiben: Tabellen, freigegebene Auswertungen, Menüeinträge, Rollen (siehe Administratorhandbuch, „Konfiguration übertragen“) | `--all` oder `--tables=`, `--reports=` (Namen), `--menus=` (Pfad, z. B. „Daten › Verkauf“), `--roles=` (je mehrfach), `--without-dependencies`, `--label=` |
| `php artisan saft:config-import <datei>` | Paket einspielen: legt nur Neues an, Vorhandenes bleibt; zeigt zuerst die Vorschau | `--dry-run` nur prüfen |
| `php artisan saft:third-party-notices` | Erzeugt `THIRD_PARTY_NOTICES.md` (alle Komponenten von Drittanbietern mit Version, Lizenz, Copyright und Lizenztexten, auch die Bibliotheken im Mermaid-Bundle). Nach jedem Paket-Update auf dem Entwickler-Rechner ausführen und die Datei committen; ein Test prüft die Vollständigkeit | braucht `node_modules` |
| `php artisan external-demo:install` | Legt die externe Demo-Forschungsdatenbank und eine Datenbankverbindung dafür an und bindet ihre Tabellen an (`fdb_…`) | `--driver=pgsql\|mysql\|mariadb\|sqlsrv`, `--database=`, `--name=`, `--without-connection`, `--without-tables` |

### 6.2 Grundausstattung und Demo-Daten (Seeder)

| Befehl | Inhalt |
|---|---|
| `php artisan db:seed` (Produktion: `--force`) | Grundausstattung: Rollen, Rechte, Menü und der erste Administrator aus `ADMIN_*` in der `.env`; wiederholbar |
| `php artisan db:seed --class=DemoDataSeeder` | Shop-Demo (Artikel, Kunden, Bestellungen, Positionen) mit Beispiel-Auswertung, Gruppe „Shop (Demo)“ |
| `php artisan db:seed --class=LagerDemoSeeder` | Lager-Demo (`lager_…`), Gruppe „Lager (Demo)“ |
| `php artisan db:seed --class=ForschungDemoSeeder` | Interne Forschungs-Demo (`forschung_…`), Gruppe „Forschung (Demo)“ |

Demo-Daten nur in Test- und Schulungssystemen einspielen (nicht bei einer Installation mit `--no-dev`). Die Demos
prüfen vorab, ob ihre Tabellennamen frei sind, und brechen sonst ab, ohne etwas anzulegen.

### 6.3 Laravel-Befehle, die SAFT braucht

| Befehl | Wann |
|---|---|
| `php artisan migrate --force` | nach jedem Update: legt neue Tabellen, Spalten, Rechte und Menüeinträge an |
| `php artisan optimize` und `php artisan filament:optimize` | nach jedem Update in der Produktion (Konfiguration, Routen, Ansichten, Filament-Komponenten zwischenspeichern) |
| `php artisan optimize:clear` | Zwischenspeicher leeren, z. B. nach Änderungen an der `.env` |
| `php artisan key:generate` | einmalig bei der Installation (Schlüssel `APP_KEY`) |
| `php artisan storage:link` | einmalig bei der Installation (öffentlicher Speicher für hochgeladene Logos) |
| `php artisan queue:work` | nur wenn `QUEUE_CONNECTION` nicht `sync` ist (z. B. für den Mailversand) |
| `php artisan schedule:run` (minütlich per Cron) | führt die [geplanten Aufgaben](#4-geplante-aufgaben-und-cron) aus; ohne Cron-Eintrag laufen sie nicht |
| `php artisan down` / `php artisan up` | Wartungsmodus während eines Updates |

### 6.4 Befehle im laufenden Betrieb

- **Zwischenspeicher** (`optimize`, `filament:optimize`, `optimize:clear`): im laufenden Betrieb gefahrlos und in der
  Produktion nach jedem Update erwünscht. Solange die Konfiguration zwischengespeichert ist, wirken Änderungen an der
  `.env` erst nach `php artisan optimize:clear` (bzw. einem erneuten `optimize`).
- **Nicht auf Entwicklungs- und Testrechnern cachen**, auf denen automatische Tests laufen: Bei zwischengespeicherter
  Konfiguration ignoriert Laravel die Testdatenbank aus `phpunit*.xml`, die Tests würden die Datenbank aus der `.env`
  leeren. Die Feature-Tests brechen in diesem Fall deshalb sofort ab; vor dem Testlauf `php artisan optimize:clear`.
- **Schema-Änderungen** (`migrate`): im Wartungsmodus – `php artisan down`, `php artisan db:backup`,
  `php artisan migrate --force`, `php artisan optimize`, `php artisan filament:optimize`, `php artisan up`.
- **Zerstörerische Befehle** (`migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`)
  leeren bzw. entfernen Tabellen. Mit `APP_ENV=production` verweigert SAFT sie. `db:seed` ist wiederholbar, Demo-Daten
  gehören trotzdem nicht in die Produktion.

## 7. Plugins

Plugins erweitern SAFT um eigene Seiten und Funktionen (siehe [Entwicklerhandbuch](DEVELOPER_GUIDE.de.md)). Es sind
Composer-Pakete; SAFT bindet alle Pakete im Ordner `plugins/` als Composer-Repository ein. Plugins laufen mit allen
Rechten der Anwendung – installieren Sie nur Plugins aus vertrauenswürdiger Quelle.

**Installieren oder aktualisieren** (im Wartungsmodus, wie ein Update):

```bash
php artisan down
php artisan db:backup
composer require saft/<name>              # Ordner plugins/<name>; aus einem Git-Repository zuvor unter
                                          # "repositories" in der composer.json eintragen
php artisan migrate --force               # eigene Tabellen des Plugins und seine Rechte
php artisan optimize && php artisan filament:optimize
php artisan up
```

Danach zeigt **Administration › Plugins** das Plugin mit Version und der Angabe, ob es zur installierten SAFT-Version
passt; dort übernimmt die Administration die vorgeschlagenen Menüeinträge, die Rechte vergibt sie in der
Rollenverwaltung. `php artisan saft:plugins` listet die Plugins auf der Konsole und legt fehlende Rechte an.

**Aus- und einschalten**, ohne zu entfernen – z. B. für Tests oder bei Plugins mit überschneidender Funktion:

```bash
php artisan saft:plugins --disable=<id>    # z. B. lager-umbuchung; meldet Plugins, die dadurch mitruhen
php artisan saft:plugins --enable=<id>
```

Ein ausgeschaltetes Plugin bleibt installiert; seine Seiten liefern „nicht gefunden“, Kacheln und Menüeinträge sind
ausgeblendet, Listener, Knöpfe, Prüfregeln und Formelfunktionen wirken nicht, geplante Aufgaben seiner Arten werden
übersprungen. Ein Neustart oder `optimize` ist nicht nötig; der Zustand steht in der Datenbank (Tabelle
`plugin_states`) und im Änderungsprotokoll. Dasselbe geht auf der Seite **Plugins** (siehe
[Administratorhandbuch, Kapitel 14](ADMIN_GUIDE.de.md#14-plugins)).

**Entfernen:**

1. Hat das Plugin eigene Tabellen, diese zuerst zurückrollen: `php artisan migrate:rollback --path=plugins/<name>/database/migrations`
   (vorher sichern; in der Produktion verweigert SAFT `migrate:rollback` – dann `APP_ENV` vorübergehend umstellen oder
   die Tabellen von Hand entfernen).
2. `composer remove saft/<name>`, danach `php artisan optimize` und `php artisan filament:optimize`.
3. Menüeinträge des Plugins werden automatisch ausgeblendet. Auf der Seite **Plugins** löscht **Aufräumen** sie
   zusammen mit den nicht mehr benötigten Rechten.

Daten, die ein Plugin in Tabellen des Table-Designers geschrieben hat, bleiben erhalten. Die Beispiel-Plugins
`saft/lager-umbuchung`, `saft/lager-tafel` und `saft/ch-pruefungen` sind nur mit den Entwicklungspaketen installiert
(nicht mit `composer install --no-dev`).

---

## Anhang A – Screenshots aktualisieren

Die Screenshots liegen in `docs/manual/images/<sprache>/` (`de`, `en`, `fr`, `it`). Sie werden
nicht von Hand erstellt, sondern von einem Skript, das die Anwendung wie die Browser-Tests in einem Chrome ohne
Fenster bedient. Nach Änderungen an der Oberfläche erzeugt dieser Befehl alle Bilder in allen vier Sprachen neu
(Einrichtung wie bei den Browser-Tests, siehe [README](../../README.de.md#browser-tests-laravel-dusk)):

```bash
composer manual:screenshots
```

Nur einzelne Sprachen, z. B. Französisch und Italienisch:

```bash
vendor/bin/phpunit -c phpunit.dusk.xml --testsuite Manual --filter '/with data set "(fr|it)"/'
```

Das Skript steht in `tests/Manual/ManualScreenshotsTest.php`. Es leert die Testdatenbank `SAFT2_dusk`, spielt die
Demo-Daten ein, stellt jede Ansicht her und setzt die roten Markierungen mit den Ziffern aus dem Text. Es arbeitet nie
mit der produktiven Datenbank.

| Datei | Inhalt |
|---|---|
| `01-login.png` | Anmeldeseite im Modus Hybrid mit zwei SSO-Schaltflächen und „Passwort vergessen“ |
| `02-dashboard.png` | Dashboard eines Super-Admins |
| `03-data-table.png` | Kundenliste mit Suche, Filter, Sortierung und „Details“ |
| `04-cascade.png` | Kunde → Bestellung → Bestellpositionen ausgewählt |
| `05-details-popup.png` | Fenster „Details“ einer Bestellung |
| `06-preview.png` | Vorschau eines Artikels aus den Bestellpositionen |
| `07-roles-data-permissions.png` | Rolle mit Datenrechte-Matrix und Menüeinträgen |
| `08-menu-data-table.png` | Neuer Menüeintrag vom Typ „Datentabelle“ |
| `09-table-designer.png` | Tabelle „Bestellungen“ im Table-Designer |
| `10-cascade-logic.svg` | Schema der Kaskaden-Logik (Zeichnung, kein Screenshot) |
| `11-identity-provider.png` | Entra-ID-Anbieter bearbeiten |
| `12-database-connection.png` | Datenbankverbindung zur Demo-Forschungsdatenbank bearbeiten |
| `13-table-connector-create.png` | Tabelle „projekte“ anbinden |
| `14-table-connector-columns.png` | Spalten der angebundenen Tabelle „projekte“ |
| `15-table-connector-writing.png` | Schreiben für „organisationen“ freischalten |

Für die Kundenliste blendet das Skript die Spalten Ansprechpartner, E-Mail und PLZ aus, damit die Liste samt
„Details“ ins Bild passt. Neue Bilder ergänzen Sie im Skript; jede Ansicht ist dort eine eigene Methode.

---

## Weitere Anleitungen

- [Anhang B – Installation auf einem Entwickler-Rechner](INSTALL_DEVELOPMENT.de.md)
- [Anhang C – Installation auf einem Produktionsserver](INSTALL_PRODUCTION.de.md)
- [Anhang D – Single Sign-On mit Microsoft Entra ID einrichten](SSO_ENTRA.de.md)
- [Entwicklerhandbuch: Plugins](DEVELOPER_GUIDE.de.md)
