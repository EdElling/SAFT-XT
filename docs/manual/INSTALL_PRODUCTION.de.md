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

---

## 1. Was der Server bieten muss

Klären Sie diese Punkte **vor** der Bestellung bzw. mit Ihrem Hoster. SAFT braucht PostgreSQL (empfohlen), MySQL oder
MariaDB – und mit MySQL bzw. MariaDB eine zweite Datenbank; das ist der häufigste Stolperstein.

| Anforderung | Details |
|---|---|
| **PHP 8.4 oder neuer** | für die Website **und** auf der Kommandozeile (SSH), mit den Erweiterungen `pdo_pgsql` bzw. `pdo_mysql`, `intl`, `zip`, `mbstring`, `xml`, `curl`, `bcmath`, `openssl`; nur für SQL Server als externe Quelle zusätzlich `pdo_sqlsrv` mit dem Microsoft ODBC Driver 18 |
| **PostgreSQL 16 oder neuer** | eine eigene Datenbank; der Benutzer muss darin ein Schema anlegen dürfen (am einfachsten: er ist Eigentümer der Datenbank). Eine Datenbank beim gleichen Hoster oder ein separater Datenbankdienst ist beides möglich. |
| *oder* **MySQL 8.0.16 oder neuer** | **zwei** Datenbanken mit demselben Benutzer: eine für SAFT und eine für die Tabellen des Table-Designers (Vorgabename `<datenbank>_app_data`, sonst in `APP_DATA_SCHEMA` eintragen). |
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
3. **Domain oder Subdomain** einrichten, z. B. `saft.example.ch`, und ein **HTTPS-Zertifikat** aktivieren.
4. **SSH-Zugang** einrichten (am besten mit SSH-Schlüssel statt Passwort).

