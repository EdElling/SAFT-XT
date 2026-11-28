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
| Zwei-Faktor-Anmeldung eines Kontos zurücksetzen (z. B. einziger Admin hat das Handy verloren) | `php artisan auth:two-factor-reset <email>` |
| Einstellungen des Table-Designers mit der Datenbank abgleichen | `php artisan app-data:verify` |
| Angebundene Tabellen mit dem Schema ihrer externen Datenbanken abgleichen (Exit-Code 1 bei Abweichungen; `--apply` übernimmt sie) | `php artisan table-connector:verify` |
| Massenverknüpfung auf der Konsole: alle noch nicht angebundenen Tabellen (`--all`) oder einzelne (`--table=…`), mit `--app=<ID>` (Pflicht bei mehreren Apps), `--prefix=`, `--menu-group=<ID>` bzw. `none`, `--role=<Name>`, `--export`, `--create`, `--update`, `--delete`, `--inactive`, `--schema=`, `--no-details` | `php artisan table-connector:connect "<Verbindung>" --all` |
| Datenbank wählen: PostgreSQL, MySQL oder MariaDB, mit den Zugangsdaten `PGSQL_*`, `MYSQL_*` bzw. `MARIADB_*` | `DB_CONNECTION=pgsql`, `mysql` bzw. `mariadb` in der `.env` |
| Datenbank sichern (nach `storage/backups`; MySQL/MariaDB: Hauptdatenbank und `…_app_data` in einer `.sql`-Datei, bei eigenen Zugangsdaten für `app_data` (`DB_APP_DATA_*`) in zwei Teilen; ohne passendes `mysqldump` mit `DB_BACKUP_METHOD=php` bzw. `--php` in PHP; Pfad zum Programm bei Bedarf in `PGSQL_DUMP_BINARY`, `MYSQL_DUMP_BINARY` bzw. `MARIADB_DUMP_BINARY`; die Dateien aus Dateifeldern und das Briefpapier daneben als `<gleicher Name>_files.zip`, ohne sie mit `--without-files`) | `php artisan db:backup` |
| Berechnete Felder neu berechnen (nach Änderungen an SAFT vorbei; optional Tabellennamen) | `php artisan computed:recompute` |
| Höchstzahl der Datensätze je Export ändern (Vorgabe 500'000, `0` = ohne Grenze; der Export läuft als Arbeit in Blöcken von `APP_DATA_EXPORT_CHUNK` Zeilen, Vorgabe 1000) | `APP_DATA_EXPORT_MAX_ROWS=500000` in der `.env` |
| Höchstzahl der Zeilen je Datenimport in eine bestehende Tabelle ändern (Vorgabe 100'000, `0` = ohne Grenze); bis `APP_DATA_IMPORT_CHECK_ROWS` Zeilen (Vorgabe 2000) prüft der Assistent die Datei schon im Dialog, darüber erst die Arbeit | `APP_DATA_IMPORT_MAX_RECORD_ROWS=100000` in der `.env` |
| Höchstzahl der Datensätze je Kopie samt mitkopierten Kind-Datensätzen ändern (Vorgabe 1000) | `APP_DATA_COPY_MAX_RECORDS=1000` in der `.env` |
| Höchstzahl der Datensätze je Sammeländerung, Sammel-Löschen und Sammel-Wiederherstellen ändern (Vorgabe 5000, `0` = ohne Grenze; die Sammeländerung läuft als Arbeit in Blöcken von 100 Datensätzen) | `APP_DATA_BULK_MAX=5000` in der `.env` |
| Mailserver für alle E-Mails (Passwort zurücksetzen, Dokumente): in den globalen Einstellungen („E-Mail-Versand“, Passwort verschlüsselt, „Verbindung prüfen“) oder in der `.env`; ein Server in den Einstellungen geht vor `MAIL_MAILER`. Die Absenderadresse muss zum Server passen (Konto-Adresse bzw. SPF/DKIM der Domain) | Administratorhandbuch, Kapitel 7, bzw. `MAIL_*` in der `.env` |
| Obergrenze je Sammellauf „Dokumente erstellen“ setzen (Vorgabe `0` = ohne Grenze; der Lauf ist eine Arbeit in Schritten). Ein einziges PDF mit allen Dokumenten fasst am Ende alle Seiten im Speicher zusammen (`memory_limit`), ZIP nicht | `DOCUMENTS_BATCH_MAX=0` in der `.env` |
| Höchstzahl der Zeilen je Datenstand einer angebundenen Tabelle ändern (Vorgabe 1'000'000, `0` = ohne Grenze; gezogen wird als Arbeit in Blöcken von `REPORTS_SNAPSHOT_CHUNK` Zeilen, Vorgabe 1000) | `REPORTS_SNAPSHOT_MAX_ROWS=1000000` in der `.env` |
| Speicher für Dateifelder: `local` (Vorgabe, `storage/app/private/files`) oder `s3` (S3-kompatibler Speicher, z. B. AWS, Infomaniak, Exoscale; Zugangsdaten in `AWS_*`, bei anderen Anbietern als AWS auch `AWS_ENDPOINT` und `AWS_USE_PATH_STYLE_ENDPOINT=true`; vorher `composer require league/flysystem-aws-s3-v3`). Nicht wechseln, solange Dateien gespeichert sind – vorhandene Dateien werden nicht verschoben | `APP_DATA_FILES_DISK=local` in der `.env` |
| Zeitzone der Anwendung (Vorgabe `Europe/Zurich`) | `APP_TIMEZONE` in der `.env` |
| Dem Load Balancer des Hosters vertrauen, wenn HTTPS vor dem Server endet (Adressen des Proxys, kommagetrennt) | `TRUSTED_PROXIES` in der `.env` |
| Antwortzeiten aufschlüsseln: Header `Server-Timing` mit Start von Laravel (`boot`), Anzahl und Dauer der Datenbankabfragen (`db`), übriger Zeit der Anwendung (`app`) und gesamt (`total`), dazu die drei langsamsten Abfragen (`q1` bis `q3`: Dauer, Verbindung, Tabellen und Art der Abfrage, kein SQL-Text; „erste“ = erste Abfrage der Verbindung, enthält den Verbindungsaufbau), sichtbar in den Entwicklerwerkzeugen des Browsers unter Netzwerk → Anfrage → Timing, auch für Klicks in Livewire. Jeder Besucher sieht die Zeiten – nach dem Messen wieder ausschalten (Vorgabe aus) | `SERVER_TIMING=true` in der `.env`, danach `php artisan optimize` |
| Vorgabesprache, wenn weder Benutzer noch Browser eine angebotene Sprache festlegen | `APP_LOCALE=de` in der `.env` |
| Demo-Daten (Artikel, Kunden, Bestellungen, Positionen) einspielen (nicht bei Installation mit `--no-dev`) | `php artisan db:seed --class=DemoDataSeeder` |
| Externe Demo-Datenbank (Schema `forschung`; die leere Datenbank `SAFT2_ext` muss existieren) mit Verbindung „Forschung (Demo)“ und den sechs angebundenen Tabellen unter „Forschungsdatenbank“ (technische Namen `fdb_…`, damit sie neben der internen Forschungs-Demo `forschung_…` bestehen; belegt eine andere Tabelle einen dieser Namen, bricht der Befehl ab, ohne etwas anzulegen; bereits angebundene bleiben unverändert; nur die Verbindung: `--without-tables`). Mit `--driver=mysql` als MySQL-Fassung (Zugangsdaten `MYSQL_*`), mit `--driver=mariadb` als MariaDB-Fassung (Zugangsdaten `MARIADB_*`), mit `--driver=sqlsrv` als SQL-Server-Fassung im Schema `forschung` einer vorhandenen Datenbank (Zugangsdaten `MSSQL_*`) | `php artisan external-demo:install` |
| Grundausstattung (Rollen, Rechte, Menü) | `php artisan db:seed` |

Hinweise:

- **Produktivbetrieb:** Installieren Sie mit `composer install --no-dev --optimize-autoloader` und setzen Sie
  `APP_ENV=production`. Sonst steht eine Test-Route bereit, über die man sich ohne Passwort anmelden kann.
- **Sicherungen** enthalten alle Daten. Bewahren Sie zusätzlich eine Kopie ausserhalb des Servers auf. Wie Sie eine
  Sicherung einspielen, steht in der [README](../../README.de.md#betrieb).
- **Dateien aus Dateifeldern** und das **Briefpapier** der Dokumente (`documents/`) sichert `db:backup` als ZIP neben
  der Datenbanksicherung (gleicher Name mit `_files.zip`, ohne Vorschaubilder). Zum Wiederherstellen nach dem Einspielen der Datenbank das ZIP in
  `storage/app/private` entpacken (`unzip <datei>_files.zip -d storage/app/private`); Vorschaubilder entstehen beim
  ersten Aufruf neu. Liegen die Dateien aus Dateifeldern in S3 (`APP_DATA_FILES_DISK=s3`), sind sie nicht enthalten – sichern Sie dort,
  z. B. mit Versionierung des Buckets.
- **Apps (ab 0.4):** Nach dem Update ordnet `php artisan migrate` alle vorhandenen Tabellen, Menüs, Auswertungen,
  Rollen und Aufgaben einer App „Bestehende Daten“ zu; Systemrollen, System-Menüs und System-Aufgaben bleiben ohne App.
  Aufteilen in eigene Apps geschieht danach in der Oberfläche (siehe
  [Administratorhandbuch, Kapitel 15](ADMIN_GUIDE.de.md#15-apps-fachanwendungen)). Der Menüeintrag „App-Einstellungen“
  heisst seither „Globale Einstellungen“.
- **Dokumente (PDF):** SAFT erzeugt PDFs mit der Bibliothek tc-lib-pdf (reines PHP, ohne externe Programme). Für
  Logos als PNG oder GIF braucht PHP die Erweiterung `gd`. Briefpapier, Logos und Hausschriften liegen unter
  `storage/app/private/documents` und sind im ZIP von `db:backup` enthalten. Umgewandelte Schriften und verkleinerte Logos legt SAFT unter `storage/framework/cache/pdf-fonts` bzw.
  `pdf-logos` ab; sie entstehen bei Bedarf neu und dürfen gelöscht werden.
- **Verwaiste Dateien** (Uploads aus abgebrochenen Formularen, Dateien von Datensätzen, die ausserhalb von SAFT gelöscht
  wurden) räumt die System-Aufgabe *Dateien aufräumen* bzw. `php artisan files:prune` nach 24 Stunden weg.
- **Grösse von Uploads:** Die Höchstgrösse je Dateifeld wirkt nur bis zur Grenze von PHP. Für z. B. 50 MB in der
  `php.ini` (bei Webhostern oft über `.user.ini` oder deren Verwaltungsoberfläche) `upload_max_filesize = 50M` und `post_max_size = 55M` setzen; beim
  Webserver gegebenenfalls auch `client_max_body_size` (nginx) bzw. `LimitRequestBody` (Apache).
- Der **technische Name** `APP_NAME` in der `.env` bestimmt Cookie- und Cache-Namen. Der sichtbare Name wird
  in den globalen Einstellungen gepflegt; eine Änderung dort meldet niemanden ab.
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
die Version der Plugin-Schnittstelle (`api_versions`, siehe [Entwicklerhandbuch, Kapitel 18](DEVELOPER_GUIDE.de.md#18-api-versionen-und-änderungsprotokoll)).

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

**Sicherheits-Header:** SAFT sendet mit jeder Antwort Header, die den Browser absichern: kein Einbetten in fremde
Seiten (`X-Frame-Options`, `frame-ancestors`), kein Erraten von Dateitypen (`X-Content-Type-Options`), Adressen nur
gekürzt an fremde Seiten (`Referrer-Policy`), keine Kamera, kein Mikrofon, kein Standort (`Permissions-Policy`). Über
HTTPS kommt **HSTS** dazu: Der Browser ruft SAFT danach ein Jahr lang nur noch über HTTPS auf, auch wenn jemand
`http://` eintippt oder ein Angreifer im Netz auf http umleiten will. Abschalten lässt sich HSTS mit
`SECURITY_HSTS=false` in der `.env`, etwa wenn die Adresse künftig wieder ohne Zertifikat laufen soll – Browser, die
den Header schon kennen, halten sich aber bis zum Ablauf daran. Setzt der Webserver dieselben Header selbst, gelten
dessen Werte. Prüfen: `curl -sI https://saft.example.ch/admin/login`.

**Passwörter:** Neue Passwörter brauchen mindestens 12 Zeichen und werden mit bekannten Datenlecks abgeglichen
(*Have I Been Pwned*). Dabei verlässt nur der Anfang des SHA-1-Hashes (5 Zeichen) den Server, nie das Passwort; der
Server braucht dafür ausgehenden HTTPS-Zugriff auf `api.pwnedpasswords.com:443`. Ist der Dienst nicht erreichbar,
gilt das Passwort als unbedenklich. Ohne Internetzugang `PASSWORD_CHECK_LEAKED=false` setzen, sonst wartet jede
Passwortänderung auf die Zeitüberschreitung. Dieselbe Regel gilt für `ADMIN_PASSWORD` beim ersten `db:seed`.

**Zwei-Faktor-Anmeldung:** Wer sie einrichten muss, legt die Administration fest (*Administration › Benutzer und Zugriff › Authentifizierung*,
Vorgabe: Pflicht für Administration). Das Geheimnis der Authenticator-App und die (gehashten) Wiederherstellungscodes
liegen verschlüsselt mit `APP_KEY` in der Tabelle `users` – geht `APP_KEY` verloren, müssen alle Konten neu
einrichten. Die Codes hängen an der Uhrzeit: Die Serverzeit muss stimmen (NTP), sonst werden Codes abgelehnt. Hat der
einzige Admin Handy und Codes verloren: `php artisan auth:two-factor-reset <email>`. `TWO_FACTOR_ENFORCE=false`
setzt nur die Pflicht aus und ist für die automatischen Tests gedacht; wer einen zweiten Faktor eingerichtet hat, wird
trotzdem danach gefragt.

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

**Fehlgeschlagene Läufe:** Die Meldung nennt eine Referenz; unter ihr steht der Fehler mit Ablauf in
`storage/logs/laravel.log` (Eintrag `Geplante Aufgabe fehlgeschlagen`). Fehler, Meldung, Ort und Ablauf sieht die
Administration auch in SAFT beim Lauf unter **Details** (Recht *Geplante Aufgaben: technische Details sehen*).
Das Ende der Logdatei zeigt *Administration › Betrieb › Systemprotokoll* (Recht *Systemprotokoll: anzeigen*) – nützlich ohne
SSH-Zugang, z. B. auf Webspaces. Gelesen werden höchstens die letzten 2 MB; Dateien `laravel.log` und
`laravel-JJJJ-MM-TT.log` (Log-Kanal `daily`) in `storage/logs`.

**Arbeiten:** Derselbe Cron-Eintrag treibt auch die [Arbeiten](ADMIN_GUIDE.de.md#arbeiten) voran: `work:run` läuft
jede Minute höchstens 50 Sekunden und übernimmt Arbeiten, deren startende Person die Seite verlassen hat. Bei gröberen
Cron-Intervallen geht eine verlassene Arbeit entsprechend später weiter; ohne Cron nur bei offener Seite. Im Browser
arbeitet SAFT je Abfrage höchstens 15 Sekunden, immer unter `max_execution_time` von PHP. Ergebnisdateien liegen in
`storage/app/private/work/<uuid>/` und werden von der System-Aufgabe *Arbeiten aufräumen* (stündlich) gelöscht.
Fehlschläge stehen unter ihrer Referenz in `storage/logs/laravel.log` (Eintrag `Arbeit fehlgeschlagen`). Aktionen wie
Export, Import, Dokumente oder Datenstand starten eine Arbeit und arbeiten bis zu `WORK_INLINE_BUDGET` Sekunden
(Vorgabe 10) direkt in der Anfrage mit; kleine Mengen sind damit sofort fertig. Optionale Einstellungen in der
`.env`: `WORK_INLINE_BUDGET` (10), `WORK_BROWSER_BUDGET` (15), `WORK_SCHEDULER_BUDGET` (50), `WORK_TAKEOVER_SECONDS`
(30), `WORK_MAX_STALLS` (Abbrüche desselben Schritts bis zum Fehlschlag, 3), `WORK_RESULT_HOURS` (24),
`WORK_STUCK_MINUTES` (ab wann das Dashboard warnt, 10). Alle Budgets bleiben unter `max_execution_time` von PHP. Lange,
unteilbare Schritte (KI-Anfragen des App-Designers, `AI_DESIGNER_TIMEOUT`) heben das Zeitlimit für sich an; geht das
im Browser nicht, führt sie der Scheduler aus. Ein Queue-Worker ist nicht nötig.

**Mit Queue-Worker:** Auf einem eigenen Server kann ein Worker die Arbeiten übernehmen – sie starten dann ohne
Verzögerung, unabhängig von Browser und Cron-Intervall, und der Browser zeigt nur noch an. Dazu `QUEUE_CONNECTION=database`
(Vorgabe, Tabelle `jobs` ist vorhanden) und `WORK_DRIVER=queue` in der `.env`; nach Änderungen `php artisan
optimize:clear` und `php artisan queue:restart`. Der Worker läuft dauerhaft als Dienst, z. B. unter systemd:

```ini
[Unit]
Description=SAFT Queue-Worker
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/saft
ExecStart=/usr/bin/php artisan queue:work --max-time=3600 --tries=1
Restart=always

[Install]
WantedBy=multi-user.target
```

oder unter Supervisor mit demselben Befehl. `--max-time=3600` lässt den Worker stündlich neu starten, damit er
geänderten Code und Konfiguration lädt. Je Arbeit arbeitet ein Job `WORK_QUEUE_BUDGET` Sekunden (Vorgabe 50) und reiht
sich wieder ein, bis sie fertig ist; auf der Queue-Verbindung `sync` wird nie ein Job gestartet. Fällt der Worker aus,
übernimmt der Scheduler nach `WORK_TAKEOVER_SECONDS` wie ohne Worker.

**System-Aufgaben für Sicherungen und Demos:** *Datenbank sichern* (02:30, mit den Dateien aus Dateifeldern),
*Sicherungen aufräumen* (02:45, 14 Tage, mindestens 3; Sicherung und ZIP zählen als eine) und *Dateien aufräumen*
(03:00) laufen ab Installation; damit ersetzt der Scheduler einen eigenen Cron-Eintrag für `db:backup`.
Wer noch einen solchen Eintrag hat, entfernt ihn oder schaltet die Aufgabe aus, sonst wird doppelt gesichert.
*App-Daten zurücksetzen* ist zerstörerisch: ausgeliefert ausgeschaltet und auch eingeschaltet nur wirksam mit
`DEMO_RESET=true` in `.env` – setzen Sie das nur auf öffentlichen Demo- oder Schulungssystemen.

**Sitzungen:** Abgelaufene Anmeldesitzungen löscht die System-Aufgabe *Sitzungen aufräumen* stündlich (Minute 40), für
`SESSION_DRIVER=database` und `file`; die Frist ist `SESSION_LIFETIME` (Minuten, Vorgabe 120). Laravels eigenes
Aufräumen nebenbei in Anfragen („Lotterie“) ist in `config/session.php` bewusst ausgeschaltet: Auf MySQL und MariaDB
geriet es gelegentlich mit einer anderen Anfrage in einen Deadlock, die dann mit Fehler 500 endete. Ohne Cron bleiben
abgelaufene Sitzungen liegen; das ist harmlos (sie gelten trotzdem als abgelaufen), die Tabelle `sessions` bzw. der
Ordner `storage/framework/sessions` wächst aber bis zum nächsten Lauf. Bei `redis`, `memcached` oder `cookie` laufen
Sitzungen selbst ab, die Aufgabe meldet „übersprungen“.

## 5. KI-Funktionen

Die KI-Assistenten für Auswertungen, Tabellen und Dokumentvorlagen (siehe [Administratorhandbuch, Kapitel 13](ADMIN_GUIDE.de.md#13-ki-funktionen))
sind ohne Einrichtung **aus**. Anbieter, Modell und API-Schlüssel lassen sich in den globalen Einstellungen eintragen; was
dort leer bleibt, kommt aus der `.env`:

| Variable | Bedeutung |
|---|---|
| `AI_PROVIDER` | `none` (Vorgabe), `anthropic`, `bedrock` oder `vertex` |
| `AI_MODEL` | Modell-ID, Vorgabe `claude-opus-5-5`; bei Bedrock mit Präfix, z. B. `anthropic.claude-opus-5-5` |
| `AI_EFFORT` | Denktiefe `low`, `medium` (Vorgabe), `high` |
| `AI_DAILY_LIMIT` | Anfragen je Person und Tag (Vorgabe 50) |
| `AI_MAX_TOKENS`, `AI_TIMEOUT` | Obergrenze der Antwort (Vorgabe 16000) und Sekunden je Anfrage (Vorgabe 120) |
| `AI_DESIGNER_MAX_TOKENS`, `AI_DESIGNER_TIMEOUT` | Nur für den KI-App-Designer (eine ganze App ist eine lange Antwort): Obergrenze der Antwort (Vorgabe 32000, etwa 25–30 Tabellen) und Sekunden je Anfrage (Vorgabe 900). Mehr Token erlauben grössere Apps, dauern aber länger und kosten mehr; PHP- und Proxy-Zeitlimits müssen für den Designer entsprechend höher sein |
| `ANTHROPIC_API_KEY` | Schlüssel für `anthropic`; besser in den globalen Einstellungen (dort verschlüsselt gespeichert) |
| `AI_INFERENCE_GEO` | Optional: Region der Verarbeitung bei Anthropic, z. B. `us` |
| `AI_BASE_URL` | Nur für Proxys/Gateways; leer = `api.anthropic.com` |
| `AI_AWS_REGION` | Amazon Bedrock: Region; Zugangsdaten wie üblich aus der Umgebung (`AWS_ACCESS_KEY_ID` …) oder der Rolle der Instanz |
| `AI_VERTEX_PROJECT`, `AI_VERTEX_LOCATION` | Google Vertex AI: Projekt und Region; Anmeldung über Application Default Credentials, dafür einmalig `composer require google/auth` |

- Der Server braucht **ausgehenden HTTPS-Zugriff** zum Anbieter (z. B. `api.anthropic.com:443`); **Verbindung testen**
  in den globalen Einstellungen meldet Netzwerk- und Firewallprobleme getrennt von falschen Schlüsseln.
- Nach Änderungen an der `.env` bei gecachter Konfiguration `php artisan config:cache` erneut ausführen.
- Eine Anfrage dauert je nach Modell und Denktiefe einige Sekunden bis etwa eine Minute; sie läuft im Webserver-Prozess.
  `max_execution_time` von PHP bzw. Zeitlimits eines vorgeschalteten Proxys sollten daher über `AI_TIMEOUT` liegen.
- Fehler stehen im Protokoll der Anwendung (`storage/logs`), jede Anfrage zusätzlich im Änderungsprotokoll.
- Ausschalten: in den globalen Einstellungen den Hauptschalter aus bzw. Anbieter „Aus“, oder `AI_PROVIDER=none`.
- **Hilfe-Assistent:** eigener Schalter in den globalen Einstellungen. Er schickt die Handbücher der Online-Hilfe mit
  (in der Sprache der Oberfläche, etwa 35 000 Tokens), die beim Anbieter zwischengespeichert werden; Folgefragen
  innerhalb weniger Minuten sind dadurch deutlich günstiger. Die Unterhaltungen liegen in der Datenbank von SAFT
  (Tabellen `ai_help_conversations` und `ai_help_messages`) und werden nicht automatisch gelöscht; jede Person löscht
  ihre eigenen. Ein lokal betriebenes Modell ist vorgesehen, aber noch nicht anschliessbar.

## 6. Befehlsreferenz (php artisan)

Alle Befehle im Hauptverzeichnis von SAFT ausführen, auf einem Produktionsserver mit dem Benutzer des Webservers.
`php artisan list` zeigt alle verfügbaren Befehle, `php artisan help <befehl>` die Optionen eines Befehls.

### 6.1 SAFT-eigene Befehle

| Befehl | Zweck | Wichtige Optionen |
|---|---|---|
| `php artisan db:backup` | Sicherung der Datenbank von SAFT (PostgreSQL, MySQL oder MariaDB), dazu die Dateien aus Dateifeldern und das Briefpapier als ZIP | `--path=` Zielordner (Vorgabe `storage/backups`), `--without-files` ohne Dateien |
| `php artisan files:prune` | Räumt verwaiste Dateien aus Dateifeldern auf: nie gespeicherte Uploads, Dateien ohne Datensatz und Dateien ohne Eintrag (nach 24 Stunden); meldet Einträge, deren Datei fehlt | `--dry-run` nur zählen, `--hours=` Wartezeit |
| `php artisan app-data:verify` | Prüft, ob die Metadaten des Table-Designers und die Tabellen im Namensraum `app_data` übereinstimmen; Exit-Code 1 bei Abweichungen (für die Überwachung) | – |
| `php artisan auth:mode [local\|sso\|hybrid]` | Zeigt oder setzt den Anmeldemodus, z. B. als Notausgang `auth:mode local`, wenn kein Identity Provider erreichbar ist | – |
| `php artisan auth:two-factor-reset <email>` | Löscht Authenticator-App und Wiederherstellungscodes eines Kontos; die Person meldet sich mit dem Passwort an und richtet neu ein. Steht im Änderungsprotokoll | – |
| `php artisan computed:recompute [tabelle …]` | Berechnet die berechneten Felder neu (ohne Angabe: alle Tabellen mit berechneten Feldern); läuft als [Arbeit](ADMIN_GUIDE.de.md#arbeiten) und zeigt danach die Zahl der geänderten Datensätze je Tabelle | interne Tabellennamen; `--background` nur starten, der Scheduler rechnet |
| `php artisan work:run` | Treibt offene Arbeiten voran, an denen seit 30 Sekunden niemand arbeitet; ruft der Scheduler jede Minute auf | `--budget=` Sekunden (Vorgabe 50) |
| `php artisan table-connector:verify [tabelle …]` | Vergleicht angebundene Tabellen mit dem Schema der externen Datenbank; Exit-Code 1 bei Abweichungen | `--apply` übernimmt Abweichungen wie „Neu einlesen“ |
| `php artisan table-connector:connect <verbindung>` | Massenverknüpfung auf der Konsole (ohne Zeitlimit des Webservers) | `--all` bzw. `--table=` (mehrfach), `--app=` (ID der App), `--schema=`, `--prefix=`, `--menu-group=` (ID oder `none`), `--role=` (mehrfach), `--export`, `--create`, `--update`, `--delete`, `--inactive`, `--no-details` |
| `php artisan tasks:list` | Geplante Aufgaben mit Zeitplan, nächstem und letztem Lauf; letzter Aufruf des Schedulers | – |
| `php artisan tasks:run <ID>` | Eine geplante Aufgabe sofort ausführen (der Zeitplan bleibt) | – |
| `php artisan tasks:run-due` | Fällige geplante Aufgaben und vom Super-Admin vorgemerkte App-Entfernungen ausführen (nach der Wartezeit von 10 Minuten); ruft der Scheduler jede Minute auf. Ohne Scheduler laufen Entfernungen über „Jetzt entfernen“ auf der Seite Apps | – |
| `php artisan saft:plugins` | Listet die installierten Plugins mit Version und Kompatibilität und legt ihre Rechte an (läuft auch nach jedem `migrate`) | – |
| `php artisan saft:plugins --check [id]` | Prüft Plugins vor der Auslieferung (Kennung, API, Rechte, Menü, nur Plugin-API); Exit-Code 1 bei Fehlern | – |
| `php artisan saft:plugin-context` | Entwickler-Kontext für Plugins (AGENTS.md, API-Referenz, Schema; ohne Daten) | `--output=`, `--tables=` (mehrfach), `--without-external`, `--zip` |
| `php artisan saft:third-party-notices` | Erzeugt `THIRD_PARTY_NOTICES.md` (alle Komponenten von Drittanbietern mit Version, Lizenz, Copyright und Lizenztexten, auch die Bibliotheken im Mermaid-Bundle). Nach jedem Paket-Update auf dem Entwickler-Rechner ausführen und die Datei committen; ein Test prüft die Vollständigkeit | braucht `node_modules` |
| `php artisan external-demo:install` | Legt die externe Demo-Forschungsdatenbank und eine Datenbankverbindung dafür an und bindet ihre Tabellen an (`fdb_…`) | `--driver=pgsql\|mysql\|mariadb\|sqlsrv`, `--database=`, `--name=`, `--without-connection`, `--without-tables` |

### 6.2 Grundausstattung und Demo-Daten (Seeder)

| Befehl | Inhalt |
|---|---|
| `php artisan db:seed` (Produktion: `--force`) | Grundausstattung: Rollen, Rechte, Menü und der erste Administrator aus `ADMIN_*` in der `.env`; wiederholbar |
| `php artisan db:seed --class=DemoDataSeeder` | Shop-Demo (Artikel, Kunden, Bestellungen, Positionen) mit Beispiel-Auswertung, Gruppe „Shop (Demo)“ |
| `php artisan db:seed --class=LagerDemoSeeder` | Lager-Demo (`lager_…`), Gruppe „Lager (Demo)“ |
| `php artisan db:seed --class=VertriebDemoSeeder` | Vertriebs-Demo (`vertrieb_…`), eigene App „Vertrieb (Demo)“, die die Lager-Artikel über eine Schnittstelle nutzt; spielt die Lager-Demo bei Bedarf zuerst ein. Wer die Lager-Daten zurücksetzt, setzt die Vertriebsdaten mit zurück |
| `php artisan db:seed --class=GitarrenshopDemoSeeder` | Gitarrenshop-Demo (`gitarren_…`), eigene App „Gitarrenshop (Demo)“: Ein- und Ausgaben mit MWST, Belege und Zahlungen mit Dateien, Artikelbilder (Platzhalter), Rolle „Gitarrenshop“ mit allen Rechten der App. Belegnummern als Nummernkreis je Belegart (`V-2026-001`, `E-2026-001`); erneut ausgeführt, stellt er eine ältere Installation mit Belegnummern als Text darauf um (die Nummern bleiben) |
| `php artisan db:seed --class=MusikschuleDemoSeeder` | Musikschul-Demo (`musikschule_…`), mittelgrosse App „Musikschule (Demo)“ mit 21 Tabellen, installiert aus der App-Definition `database/seeders/apps/musikschule.saft-app.json` (entworfen mit dem KI-App-Designer): Kurse, Lektionen mit Präsenzen, Mietinstrumente, Semesterrechnungen mit Zahlungen, Konzerte, Ferien; 5 Auswertungen, Rolle „Musikschule“. Erneut ausgeführt, aktualisiert er eine installierte ältere Version der App (wie mit *Aktualisieren*, vorher mit Sicherung) und ergänzt die Ferien. Braucht einen Super-Admin (nach `php artisan db:seed`) |
| `php artisan db:seed --class=ForschungDemoSeeder` | Interne Forschungs-Demo (`forschung_…`), Gruppe „Forschung (Demo)“ |

Demo-Daten nur in Test- und Schulungssystemen einspielen (nicht bei einer Installation mit `--no-dev`). Die Demos
prüfen vorab, ob ihre Tabellennamen frei sind, und brechen sonst ab, ohne etwas anzulegen. Auf einem Server mit
`APP_ENV=production` (z. B. einer Demo bei Strato) fragt Laravel vor jedem Seeder „Are you sure you want to run this
command?“ – mit `--force` entfällt die Frage, z. B. `php artisan db:seed --class=MusikschuleDemoSeeder --force`.
Einen laufenden Seeder nicht abbrechen (Ctrl+C): Die Demo bleibt sonst halb gefüllt. Die Musikschul-Demo erkennt das
beim nächsten Lauf und lädt alle ihre Daten neu; bei den anderen Demos ihre Daten neu laden, mit der Aufgabe
„App-Daten zurücksetzen“ (braucht `DEMO_RESET=true`) oder z. B.
`php artisan tinker --execute='echo app(\Database\Seeders\LagerDemoSeeder::class)->resetDemoData();'`.

### 6.3 Laravel-Befehle, die SAFT braucht

| Befehl | Wann |
|---|---|
| `php artisan migrate --force` | nach jedem Update: legt neue Tabellen, Spalten, Rechte und Menüeinträge an |
| `php artisan optimize` und `php artisan filament:optimize` | nach jedem Update in der Produktion (Konfiguration, Routen, Ansichten, Filament-Komponenten zwischenspeichern) |
| `php artisan optimize:clear` | Zwischenspeicher leeren, z. B. nach Änderungen an der `.env` |
| `php artisan key:generate` | einmalig bei der Installation (Schlüssel `APP_KEY`) |
| `php artisan storage:link` | einmalig bei der Installation (öffentlicher Speicher für hochgeladene Logos) |
| `php artisan queue:work` | nur wenn `QUEUE_CONNECTION` nicht `sync` ist (z. B. für den Mailversand) oder mit `WORK_DRIVER=queue` für die [Arbeiten](#4-geplante-aufgaben-und-cron) |
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

Danach zeigt **Administration › Anwendung › Plugins** das Plugin mit Version und der Angabe, ob es zur installierten SAFT-Version
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
`saft/lager-umbuchung`, `saft/lager-tafel`, `saft/ch-pruefungen` und `saft/musikschule-planung` sind nur mit den Entwicklungspaketen installiert
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
