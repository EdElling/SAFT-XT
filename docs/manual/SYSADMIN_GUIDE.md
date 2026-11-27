# SAFT XT – System Administration

**English** · [Deutsch](SYSADMIN_GUIDE.de.md)

**Version:** 0.1 · **Audience:** operation and installation (server, database, updates)

**Other guides:** [User guide](USER_GUIDE.md) · [Administrator guide](ADMIN_GUIDE.md) · [Frequently asked questions](FAQ.md)

---

## Contents

- [1. Operations](#1-operations)
- [2. Online help and version](#2-online-help-and-version)
- [3. Updates and security](#3-updates-and-security)
- [4. Scheduled tasks and cron](#4-scheduled-tasks-and-cron)
- [5. AI functions](#5-ai-functions)
- [6. Command reference (php artisan)](#6-command-reference-php-artisan)
   - [6.1 SAFT's own commands](#61-safts-own-commands)
   - [6.2 Basic setup and demo data (seeders)](#62-basic-setup-and-demo-data-seeders)
   - [6.3 Laravel commands SAFT needs](#63-laravel-commands-saft-needs)
   - [6.4 Commands during operation](#64-commands-during-operation)
- [7. Plugins](#7-plugins)
- [Appendix A – Updating the screenshots](#appendix-a--updating-the-screenshots)
- [Further guides](#further-guides)

---
## 1. Operations

Installing SAFT step by step is described in [Appendix B](INSTALL_DEVELOPMENT.md) (development machine) and
[Appendix C](INSTALL_PRODUCTION.md) (production server, e.g. a managed server).

| Task | Command / setting |
|---|---|
| Force local sign-in (emergency exit, e.g. if the IdP fails) | `AUTH_FORCE_LOCAL=true` in `.env` |
| Set the sign-in mode from the console | `php artisan auth:mode local` (or `sso`, `hybrid`) |
| Reset the two-factor authentication of an account (e.g. the only admin lost their phone) | `php artisan auth:two-factor-reset <email>` |
| Compare the table designer settings with the database | `php artisan app-data:verify` |
| Compare the linked tables with the schema of their external databases (exit code 1 for differences; `--apply` applies them) | `php artisan table-connector:verify` |
| Bulk link on the console: all tables not linked yet (`--all`) or single ones (`--table=…`), with `--app=<ID>` (required if there are several apps), `--prefix=`, `--menu-group=<ID>` or `none`, `--role=<name>`, `--export`, `--create`, `--update`, `--delete`, `--inactive`, `--schema=`, `--no-details` | `php artisan table-connector:connect "<connection>" --all` |
| Choose the database: PostgreSQL, MySQL or MariaDB, with the credentials `PGSQL_*`, `MYSQL_*` or `MARIADB_*` | `DB_CONNECTION=pgsql`, `mysql` or `mariadb` in `.env` |
| Back up the database (to `storage/backups`; MySQL/MariaDB: main database and `…_app_data` in one `.sql` file, in two parts if `app_data` has its own credentials (`DB_APP_DATA_*`); without a suitable `mysqldump` in PHP with `DB_BACKUP_METHOD=php` or `--php`; path to the program if needed in `PGSQL_DUMP_BINARY`, `MYSQL_DUMP_BINARY` or `MARIADB_DUMP_BINARY`; the files from file fields and the letterhead alongside as `<same name>_files.zip`, without them with `--without-files`) | `php artisan db:backup` |
| Recalculate computed fields (after changes outside SAFT; optionally table names) | `php artisan computed:recompute` |
| Change the maximum number of records per export (default 500,000, `0` = no limit; the export runs as a job in blocks of `APP_DATA_EXPORT_CHUNK` rows, default 1000) | `APP_DATA_EXPORT_MAX_ROWS=500000` in `.env` |
| Change the maximum number of rows per data import into an existing table (default 100,000, `0` = no limit); up to `APP_DATA_IMPORT_CHECK_ROWS` rows (default 2000) the wizard checks the file in the dialog already, above that the job does | `APP_DATA_IMPORT_MAX_RECORD_ROWS=100000` in `.env` |
| Change the maximum number of records per copy including child records copied along (default 1000) | `APP_DATA_COPY_MAX_RECORDS=1000` in `.env` |
| Change the maximum number of records per bulk edit, bulk delete and bulk restore (default 5000, `0` = no limit; the bulk edit runs as a job in blocks of 100 records) | `APP_DATA_BULK_MAX=5000` in `.env` |
| Mail server for all emails (password reset, documents): in the global settings (“Email sending”, password encrypted, “Check connection”) or in `.env`; a server in the settings takes precedence over `MAIL_MAILER`. The sender address must suit the server (account address or SPF/DKIM of the domain) | Administrator guide, chapter 7, or `MAIL_*` in `.env` |
| Set a limit per batch “Create documents” (default `0` = no limit; the batch is a job in steps). A single PDF with all documents merges all pages in memory at the end (`memory_limit`), a ZIP does not | `DOCUMENTS_BATCH_MAX=0` in `.env` |
| Change the maximum number of rows per snapshot of a linked table (default 1,000,000, `0` = no limit; taken as a job in blocks of `REPORTS_SNAPSHOT_CHUNK` rows, default 1000) | `REPORTS_SNAPSHOT_MAX_ROWS=1000000` in `.env` |
| Storage for file fields: `local` (default, `storage/app/private/files`) or `s3` (S3-compatible storage, e.g. AWS, Infomaniak, Exoscale; credentials in `AWS_*`, for providers other than AWS also `AWS_ENDPOINT` and `AWS_USE_PATH_STYLE_ENDPOINT=true`; first run `composer require league/flysystem-aws-s3-v3`). Do not switch while files are stored: existing files are not moved | `APP_DATA_FILES_DISK=local` in `.env` |
| Time zone of the application (default `Europe/Zurich`) | `APP_TIMEZONE` in `.env` |
| Trust the provider's load balancer when HTTPS ends before the server (proxy addresses, comma-separated) | `TRUSTED_PROXIES` in `.env` |
| Break down response times: `Server-Timing` header with Laravel start-up (`boot`), number and duration of database queries (`db`), remaining application time (`app`) and total (`total`), plus the three slowest queries (`q1` to `q3`: duration, connection, tables and kind of query, no SQL text; "erste" = first query of the connection, includes connecting), shown in the browser's developer tools under Network → request → Timing, also for Livewire clicks. Every visitor sees the timings – switch off again after measuring (default off) | `SERVER_TIMING=true` in `.env`, then `php artisan optimize` |
| Default language if neither user nor browser selects an offered language | `APP_LOCALE=de` in `.env` |
| Load demo data (articles, customers, orders, order lines) (not with an installation using `--no-dev`) | `php artisan db:seed --class=DemoDataSeeder` |
| External demo database (schema `forschung`; the empty database `SAFT2_ext` must exist) with the connection "Forschung (Demo)" and the six linked tables under "Research database" (technical names `fdb_…`, so they coexist with the internal research demo `forschung_…`; if another table uses one of these names, the command stops without creating anything; tables already linked remain unchanged; connection only: `--without-tables`). With `--driver=mysql` as the MySQL version (credentials `MYSQL_*`), with `--driver=mariadb` as the MariaDB version (credentials `MARIADB_*`), with `--driver=sqlsrv` as the SQL Server version in the schema `forschung` of an existing database (credentials `MSSQL_*`) | `php artisan external-demo:install` |
| Basic setup (roles, permissions, menu) | `php artisan db:seed` |

Notes:

- **Production:** Install with `composer install --no-dev --optimize-autoloader` and set `APP_ENV=production`.
  Otherwise a test route is available through which anyone can sign in without a password.
- **Backups** contain all data. Keep an additional copy outside the server. How to restore a backup is described in
  the [README](../../README.md#operations).
- **Files from file fields** and the documents' **letterhead** (`documents/`) are saved by `db:backup` as a ZIP next
  to the database backup (same name with `_files.zip`, without thumbnails). To restore, unzip it into `storage/app/private` after restoring the database
  (`unzip <file>_files.zip -d storage/app/private`); thumbnails are recreated when first shown. If the files from file
  fields are stored in S3 (`APP_DATA_FILES_DISK=s3`), they are not included – back them up there, e.g. with bucket versioning.
- **Apps (from 0.4):** after the update, `php artisan migrate` assigns all existing tables, menus, reports, roles and
  tasks to an app “Existing data”; system roles, system menus and system tasks stay without an app. Splitting into
  separate apps is then done in the interface (see
  [Administrator guide, chapter 15](ADMIN_GUIDE.md#15-apps-business-applications)). The menu entry “App settings” has
  been called “Global settings” since then.
- **Documents (PDF):** SAFT generates PDFs with the library tc-lib-pdf (pure PHP, no external programs). For logos
  as PNG or GIF, PHP needs the extension `gd`. Letterheads, logos and house fonts are stored under
  `storage/app/private/documents` and are included in the ZIP of `db:backup`. SAFT stores converted fonts and reduced logos under `storage/framework/cache/pdf-fonts` and `pdf-logos`; they
  are recreated when needed and may be deleted.
- **Orphaned files** (uploads from cancelled forms, files of records deleted outside SAFT) are removed after 24 hours by
  the system task *Clean up files* or `php artisan files:prune`.
- **Upload size:** The maximum size per file field only applies up to PHP's limit. For e.g. 50 MB set
  `upload_max_filesize = 50M` and `post_max_size = 55M` in `php.ini` (with web hosts often via `.user.ini` or their
  control panel); on the web server possibly also `client_max_body_size` (nginx) or `LimitRequestBody` (Apache).
- The **technical name** `APP_NAME` in `.env` determines cookie and cache names. The visible name is maintained in
  the global settings; changing it there does not sign anyone out.
- The tables of the table designer are stored separately from the system tables: with PostgreSQL in the schema
  `app_data`, with MySQL in a database of their own, `<database>_app_data`.
- **Time zone:** All times are shown in the time zone from `APP_TIMEZONE`.

**Adding another language** (development, not through the interface):

1. Add an entry with name, date formats and CSV notation in `config/locales.php`.
2. Install Laravel's standard texts with `php artisan lang:add <language>`.
3. Translate the application's own texts: for every file in `lang/de`, a file of the same name in
   `lang/<language>`.
4. The tests show whether texts are missing, including those missing from Filament's own translation. Add these
   under `lang/vendor/<package>/<language>/`.

The new language then appears automatically in the language icon and as an input field for multilingual labels.

---

## 2. Online help and version

The online help "Documentation" reads the user guide, the frequently asked questions and the administrator guide
directly from `docs/manual` (German for a German interface, otherwise English); changes to these files appear without
any further step. This guide and the installation guides intentionally do not appear there. The version shown under
"About SAFT XT" is set in `config/saft.php` (`version`) and is raised with every release; next to it is the
version of the plugin interface (`api_versions`, see [developer guide, chapter 18](DEVELOPER_GUIDE.md#18-api-versions-and-change-log)).

## 3. Updates and security

SAFT consists of its own code plus about 135 PHP packages and a few JavaScript libraries (complete list:
`THIRD_PARTY_NOTICES.md`). Three levels are responsible for their security:

| Level | Examples | Responsible | How |
|---|---|---|---|
| Server | operating system, web server, PHP, PostgreSQL/MySQL/MariaDB, TLS | operations | operating system security updates (automatic where possible), apply PHP patch versions promptly |
| Dependencies | Laravel, Filament, other packages, Mermaid | SAFT development | monitoring and updates as below; shipped only with a tested SAFT release |
| SAFT | own code | SAFT development; operations installs releases | releases on GitHub (pre-release or release with notes) |

**Pinned versions:** `composer.lock` pins every dependency exactly. A server therefore never receives new packages by
itself, only with the next SAFT release (`composer install --no-dev` installs exactly the tested versions).

**Monitoring:**

- **Dependabot alerts** (GitHub) report known vulnerabilities in dependencies by e-mail and in the repository's
  *Security* tab.
- **Dependabot security updates** immediately open a pull request with the fixed version; `.github/dependabot.yml`
  also bundles compatible updates (patch/minor) into one pull request every week.
- The **Security audit** workflow checks every week and on every change to `composer.lock` or `package-lock.json`
  with `composer audit` and `npm audit` (only what runs on the server).

**Cadence:**

| Kind | When | Tests before the release |
|---|---|---|
| security fix affecting SAFT | within days, as a patch release (e.g. 2.4.1), marked as a security update in the notes | feature tests on all databases; browser tests of the affected areas |
| compatible updates (patch/minor) | collected monthly | feature tests on all databases and the complete browser tests |
| new major versions (Laravel, Filament, OpenSpout …) | planned as a separate stage | complete, with adjustments |

Not every reported vulnerability affects SAFT: what matters is whether the affected part of a package is used in
operation. When in doubt, update.

**For operators:** watch releases on GitHub (*Watch → Custom → Releases*), install updates on a test system first,
then in production – with a backup beforehand (`php artisan db:backup`) and the previous release as a way back.
Procedure: [Appendix C, section 9](INSTALL_PRODUCTION.md#9-installing-updates).

**Supported versions** of the foundations: Laravel 13 receives security fixes for about two years, PHP 8.4 until the
end of 2028; switch to the next version in good time. Reporting vulnerabilities in SAFT itself: see `SECURITY.md` in
the program directory.

**Security headers:** SAFT sends headers with every response that harden the browser: no embedding in other sites
(`X-Frame-Options`, `frame-ancestors`), no guessing of file types (`X-Content-Type-Options`), addresses only shortened
to other sites (`Referrer-Policy`), no camera, microphone or location (`Permissions-Policy`). Over HTTPS, **HSTS** is
added: the browser then calls SAFT only over HTTPS for one year, even if someone types `http://` or an attacker on the
network tries to redirect to http. HSTS can be switched off with `SECURITY_HSTS=false` in `.env`, e.g. if the address
is to run without a certificate again in future – browsers that already know the header keep to it until it expires.
If the web server sets the same headers itself, its values apply. Check: `curl -sI https://saft.example.ch/admin/login`.

**Passwords:** new passwords need at least 12 characters and are checked against known data leaks (*Have I Been
Pwned*). Only the beginning of the SHA-1 hash (5 characters) leaves the server, never the password; the server needs
outbound HTTPS access to `api.pwnedpasswords.com:443` for this. If the service cannot be reached, the password counts
as safe. Without internet access, set `PASSWORD_CHECK_LEAKED=false`, otherwise every password change waits for the
timeout. The same rule applies to `ADMIN_PASSWORD` during the first `db:seed`.

**Two-factor authentication:** the administration decides who must set it up (*Administration › Users and access › Authentication*,
default: required for administration). The authenticator app's secret and the (hashed) recovery codes are stored
encrypted with `APP_KEY` in the `users` table – if `APP_KEY` is lost, all accounts must set up again. The codes depend
on the time: the server clock must be correct (NTP), otherwise codes are rejected. If the only admin has lost phone and
codes: `php artisan auth:two-factor-reset <email>`. `TWO_FACTOR_ENFORCE=false` only suspends the requirement and is
meant for the automated tests; anyone who has set up a second factor is still asked for it.

## 4. Scheduled tasks and cron

[Scheduled tasks](ADMIN_GUIDE.md#12-scheduled-tasks) (e.g. refreshing data snapshots) are run by the Laravel
scheduler. It needs **one** cron entry that calls it every minute; SAFT does not need a queue worker for this. The
tasks run in the cron process, without the web server's time limit, one after another.

**Own server or VPS** (as the user SAFT runs as – usually the web server's user):

1. Open the cron table: `crontab -e` (for the web server user e.g. `sudo crontab -u www-data -e`). The first time, the
   system asks for an editor; `nano` is the easiest.
2. Add this line (adjust the paths; `which php` shows the path to PHP):

   ```cron
   * * * * * cd /path/to/saft && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
   ```

   Write out full paths: cron starts with a minimal environment. The output is discarded; results are shown in SAFT
   under *Scheduled tasks → Runs*.
3. Save (in nano `Ctrl+O`, `Enter`, `Ctrl+X`); you see `crontab: installing new crontab`. `crontab -l` shows the entry.
4. Check: after one or two minutes `php artisan tasks:list` shows a current time under "Scheduler last ran", and the
   notice "Scheduled tasks are currently not running" in the administration disappears.

**Web hosting with a customer portal:** most hosting plans include cron jobs (section "Cron jobs" or "Scheduled
tasks"). Enter `cd /path/to/saft && php artisan schedule:run` as the command, interval every minute. If the plan only
allows coarser intervals (e.g. every 15 minutes), SAFT still works: due tasks then run up to that interval later.
Without cron, no scheduled tasks run; data snapshots can then only be refreshed manually.

**Trying it out without cron** (developer machine): `php artisan schedule:work` calls the scheduler every minute as long
as the terminal is open.

**Console:** `php artisan tasks:list` (tasks, next and last run, last scheduler call), `php artisan tasks:run <ID>`
(one task immediately), `php artisan tasks:run-due` (due tasks; this is what the scheduler calls). Optional settings in
`.env`: `TASKS_HEARTBEAT_MINUTES` (when the administration warns, default 15), `TASKS_HANGING_HOURS` (when a run counts
as aborted, default 6).

**Failed runs:** the message names a reference; under it, the error with trace is in `storage/logs/laravel.log`
(entry `Geplante Aufgabe fehlgeschlagen`). The administration also sees error, message, location and trace in SAFT at
the run under **Details** (permission *Scheduled tasks: see technical details*). *Administration › Operations › System log*
(permission *System log: view*) shows the end of the log file – useful without SSH access, e.g. on web hosting. At most
the last 2 MB are read; files `laravel.log` and `laravel-YYYY-MM-DD.log` (log channel `daily`) in `storage/logs`.

**Jobs:** The same cron entry also drives the [jobs](ADMIN_GUIDE.md#jobs) forward: `work:run` runs every minute for
at most 50 seconds and takes over jobs whose starting person has left the page. With coarser cron intervals an
abandoned job continues correspondingly later; without cron only while the page is open. In the browser SAFT works at
most 15 seconds per request, always below PHP's `max_execution_time`. Result files are stored in
`storage/app/private/work/<uuid>/` and deleted by the system task *Clean up jobs* (hourly). Failures are logged under
their reference in `storage/logs/laravel.log` (entry `Arbeit fehlgeschlagen`). Actions such as export, import,
documents or snapshot start a job and work along for up to `WORK_INLINE_BUDGET` seconds (default 10) within the
request; small amounts are done at once this way. Optional settings in `.env`: `WORK_INLINE_BUDGET` (10),
`WORK_BROWSER_BUDGET` (15), `WORK_SCHEDULER_BUDGET` (50), `WORK_TAKEOVER_SECONDS` (30), `WORK_MAX_STALLS` (aborts of the
same step until failure, 3), `WORK_RESULT_HOURS` (24), `WORK_STUCK_MINUTES` (when the dashboard warns, 10). All budgets
stay below PHP's `max_execution_time`. Long, indivisible steps (AI requests of the app designer, `AI_DESIGNER_TIMEOUT`)
raise the time limit for themselves; if that is not possible in the browser, the scheduler runs them. A queue worker is
not needed.

**With a queue worker:** On a server of your own, a worker can take over the jobs – they then start without delay,
independent of browser and cron interval, and the browser only displays. For this set `QUEUE_CONNECTION=database`
(default, the `jobs` table exists) and `WORK_DRIVER=queue` in `.env`; after changes run `php artisan optimize:clear` and
`php artisan queue:restart`. The worker runs permanently as a service, e.g. under systemd:

```ini
[Unit]
Description=SAFT queue worker
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/saft
ExecStart=/usr/bin/php artisan queue:work --max-time=3600 --tries=1
Restart=always

[Install]
WantedBy=multi-user.target
```

or under Supervisor with the same command. `--max-time=3600` restarts the worker every hour so that it loads changed
code and configuration. Per job a worker run works `WORK_QUEUE_BUDGET` seconds (default 50) and queues itself again
until the job is done; on the queue connection `sync` no job is ever started. If the worker fails, the scheduler takes
over after `WORK_TAKEOVER_SECONDS` as without a worker.

**System tasks for backups and demos:** *Back up database* (02:30, with the files from file fields), *Clean up
backups* (02:45, 14 days, at least 3; a backup and its ZIP count as one) and *Clean up files* (03:00) run from
installation on; the scheduler thus replaces a separate cron entry for `db:backup`. If you still have such
an entry, remove it or switch off the task, otherwise backups are made twice. *Reset app data* is destructive:
delivered switched off and, even when switched on, only effective with `DEMO_RESET=true` in `.env` – set this only on
public demo or training systems.

## 5. AI functions

The AI assistants for reports, tables and document templates (see [Administrator guide, chapter 13](ADMIN_GUIDE.md#13-ai-functions)) are
**off** until set up. Provider, model and API key can be entered in the global settings; whatever is left empty there
comes from the `.env`:

| Variable | Meaning |
|---|---|
| `AI_PROVIDER` | `none` (default), `anthropic`, `bedrock` or `vertex` |
| `AI_MODEL` | Model ID, default `claude-opus-5-5`; with Bedrock prefixed, e.g. `anthropic.claude-opus-5-5` |
| `AI_EFFORT` | Thinking depth `low`, `medium` (default), `high` |
| `AI_DAILY_LIMIT` | Requests per person and day (default 50) |
| `AI_MAX_TOKENS`, `AI_TIMEOUT` | Maximum answer size (default 16000) and seconds per request (default 120) |
| `AI_DESIGNER_MAX_TOKENS`, `AI_DESIGNER_TIMEOUT` | Only for the AI app designer (a whole app is a long answer): maximum answer size (default 32000, about 25–30 tables) and seconds per request (default 900). More tokens allow larger apps but take longer and cost more; PHP and proxy time limits must be correspondingly higher for the designer |
| `ANTHROPIC_API_KEY` | Key for `anthropic`; better in the global settings (stored encrypted there) |
| `AI_INFERENCE_GEO` | Optional: processing region with Anthropic, e.g. `us` |
| `AI_BASE_URL` | Only for proxies/gateways; empty = `api.anthropic.com` |
| `AI_AWS_REGION` | Amazon Bedrock: region; credentials as usual from the environment (`AWS_ACCESS_KEY_ID` …) or the instance role |
| `AI_VERTEX_PROJECT`, `AI_VERTEX_LOCATION` | Google Vertex AI: project and region; sign-in via Application Default Credentials, requires `composer require google/auth` once |

- The server needs **outbound HTTPS access** to the provider (e.g. `api.anthropic.com:443`); **Test connection** in the
  global settings reports network and firewall problems separately from wrong keys.
- After changing the `.env` with a cached configuration, run `php artisan config:cache` again.
- Depending on model and thinking depth a request takes a few seconds up to about a minute; it runs in the web server
  process. PHP's `max_execution_time` and time limits of an upstream proxy should therefore be above `AI_TIMEOUT`.
- Errors are written to the application log (`storage/logs`), every request also to the activity log.
- Switching off: main switch off or provider "Off" in the global settings, or `AI_PROVIDER=none`.
- **Help assistant:** separate switch in the global settings. It sends the manuals of the online help (in the
  language of the interface, about 35,000 tokens), which the provider caches; follow-up questions within a few minutes
  are therefore much cheaper. The conversations are stored in SAFT's database (tables `ai_help_conversations` and
  `ai_help_messages`) and are not deleted automatically; each person deletes their own. A locally operated model is
  planned but cannot be connected yet.

## 6. Command reference (php artisan)

Run all commands in the SAFT root directory, on a production server as the web server user. `php artisan list`
shows all available commands, `php artisan help <command>` the options of a command.

### 6.1 SAFT's own commands

| Command | Purpose | Important options |
|---|---|---|
| `php artisan db:backup` | Backup of SAFT's database (PostgreSQL, MySQL or MariaDB), plus the files from file fields and the letterhead as a ZIP | `--path=` target folder (default `storage/backups`), `--without-files` without files |
| `php artisan files:prune` | Removes orphaned files from file fields: uploads never saved, files without record and files without entry (after 24 hours); reports entries whose file is missing | `--dry-run` only count, `--hours=` waiting time |
| `php artisan app-data:verify` | Checks whether the table designer's metadata and the tables in the `app_data` namespace match; exit code 1 on differences (for monitoring) | – |
| `php artisan auth:mode [local\|sso\|hybrid]` | Shows or sets the sign-in mode, e.g. `auth:mode local` as an emergency exit when no identity provider is reachable | – |
| `php artisan auth:two-factor-reset <email>` | Deletes the authenticator app and recovery codes of an account; the person signs in with the password and sets up again. Recorded in the activity log | – |
| `php artisan computed:recompute [table …]` | Recalculates the computed fields (without arguments: all tables with computed fields); runs as a [job](ADMIN_GUIDE.md#jobs) and then shows the number of changed records per table | internal table names; `--background` only start, the scheduler computes |
| `php artisan work:run` | Drives open jobs forward that nobody has worked on for 30 seconds; the scheduler calls it every minute | `--budget=` seconds (default 50) |
| `php artisan table-connector:verify [table …]` | Compares connected tables with the schema of the external database; exit code 1 on differences | `--apply` takes over differences like "Re-read" |
| `php artisan table-connector:connect <connection>` | Bulk link on the console (without the web server's time limit) | `--all` or `--table=` (repeatable), `--app=` (ID of the app), `--schema=`, `--prefix=`, `--menu-group=` (ID or `none`), `--role=` (repeatable), `--export`, `--create`, `--update`, `--delete`, `--inactive`, `--no-details` |
| `php artisan tasks:list` | Scheduled tasks with schedule, next and last run; last scheduler call | – |
| `php artisan tasks:run <ID>` | Run one scheduled task immediately (the schedule stays) | – |
| `php artisan tasks:run-due` | Run due scheduled tasks and app removals scheduled by the super admin (after the 10-minute waiting time); called by the scheduler every minute. Without a scheduler, removals run via “Remove now” on the Apps page | – |
| `php artisan saft:plugins` | Lists the installed plugins with version and compatibility and creates their permissions (also runs after every `migrate`) | – |
| `php artisan saft:plugins --check [id]` | Checks plugins before delivery (ID, API, permissions, menu, plugin API only); exit code 1 on errors | – |
| `php artisan saft:plugin-context` | Developer context for plugins (AGENTS.md, API reference, schema; no data) | `--output=`, `--tables=` (repeatable), `--without-external`, `--zip` |
| `php artisan saft:third-party-notices` | Generates `THIRD_PARTY_NOTICES.md` (all third-party components with version, license, copyright and license texts, including the libraries inside the Mermaid bundle). Run it on the development machine after every package update and commit the file; a test checks it is complete | needs `node_modules` |
| `php artisan external-demo:install` | Creates the external demo research database and a database connection for it and links its tables (`fdb_…`) | `--driver=pgsql\|mysql\|mariadb\|sqlsrv`, `--database=`, `--name=`, `--without-connection`, `--without-tables` |

### 6.2 Basic setup and demo data (seeders)

| Command | Content |
|---|---|
| `php artisan db:seed` (production: `--force`) | Basic setup: roles, permissions, menu and the first administrator from `ADMIN_*` in `.env`; repeatable |
| `php artisan db:seed --class=DemoDataSeeder` | Shop demo (articles, customers, orders, order lines) with an example report, group "Shop (Demo)" |
| `php artisan db:seed --class=LagerDemoSeeder` | Warehouse demo (`lager_…`), group "Lager (Demo)" |
| `php artisan db:seed --class=VertriebDemoSeeder` | Sales demo (`vertrieb_…`), own app "Sales (demo)" that uses the warehouse items through an interface; loads the warehouse demo first if missing. Resetting the warehouse data also resets the sales data |
| `php artisan db:seed --class=GitarrenshopDemoSeeder` | Guitar shop demo (`gitarren_…`), own app "Guitar shop (demo)": income and expenses with VAT, receipts and payments with files, item images (placeholders), role "Gitarrenshop" with all permissions of the app. Receipt numbers as a number sequence per document type (`V-2026-001`, `E-2026-001`); run again, it converts an older installation with receipt numbers as text (the numbers stay) |
| `php artisan db:seed --class=MusikschuleDemoSeeder` | Music school demo (`musikschule_…`), medium-sized app "Music school (demo)" with 21 tables, installed from the app definition `database/seeders/apps/musikschule.saft-app.json` (designed with the AI app designer): courses, lessons with attendance, rental instruments, semester invoices with payments, concerts, holidays; 5 reports, role "Musikschule". Run again, it updates an installed older version of the app (as with *Update*, with a backup first) and adds the holidays. Needs a super admin (after `php artisan db:seed`) |
| `php artisan db:seed --class=ForschungDemoSeeder` | Internal research demo (`forschung_…`), group "Research (demo)" |

Load demo data only on test and training systems (not with an installation using `--no-dev`). The demos first check
whether their table names are free and otherwise stop without creating anything. On a server with
`APP_ENV=production` (e.g. a demo at Strato), Laravel asks "Are you sure you want to run this command?" before every
seeder – `--force` skips the question, e.g. `php artisan db:seed --class=MusikschuleDemoSeeder --force`. Do not
interrupt a running seeder (Ctrl+C): the demo then stays half filled. The music school demo detects this on the next run
and reloads all its data; for the other demos, reload their data with the task "Reset app data" (needs
`DEMO_RESET=true`) or e.g.
`php artisan tinker --execute='echo app(\Database\Seeders\LagerDemoSeeder::class)->resetDemoData();'`.

### 6.3 Laravel commands SAFT needs

| Command | When |
|---|---|
| `php artisan migrate --force` | after every update: creates new tables, columns, permissions and menu entries |
| `php artisan optimize` and `php artisan filament:optimize` | after every update in production (cache configuration, routes, views, Filament components) |
| `php artisan optimize:clear` | clear the caches, e.g. after changes to `.env` |
| `php artisan key:generate` | once during installation (key `APP_KEY`) |
| `php artisan storage:link` | once during installation (public storage for uploaded logos) |
| `php artisan queue:work` | only if `QUEUE_CONNECTION` is not `sync` (e.g. for sending mail) or with `WORK_DRIVER=queue` for the [jobs](#4-scheduled-tasks-and-cron) |
| `php artisan schedule:run` (every minute via cron) | runs the [scheduled tasks](#4-scheduled-tasks-and-cron); without a cron entry they do not run |
| `php artisan down` / `php artisan up` | maintenance mode during an update |

### 6.4 Commands during operation

- **Caches** (`optimize`, `filament:optimize`, `optimize:clear`): safe while the system is running, and recommended in
  production after every update. While the configuration is cached, changes to `.env` only take effect after
  `php artisan optimize:clear` (or another `optimize`).
- **Do not cache on development or test machines** that run the automated tests: with a cached configuration Laravel
  ignores the test database from `phpunit*.xml`, and the tests would wipe the database from `.env`. The feature tests
  therefore abort immediately in that case; run `php artisan optimize:clear` before testing.
- **Schema changes** (`migrate`): in maintenance mode – `php artisan down`, `php artisan db:backup`,
  `php artisan migrate --force`, `php artisan optimize`, `php artisan filament:optimize`, `php artisan up`.
- **Destructive commands** (`migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`) empty or
  drop tables. With `APP_ENV=production` SAFT refuses them. `db:seed` can be repeated safely, but demo data does not
  belong in production.

## 7. Plugins

Plugins extend SAFT with their own pages and functions (see the [developer guide](DEVELOPER_GUIDE.md)). They are
Composer packages; SAFT includes every package in the `plugins/` folder as a Composer repository. Plugins run with all
of the application's rights – only install plugins from a trusted source.

**Installing or updating** (in maintenance mode, like an update):

```bash
php artisan down
php artisan db:backup
composer require saft/<name>              # folder plugins/<name>; for a Git repository, first add it under
                                          # "repositories" in composer.json
php artisan migrate --force               # the plugin's own tables and its permissions
php artisan optimize && php artisan filament:optimize
php artisan up
```

Afterwards **Administration › Application › Plugins** shows the plugin with its version and whether it fits the installed SAFT
version; there the administration adopts the suggested menu entries and grants the permissions in role management.
`php artisan saft:plugins` lists the plugins on the console and creates missing permissions.

**Switching off and on** without removing – e.g. for tests or with plugins that overlap in function:

```bash
php artisan saft:plugins --disable=<id>    # e.g. lager-umbuchung; reports plugins that rest as a result
php artisan saft:plugins --enable=<id>
```

A switched-off plugin stays installed; its pages return “not found”, tiles and menu entries are hidden, listeners,
buttons, validation rules and formula functions do not apply, scheduled tasks of its kinds are skipped. No restart or
`optimize` is needed; the state is kept in the database (table `plugin_states`) and in the change log. The same is
possible on the **Plugins** page (see [administrator guide, chapter 14](ADMIN_GUIDE.md#14-plugins)).

**Removing:**

1. If the plugin has its own tables, roll them back first: `php artisan migrate:rollback --path=plugins/<name>/database/migrations`
   (back up first; in production SAFT refuses `migrate:rollback` – then change `APP_ENV` temporarily or remove the
   tables by hand).
2. `composer remove saft/<name>`, then `php artisan optimize` and `php artisan filament:optimize`.
3. The plugin's menu entries are hidden automatically. On the **Plugins** page, **Clean up** deletes them together
   with the permissions that are no longer needed.

Data a plugin wrote to table designer tables is kept. The example plugins `saft/lager-umbuchung`, `saft/lager-tafel`,
`saft/ch-pruefungen` and `saft/musikschule-planung` are only installed with the development packages (not with `composer install --no-dev`).

---

## Appendix A – Updating the screenshots

The screenshots are stored in `docs/manual/images/<language>/` (`de`, `en`, `fr`, `it`). They are not
made by hand but by a script that operates the application like the browser tests, in a Chrome without a window.
After changes to the interface, this command recreates all figures in all four languages (setup as for the browser
tests, see the [README](../../README.md#browser-tests-laravel-dusk)):

```bash
composer manual:screenshots
```

Only some languages, e.g. French and Italian:

```bash
vendor/bin/phpunit -c phpunit.dusk.xml --testsuite Manual --filter '/with data set "(fr|it)"/'
```

The script is `tests/Manual/ManualScreenshotsTest.php`. It empties the test database `SAFT2_dusk`, loads the demo
data, opens each view and adds the red markers with the numbers used in the text. It never works with the production
database.

| File | Content |
|---|---|
| `01-login.png` | Sign-in page in Hybrid mode with two SSO buttons and "Forgot password" |
| `02-dashboard.png` | Dashboard of a super admin |
| `03-data-table.png` | Customer list with search, filter, sorting and "Details" |
| `04-cascade.png` | Customer → order → order lines selected |
| `05-details-popup.png` | "Details" window of an order |
| `06-preview.png` | Preview of an article from the order lines |
| `07-roles-data-permissions.png` | Role with the data permissions matrix and menu entries |
| `08-menu-data-table.png` | New menu entry of type "Data table" |
| `09-table-designer.png` | Table "Orders" in the table designer |
| `10-cascade-logic.svg` | Diagram of the cascade logic (drawing, not a screenshot) |
| `11-identity-provider.png` | Editing an Entra ID provider |
| `12-database-connection.png` | Editing the connection to the demo research database |
| `13-table-connector-create.png` | Linking the table "projekte" |
| `14-table-connector-columns.png` | Columns of the linked table "projekte" |
| `15-table-connector-writing.png` | Enabling writing for "organisationen" |

For the customer list the script hides the columns contact person, email and postcode so that the list fits into the
picture together with "Details". New figures are added in the script; every view is a method of its own there.

---

## Further guides

- [Appendix B – Installation on a development machine](INSTALL_DEVELOPMENT.md)
- [Appendix C – Installation on a production server](INSTALL_PRODUCTION.md)
- [Appendix D – Setting up single sign-on with Microsoft Entra ID](SSO_ENTRA.md)
- [Developer guide: plugins](DEVELOPER_GUIDE.md)