Auf einem **eigenen Linux-Server** (mit Administratorrechten) installieren Sie stattdessen die Pakete wie in
[Anhang B, Schritt 2](INSTALL_DEVELOPMENT.de.md#2-programme-installieren), zusätzlich `nginx` und `php-fpm`, und legen
Datenbank und Benutzer wie in [Anhang B, Schritt 3](INSTALL_DEVELOPMENT.de.md#3-datenbank-anlegen) an.

---

## 3. SAFT auf den Server bringen

Melden Sie sich per SSH an und holen Sie die gewünschte Version (hier 2.1.0) in Ihr Benutzerverzeichnis:

```bash
cd ~
git clone https://github.com/EdElling/SAFT-XT.git saft
cd saft
git checkout v0.1.0                                   # feste Version statt des Entwicklungsstands
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
ADMIN_PASSWORD=                 # leer lassen: SAFT erzeugt ein Passwort und zeigt es einmal an
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
2. **Administration → App-Einstellungen:** Anzeigename und Farbschema festlegen
   ([Administratorhandbuch, Kapitel 7](ADMIN_GUIDE.de.md#7-app-einstellungen)).
3. **Administration → Authentifizierung:** Anmeldeart wählen, bei Bedarf den Schalter *„Passwort vergessen“ anbieten* einschalten
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

**Täglich automatisch** mit einem Cron-Job (im Kundenportal oder mit `crontab -e`). Jede Sicherung ist eine Datei
`<datenbank>_<datum>_<zeit>.dump`, nur für Ihren Benutzer lesbar:

```cron
# Jeden Tag um 02:30 die Datenbank nach storage/backups sichern
30 2 * * * cd /home/<benutzer>/saft && php artisan db:backup >> storage/logs/backup.log 2>&1
```

- `db:backup` braucht `pg_dump`, `mysqldump` bzw. `mariadb-dump` (Paket `mariadb-client`) in einer Version, die zum
  Datenbankserver passt. Liegt das Programm nicht im Suchpfad, tragen Sie es in `.env` ein (`PGSQL_DUMP_BINARY`,
  `MYSQL_DUMP_BINARY` bzw. `MARIADB_DUMP_BINARY`). Fehlt es ganz, nutzen Sie die automatische Datenbanksicherung Ihres
  Hosters. Bei MySQL und MariaDB enthält die `.sql`-Datei beide Datenbanken. Eine Sicherung aus MySQL lässt sich nicht
  verlässlich in MariaDB einspielen und umgekehrt.
- Die Sicherungen liegen auf demselben Server – kopieren Sie sie regelmässig an einen **anderen Ort** und löschen Sie
  alte Dateien, damit der Speicher nicht vollläuft.
- Sichern Sie zusätzlich die Datei `.env` (siehe Schritt 4).
- **Wiederherstellen** in eine neue, leere Datenbank:
  `pg_restore -h <host> -U <benutzer> --no-owner -d <neue_datenbank> storage/backups/<datei>.dump`
  – MySQL: `mysql -h <host> -u <benutzer> -p < storage/backups/<datei>.sql` (MariaDB: `mariadb …`) spielt beide Datenbanken unter ihren
  **ursprünglichen Namen** zurück und überschreibt dort vorhandene Tabellen; für andere Namen passen Sie zuvor die
  Zeilen `CREATE DATABASE` und `USE` in der Datei an.

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
- [ ] HTTPS aktiv, `SESSION_SECURE_COOKIE=true`, HTTP leitet auf HTTPS um
- [ ] `TRUSTED_PROXIES` leer oder nur mit den Adressen des Proxys des Hosters (`*` nur, wenn der Server nur über den Proxy erreichbar ist)
- [ ] `.env` mit `chmod 600`, Kopie von `.env` sicher verwahrt
- [ ] Erstes Admin-Passwort nach der Anmeldung geändert bzw. sicher abgelegt
- [ ] Tägliche Sicherung eingerichtet und einmal Wiederherstellung ausprobiert
- [ ] Datenbankverbindungen zu externen Datenbanken mit Benutzern mit möglichst wenigen Rechten (nur lesen, wo
      nicht geschrieben wird)

---

## 11. Wenn etwas nicht klappt

| Beobachtung | Ursache und Lösung |
|---|---|
| Weisse Seite oder „500 Server Error“ | Ursache steht in `storage/logs/laravel.log`. Häufig: Ordner nicht beschreibbar (Schritt 5) oder `APP_KEY` fehlt. |
| `could not find driver` | PHP-Erweiterung `pdo_pgsql` bzw. `pdo_mysql` fehlt – beim Hoster aktivieren lassen. |
| `Access denied for user` (MySQL) | Der Benutzer hat keine Rechte auf die zweite Datenbank (`…_app_data`) – im Kundenportal zuweisen oder den Namen in `APP_DATA_SCHEMA` korrigieren. |
| Kommandozeile meldet eine alte PHP-Version | Auf der Kommandozeile ist eine andere PHP-Version eingestellt; beim Hoster umstellen oder den passenden Befehl nutzen (oft `php8.4`). |
| `permission denied to create schema` | Der Datenbankbenutzer darf in der Datenbank kein Schema anlegen – der Hoster muss ihn zum Eigentümer machen oder `CREATE` erlauben. |
| Anmeldeseite ohne Gestaltung | `php artisan filament:upgrade` ausführen; prüfen, dass die Domain auf `public` zeigt. |
| Änderungen in `.env` wirken nicht | `php artisan optimize` (und `filament:optimize`) erneut ausführen. |
| Links oder Weiterleitungen zeigen `http://` statt `https://`, im Protokoll steht immer dieselbe IP-Adresse | HTTPS endet vor dem Server (Load Balancer): `TRUSTED_PROXIES` setzen (Schritt 4), dann `php artisan optimize`. |
| „Seite abgelaufen“ (419) nach der Anmeldung | `SESSION_SECURE_COOKIE=true` ohne HTTPS, oder `APP_URL` passt nicht zur aufgerufenen Adresse. |
| E-Mails kommen nicht an | SMTP-Angaben prüfen; `MAIL_FROM_ADDRESS` muss eine Adresse der eigenen Domain sein. |
| `db:backup` meldet „Sicherung fehlgeschlagen“ | Die Meldung dahinter nennt den Grund: `pg_dump` fehlt, ist älter als der Datenbankserver (`server version mismatch`) oder darf nicht verbinden – sonst die Sicherung des Hosters nutzen. |
