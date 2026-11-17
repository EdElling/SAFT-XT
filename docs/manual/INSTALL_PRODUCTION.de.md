# Anhang C – Installation auf einem Produktionsserver

[English](INSTALL_PRODUCTION.md) · **Deutsch** · [Zurück zum Benutzerhandbuch](USER_GUIDE.de.md)

**Stand:** Version 0.1 · **Zielgruppe:** Administratorinnen und Administratoren, die SAFT für andere bereitstellen

Diese Anleitung bringt SAFT auf einen Server, der aus dem Internet oder dem Firmennetz erreichbar ist – zum Beispiel
einen **extern gehosteten Managed Server** oder einen eigenen Linux-Server. Zum Ausprobieren auf dem eigenen Rechner
gilt [Anhang B – Installation auf einem Entwickler-Rechner](INSTALL_DEVELOPMENT.de.md).

---

## Inhalt

1. [Was der Server bieten muss](#1-was-der-server-bieten-muss)
2. [Vorbereitung beim Hoster](#2-vorbereitung-beim-hoster)
3. [SAFT auf den Server bringen](#3-saft-auf-den-server-bringen)
4. [Konfiguration (.env)](#4-konfiguration-env)
5. [Datenbank einrichten und optimieren](#5-datenbank-einrichten-und-optimieren)
6. [Webserver auf SAFT zeigen lassen](#6-webserver-auf-saft-zeigen-lassen)
7. [Erste Anmeldung und Grundeinstellungen](#7-erste-anmeldung-und-grundeinstellungen)
8. [Sicherungen](#8-sicherungen)
9. [Updates einspielen](#9-updates-einspielen)
10. [Sicherheits-Checkliste](#10-sicherheits-checkliste)
11. [Wenn etwas nicht klappt](#11-wenn-etwas-nicht-klappt)
12. [Beispiel: Webspace bei Strato](#12-beispiel-webspace-bei-strato)

---

## 1. Was der Server bieten muss

Klären Sie diese Punkte **vor** der Bestellung bzw. mit Ihrem Hoster. SAFT braucht PostgreSQL (empfohlen), MySQL oder
MariaDB – und mit MySQL bzw. MariaDB eine zweite Datenbank; das ist der häufigste Stolperstein.

| Anforderung | Details |
|---|---|
| **PHP 8.4 oder neuer** | für die Website **und** auf der Kommandozeile (SSH), mit den Erweiterungen `pdo_pgsql` bzw. `pdo_mysql`, `intl`, `zip`, `mbstring`, `xml`, `curl`, `bcmath`, `openssl`; nur für SQL Server als externe Quelle zusätzlich `pdo_sqlsrv` mit dem Microsoft ODBC Driver 18 |
| **PostgreSQL 16 oder neuer** | eine eigene Datenbank; der Benutzer muss darin ein Schema anlegen dürfen (am einfachsten: er ist Eigentümer der Datenbank). Eine Datenbank beim gleichen Hoster oder ein separater Datenbankdienst ist beides möglich. |
| *oder* **MySQL 8.0.16 oder neuer** | **zwei** Datenbanken mit demselben Benutzer (oder je eigenem, siehe Schritt 2): eine für SAFT und eine für die Tabellen des Table-Designers (Vorgabename `<datenbank>_app_data`, sonst in `APP_DATA_SCHEMA` eintragen). |
| *oder* **MariaDB 10.11 oder neuer** | wie MySQL: **zwei** Datenbanken mit demselben Benutzer. Auch ein Galera-Cluster ist möglich (alle Tabellen von SAFT haben einen Primärschlüssel und nutzen InnoDB); Strukturänderungen im Table-Designer laufen dort clusterweit und sperren kurz, grössere Umbauten daher ausserhalb der Hauptzeit. |
| **SSH-Zugang** | zum Ausführen von Befehlen (`composer`, `php artisan`); reiner FTP-Zugang reicht nicht |
| **Composer 2** | auf dem Server vorinstalliert oder selbst ins Benutzerverzeichnis installierbar |
| **Git** | empfohlen, zum Holen und Aktualisieren des Quellcodes |
| **Wählbares Web-Verzeichnis** | die Domain muss auf den Ordner `public` von SAFT zeigen können (*Document Root*) |
| **HTTPS-Zertifikat** | z. B. Let's Encrypt, meist im Kundenportal mit einem Klick |
| **Cron-Jobs** | für automatische Sicherungen (empfohlen) |
| **E-Mail-Versand (SMTP)** | für Benachrichtigungen und „Passwort zurücksetzen“ |
| `pg_dump`, `mysqldump` bzw. `mariadb-dump` | für `php artisan db:backup`; fehlt es, nutzen Sie die Datenbanksicherung des Hosters |

**Ressourcen:** Für einige Dutzend Benutzer genügen 2 GB Arbeitsspeicher und wenige GB Speicherplatz. SAFT braucht
**keine** Hintergrundprozesse (Queue-Worker) und kein Node.js auf dem Server.

---

## 2. Vorbereitung beim Hoster

Im Kundenportal Ihres Hosters (die Bezeichnungen unterscheiden sich je nach Anbieter):

1. **PHP-Version** der Website auf 8.4 oder neuer stellen; prüfen, dass die Kommandozeile dieselbe Version nutzt.
2. **PostgreSQL-Datenbank anlegen**, z. B. `saft`, mit eigenem Benutzer und starkem Passwort. Notieren Sie Host, Port
   (meist 5432), Datenbankname, Benutzer und Passwort. **Bei MySQL oder MariaDB** legen Sie zwei Datenbanken für denselben Benutzer
   an, z. B. `saft` und `saft_app_data` (Port meist 3306). Vergibt der Hoster Namen mit Präfix (z. B.
   `kunde42_saft_app_data`), tragen Sie den Namen der zweiten in Schritt 4 als `APP_DATA_SCHEMA` ein.

   **Hoster mit einem Benutzer und Server je Datenbank** (z. B. Strato): Dort erhält jede Datenbank einen eigenen,
   vom Hoster vergebenen Namen, Benutzer und Server (z. B. `database-5011111111.webspace-host.com`). Legen Sie zwei
   Datenbanken an und tragen Sie die zweite in Schritt 4 mit eigenen Zugangsdaten ein – SAFT verbindet sich mit beiden
   getrennt und legt keine Datenbank selbst an:

   ```dotenv
   DB_CONNECTION=mysql
   MYSQL_HOST=database-5011111111.webspace-host.com
   MYSQL_DATABASE=dbs11111111
   MYSQL_USERNAME=dbu11111111
   MYSQL_PASSWORD=<passwort der ersten datenbank>

   APP_DATA_SCHEMA=dbs22222222
   DB_APP_DATA_HOST=database-5022222222.webspace-host.com
   DB_APP_DATA_USERNAME=dbu22222222
   DB_APP_DATA_PASSWORD=<passwort der zweiten datenbank>
   ```
3. **Domain oder Subdomain** einrichten, z. B. `saft.example.ch`, und ein **HTTPS-Zertifikat** aktivieren.
4. **SSH-Zugang** einrichten (am besten mit SSH-Schlüssel statt Passwort).

Auf einem **eigenen Linux-Server** (mit Administratorrechten) installieren Sie stattdessen die Pakete wie in
[Anhang B, Schritt 2](INSTALL_DEVELOPMENT.de.md#2-programme-installieren), zusätzlich `nginx` und `php-fpm`, und legen
Datenbank und Benutzer wie in [Anhang B, Schritt 3](INSTALL_DEVELOPMENT.de.md#3-datenbank-anlegen) an.

---

## 3. SAFT auf den Server bringen

Melden Sie sich per SSH an und holen Sie die gewünschte Version (hier v0.2.0) in Ihr Benutzerverzeichnis:

```bash
cd ~
git clone https://github.com/EdElling/SAFT-XT.git saft
cd saft
git checkout v0.2.0                                   # feste Version statt des Entwicklungsstands
composer install --no-dev --optimize-autoloader      # ohne Entwicklungspakete – wichtig für die Sicherheit
```

> **Warum `--no-dev`?** Mit den Entwicklungspaketen gäbe es eine Testadresse, über die sich jede Person ohne Passwort
> anmelden könnte. Ohne sie existiert diese Adresse nicht. Die Demo-Daten und die Tests sind dann nicht verfügbar –
> auf einem Produktionsserver gewollt.

Ist das Repository nicht öffentlich, richten Sie für den Server einen **Deploy-Key** (Lesezugriff) bei GitHub ein.
Ohne Git können Sie das Release-Archiv („Source code“) von der
[Release-Seite](https://github.com/EdElling/SAFT-XT/releases) herunterladen, per SFTP hochladen und auf dem Server
entpacken; danach geht es mit `composer install …` weiter.

---

## 4. Konfiguration (.env)

```bash
cp .env.example .env
php artisan key:generate        # einmalig! Ein neuer Schlüssel macht gespeicherte Passwörter von Datenbankverbindungen unlesbar.
nano .env                       # oder ein anderer Editor
```

Setzen bzw. ergänzen Sie diese Werte (Platzhalter ersetzen):

```dotenv
APP_NAME=SAFT
APP_ENV=production
APP_DEBUG=false
APP_URL=https://saft.example.ch

APP_TIMEZONE=Europe/Zurich
APP_LOCALE=de

LOG_LEVEL=warning

DB_CONNECTION=pgsql             # oder mysql bzw. mariadb
PGSQL_HOST=<datenbank-host>
PGSQL_PORT=5432
PGSQL_DATABASE=<datenbankname>
PGSQL_USERNAME=<datenbank-benutzer>
PGSQL_PASSWORD=<datenbank-passwort>
DB_SSLMODE=prefer               # require, wenn die Datenbank auf einem anderen Rechner liegt
# Mit MySQL stattdessen MYSQL_HOST, MYSQL_PORT (3306), MYSQL_DATABASE, MYSQL_USERNAME, MYSQL_PASSWORD,
# mit MariaDB MARIADB_HOST, MARIADB_PORT, MARIADB_DATABASE, MARIADB_USERNAME, MARIADB_PASSWORD
# und, falls der Hoster die zweite Datenbank anders benennt: APP_DATA_SCHEMA=<name der zweiten datenbank>
# – hat sie eigenen Server oder Benutzer: DB_APP_DATA_HOST, DB_APP_DATA_PORT, DB_APP_DATA_USERNAME, DB_APP_DATA_PASSWORD

SESSION_SECURE_COOKIE=true      # Sitzungs-Cookie nur über HTTPS
TRUSTED_PROXIES=                # nur bei Load Balancer des Hosters, siehe unten

MAIL_MAILER=smtp
MAIL_HOST=<smtp-server des hosters>
MAIL_PORT=587
MAIL_USERNAME=<smtp-benutzer>
MAIL_PASSWORD=<smtp-passwort>
MAIL_FROM_ADDRESS=saft@example.ch

ADMIN_NAME="<Name der ersten Administratorin / des ersten Administrators>"
ADMIN_EMAIL=<adresse@example.ch>
ADMIN_PASSWORD=                 # leer lassen: SAFT erzeugt ein Passwort und zeigt es einmal an (sonst mind. 12 Zeichen)
```

**Load Balancer / Reverse Proxy:** Endet HTTPS beim Hoster vor Ihrem Server (fragen Sie nach, wenn unklar), tragen
Sie bei `TRUSTED_PROXIES` die Adressen des Proxys ein, z. B. `10.0.0.0/8` (mehrere durch Komma getrennt). Nur dann
erzeugt SAFT Links mit `https://` und protokolliert die echte IP-Adresse der Benutzer. `*` vertraut jedem direkt
anfragenden Rechner – das ist nur sicher, wenn Ihr Server ausschliesslich über den Proxy erreichbar ist. Ohne Proxy
bleibt der Wert leer.

Danach die Datei vor anderen Benutzern des Servers schützen:

```bash
chmod 600 .env
```

> **Wichtig:** Bewahren Sie eine Kopie von `.env` (insbesondere `APP_KEY`) an einem sicheren Ort auf, z. B. in einem
> Passwort-Manager. Ohne diesen Schlüssel lassen sich verschlüsselte Werte nach einer Wiederherstellung nicht mehr
> lesen.

---

## 5. Datenbank einrichten und optimieren

```bash
php artisan migrate --force      # legt alle Tabellen an („--force“ bestätigt die Ausführung in Produktion)
php artisan db:seed --force      # Rollen, Rechte, Menü und der erste Administrator aus ADMIN_*
```

Steht `ADMIN_PASSWORD` leer, erscheint nun eine Zeile *„Admin-Passwort für … (generiert, bitte notieren)“* – notieren
Sie das Passwort sofort.
Bei der ersten Anmeldung führt SAFT das Admin-Konto zur Einrichtung der **Zwei-Faktor-Anmeldung** – halten Sie ein
Handy mit einer Authenticator-App bereit und bewahren Sie die Wiederherstellungscodes sicher auf
([Administratorhandbuch, Kapitel 5](ADMIN_GUIDE.de.md#5-authentifizierung)).

Zum Schluss die Zwischenspeicher für schnelle Antwortzeiten aufbauen:

```bash
php artisan optimize             # Konfiguration, Routen, Ansichten
php artisan filament:optimize    # Komponenten und Symbole der Oberfläche
```

> Nach **jeder** Änderung an `.env` diese beiden Befehle erneut ausführen – sonst gilt die alte Konfiguration weiter.

Die Ordner `storage` und `bootstrap/cache` müssen für den Webserver beschreibbar sein. Bei Managed Servern läuft der
Webserver meist unter Ihrem eigenen Benutzer, dann passt das bereits. Auf einem eigenen Server:

```bash
sudo chown -R $USER:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

---

## 6. Webserver auf SAFT zeigen lassen

Die Domain muss auf den Ordner **`public`** zeigen – **nie** auf den SAFT-Ordner selbst, sonst wären `.env` und der
Quellcode aus dem Internet lesbar.

**Managed Server:** Stellen Sie im Kundenportal das Web-Verzeichnis (*Document Root*) der Domain auf
`/home/<benutzer>/saft/public` (genauer Pfad: `pwd` im SAFT-Ordner, dann `/public` anhängen). Die mitgelieferte Datei
`public/.htaccess` erledigt bei Apache den Rest.

Lässt der Hoster das Web-Verzeichnis nicht ändern, ersetzen Sie das vorgegebene Verzeichnis durch eine Verknüpfung
(Beispiel; Namen beim Hoster nachsehen):

```bash
mv ~/www ~/www.alt && ln -s ~/saft/public ~/www
```

**Eigener Server mit nginx** – Beispiel `/etc/nginx/sites-available/saft` (HTTPS-Zertifikat z. B. mit Certbot):

```nginx
server {
    listen 443 ssl;
    server_name saft.example.ch;
    root /var/www/saft/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/saft.example.ch/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/saft.example.ch/privkey.pem;

    client_max_body_size 20M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;   # an die installierte PHP-Version anpassen
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}

server {
    listen 80;
    server_name saft.example.ch;
    return 301 https://$host$request_uri;
}
```

Prüfen Sie im Browser: **https://saft.example.ch** leitet auf `/admin` und zeigt die Anmeldeseite.

---

## 7. Erste Anmeldung und Grundeinstellungen

1. Mit `ADMIN_EMAIL` und dem Passwort anmelden.
2. **Administration → Anwendung → App-Einstellungen:** Anzeigename und Farbschema festlegen
   ([Administratorhandbuch, Kapitel 7](ADMIN_GUIDE.de.md#7-app-einstellungen)).
3. **Administration → Benutzer und Zugriff → Authentifizierung:** Anmeldeart wählen, bei Bedarf den Schalter *„Passwort vergessen“ anbieten* einschalten
   ([Administratorhandbuch, Kapitel 5](ADMIN_GUIDE.de.md#5-authentifizierung)).
4. **Single Sign-on** (optional): Identity Provider anlegen. Die Adresse, die Sie beim Anbieter (z. B. Microsoft Entra
   ID) eintragen, lautet `https://saft.example.ch/auth/sso/<kurzname>/callback`, bei SAML
   `https://saft.example.ch/auth/saml/<kurzname>/acs` ([Administratorhandbuch, Kapitel 6](ADMIN_GUIDE.de.md#6-identity-provider-single-sign-on)).
5. **Benutzer und Rollen** anlegen ([Administratorhandbuch, Kapitel 1](ADMIN_GUIDE.de.md#1-benutzer) und
   [Administratorhandbuch, Kapitel 2](ADMIN_GUIDE.de.md#2-rollen-und-rechte)).
6. Tabellen mit dem **Table-Designer** anlegen oder externe Datenbanken über **Datenbankverbindungen** und den
   **Table-Connector** anbinden ([Administratorhandbuch, Kapitel 4](ADMIN_GUIDE.de.md#4-table-designer),
   [Administratorhandbuch, Kapitel 9](ADMIN_GUIDE.de.md#9-datenbankverbindungen), [Administratorhandbuch, Kapitel 10](ADMIN_GUIDE.de.md#10-table-connector)).
7. **Scheduler** für [geplante Aufgaben](ADMIN_GUIDE.de.md#12-geplante-aufgaben) einrichten – ein Cron-Eintrag, der
   jede Minute läuft (im Kundenportal oder mit `crontab -e`):

   ```cron
   * * * * * cd /home/<benutzer>/saft && php artisan schedule:run >> /dev/null 2>&1
   ```

   Schritt für Schritt und für Hoster mit gröberen Intervallen: [Systemadministration](SYSADMIN_GUIDE.de.md#4-geplante-aufgaben-und-cron).

> **Notausgang:** Sperrt eine falsche Einstellung der Anmeldung alle aus, setzen Sie `AUTH_FORCE_LOCAL=true` in `.env`
> und führen `php artisan optimize` aus – danach ist die lokale Anmeldung wieder möglich.

---

## 8. Sicherungen

**Täglich automatisch** über die geplante System-Aufgabe **Datenbank sichern** (02:30) – dafür genügt der Cron-Eintrag
für den Scheduler aus Schritt 7. Jede Sicherung ist eine Datei `<datenbank>_<datum>_<zeit>.dump` (bzw. `.sql`) in
`storage/backups`, nur für Ihren Benutzer lesbar. Schlägt sie fehl, steht der Grund unter *Geplante Aufgaben → Läufe*,
und die Super-Admins bekommen eine Meldung in der Glocke. **Sicherungen aufräumen** (02:45) löscht Sicherungen, die
älter als 14 Tage sind, und behält immer die 3 neuesten. Zeiten und Fristen ändern Sie unter *Administration › Betrieb ›
Geplante Aufgaben* ([Administratorhandbuch, Kapitel 12](ADMIN_GUIDE.de.md#12-geplante-aufgaben)). Von Hand jederzeit:
`php artisan db:backup`.

- `db:backup` braucht `pg_dump`, `mysqldump` bzw. `mariadb-dump` (Paket `mariadb-client`) in einer Version, die zum
  Datenbankserver passt. Liegt das Programm nicht im Suchpfad, tragen Sie es in `.env` ein (`PGSQL_DUMP_BINARY`,
  `MYSQL_DUMP_BINARY` bzw. `MARIADB_DUMP_BINARY`). Fehlt es ganz oder ist es zu alt (z. B. `mysqldump` 5.6 bei Strato,
  Meldung `Error in field count`), sichert SAFT bei MySQL und MariaDB auch **in PHP**: `DB_BACKUP_METHOD=php` in `.env`
  (oder einmalig `php artisan db:backup --php`). Die Datei ist ebenfalls ein SQL-Skript und lässt sich mit `mysql`,
  `mariadb` oder in phpMyAdmin einspielen. Bei MySQL und MariaDB enthält die `.sql`-Datei beide Datenbanken. Eine Sicherung aus MySQL lässt sich nicht
  verlässlich in MariaDB einspielen und umgekehrt.
- Die Sicherungen liegen auf demselben Server – kopieren Sie sie regelmässig an einen **anderen Ort**.
- Sichern Sie zusätzlich die Datei `.env` (siehe Schritt 4).
- **Wiederherstellen** in eine neue, leere Datenbank:
  `pg_restore -h <host> -U <benutzer> --no-owner -d <neue_datenbank> storage/backups/<datei>.dump`
  – MySQL: `mysql -h <host> -u <benutzer> -p < storage/backups/<datei>.sql` (MariaDB: `mariadb …`) spielt beide Datenbanken unter ihren
  **ursprünglichen Namen** zurück und überschreibt dort vorhandene Tabellen; für andere Namen passen Sie zuvor die
  Zeilen `CREATE DATABASE` und `USE` in der Datei an. Haben die beiden Datenbanken eigene Benutzer bzw. Server (z. B.
  Strato), besteht die Datei aus zwei Teilen, je beginnend mit `-- MySQL dump` bzw. `-- MariaDB dump`; spielen Sie jeden
  Teil mit den Zugangsdaten seiner Datenbank ein.

Wer angebundene externe Tabellen nutzt, kann zusätzlich wöchentlich prüfen, ob sich deren Aufbau geändert hat:

```cron
0 6 * * 1 cd /home/<benutzer>/saft && php artisan table-connector:verify >> storage/logs/verify.log 2>&1
```

---

## 9. Updates einspielen

Lesen Sie zuerst die Release-Notes der neuen Version. Dann:

```bash
cd ~/saft
php artisan db:backup                                   # 1. Sicherung
php artisan down                                        # 2. Wartungsmodus: Benutzer sehen einen Hinweis
git fetch --tags && git checkout v<neue-version>        # 3. neue Version holen
composer install --no-dev --optimize-autoloader         # 4. Pakete aktualisieren
php artisan migrate --force                             # 5. Datenbank anpassen
php artisan optimize && php artisan filament:optimize   # 6. Zwischenspeicher neu aufbauen
php artisan up                                          # 7. Wartungsmodus beenden
```

Geht etwas schief: `git checkout v<alte-version>`, `composer install --no-dev --optimize-autoloader`, die Sicherung in
eine neue Datenbank zurückspielen und `PGSQL_DATABASE` bzw. `MYSQL_DATABASE` in `.env` darauf umstellen.

---

## 10. Sicherheits-Checkliste

- [ ] `APP_ENV=production` und `APP_DEBUG=false`
- [ ] Installiert mit `composer install --no-dev`
- [ ] Domain zeigt auf `…/saft/public`, nicht auf den SAFT-Ordner
- [ ] HTTPS aktiv, `APP_URL` beginnt mit `https://`, `SESSION_SECURE_COOKIE=true`, HTTP leitet auf HTTPS um (bei
  Webspaces meist eine Einstellung beim Hoster, z. B. „SSL erzwingen“)
- [ ] `TRUSTED_PROXIES` leer oder nur mit den Adressen des Proxys des Hosters (`*` nur, wenn der Server nur über den Proxy erreichbar ist)
- [ ] `.env` mit `chmod 600`, Kopie von `.env` sicher verwahrt
- [ ] Erstes Admin-Passwort nach der Anmeldung geändert bzw. sicher abgelegt
- [ ] Zwei-Faktor-Anmeldung für das Admin-Konto eingerichtet, Wiederherstellungscodes sicher abgelegt
- [ ] Tägliche Sicherung eingerichtet und einmal Wiederherstellung ausprobiert
- [ ] Datenbankverbindungen zu externen Datenbanken mit Benutzern mit möglichst wenigen Rechten (nur lesen, wo
      nicht geschrieben wird)

---

## 11. Wenn etwas nicht klappt

| Beobachtung | Ursache und Lösung |
|---|---|
| Weisse Seite oder „500 Server Error“ | Ursache steht in `storage/logs/laravel.log`. Häufig: Ordner nicht beschreibbar (Schritt 5) oder `APP_KEY` fehlt. |
| `could not find driver` | PHP-Erweiterung `pdo_pgsql` bzw. `pdo_mysql` fehlt – beim Hoster aktivieren lassen. |
| `Access denied for user` (MySQL) | Der Benutzer hat keine Rechte auf die zweite Datenbank (`…_app_data`) – im Kundenportal zuweisen oder den Namen in `APP_DATA_SCHEMA` korrigieren. Hat die zweite Datenbank einen eigenen Benutzer (z. B. Strato), dessen Zugangsdaten in `DB_APP_DATA_HOST`, `DB_APP_DATA_USERNAME` und `DB_APP_DATA_PASSWORD` eintragen. |
| Kommandozeile meldet eine alte PHP-Version | Auf der Kommandozeile ist eine andere PHP-Version eingestellt; beim Hoster umstellen oder den passenden Befehl nutzen (oft `php8.4`). |
| `permission denied to create schema` | Der Datenbankbenutzer darf in der Datenbank kein Schema anlegen – der Hoster muss ihn zum Eigentümer machen oder `CREATE` erlauben. |
| Anmeldeseite ohne Gestaltung | `php artisan filament:upgrade` ausführen; prüfen, dass die Domain auf `public` zeigt. |
| Änderungen in `.env` wirken nicht | `php artisan optimize` (und `filament:optimize`) erneut ausführen. |
| Links oder Weiterleitungen zeigen `http://` statt `https://`, im Protokoll steht immer dieselbe IP-Adresse | HTTPS endet vor dem Server (Load Balancer): `TRUSTED_PROXIES` setzen (Schritt 4), dann `php artisan optimize`. |
| Browser verlangt nach dem Wechsel zurück auf http weiterhin HTTPS | HSTS: Nach einem Aufruf über HTTPS merkt sich der Browser das ein Jahr lang. HTTPS beibehalten; nur wenn es wirklich nicht anders geht, `SECURITY_HSTS=false` setzen und die Website-Daten im Browser löschen ([Systemhandbuch, Kapitel 3](SYSADMIN_GUIDE.de.md#3-updates-und-sicherheit)). |
| „Seite abgelaufen“ (419) nach der Anmeldung | `SESSION_SECURE_COOKIE=true` ohne HTTPS, oder `APP_URL` passt nicht zur aufgerufenen Adresse. |
| E-Mails kommen nicht an | SMTP-Angaben prüfen; `MAIL_FROM_ADDRESS` muss eine Adresse der eigenen Domain sein. |
| `db:backup` meldet „Sicherung fehlgeschlagen“ | Die Meldung dahinter nennt den Grund: Programm fehlt (Pfad in `…_DUMP_BINARY`), ist älter als der Datenbankserver (`server version mismatch`, `Error in field count`) oder darf nicht verbinden. Bei MySQL und MariaDB hilft `DB_BACKUP_METHOD=php`, sonst die Sicherung des Hosters. |

---

## 12. Beispiel: Webspace bei Strato

Erprobt mit einem Strato-Webspace (MariaDB 11.8, PHP 8.4). Bezeichnungen im Kundenportal können sich ändern.

**Besonderheiten bei Strato:**

| Thema | Lösung |
|---|---|
| `php` auf der Kommandozeile ist die CGI-Fassung (Ausgaben wie `Status:`, `Content-type:`) | das Kommandozeilen-PHP verwenden: `alias php='/opt/RZphp84/bin/php-cli'`, dauerhaft in `~/.bashrc` |
| kein `composer` | `composer.phar` in den SAFT-Ordner laden (siehe unten) und mit `php composer.phar …` aufrufen |
| jede Datenbank hat eigenen Namen, Benutzer und Server | zwei Datenbanken anlegen, die zweite mit `APP_DATA_SCHEMA` und `DB_APP_DATA_*` eintragen (Schritt 2) |
| `mysqldump` 5.6 ist zu alt, `mariadb-dump` fehlt | `DB_BACKUP_METHOD=php` (Schritt 8) |
| kein `which` | `command -v <programm>` |
| HTTPS | Zertifikat für die (Sub-)Domain aktivieren und unter *Sicherheit* **SSL erzwingen** einschalten |

**Ablauf** (per SSH; das Home-Verzeichnis ist bei Strato bereits `htdocs`):

```bash
alias php='/opt/RZphp84/bin/php-cli'
git clone https://github.com/EdElling/SAFT-XT.git saft
cd saft
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php && rm composer-setup.php
php composer.phar install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
```

In `.env` (Werte aus dem Kundenportal, Port 3306):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://saft.example.ch
SESSION_SECURE_COOKIE=true

DB_CONNECTION=mariadb
MARIADB_HOST=database-5011111111.webspace-host.com
MARIADB_DATABASE=dbs11111111
MARIADB_USERNAME=dbu1111111
MARIADB_PASSWORD=<passwort der ersten datenbank>

APP_DATA_SCHEMA=dbs22222222
DB_APP_DATA_HOST=database-5022222222.webspace-host.com
DB_APP_DATA_USERNAME=dbu2222222
DB_APP_DATA_PASSWORD=<passwort der zweiten datenbank>

DB_BACKUP_METHOD=php
```

Dann weiter mit Schritt 5 (`migrate`, `db:seed`, `optimize`). Im Kundenportal das Zielverzeichnis der Domain auf
`…/saft/public` setzen, das Zertifikat aktivieren und **SSL erzwingen** einschalten.

**Test- oder Schulungssystem mit den Beispielen:** Für die Lager-Demo und die Beispiel-Plugins installieren Sie die
Abhängigkeiten **mit** den Entwicklungspaketen (`php composer.phar install --optimize-autoloader`, ohne `--no-dev`) und
spielen danach `php artisan db:seed --class=LagerDemoSeeder --force` ein. `APP_ENV=production` muss dabei bleiben –
nur so ist die Test-Anmeldung der Entwicklungspakete abgeschaltet. Für ein Produktivsystem bleibt `--no-dev` richtig.

**Geplante Aufgaben und Sicherung:** Im Kundenportal einen Cronjob anlegen, der jede Minute (oder im kürzesten
angebotenen Abstand) diesen Befehl ausführt:

```bash
cd ~/saft && /opt/RZphp84/bin/php-cli artisan schedule:run
```

Damit laufen auch die tägliche Sicherung und das Aufräumen der Sicherungen. Bei gröberen Abständen siehe
[Systemadministration, Kapitel 4](SYSADMIN_GUIDE.de.md#4-geplante-aufgaben-und-cron).

**Öffentliche Demo jede Nacht zurücksetzen:** `DEMO_RESET=true` in `.env`, dann `php artisan optimize`, und unter
*Administration › Betrieb › Geplante Aufgaben* die Aufgabe **Demo-Daten zurücksetzen** einschalten. Sie lädt um 04:00 die Daten
der gewählten Demos neu; Änderungen der Besucher an den Demo-Daten sind danach weg, alles andere bleibt.

**Updates** (Ablauf wie in [Abschnitt 9](#9-updates-einspielen), mit dem Kommandozeilen-PHP):

```bash
alias php='/opt/RZphp84/bin/php-cli'   # falls nicht in ~/.bashrc
cd ~/saft
php artisan down
php artisan db:backup
git pull
php composer.phar install --optimize-autoloader   # Demo mit Beispiel-Plugins: ohne --no-dev
php artisan migrate --force
php artisan optimize && php artisan filament:optimize
php artisan up
```

Die Lager-Demo bekommt neue Menüs (z. B. die Untermenüs *Lager führen*, *Stammdaten*, *Auswertungen*), wenn ihr Seeder
nach dem Update noch einmal läuft: `php artisan db:seed --class=LagerDemoSeeder --force` – vorhandene Daten bleiben,
selbst verschobene Menüeinträge auch.

Wurde eine Demo mit Beispiel-Plugins einmal mit `--no-dev` aktualisiert, fehlen die Plugins danach. Dann **nicht** auf
der Seite *Plugins* **Aufräumen** klicken – das löscht ihre Menüeinträge und Rechte, und die Rollen verlieren die
Zuweisung. Stattdessen `php composer.phar install` ohne `--no-dev` ausführen, danach `optimize` und
`filament:optimize`.
