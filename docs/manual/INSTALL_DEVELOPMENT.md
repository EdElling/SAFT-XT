# Appendix B – Installation on a development machine

**English** · [Deutsch](INSTALL_DEVELOPMENT.de.md) · [Back to the user manual](USER_GUIDE.md)

**Version:** 0.1 · **Audience:** developers and technically experienced administrators

This guide sets up SAFT on your own computer: for trying it out, developing and testing. For a server on the internet,
see [Appendix C – Installation on a production server](INSTALL_PRODUCTION.md).

---

## Contents

1. [What you need](#1-what-you-need)
2. [Install the programs](#2-install-the-programs)
3. [Create the database](#3-create-the-database)
4. [Download and set up SAFT](#4-download-and-set-up-saft)
5. [Start and sign in](#5-start-and-sign-in)
6. [Demo data (optional)](#6-demo-data-optional)
7. [Set up the tests (optional)](#7-set-up-the-tests-optional)
8. [Day to day](#8-day-to-day)
9. [If something goes wrong](#9-if-something-goes-wrong)

---

## 1. What you need

| Component | Version | Purpose |
|---|---|---|
| **PHP** | 8.4 or newer (developed with 8.5) | runs SAFT |
| PHP extensions | `pdo_pgsql` (or `pdo_mysql`), `intl`, `zip`, `mbstring`, `xml`, `curl`, `bcmath`, `openssl` | database, languages, Excel export, single sign-on |
| **Composer** | 2.x | downloads the PHP packages |
| **PostgreSQL** | 16 or newer (developed with 18) | stores all data (recommended) |
| *or* **MySQL** | 8.0.16 or newer (tested with 8.4) | alternative to PostgreSQL |
| **Git** | current | downloads the source code |
| Node.js | 20 or newer | **only** for the browser tests |

The recommended operating system is **Linux** (e.g. Ubuntu 26.04 LTS). On **Windows**, the easiest way is **WSL 2**
(Windows Subsystem for Linux), then follow the Linux steps. **macOS** works with Homebrew.

> **Tip:** You type all commands in this guide into a terminal. Lines starting with `#` are explanations and are not
> typed. Replace values in angle brackets such as `<password>` with your own.

---

## 2. Install the programs

### Linux (Ubuntu / Debian)

```bash
sudo apt update
sudo apt install -y git unzip php-cli php-pgsql php-intl php-zip php-mbstring php-xml php-curl php-bcmath \
                    postgresql postgresql-client
```

Check that the versions fit:

```bash
php -v          # must show 8.4 or higher
psql --version  # must show 16 or higher
```

> If `php -v` shows an older version (e.g. on Ubuntu 24.04), install PHP 8.4 from an additional package source of
> your distribution or use a newer Ubuntu release.

Install **Composer** following the instructions on [getcomposer.org](https://getcomposer.org/download/), or with
`sudo apt install -y composer` if your distribution ships Composer 2 (`composer --version`).

### Windows

1. In a command prompt with administrator rights: `wsl --install -d Ubuntu`
2. Restart the computer, open Ubuntu from the start menu and create a user.
3. Run the Linux steps above in this Ubuntu window. Afterwards keep the source code **in the Linux part**
   (e.g. `~/projects`), not under `C:\` – this is much faster.

### macOS

With [Homebrew](https://brew.sh):

```bash
brew install php composer postgresql@18 git
brew services start postgresql@18
```

The extensions `intl`, `pdo_pgsql` and `zip` are already included in Homebrew's PHP (`php -m` lists them).

### MySQL instead of PostgreSQL

SAFT also runs with **MySQL 8.0.16 or newer**. Instead of the PostgreSQL packages, install:

```bash
sudo apt install -y php-mysql mysql-server      # Linux; macOS: brew install mysql && brew services start mysql
```

Differences from PostgreSQL you should know:

- MySQL compares text without regard to upper and lower case: for unique fields, "A" and "a" count as duplicates.
- Text fields that are unique or sortable may be at most 768 characters long.
- A table cannot have any number of long text fields (MySQL's row limit); create longer texts as
  "Text (multi-line)".

### MariaDB instead of PostgreSQL

SAFT also runs with **MariaDB 10.11 or newer**; the same differences as with MySQL apply. PHP only needs `pdo_mysql`;
`db:backup` needs the program `mariadb-dump`:

```bash
sudo apt install -y php-mysql mariadb-server mariadb-client   # or MariaDB in a container, see section 6
```

Under MariaDB, unique fields of the table designer get an invisible helper column (`cf_<number>_uq_v`); it appears
in no list and no export.

---

## 3. Create the database

SAFT needs its own database and a database user who owns it. As owner, the user may create the schema `app_data`,
which holds the tables of the table designer.

**Linux / WSL:**

```bash
sudo -u postgres createuser --pwprompt saft   # asks for a password for the user "saft"
sudo -u postgres createdb --owner=saft SAFT2
```

**macOS** (Homebrew makes your Mac user the database administrator):

```bash
createuser --pwprompt saft
createdb --owner=saft SAFT2
```

Test the connection: `psql -h 127.0.0.1 -U saft SAFT2 -c "select version();"` – after entering the password, the
PostgreSQL version appears.

**MySQL:** MySQL has no schemas; the tables of the table designer live in a **second database** `SAFT2_app_data`,
which SAFT creates itself. The user therefore needs rights on both:

```bash
sudo mysql <<'SQL'
CREATE USER 'saft'@'localhost' IDENTIFIED BY '<password>';
CREATE DATABASE SAFT2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `SAFT2`.* TO 'saft'@'localhost';
GRANT ALL PRIVILEGES ON `SAFT2\_app\_data`.* TO 'saft'@'localhost';
SQL
```

**MariaDB:** as with MySQL, only with `sudo mariadb` (or in the container `docker exec -it mariadb mariadb -u root -p`).
Create `SAFT2_app_data` right away in case the user may not create databases:

```sql
CREATE USER 'saft'@'localhost' IDENTIFIED BY '<password>';     -- in the container: 'saft'@'%'
CREATE DATABASE SAFT2          CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE SAFT2_app_data CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `SAFT2`.*          TO 'saft'@'localhost';
GRANT ALL PRIVILEGES ON `SAFT2_app_data`.* TO 'saft'@'localhost';
```

---

## 4. Download and set up SAFT

```bash
mkdir -p ~/projects && cd ~/projects
git clone https://github.com/EdElling/SAFT-XT.git
cd SAFT-XT
composer install                 # downloads all packages, including those for tests (takes a few minutes)
cp .env.example .env             # create the configuration file
php artisan key:generate         # create the key for encryption and sessions
```

Now open the file `.env` in an editor. It contains one block each for PostgreSQL (`PGSQL_*`), MySQL (`MYSQL_*`) and MariaDB (`MARIADB_*`);
**`DB_CONNECTION`** selects which one applies. Above all, enter the database password and the details of the first
administrator:

```dotenv
APP_NAME=SAFT
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql              # or mysql or mariadb

PGSQL_HOST=127.0.0.1
PGSQL_PORT=5432
PGSQL_DATABASE=SAFT2
PGSQL_USERNAME=saft
PGSQL_PASSWORD=<password from step 3>

MYSQL_HOST=127.0.0.1
MYSQL_PORT=3306
MYSQL_DATABASE=SAFT2
MYSQL_USERNAME=saft
MYSQL_PASSWORD=<password from step 3>

ADMIN_NAME="<your name>"
ADMIN_EMAIL=<your@address.com>
ADMIN_PASSWORD=<at least 12 characters>   # leave empty = SAFT creates one and shows it once
```

> **Important:** `.env` contains passwords and never belongs in the repository or in an email. It is already listed
> in `.gitignore`.

> `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` take precedence over both blocks (this is how
> the tests set their own databases). Leave them out of `.env` if you want to switch between PostgreSQL and MySQL.

Then build the database and load the basic setup:

```bash
php artisan migrate      # creates all tables
php artisan db:seed      # roles, permissions, menu and the first administrator (ADMIN_*)
```

---

## 5. Start and sign in

```bash
php artisan serve
```

Open **http://localhost:8000** in the browser – you land on the sign-in page under `/admin`. Sign in with
`ADMIN_EMAIL` and `ADMIN_PASSWORD`. Stop the server in the terminal with `Ctrl+C`.

After signing in, have a look at **Administration → Application → App settings** (display name, colour scheme) and **Users**. The
[user manual](USER_GUIDE.md) describes how to continue.

---

## 6. Demo data (optional)

**Order example** (items, customers, orders, order lines) with master-detail and a rule:

```bash
php artisan db:seed --class=DemoDataSeeder
```

The group "Data" also gets the entry **"Data model"** with the diagram of the four tables.

**External research database** for database connections and the table connector (people, projects, publications,
organisations):

```bash
sudo -u postgres createdb --owner=saft SAFT2_ext   # macOS: createdb --owner=saft SAFT2_ext
php artisan external-demo:install
```

Afterwards the menu contains the group "Research database" with six linked tables (technical names `fdb_…`) and a
"Data model" entry. The internal research demo (`db:seed --class=ForschungDemoSeeder`, tables `forschung_…`) can
exist alongside. If a name is already used by another table, both stop without creating anything and list the names.

The research database also comes as a **MySQL version** (credentials `MYSQL_*`; the database is created if needed). It
can be used regardless of which database SAFT itself runs on:

```bash
php artisan external-demo:install --driver=mysql
```

### MariaDB version (optional)

**MariaDB** as an external source needs no additional PHP driver (`pdo_mysql` is enough). The server is easiest to run
as a container, on port 3307 if MySQL already runs locally on 3306:

```bash
read -rsp 'MariaDB root password: ' MARIADB_ROOT_PASSWORD; echo
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

`'saft'@'%'` is needed because connections reach the container through the Docker network; the server is still only
reachable via `127.0.0.1:3307`. Put the credentials as `MARIADB_HOST`, `MARIADB_PORT`, `MARIADB_DATABASE`,
`MARIADB_USERNAME` and `MARIADB_PASSWORD` into `.env`, then:

```bash
php artisan external-demo:install --driver=mariadb --name="Forschung (MariaDB)"
```

### SQL Server version (optional)

For **Microsoft SQL Server** as an external source, PHP needs the Microsoft ODBC Driver 18 and the extension
`pdo_sqlsrv`. On Ubuntu 22.04/24.04 from the Microsoft package source of your version; for Ubuntu 26.04 the driver is
not available there yet, but the package for 24.04 works (low priority, so that the source does not replace Ubuntu
packages):

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

The server itself (Express is enough) is easiest to run as a container; `read -s` asks for the password without
showing it:

```bash
mkdir -p ~/mssql-data && sudo chown 10001:10001 ~/mssql-data
read -rsp 'SA password: ' MSSQL_SA_PASSWORD; echo
docker run -d --name mssql -e ACCEPT_EULA=Y -e MSSQL_PID=Express -e MSSQL_SA_PASSWORD="$MSSQL_SA_PASSWORD" \
  -p 127.0.0.1:1433:1433 -v ~/mssql-data:/var/opt/mssql --restart unless-stopped \
  mcr.microsoft.com/mssql/server:2025-latest
unset MSSQL_SA_PASSWORD
```

Then create a login of your own and the databases (`-x` switches off the variables of `sqlcmd`, important for
passwords containing `$`):

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

Put the credentials as `MSSQL_HOST`, `MSSQL_PORT`, `MSSQL_DATABASE`, `MSSQL_USERNAME` and `MSSQL_PASSWORD` into
`.env`, then:

```bash
php artisan external-demo:install --driver=sqlsrv --name="Forschung (SQL Server)"
```

---

## 7. Set up the tests (optional)

The tests run against **their own databases**, never against `SAFT2`. Create them once:

```bash
for db in SAFT2_test SAFT2_ext_test SAFT2_dusk SAFT2_ext_dusk; do
  sudo -u postgres createdb --owner=saft $db         # macOS: createdb --owner=saft $db
done
```

**Feature tests** (fast, without a browser; credentials from `.env`, database names from `phpunit.xml`):

```bash
composer test           # against PostgreSQL
composer test:mysql     # the same tests against MySQL (phpunit.mysql.xml, database SAFT2_test on the MySQL server)
composer test:mariadb   # the same tests with SAFT on MariaDB (phpunit.mariadb.xml, SAFT2_test and SAFT2_test_app_data on the MariaDB server, MARIADB_*)
```

The tests for MySQL as an external source (`MySqlSourceTest`) run in both cases and use the database
`SAFT2_ext_test` on the MySQL server; if no MySQL server is reachable, they are skipped. On the MySQL server, the test
user needs rights on `SAFT2_test`, `SAFT2_test_app_data` and `SAFT2_ext_test`.

The same tests run as `MariaDbSourceTest` against `SAFT2_ext_test` on the MariaDB server (`MARIADB_*`); without a
reachable server they are skipped. Likewise, the tests for SQL Server as an external source (`SqlServerSourceTest`) use the database `SAFT2_ext_test` on
the SQL Server (`MSSQL_*`); without `pdo_sqlsrv` or a reachable server they are skipped.

**Browser tests** (with an automatically controlled Chrome):

```bash
npm install                                                              # axe-core for the contrast check
npx @puppeteer/browsers install chrome@stable --path ~/.cache/saft-dusk
npx @puppeteer/browsers install chromedriver@stable --path ~/.cache/saft-dusk
```

Also copy the template `.env.browser.example` to `.env.browser` and enter the credentials (details in the
[README](../../README.md#tests)). Then: `composer test:browser` (takes about half an hour). With `.env.browser-mysql`
and `composer test:browser:mysql` the same tests run with SAFT on MySQL, with `.env.browser-mariadb` and
`composer test:browser:mariadb` on MariaDB (databases `SAFT2_dusk` and `SAFT2_dusk_app_data` on the MariaDB server,
template `.env.browser-mariadb.example`). The complete suite is meant for the time
immediately before a release; after changes, the affected test class is enough.

**Diagram library:** The "Data model" page uses Mermaid. The file is part of the repository at
`resources/js/vendor/mermaid.min.js`; `composer install` publishes it to `public/js/app`. Only for updating: change
the version in `package.json`, run `npm install` and `npm run vendor:mermaid`, then `php artisan filament:assets`.

---

## 8. Day to day

| Task | Command |
|---|---|
| Get the latest version | `git pull`, then `composer install` and `php artisan migrate` |
| Start the server | `php artisan serve` |
| Back up the database | `php artisan db:backup` (to `storage/backups`; MySQL: main database and `…_app_data` in one `.sql` file) |
| Discard cached configuration | `php artisan optimize:clear` |
| Regenerate the manual screenshots | `composer manual:screenshots` |

More commands are listed in [System administration, chapter 6](SYSADMIN_GUIDE.md#6-command-reference-php-artisan).

---

## 9. If something goes wrong

| Message / observation | Cause and solution |
|---|---|
| `could not find driver` | The PHP extension `pdo_pgsql` or `pdo_mysql` is missing: `sudo apt install php-pgsql` or `php-mysql` (Linux). |
| `Access denied for user` (MySQL) | User, password or rights do not match; the user needs rights on `SAFT2` **and** `SAFT2_app_data` (step 3). |
| `db:backup` does not find `mysqldump` | Set the path in `.env`, e.g. for MySQL as a snap: `MYSQL_DUMP_BINARY=/snap/bin/mysql.mysqldump` (for PostgreSQL: `PGSQL_DUMP_BINARY`). |
| `SQLSTATE[08006] … password authentication failed` | User or password in `.env` do not match step 3. |
| `permission denied to create schema` / `for database` | The database user does not own the database: `sudo -u postgres psql -c "ALTER DATABASE \"SAFT2\" OWNER TO saft;"` |
| `Your requirements could not be resolved … php ^8.4` | PHP is too old, see step 2. |
| `The ext-intl extension is missing` (or `zip`) | Install the extension: `sudo apt install php-intl php-zip`. |
| Sign-in page without styling | Run `php artisan filament:upgrade` (copies the files to `public/`). |
| Admin password unknown | Set it directly: `php artisan tinker --execute="App\Models\User::where('email', '<address>')->first()->update(['password' => '<new password>']);"` (If the switch *Offer “Forgot password”* is on under Administration → Users and access → Authentication, the sign-in page works too; locally the email is written to `storage/logs/laravel.log`.) |
| Nothing happens after changing `.env` | Run `php artisan optimize:clear` and restart the server. |
