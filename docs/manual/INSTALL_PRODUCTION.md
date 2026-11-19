# Appendix C – Installation on a production server

**English** · [Deutsch](INSTALL_PRODUCTION.de.md) · [Back to the user manual](USER_GUIDE.md)

**Version:** 0.1 · **Audience:** administrators who provide SAFT for others

This guide puts SAFT on a server that can be reached from the internet or the company network – for example an
**externally hosted managed server** or your own Linux server. For trying it out on your own computer, see
[Appendix B – Installation on a development machine](INSTALL_DEVELOPMENT.md).

---

## Contents

1. [What the server must offer](#1-what-the-server-must-offer)
2. [Preparation at the hosting provider](#2-preparation-at-the-hosting-provider)
3. [Bring SAFT to the server](#3-bring-saft-to-the-server)
4. [Configuration (.env)](#4-configuration-env)
5. [Set up and optimise the database](#5-set-up-and-optimise-the-database)
6. [Point the web server to SAFT](#6-point-the-web-server-to-saft)
7. [First sign-in and basic settings](#7-first-sign-in-and-basic-settings)
8. [Backups](#8-backups)
9. [Installing updates](#9-installing-updates)
10. [Security checklist](#10-security-checklist)
11. [If something goes wrong](#11-if-something-goes-wrong)
12. [Example: webspace at Strato](#12-example-webspace-at-strato)

---

## 1. What the server must offer

Clarify these points **before** ordering or with your hosting provider. SAFT needs PostgreSQL (recommended), MySQL or
MariaDB – and with MySQL or MariaDB a second database; this is the most common stumbling block.

| Requirement | Details |
|---|---|
| **PHP 8.4 or newer** | for the website **and** on the command line (SSH), with the extensions `pdo_pgsql` or `pdo_mysql`, `intl`, `zip`, `mbstring`, `xml`, `curl`, `bcmath`, `openssl`; only for SQL Server as an external source also `pdo_sqlsrv` with the Microsoft ODBC Driver 18 |
| **PostgreSQL 16 or newer** | a database of its own; the user must be allowed to create a schema in it (easiest: the user owns the database). A database at the same provider or a separate database service both work. |
| *or* **MySQL 8.0.16 or newer** | **two** databases with the same user (or one each, see step 2): one for SAFT and one for the tables of the table designer (default name `<database>_app_data`, otherwise set `APP_DATA_SCHEMA`). |
| *or* **MariaDB 10.11 or newer** | as with MySQL: **two** databases with the same user. A Galera cluster works as well (all tables of SAFT have a primary key and use InnoDB); structure changes in the table designer run cluster-wide and lock briefly, so do larger changes outside peak hours. |
| **SSH access** | to run commands (`composer`, `php artisan`); FTP access alone is not enough |
| **Composer 2** | preinstalled on the server or installable in your home directory |
| **Git** | recommended, to fetch and update the source code |
| **Selectable web directory** | the domain must be able to point to SAFT's `public` folder (*document root*) |
| **HTTPS certificate** | e.g. Let's Encrypt, usually one click in the customer portal |
| **Cron jobs** | for automatic backups (recommended) |
| **Email sending (SMTP)** | for notifications and "Forgot password" |
| `pg_dump`, `mysqldump` or `mariadb-dump` | for `php artisan db:backup`; if it is missing, use the provider's database backup |

**Resources:** For a few dozen users, 2 GB of memory and a few GB of disk space are enough. SAFT needs **no**
background processes (queue workers) and no Node.js on the server.

---

## 2. Preparation at the hosting provider

In your provider's customer portal (the names differ between providers):

1. Set the **PHP version** of the website to 8.4 or newer; check that the command line uses the same version.
2. **Create a PostgreSQL database**, e.g. `saft`, with its own user and a strong password. Note host, port
   (usually 5432), database name, user and password. **With MySQL or MariaDB**, create two databases for the same user, e.g.
   `saft` and `saft_app_data` (port usually 3306). If the provider prefixes names (e.g. `customer42_saft_app_data`),
   enter the name of the second one as `APP_DATA_SCHEMA` in step 4.

   **Providers with one user and server per database** (e.g. Strato): there every database gets its own name, user and
   server assigned by the provider (e.g. `database-5011111111.webspace-host.com`). Create two databases and enter the
   second one in step 4 with its own credentials – SAFT connects to both separately and does not create a database
   itself:

   ```dotenv
   DB_CONNECTION=mysql
   MYSQL_HOST=database-5011111111.webspace-host.com
   MYSQL_DATABASE=dbs11111111
   MYSQL_USERNAME=dbu11111111
   MYSQL_PASSWORD=<password of the first database>

   APP_DATA_SCHEMA=dbs22222222
   DB_APP_DATA_HOST=database-5022222222.webspace-host.com
   DB_APP_DATA_USERNAME=dbu22222222
   DB_APP_DATA_PASSWORD=<password of the second database>
   ```
3. Set up a **domain or subdomain**, e.g. `saft.example.com`, and activate an **HTTPS certificate**.
4. Set up **SSH access** (preferably with an SSH key instead of a password).

On **your own Linux server** (with administrator rights), install the packages as in
[Appendix B, step 2](INSTALL_DEVELOPMENT.md#2-install-the-programs) plus `nginx` and `php-fpm`, and create the
database and user as in [Appendix B, step 3](INSTALL_DEVELOPMENT.md#3-create-the-database).

---

## 3. Bring SAFT to the server

Sign in via SSH and fetch the desired version (here v0.3.0) into your home directory:

```bash
cd ~
git clone https://github.com/EdElling/SAFT-XT.git saft
cd saft
git checkout v0.3.0                                   # a fixed version instead of the development state
composer install --no-dev --optimize-autoloader      # without development packages – important for security
```

> **Why `--no-dev`?** With the development packages there would be a test address through which anyone could sign
> in without a password. Without them this address does not exist. Demo data and tests are then not available –
> which is intended on a production server.

If the repository is not public, set up a **deploy key** (read access) for the server on GitHub. Without Git, download
the release archive ("Source code") from the [releases page](https://github.com/EdElling/SAFT-XT/releases), upload it
via SFTP and unpack it on the server; then continue with `composer install …`.

---

## 4. Configuration (.env)

```bash
cp .env.example .env
php artisan key:generate        # once only! A new key makes stored passwords of database connections unreadable.
nano .env                       # or another editor
```

Set or add these values (replace the placeholders):

```dotenv
APP_NAME=SAFT
APP_ENV=production
APP_DEBUG=false
APP_URL=https://saft.example.com

APP_TIMEZONE=Europe/Zurich
APP_LOCALE=en

LOG_LEVEL=warning

DB_CONNECTION=pgsql             # or mysql or mariadb
PGSQL_HOST=<database host>
PGSQL_PORT=5432
PGSQL_DATABASE=<database name>
PGSQL_USERNAME=<database user>
PGSQL_PASSWORD=<database password>
DB_SSLMODE=prefer               # require if the database runs on another machine
# With MySQL instead MYSQL_HOST, MYSQL_PORT (3306), MYSQL_DATABASE, MYSQL_USERNAME, MYSQL_PASSWORD,
# with MariaDB MARIADB_HOST, MARIADB_PORT, MARIADB_DATABASE, MARIADB_USERNAME, MARIADB_PASSWORD
# and, if the provider names the second database differently: APP_DATA_SCHEMA=<name of the second database>
# – if it has its own server or user: DB_APP_DATA_HOST, DB_APP_DATA_PORT, DB_APP_DATA_USERNAME, DB_APP_DATA_PASSWORD

SESSION_SECURE_COOKIE=true      # session cookie over HTTPS only
TRUSTED_PROXIES=                # only with the provider's load balancer, see below

MAIL_MAILER=smtp
MAIL_HOST=<provider's SMTP server>
MAIL_PORT=587
MAIL_USERNAME=<SMTP user>
MAIL_PASSWORD=<SMTP password>
MAIL_FROM_ADDRESS=saft@example.com

ADMIN_NAME="<name of the first administrator>"
ADMIN_EMAIL=<address@example.com>
ADMIN_PASSWORD=                 # leave empty: SAFT creates a password and shows it once (else at least 12 characters)
```

**Load balancer / reverse proxy:** If HTTPS ends at the provider before your server (ask if unsure), enter the
addresses of the proxy in `TRUSTED_PROXIES`, e.g. `10.0.0.0/8` (several separated by commas). Only then does SAFT
create links with `https://` and record the users' real IP addresses. `*` trusts any directly calling machine – this
is only safe if your server can be reached exclusively through the proxy. Without a proxy, leave the value empty.

Then protect the file from other users of the server:

```bash
chmod 600 .env
```

> **Important:** Keep a copy of `.env` (especially `APP_KEY`) in a safe place, e.g. a password manager. Without this
> key, encrypted values cannot be read after a restore.

---

## 5. Set up and optimise the database

```bash
php artisan migrate --force      # creates all tables ("--force" confirms running in production)
php artisan db:seed --force      # roles, permissions, menu and the first administrator from ADMIN_*
```

If `ADMIN_PASSWORD` is empty, a line *"Admin-Passwort für … (generiert, bitte notieren)"* (generated admin password)
appears now – note the password immediately.
On first sign-in, SAFT takes the admin account to the setup of **two-factor authentication** – have a phone with an
authenticator app ready and keep the recovery codes in a safe place
([Administrator guide, chapter 5](ADMIN_GUIDE.md#5-authentication)).

Finally build the caches for fast response times:

```bash
php artisan optimize             # configuration, routes, views
php artisan filament:optimize    # components and icons of the interface
```

> Run both commands again after **every** change to `.env` – otherwise the old configuration stays in effect.

The folders `storage` and `bootstrap/cache` must be writable for the web server. On managed servers the web server
usually runs as your own user, so this already fits. On your own server:

```bash
sudo chown -R $USER:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

---

## 6. Point the web server to SAFT

The domain must point to the **`public`** folder – **never** to the SAFT folder itself, otherwise `.env` and the
source code could be read from the internet.

**Managed server:** In the customer portal, set the web directory (*document root*) of the domain to
`/home/<user>/saft/public` (exact path: `pwd` in the SAFT folder, then append `/public`). The supplied file
`public/.htaccess` does the rest on Apache.

If the provider does not let you change the web directory, replace the given directory with a link (example; check
the names at your provider):

```bash
mv ~/www ~/www.old && ln -s ~/saft/public ~/www
```

**Own server with nginx** – example `/etc/nginx/sites-available/saft` (HTTPS certificate e.g. with Certbot):

```nginx
server {
    listen 443 ssl;
    server_name saft.example.com;
    root /var/www/saft/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/saft.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/saft.example.com/privkey.pem;

    client_max_body_size 20M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;   # adjust to the installed PHP version
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}

server {
    listen 80;
    server_name saft.example.com;
    return 301 https://$host$request_uri;
}
```

Check in the browser: **https://saft.example.com** redirects to `/admin` and shows the sign-in page.

---

## 7. First sign-in and basic settings

1. Sign in with `ADMIN_EMAIL` and the password.
2. **Administration → Application → Global settings:** set the display name and colour scheme
   ([Administrator guide, chapter 7](ADMIN_GUIDE.md#7-global-settings)).
3. **Administration → Users and access → Authentication:** choose the sign-in mode, switch on *Offer “Forgot password”* if needed
   ([Administrator guide, chapter 5](ADMIN_GUIDE.md#5-authentication)).
4. **Single sign-on** (optional): create an identity provider. The address you enter at the provider (e.g. Microsoft
   Entra ID) is `https://saft.example.com/auth/sso/<short name>/callback`, for SAML
   `https://saft.example.com/auth/saml/<short name>/acs` ([Administrator guide, chapter 6](ADMIN_GUIDE.md#6-identity-providers-single-sign-on)).
5. Create **users and roles** ([Administrator guide, chapter 1](ADMIN_GUIDE.md#1-users) and
   [Administrator guide, chapter 2](ADMIN_GUIDE.md#2-roles-and-permissions)).
6. Create tables with the **table designer** or link external databases via **database connections** and the
   **table connector** ([Administrator guide, chapter 4](ADMIN_GUIDE.md#4-table-designer),
   [Administrator guide, chapter 9](ADMIN_GUIDE.md#9-database-connections), [Administrator guide, chapter 10](ADMIN_GUIDE.md#10-table-connector)).
7. Set up the **scheduler** for [scheduled tasks](ADMIN_GUIDE.md#12-scheduled-tasks) – one cron entry that runs every
   minute (in the customer portal or with `crontab -e`):

   ```cron
   * * * * * cd /home/<user>/saft && php artisan schedule:run >> /dev/null 2>&1
   ```

   Step by step and for hosts with coarser intervals: [system administration](SYSADMIN_GUIDE.md#4-scheduled-tasks-and-cron).

> **Emergency exit:** If a wrong sign-in setting locks everyone out, set `AUTH_FORCE_LOCAL=true` in `.env` and run
> `php artisan optimize` – local sign-in is possible again afterwards.

---

## 8. Backups

**Daily and automatic** through the scheduled system task **Back up database** (02:30) – the cron entry for the
scheduler from step 7 is all it needs. Each backup is a file `<database>_<date>_<time>.dump` (or `.sql`) in
`storage/backups`, readable only by your user. If it fails, the reason is shown under *Scheduled tasks → Runs*, and
the super admins get a notification in the bell. **Clean up backups** (02:45) deletes backups older than 14 days and
always keeps the 3 newest. Change times and periods under *Administration › Operations › Scheduled tasks*
([Administrator guide, chapter 12](ADMIN_GUIDE.md#12-scheduled-tasks)). Manually at any time: `php artisan db:backup`.

- `db:backup` needs `pg_dump`, `mysqldump` or `mariadb-dump` (package `mariadb-client`) in a version that matches the
  database server. If the program is not in the search path, enter it in `.env` (`PGSQL_DUMP_BINARY`,
  `MYSQL_DUMP_BINARY` or `MARIADB_DUMP_BINARY`). If it is missing altogether or too old (e.g. `mysqldump` 5.6 at Strato,
  message `Error in field count`), SAFT also backs up MySQL and MariaDB **in PHP**: `DB_BACKUP_METHOD=php` in `.env` (or
  once `php artisan db:backup --php`). The file is also an SQL script and can be restored with `mysql`, `mariadb` or in
  phpMyAdmin. With MySQL and MariaDB, the `.sql` file contains both databases. A backup from MySQL cannot reliably be
  restored into MariaDB and vice versa.
- The backups are on the same server – copy them regularly to **another place**.
- Also back up the file `.env` (see step 4).
- **Restore** into a new, empty database:
  `pg_restore -h <host> -U <user> --no-owner -d <new_database> storage/backups/<file>.dump`
  – MySQL: `mysql -h <host> -u <user> -p < storage/backups/<file>.sql` (MariaDB: `mariadb …`) restores both databases under their
  **original names** and overwrites existing tables there; for other names, first adjust the `CREATE DATABASE` and
  `USE` lines in the file. If the two databases have their own users or servers (e.g. Strato), the file consists of
  two parts, each starting with `-- MySQL dump` or `-- MariaDB dump`; restore each part with the credentials of its
  database.

If you use linked external tables, you can additionally check weekly whether their structure has changed:

```cron
0 6 * * 1 cd /home/<user>/saft && php artisan table-connector:verify >> storage/logs/verify.log 2>&1
```

---

## 9. Installing updates

First read the release notes of the new version. Then:

```bash
cd ~/saft
php artisan db:backup                                   # 1. backup
php artisan down                                        # 2. maintenance mode: users see a notice
git fetch --tags && git checkout v<new-version>         # 3. fetch the new version
composer install --no-dev --optimize-autoloader         # 4. update the packages
php artisan migrate --force                             # 5. adjust the database
php artisan optimize && php artisan filament:optimize   # 6. rebuild the caches
php artisan up                                          # 7. end maintenance mode
```

If something goes wrong: `git checkout v<old-version>`, `composer install --no-dev --optimize-autoloader`, restore the
backup into a new database and point `PGSQL_DATABASE` or `MYSQL_DATABASE` in `.env` to it.

---

## 10. Security checklist

- [ ] `APP_ENV=production` and `APP_DEBUG=false`
- [ ] Installed with `composer install --no-dev`
- [ ] The domain points to `…/saft/public`, not to the SAFT folder
- [ ] HTTPS active, `APP_URL` starts with `https://`, `SESSION_SECURE_COOKIE=true`, HTTP redirects to HTTPS (on
  webspaces usually a setting at the provider, e.g. “force SSL”)
- [ ] `TRUSTED_PROXIES` empty or only with the addresses of the provider's proxy (`*` only if the server can only be reached through the proxy)
- [ ] `.env` with `chmod 600`, a copy of `.env` kept safe
- [ ] First admin password changed after signing in or stored safely
- [ ] Two-factor authentication set up for the admin account, recovery codes stored safely
- [ ] Daily backup set up and a restore tried once
- [ ] Database connections to external databases use users with as few rights as possible (read only where nothing
      is written)

---

## 11. If something goes wrong

| Observation | Cause and solution |
|---|---|
| White page or "500 Server Error" | The cause is in `storage/logs/laravel.log`. Common: folders not writable (step 5) or `APP_KEY` missing. |
| `could not find driver` | PHP extension `pdo_pgsql` or `pdo_mysql` is missing – ask the provider to enable it. |
| `Access denied for user` (MySQL) | The user has no rights on the second database (`…_app_data`) – assign them in the customer portal or correct the name in `APP_DATA_SCHEMA`. If the second database has its own user (e.g. Strato), enter its credentials in `DB_APP_DATA_HOST`, `DB_APP_DATA_USERNAME` and `DB_APP_DATA_PASSWORD`. |
| The command line reports an old PHP version | The command line uses another PHP version; change it at the provider or use the matching command (often `php8.4`). |
| `permission denied to create schema` | The database user may not create a schema in the database – the provider must make the user the owner or grant `CREATE`. |
| Sign-in page without styling | Run `php artisan filament:upgrade`; check that the domain points to `public`. |
| Changes in `.env` have no effect | Run `php artisan optimize` (and `filament:optimize`) again. |
| Links or redirects show `http://` instead of `https://`, the activity log always shows the same IP address | HTTPS ends before the server (load balancer): set `TRUSTED_PROXIES` (step 4), then `php artisan optimize`. |
| After switching back to http, the browser still insists on HTTPS | HSTS: after one visit over HTTPS, the browser remembers this for a year. Keep HTTPS; only if there is really no other way, set `SECURITY_HSTS=false` and clear the site data in the browser ([System administration guide, chapter 3](SYSADMIN_GUIDE.md#3-updates-and-security)). |
| "Page expired" (419) after signing in | `SESSION_SECURE_COOKIE=true` without HTTPS, or `APP_URL` does not match the address used. |
| Emails do not arrive | Check the SMTP settings; `MAIL_FROM_ADDRESS` must be an address of your own domain. |
| `db:backup` reports "Sicherung fehlgeschlagen" (backup failed) | The message behind it names the reason: program missing (path in `…_DUMP_BINARY`), older than the database server (`server version mismatch`, `Error in field count`) or may not connect. With MySQL and MariaDB, `DB_BACKUP_METHOD=php` helps, otherwise the provider's backup. |

---

## 12. Example: webspace at Strato

Tried with a Strato webspace (MariaDB 11.8, PHP 8.4). Names in the customer portal may change.

**Specifics at Strato:**

| Topic | Solution |
|---|---|
| `php` on the command line is the CGI version (output like `Status:`, `Content-type:`) | use the command-line PHP: `alias php='/opt/RZphp84/bin/php-cli'`, permanently in `~/.bashrc` |
| no `composer` | download `composer.phar` into the SAFT folder (see below) and call it as `php composer.phar …` |
| every database has its own name, user and server | create two databases, enter the second one with `APP_DATA_SCHEMA` and `DB_APP_DATA_*` (step 2) |
| `mysqldump` 5.6 is too old, `mariadb-dump` is missing | `DB_BACKUP_METHOD=php` (step 8) |
| no `which` | `command -v <program>` |
| HTTPS | activate the certificate for the (sub)domain and switch on **force SSL** under *Security* |

**Procedure** (via SSH; at Strato the home directory already is `htdocs`):

```bash
alias php='/opt/RZphp84/bin/php-cli'
git clone https://github.com/EdElling/SAFT-XT.git saft
cd saft
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php && rm composer-setup.php
php composer.phar install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
```

In `.env` (values from the customer portal, port 3306):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://saft.example.com
SESSION_SECURE_COOKIE=true

DB_CONNECTION=mariadb
MARIADB_HOST=database-5011111111.webspace-host.com
MARIADB_DATABASE=dbs11111111
MARIADB_USERNAME=dbu1111111
MARIADB_PASSWORD=<password of the first database>

APP_DATA_SCHEMA=dbs22222222
DB_APP_DATA_HOST=database-5022222222.webspace-host.com
DB_APP_DATA_USERNAME=dbu2222222
DB_APP_DATA_PASSWORD=<password of the second database>

DB_BACKUP_METHOD=php
```

Then continue with step 5 (`migrate`, `db:seed`, `optimize`). In the customer portal set the target directory of the
domain to `…/saft/public`, activate the certificate and switch on **force SSL**.

**Test or training system with the examples:** for the warehouse demo and the example plugins, install the dependencies
**with** the development packages (`php composer.phar install --optimize-autoloader`, without `--no-dev`) and then run
`php artisan db:seed --class=LagerDemoSeeder --force`. `APP_ENV=production` must stay – only then is the test sign-in
of the development packages switched off. For a production system `--no-dev` remains right.

**Scheduled tasks and backup:** in the customer portal create a cron job that runs this command every minute (or at
the shortest interval offered):

```bash
cd ~/saft && /opt/RZphp84/bin/php-cli artisan schedule:run
```

This also runs the daily backup and the clean-up of backups. For coarser intervals see
[System administration, chapter 4](SYSADMIN_GUIDE.md#4-scheduled-tasks-and-cron).

**Reset a public demo every night:** `DEMO_RESET=true` in `.env`, then `php artisan optimize`, and switch on the task
**Reset app data** under *Administration › Operations › Scheduled tasks*. At 04:00 it reloads the data of the chosen demo apps;
visitors' changes to the demo data are gone afterwards, everything else stays.

**Updates** (procedure as in [section 9](#9-installing-updates), with the command-line PHP):

```bash
alias php='/opt/RZphp84/bin/php-cli'   # if not in ~/.bashrc
cd ~/saft
php artisan down
php artisan db:backup
git pull
php composer.phar install --optimize-autoloader   # demo with example plugins: without --no-dev
php artisan migrate --force
php artisan optimize && php artisan filament:optimize
php artisan up
```

The warehouse demo gets new menus (e.g. the submenus *Warehouse operations*, *Master data*, *Reports*) when its seeder
runs again after the update: `php artisan db:seed --class=LagerDemoSeeder --force` – existing data stays, and so do
menu entries you moved yourself.

If a demo with example plugins was once updated with `--no-dev`, the plugins are missing afterwards. Then do **not**
click **Clean up** on the *Plugins* page – that deletes their menu entries and permissions, and the roles lose the
assignment. Instead run `php composer.phar install` without `--no-dev`, then `optimize` and `filament:optimize`.
