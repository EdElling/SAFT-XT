# Anhang B – Installation auf einem Entwickler-Rechner

[English](INSTALL_DEVELOPMENT.md) · **Deutsch** · [Zurück zum Benutzerhandbuch](USER_GUIDE.de.md)

**Stand:** Version 0.1 · **Zielgruppe:** Entwicklerinnen und Entwickler, technisch versierte Administratorinnen und
Administratoren

Diese Anleitung richtet SAFT auf einem eigenen Rechner ein: zum Ausprobieren, Weiterentwickeln und Testen. Für einen
Server im Internet gilt [Anhang C – Installation auf einem Produktionsserver](INSTALL_PRODUCTION.de.md).

---

## Inhalt

1. [Was Sie brauchen](#1-was-sie-brauchen)
2. [Programme installieren](#2-programme-installieren)
3. [Datenbank anlegen](#3-datenbank-anlegen)
4. [SAFT herunterladen und einrichten](#4-saft-herunterladen-und-einrichten)
5. [Starten und anmelden](#5-starten-und-anmelden)
6. [Demo-Daten (optional)](#6-demo-daten-optional)
7. [Tests einrichten (optional)](#7-tests-einrichten-optional)
8. [Im Alltag](#8-im-alltag)
9. [Wenn etwas nicht klappt](#9-wenn-etwas-nicht-klappt)

---

## 1. Was Sie brauchen

| Komponente | Version | Wofür |
|---|---|---|
| **PHP** | 8.4 oder neuer (entwickelt mit 8.5) | führt SAFT aus |
| PHP-Erweiterungen | `pdo_pgsql` (bzw. `pdo_mysql`), `intl`, `zip`, `mbstring`, `xml`, `curl`, `bcmath`, `openssl` | Datenbank, Sprachen, Excel-Export, Single Sign-on |
| **Composer** | 2.x | lädt die PHP-Pakete |
| **PostgreSQL** | 16 oder neuer (entwickelt mit 18) | speichert alle Daten (empfohlen) |
| *oder* **MySQL** | 8.0.16 oder neuer (getestet mit 8.4) | Alternative zu PostgreSQL |
| **Git** | aktuell | lädt den Quellcode |
| Node.js | 20 oder neuer | **nur** für die Browser-Tests |

Empfohlenes Betriebssystem ist **Linux** (z. B. Ubuntu 26.04 LTS). Unter **Windows** arbeiten Sie am einfachsten mit
**WSL 2** (Windows-Subsystem für Linux) und folgen dann den Linux-Schritten. **macOS** funktioniert mit Homebrew.

> **Tipp:** Alle Befehle in dieser Anleitung geben Sie in einem Terminal ein. Zeilen, die mit `#` beginnen, sind
> Erklärungen und werden nicht eingegeben. Ersetzen Sie Werte in spitzen Klammern wie `<passwort>` durch Ihre eigenen.

---

## 2. Programme installieren

### Linux (Ubuntu / Debian)

```bash
sudo apt update
sudo apt install -y git unzip php-cli php-pgsql php-intl php-zip php-mbstring php-xml php-curl php-bcmath \
                    postgresql postgresql-client
```

Prüfen, ob die Versionen passen:

```bash
php -v          # muss 8.4 oder höher zeigen
psql --version  # muss 16 oder höher zeigen
```

> Zeigt `php -v` eine ältere Version (z. B. bei Ubuntu 24.04), installieren Sie PHP 8.4 aus einer zusätzlichen
> Paketquelle Ihrer Distribution oder nehmen Sie eine neuere Ubuntu-Version.

**Composer** installieren Sie nach der Anleitung auf [getcomposer.org](https://getcomposer.org/download/) oder mit
`sudo apt install -y composer`, falls Ihre Distribution Composer 2 mitbringt (`composer --version`).

### Windows

1. In einer Eingabeaufforderung mit Administratorrechten: `wsl --install -d Ubuntu`
2. Den Rechner neu starten, Ubuntu aus dem Startmenü öffnen und einen Benutzer anlegen.
3. In diesem Ubuntu-Fenster die Linux-Schritte oben ausführen. Den Quellcode legen Sie danach **im Linux-Teil** ab
   (z. B. `~/projekte`), nicht unter `C:\` – das ist deutlich schneller.

### macOS

Mit [Homebrew](https://brew.sh):

```bash
brew install php composer postgresql@18 git
brew services start postgresql@18
```

Die Erweiterungen `intl`, `pdo_pgsql` und `zip` sind im PHP von Homebrew bereits enthalten (`php -m` zeigt sie).

### MySQL statt PostgreSQL

SAFT läuft wahlweise mit **MySQL 8.0.16 oder neuer**. Statt der PostgreSQL-Pakete installieren Sie:

```bash
sudo apt install -y php-mysql mysql-server      # Linux; macOS: brew install mysql && brew services start mysql
```

Unterschiede zu PostgreSQL, die Sie kennen sollten:

- MySQL vergleicht Text ohne Gross-/Kleinschreibung: Bei eindeutigen Feldern gelten „A“ und „a“ als doppelt.
- Textfelder, die eindeutig oder sortierbar sind, dürfen höchstens 768 Zeichen lang sein.
- Eine Tabelle darf nicht beliebig viele lange Textfelder haben (Zeilengrenze von MySQL); längere Texte als
  „Text (mehrzeilig)“ anlegen.

### MariaDB statt PostgreSQL

SAFT läuft auch mit **MariaDB 10.11 oder neuer**; es gelten dieselben Unterschiede wie bei MySQL. PHP braucht nur
`pdo_mysql`; für `db:backup` ist das Programm `mariadb-dump` nötig:

```bash
sudo apt install -y php-mysql mariadb-server mariadb-client   # oder MariaDB im Container, siehe Abschnitt 6
```

Eindeutige Felder des Table-Designers bekommen unter MariaDB eine unsichtbare Hilfsspalte (`cf_<Nummer>_uq_v`); sie
erscheint in keiner Liste und keinem Export.

---

## 3. Datenbank anlegen

SAFT braucht eine eigene Datenbank und einen Datenbankbenutzer, dem sie gehört. Als Eigentümer darf er das Schema
`app_data` anlegen, in dem die Tabellen des Table-Designers liegen.

**Linux / WSL:**

```bash
sudo -u postgres createuser --pwprompt saft   # fragt nach einem Passwort für den Benutzer „saft“
sudo -u postgres createdb --owner=saft SAFT2
```

**macOS** (Homebrew richtet Ihren Mac-Benutzer als Datenbank-Administrator ein):

```bash
createuser --pwprompt saft
createdb --owner=saft SAFT2
```

Testen Sie die Verbindung: `psql -h 127.0.0.1 -U saft SAFT2 -c "select version();"` – nach Eingabe des Passworts
erscheint die PostgreSQL-Version.

**MySQL:** Bei MySQL gibt es keine Schemas; die Tabellen des Table-Designers liegen in einer **zweiten Datenbank**
`SAFT2_app_data`, die SAFT selbst anlegt. Der Benutzer braucht daher Rechte auf beide:

```bash
sudo mysql <<'SQL'
CREATE USER 'saft'@'localhost' IDENTIFIED BY '<passwort>';
CREATE DATABASE SAFT2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `SAFT2`.* TO 'saft'@'localhost';
GRANT ALL PRIVILEGES ON `SAFT2\_app\_data`.* TO 'saft'@'localhost';
SQL
```

**MariaDB:** wie MySQL, nur mit `sudo mariadb` (bzw. im Container `docker exec -it mariadb mariadb -u root -p`). Legen
Sie `SAFT2_app_data` gleich mit an, falls der Benutzer keine Datenbanken anlegen darf:

```sql
CREATE USER 'saft'@'localhost' IDENTIFIED BY '<passwort>';     -- im Container: 'saft'@'%'
CREATE DATABASE SAFT2          CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE SAFT2_app_data CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `SAFT2`.*          TO 'saft'@'localhost';
GRANT ALL PRIVILEGES ON `SAFT2_app_data`.* TO 'saft'@'localhost';
```

---

## 4. SAFT herunterladen und einrichten

```bash
mkdir -p ~/projekte && cd ~/projekte
git clone https://github.com/EdElling/SAFT-XT.git
cd SAFT-XT
composer install                 # lädt alle Pakete, auch die für Tests (dauert einige Minuten)
cp .env.example .env             # Konfigurationsdatei anlegen
php artisan key:generate         # Schlüssel für Verschlüsselung und Sitzungen erzeugen
```

Öffnen Sie jetzt die Datei `.env` in einem Editor. Sie enthält je einen Block für PostgreSQL (`PGSQL_*`), MySQL
(`MYSQL_*`) und MariaDB (`MARIADB_*`); **`DB_CONNECTION`** wählt aus, welcher gilt. Tragen Sie vor allem das Datenbank-Passwort und die Angaben
zum ersten Administrator ein:

```dotenv
APP_NAME=SAFT
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql              # oder mysql bzw. mariadb

PGSQL_HOST=127.0.0.1
PGSQL_PORT=5432
PGSQL_DATABASE=SAFT2
PGSQL_USERNAME=saft
PGSQL_PASSWORD=<passwort aus Schritt 3>

MYSQL_HOST=127.0.0.1
MYSQL_PORT=3306
MYSQL_DATABASE=SAFT2
MYSQL_USERNAME=saft
MYSQL_PASSWORD=<passwort aus Schritt 3>

ADMIN_NAME="<Ihr Name>"
ADMIN_EMAIL=<ihre@adresse.ch>
ADMIN_PASSWORD=<mind. 12 Zeichen>   # leer lassen = SAFT erzeugt eines und zeigt es einmal an
```

> **Wichtig:** `.env` enthält Passwörter und gehört nie ins Repository oder in eine E-Mail. Sie ist bereits in
> `.gitignore` eingetragen.

> `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` und `DB_PASSWORD` haben Vorrang vor beiden Blöcken (so setzen die
> Tests ihre eigenen Datenbanken). Lassen Sie sie in der `.env` weg, wenn Sie zwischen PostgreSQL und MySQL wechseln
> möchten.

Dann die Datenbank aufbauen und die Grundeinrichtung einspielen:

```bash
php artisan migrate      # legt alle Tabellen an
php artisan db:seed      # Rollen, Rechte, Menü und den ersten Administrator (ADMIN_*)
```

---

## 5. Starten und anmelden

```bash
php artisan serve
```

Öffnen Sie im Browser **http://localhost:8000** – Sie landen auf der Anmeldeseite unter `/admin`. Melden Sie sich mit
`ADMIN_EMAIL` und `ADMIN_PASSWORD` an. Beenden Sie den Server im Terminal mit `Strg+C`.

Nach der Anmeldung lohnt ein Blick in **Administration → Anwendung → Globale Einstellungen** (Anzeigename, Farbschema) und
**Benutzer**. Wie es weitergeht, beschreibt das [Benutzerhandbuch](USER_GUIDE.de.md).

---

## 6. Demo-Daten (optional)

**Bestellbeispiel** (Artikel, Kunden, Bestellungen, Bestellpositionen) mit Master-Detail und einer Regel:

```bash
php artisan db:seed --class=DemoDataSeeder
```

In der Gruppe „Daten“ erscheint zusätzlich der Eintrag **„Datenmodell“** mit dem Diagramm der vier Tabellen.

**Externe Forschungsdatenbank** für Datenbankverbindungen und Table-Connector (Personen, Projekte, Publikationen,
Organisationen):

```bash
sudo -u postgres createdb --owner=saft SAFT2_ext   # macOS: createdb --owner=saft SAFT2_ext
php artisan external-demo:install
```

Danach gibt es im Menü die Gruppe „Forschungsdatenbank“ mit sechs angebundenen Tabellen (technische Namen `fdb_…`)
und einem Eintrag „Datenmodell“. Die interne Forschungs-Demo (`db:seed --class=ForschungDemoSeeder`, Tabellen
`forschung_…`) kann daneben bestehen. Ist ein Name schon von einer anderen Tabelle belegt, brechen beide ab, ohne
etwas anzulegen, und nennen die Namen.

Die Forschungsdatenbank gibt es auch als **MySQL-Fassung** (Zugangsdaten `MYSQL_*`, die Datenbank wird bei Bedarf
angelegt). Sie lässt sich unabhängig davon nutzen, welche Datenbank SAFT selbst verwendet:

```bash
php artisan external-demo:install --driver=mysql
```

### MariaDB-Fassung (optional)

**MariaDB** als externe Quelle braucht keinen zusätzlichen PHP-Treiber (`pdo_mysql` genügt). Den Server betreibt man am
einfachsten als Container, auf Port 3307, falls lokal schon MySQL auf 3306 läuft:

```bash
read -rsp 'MariaDB-Root-Passwort: ' MARIADB_ROOT_PASSWORD; echo
docker run -d --name mariadb -e MARIADB_ROOT_PASSWORD="$MARIADB_ROOT_PASSWORD" \
  -p 127.0.0.1:3307:3306 -v mariadb-data:/var/lib/mysql --restart unless-stopped \
  mariadb:lts --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci
unset MARIADB_ROOT_PASSWORD
docker exec -it mariadb mariadb -u root -p
```

```sql
CREATE USER 'saft'@'%' IDENTIFIED BY '…';
CREATE DATABASE SAFT2_ext      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE SAFT2_ext_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE SAFT2_ext_dusk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `SAFT2_ext`.*      TO 'saft'@'%';
GRANT ALL PRIVILEGES ON `SAFT2_ext_test`.* TO 'saft'@'%';
GRANT ALL PRIVILEGES ON `SAFT2_ext_dusk`.* TO 'saft'@'%';
```

`'saft'@'%'` ist nötig, weil Verbindungen aus Sicht des Containers über das Docker-Netz kommen; erreichbar ist der
Server trotzdem nur über `127.0.0.1:3307`. Zugangsdaten als `MARIADB_HOST`, `MARIADB_PORT`, `MARIADB_DATABASE`,
`MARIADB_USERNAME` und `MARIADB_PASSWORD` in die `.env`, dann:

```bash
php artisan external-demo:install --driver=mariadb --name="Forschung (MariaDB)"
```

### SQL-Server-Fassung (optional)

Für **Microsoft SQL Server** als externe Quelle braucht PHP den Microsoft ODBC Driver 18 und die Erweiterung
`pdo_sqlsrv`. Unter Ubuntu 22.04/24.04 aus der Microsoft-Paketquelle der eigenen Version; für Ubuntu 26.04 gibt es
den Treiber dort noch nicht, das Paket für 24.04 funktioniert aber (niedrige Priorität, damit die Quelle keine
Ubuntu-Pakete ersetzt):

```bash
curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | sudo gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg
curl -fsSL https://packages.microsoft.com/config/ubuntu/24.04/prod.list | sudo tee /etc/apt/sources.list.d/microsoft-prod.list
printf 'Package: *\nPin: origin packages.microsoft.com\nPin-Priority: 100\n' | sudo tee /etc/apt/preferences.d/microsoft-prod
sudo apt update
sudo ACCEPT_EULA=Y apt install msodbcsql18 mssql-tools18 unixodbc-dev
sudo apt install php8.5-dev php-pear
sudo pecl install pdo_sqlsrv
printf '; priority=30\nextension=pdo_sqlsrv.so\n' | sudo tee /etc/php/8.5/mods-available/pdo_sqlsrv.ini
sudo phpenmod pdo_sqlsrv
```

Den Server selbst (Express genügt) betreibt man am einfachsten als Container; das Passwort fragt `read -s` verdeckt ab:

```bash
mkdir -p ~/mssql-data && sudo chown 10001:10001 ~/mssql-data
read -rsp 'SA-Passwort: ' MSSQL_SA_PASSWORD; echo
docker run -d --name mssql -e ACCEPT_EULA=Y -e MSSQL_PID=Express -e MSSQL_SA_PASSWORD="$MSSQL_SA_PASSWORD" \
  -p 127.0.0.1:1433:1433 -v ~/mssql-data:/var/opt/mssql --restart unless-stopped \
  mcr.microsoft.com/mssql/server:2025-latest
unset MSSQL_SA_PASSWORD
```

Dann einen eigenen Login und die Datenbanken anlegen (`-x` schaltet die Variablen von `sqlcmd` ab, wichtig bei
Passwörtern mit `$`):

```bash
docker exec -it mssql /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -C -x
```

```sql
CREATE LOGIN saft WITH PASSWORD = '…';
GO
CREATE DATABASE SAFT2_ext;
CREATE DATABASE SAFT2_ext_test;
CREATE DATABASE SAFT2_ext_dusk;
GO
USE SAFT2_ext;      CREATE USER saft FOR LOGIN saft; ALTER ROLE db_owner ADD MEMBER saft;
GO
USE SAFT2_ext_test; CREATE USER saft FOR LOGIN saft; ALTER ROLE db_owner ADD MEMBER saft;
GO
USE SAFT2_ext_dusk; CREATE USER saft FOR LOGIN saft; ALTER ROLE db_owner ADD MEMBER saft;
GO
```

Zugangsdaten als `MSSQL_HOST`, `MSSQL_PORT`, `MSSQL_DATABASE`, `MSSQL_USERNAME` und `MSSQL_PASSWORD` in die `.env`,
dann:

```bash
php artisan external-demo:install --driver=sqlsrv --name="Forschung (SQL Server)"
```

---

## 7. Tests einrichten (optional)

Die Tests laufen gegen **eigene Datenbanken**, nie gegen `SAFT2`. Legen Sie sie einmal an:

```bash
for db in SAFT2_test SAFT2_ext_test SAFT2_dusk SAFT2_ext_dusk; do
  sudo -u postgres createdb --owner=saft $db         # macOS: createdb --owner=saft $db
done
```

**Feature-Tests** (schnell, ohne Browser; Zugangsdaten aus `.env`, Datenbanknamen aus `phpunit.xml`):

```bash
composer test           # gegen PostgreSQL
composer test:mysql     # dieselben Tests gegen MySQL (phpunit.mysql.xml, Datenbank SAFT2_test auf dem MySQL-Server)
composer test:mariadb   # dieselben Tests mit SAFT auf MariaDB (phpunit.mariadb.xml, SAFT2_test und SAFT2_test_app_data auf dem MariaDB-Server, MARIADB_*)
```

Die Tests für MySQL als externe Quelle (`MySqlSourceTest`) laufen in beiden Fällen und nutzen die Datenbank
`SAFT2_ext_test` auf dem MySQL-Server; ist kein MySQL-Server erreichbar, werden sie übersprungen. Auf dem
MySQL-Server braucht der Testbenutzer Rechte auf `SAFT2_test`, `SAFT2_test_app_data` und `SAFT2_ext_test`.

Dieselben Tests laufen als `MariaDbSourceTest` gegen `SAFT2_ext_test` auf dem MariaDB-Server (`MARIADB_*`); ohne
erreichbaren Server werden sie übersprungen. Ebenso nutzen die Tests für SQL Server als externe Quelle (`SqlServerSourceTest`) die Datenbank `SAFT2_ext_test` auf
dem SQL Server (`MSSQL_*`); ohne `pdo_sqlsrv` oder erreichbaren Server werden sie übersprungen.

**Browser-Tests** (mit einem automatisch gesteuerten Chrome):

```bash
npm install                                                              # axe-core für die Kontrastprüfung
npx @puppeteer/browsers install chrome@stable --path ~/.cache/saft-dusk
npx @puppeteer/browsers install chromedriver@stable --path ~/.cache/saft-dusk
```

Kopieren Sie ausserdem die Vorlage `.env.browser.example` nach `.env.browser` und tragen Sie die Zugangsdaten ein
(Einzelheiten im [README](../../README.de.md#tests)). Dann: `composer test:browser` (dauert etwa eine halbe Stunde).
Mit `.env.browser-mysql` und `composer test:browser:mysql` laufen dieselben Tests mit SAFT auf MySQL, mit
`.env.browser-mariadb` und `composer test:browser:mariadb` auf MariaDB (Datenbanken `SAFT2_dusk` und
`SAFT2_dusk_app_data` auf dem MariaDB-Server, Vorlage `.env.browser-mariadb.example`). Die komplette
Suite ist für die Zeit unmittelbar vor einem Release gedacht; nach Änderungen genügt die betroffene Testklasse.

**Diagramm-Bibliothek:** Die Seite „Datenmodell“ nutzt Mermaid. Die Datei liegt fertig unter
`resources/js/vendor/mermaid.min.js` im Repository; `composer install` veröffentlicht sie nach `public/js/app`. Nur
zum Aktualisieren: Version in `package.json` ändern, `npm install` und `npm run vendor:mermaid`, danach
`php artisan filament:assets`.

---

## 8. Im Alltag

| Aufgabe | Befehl |
|---|---|
| Neuesten Stand holen | `git pull`, danach `composer install` und `php artisan migrate` |
| Server starten | `php artisan serve` |
| Datenbank sichern | `php artisan db:backup` (nach `storage/backups`; MySQL: Hauptdatenbank und `…_app_data` in einer `.sql`-Datei) |
| Zwischengespeicherte Konfiguration verwerfen | `php artisan optimize:clear` |
| Handbuch-Screenshots neu erzeugen | `composer manual:screenshots` |

Weitere Befehle stehen in der [Systemadministration, Kapitel 6](SYSADMIN_GUIDE.de.md#6-befehlsreferenz-php-artisan).

---

## 9. Wenn etwas nicht klappt

| Meldung / Beobachtung | Ursache und Lösung |
|---|---|
| `could not find driver` | Die PHP-Erweiterung `pdo_pgsql` bzw. `pdo_mysql` fehlt: `sudo apt install php-pgsql` bzw. `php-mysql` (Linux). |
| `Access denied for user` (MySQL) | Benutzer, Passwort oder Rechte stimmen nicht; der Benutzer braucht Rechte auf `SAFT2` **und** `SAFT2_app_data` (Schritt 3). |
| `db:backup` findet `mysqldump` nicht | Pfad in der `.env` angeben, z. B. bei MySQL als Snap: `MYSQL_DUMP_BINARY=/snap/bin/mysql.mysqldump` (für PostgreSQL: `PGSQL_DUMP_BINARY`). |
| `SQLSTATE[08006] … password authentication failed` | Benutzer oder Passwort in `.env` stimmen nicht mit Schritt 3 überein. |
| `permission denied to create schema` / `for database` | Der Datenbankbenutzer ist nicht Eigentümer der Datenbank: `sudo -u postgres psql -c "ALTER DATABASE \"SAFT2\" OWNER TO saft;"` |
| `Your requirements could not be resolved … php ^8.4` | PHP ist zu alt, siehe Schritt 2. |
| `The ext-intl extension is missing` (oder `zip`) | Erweiterung nachinstallieren: `sudo apt install php-intl php-zip`. |
| Anmeldeseite ohne Gestaltung | `php artisan filament:upgrade` ausführen (kopiert die Dateien nach `public/`). |
| Admin-Passwort unbekannt | Direkt setzen: `php artisan tinker --execute="App\Models\User::where('email', '<adresse>')->first()->update(['password' => '<neues passwort>']);"` (Ist unter Administration → Benutzer und Zugriff → Authentifizierung der Schalter *„Passwort vergessen“ anbieten* eingeschaltet, geht es auch über die Anmeldeseite; lokal steht die E-Mail in `storage/logs/laravel.log`.) |
| Nach einer Änderung an `.env` passiert nichts | `php artisan optimize:clear` und den Server neu starten. |
