# SAFT XT – Architecture

**English** · [Deutsch](ARCHITECTURE.de.md)

As of: 24 November 2026 (Foundation 2, F5; release 0.6.0) · For: core development, plugin development, AI agents

This document describes how SAFT XT is built and why. It names the layers, the two database connections, the single
write path, the permission model, the extension points and background work, and ends with the architecture
decisions, numbered, dated and with their reasons. What users and administrators need is in the
[manuals](manual/README.md); how to write a plugin is in the [developer guide](manual/DEVELOPER_GUIDE.md). Larger
undertakings have a concept under `docs/concept/`; the decisions here summarise their outcomes.

## Contents

- [1. Principles](#1-principles)
- [2. Layers and directories](#2-layers-and-directories)
- [3. The two connections](#3-the-two-connections)
- [4. Metadata and records](#4-metadata-and-records)
- [5. The write path](#5-the-write-path)
- [6. Reading and permissions](#6-reading-and-permissions)
- [7. Extension points](#7-extension-points)
- [8. Background work](#8-background-work)
- [9. Portability and quality assurance](#9-portability-and-quality-assurance)
- [10. Decisions](#10-decisions)

## 1. Principles

SAFT XT is a metadata-driven database application framework on Laravel 13, FilamentPHP v5 (Livewire 4) and PHP 8.4.
Business applications are configured rather than programmed: tables, fields, formulas, validation rules, menus,
roles, reports, document templates and tasks are data. Only what cannot be configured is programmed, and then as a
plugin against a versioned API.

Five principles run through the code:

1. **One truth.** The database is authoritative; definitions (`saft-app/1`, `saft-schema/1`) are generated from it,
   never maintained the other way round.
2. **One way per job.** Structure changes go only through `DynamicSchemaService`, records are written only through
   the `RecordWriter`, background work runs only as a work item (`WorkType`). Architecture tests pin this down.
3. **Portable.** PostgreSQL, MySQL and MariaDB are equals; dialect-specific SQL lives only in dialect classes.
4. **Operation without special equipment.** Shared hosting with cron is enough; queue workers and Redis are optional.
5. **Machine-checkable.** Whatever can be stated as a rule is checked by a test or by static analysis, because with
   AI-assisted development the bottleneck is reviewing, not writing.

## 2. Layers and directories

From the outside in: interface (Filament, Livewire) → domain logic per topic (own namespaces under `app/`) →
models (Eloquent) → database. Domain logic never depends on the interface; Filament classes call services and hold
no rules themselves. The most important namespaces:

| Directory | Contents |
|---|---|
| `app/Models`, `app/Models/Base` | system tables as Eloquent models in the base-model pattern: `Base/*` is generated from the schema with Reliese and holds only columns, casts and relations; `Models/*` extends it with business logic, traits (`HasAuditLog`, `BelongsToApp`, `HasStableKey`, `HasTranslatedAttributes`) and scopes. `DynamicRecord` is the generic model for table designer data and connected tables |
| `app/DynamicData` | table designer and records: schema (`Schema`, `Ddl`, `TableDefinition`, `SchemaVerifier`), write path (`Write`), formulas (`Formula`), field and table rules (`Rules`, `Validation`), files (`Files`), import and export, copying, cascade, delete guard, permissions (`DataAccess`, `DataPermissions`), interface building blocks (`Ui`: form, table and action builders) |
| `app/Services/DynamicSchemaService.php` | the only way to change the structure of designer tables: metadata and DDL with a lock per table and reconciliation after errors |
| `app/ExternalData` | table connector: external connections at runtime (`ConnectionRegistry`, `ext_<id>`), schema inspection and comparison, type mapping, snapshots (`Snapshots`) |
| `app/Reports` | reports: definition, resolution, query building (`ReportQuery`, `ReportExpression`), query dialects, presentation, export, links |
| `app/Apps` | apps (business bracket): current app, permissions per app, interfaces between apps, definition and change plan (`Definition`, `Update`), AI app designer (`Designer`), moving, removing |
| `app/Documents` | document templates: rendering (PDF, DOCX), letterhead, QR bill, merging, mailing, building blocks for batch runs |
| `app/Plugins` | plugin registry, lifecycle, dependencies, plugin permissions and menus, developer context; `Plugins/Api` is the guaranteed API (`Saft`, `DataTable`, events, `ApiManifest`) |
| `app/Work`, `app/Jobs` | work items (background work in steps): contract, runner, service, drivers; `Jobs/AdvanceWork` is the queue driver |
| `app/Tasks` | scheduled tasks: contract `TaskType`, registry, runner, schedule, system tasks under `Types` |
| `app/Auth` | sign-in: modes local/SSO/hybrid, SSO drivers (OIDC, SAML), role mapping, two-factor, "view as role" (`RoleSimulation`) |
| `app/Policies` | Laravel policies of the system resources, mapped to permissions `<prefix>.<action>` |
| `app/Navigation` | menu tree from the `menus` table, filtered by permissions |
| `app/Filament`, `app/Livewire` | interface: resources, pages, widgets, actions; own Livewire components (detail table, progress of a work item, AI help) |
| `app/Ai`, `app/Help`, `app/Documentation` | AI connection (Anthropic), AI help with knowledge sources, online help from the manuals |
| `app/Database` | backup and dumper (where raw SQL lives), numbered migration creator |
| `app/Exceptions` | `BusinessRuleException` (domain, with code), classification of database errors, presentation for the interface |
| `plugins/*` | bundled plugins as Composer packages with their own tests (test suite `Plugins`) |
| `database/migrations`, `database/settings` | one number sequence `0001_01_01_0000NN_…` across both directories |
| `docs/concept`, `docs/manual` | concepts per undertaking; manuals, which are also the online help |

Translations live under `lang/{de,en,fr,it}`; every interface string has a key. The manuals for users, FAQ and
administration exist in four languages, the technical ones in German and English.

## 3. The two connections

SAFT always works with at least two database connections, even when both point to the same server:

| Connection | Contents | Configuration |
|---|---|---|
| **Main connection** (`DB_CONNECTION`) | system tables: users, roles, permissions, menus, table designer metadata (`custom_tables`, `custom_fields`), reports, apps, document templates, tasks, work items, activity log (`activity_log`), notifications, plugin system tables | Laravel default |
| **`app_data`** | the physical tables of the table designer, the snapshot copies (`ds_…`) and the counters of the number sequences (`saft_number_sequences`); PostgreSQL: schema `app_data`, MySQL/MariaDB: separate database `<name>_app_data` | `config/dynamic_data.php`; own user, host and password possible (`DB_APP_DATA_*`), recommended for production |
| **`ext_<id>`** | connected external tables (table connector), configured at runtime per connection, credentials encrypted, "read only" enforced at database level | table `database_connections`, `ConnectionRegistry` |

`DynamicDataNamespace` is the entry to the namespace: connection, DDL dialect and qualification of table names. Table
names are globally unique (one namespace for all apps); the table designer suggests the app's prefix.

Consequences: there is **no distributed transaction** between the main connection and `app_data` (different servers
are allowed). The write path therefore opens one transaction on each connection and commits the data first
(section 5). DDL is not transactional on MySQL/MariaDB; `DynamicSchemaService` reconciles after an error, and
`app-data:verify` checks metadata against the physical tables.

## 4. Metadata and records

A designer table exists twice: as metadata (`CustomTable` with `CustomField`s on the main connection: name, labels
per language, field types, options, formulas, field and table rules, app) and as a physical table in `app_data` with
`id`, business columns, `created_at`/`updated_at`, `created_by`/`updated_by` and `deleted_at` (recycle bin).
Connected tables have metadata only; their structure comes from the external database (`SchemaInspector`,
`ExternalTypeMapper`).

`DynamicRecord` is **one** Eloquent model for all these tables. It is bound to a definition through
`forTable(CustomTable)` or `forTableName()` (a registry per process: `$fillable`, casts, connection, key type, soft
deletes only where the column exists). Eloquent keeps creating new instances internally; the registry keeps them
configured. Every place that **reads** through this model is listed in `tests/Fixtures/read-sites.json` with a reason
(section 6).

Field types (`FieldType`) define how a value is stored, validated, displayed, imported, exported and treated in
reports; formulas (`Formula/Parser`, `Evaluator`) are evaluated in PHP with decimal arithmetic and translated to SQL
for reports. The formula language is specified in [`FORMULA_SPEC.md`](concept/FORMULA_SPEC.md); a conformance table
runs against both evaluation paths on all three databases.

## 5. The write path

Records are written exclusively through `App\DynamicData\Write\RecordWriter` (`create`, `update`, `save`, `delete`,
`forceDelete`, `restore`, `batch`). Every outermost call:

1. opens a transaction on the data connection (`app_data` or `ext_<id>`) and one on the main connection;
2. on changes checks the **version** (`WriteOptions::expectedVersion`, `RecordVersion` from `updated_at` or from the
   original values read for connected tables without timestamps) with `lockForUpdate()` and throws
   `StaleRecordException` if someone saved in the meantime;
3. runs the **before phase** (`WritePhases`): read-only? → protected documents → database defaults or "copy from"
   and sequential numbers → assign or protect number sequences → plugin event `RecordSaving` (numbers must not differ
   afterwards) → computed fields → `created_by`/`updated_by`;
4. writes the record;
5. runs the **after phase**: sums in master records → reconcile files → `RecordSaved`; on delete the delete guard
   before, sums and `RecordDeleted` after;
6. logs on the main connection (`activity_log`, with `causer` and origin `via` from `WriteOptions::origin`);
7. commits the data, then the main connection. If a phase fails, both roll back, including the log, notifications
   and plugin system tables.

Nested calls (sums, plugins in events, `batch()`) run in the same transaction; `WriteContext` holds the active
options per process. **Effects on the outside** (e-mail, webhooks, notifications to people) are registered with
`WriteContext::afterCommit()` or `Saft::afterCommit()` and run only after the commit of the outermost operation; a
rolled-back operation leaves no e-mail behind.

`WriteOptions` says who writes (`ui`, `plugin`, `import`, `copy`, `document`, `recompute`, `seed`, `work`, `direct`),
whether plugin events and log entries per record are wanted, which version is expected and whether the person
overwrites deliberately ("Save anyway", recorded in the log). `DynamicRecord::save()`/`delete()` outside the writer
**forward to it**, so that old code, seeders and plugins still get the transaction; application code calls the writer
explicitly, because only then are the options visible (architecture test 3).

Structure changes follow the same idea on the metadata level: `DynamicSchemaService` is the only way to create,
change and remove tables and fields; it takes a lock per table, checks existing values (required, type change,
uniqueness, table rules), writes metadata and DDL through the dialect and creates the permissions
`data.<table>.<action>` alongside.

## 6. Reading and permissions

**Permissions** are Spatie permissions, bundled by roles. There are three families:

| Family | Pattern | Assignment |
|---|---|---|
| System resources | `<prefix>.<action>` with the actions `view_any`, `view`, `create`, `update`, `delete` and more, e.g. `users.update`, `work.view_any` | migrations; policies under `app/Policies` map Filament abilities onto them |
| Records of a designer table | `data.<table>.<action>` with the actions `view`, `create`, `update`, `delete`, `export`, `import` | created and removed with the table (`DataPermissions`); checked through `DataAccess::allows()` |
| Plugins | `<plugin-id>.<action>` | declared by the plugin (`SaftPlugin::permissions()`) |

The **super admin** is the system role `super-admin`; whether a person has it is decided solely by
`User::isSuperAdmin()` (queries: `User::superAdmins()`). It receives all permissions through `Gate::after`; a policy
that explicitly returns `false` applies to it as well. An architecture test forbids any other detection by role name,
because "view as role" (`RoleSimulation`) would otherwise have gaps: there an administrator sees the interface with
the permissions of a role, and every check must see the simulated roles, including in the background.

**Apps** are the business bracket (concept [`APPS.md`](concept/APPS.md)): every table, menu, report, role and task
belongs to exactly one app. The app determines what a person sees (navigation, dashboard, lists, AI context); access
is still governed by permissions. Roles belong to an app, except the system roles `super-admin` and `benutzer`;
whoever has a role of an app sees the app (`CurrentApp`). References between apps exist only through **explicitly
shared interfaces** (`AppInterfaces`): single tables with selected fields, read-only, and as long as another app uses
them, a contract (no deleting, renaming, withdrawing). An app can be exported as a definition `saft-app/1`, installed
as a package and updated through a change plan (three-way comparison, classified safe/delicate/destructive).

**Reading** records is protected today at the level of table and action (`data.<table>.view`). Row-level access
(concept [`ROW_ACCESS.md`](concept/ROW_ACCESS.md), B-020) will be added as a global scope on `DynamicRecord`. In
preparation, every read site is listed in `tests/Fixtures/read-sites.json` with a classification: `person` reads on
behalf of the signed-in person (data view, detail tables, choosing links, documents, export, files, plugin API with
authorization), `system` implements SAFT's own rules and must see all records (sums, uniqueness, sequential numbers,
delete guard, snapshots, recomputation), `none` does not read. An architecture test keeps the list complete.

## 7. Extension points

Everything that extends an installation hangs on a named contract and is registered in a configuration list or by
the plugin. Core classes are created through the container.

| Extension | Contract | Registration |
|---|---|---|
| **Plugin** | `App\Plugins\SaftPlugin` (permissions, menu suggestions, required tables, pages, resources, widgets, manuals) | Composer package under `plugins/` or from its own repository with a `PluginServiceProvider`; dormant when dependencies are missing |
| **Plugin API** | `App\Plugins\Api\*` (`Saft`, `DataTable`, events, `RecordListener`, `DataAction`, `FormulaFunction`, `FieldRule`, `DashboardWidget`, `Manual`) and the contracts marked `@api` | `ApiManifest` lists the guaranteed classes; `PluginApiContractTest` compares signatures with `plugin-api.json`; own version number (1.10), plugins state the version they require |
| **Scheduled task type** | `App\Tasks\TaskType` (`key`, parameter fields, `run()` without a person) | `config/tasks.php` or `SaftPlugin::taskTypes()` |
| **Work type** | `App\Work\WorkType` (`prepare()`, `step()`), optionally `WritesRecords`, `CleansUp`, `LongSteps`, `DescribesProgress` | `config/work.php` or `SaftPlugin::workTypes()`; started with `WorkService` or `Saft::startWork()` |
| **Formula function** | `App\Plugins\Api\FormulaFunction` (name, arguments, evaluation in PHP; blocked for SQL when not translatable) | `SaftPlugin::formulaFunctions()` |
| **Field validation rule** | `App\Plugins\Api\FieldRule` | `SaftPlugin::fieldRules()` |
| **Action in the data view** | `App\Plugins\Api\DataAction` | `SaftPlugin::dataActions()` |
| **Reaction to changes** | `RecordListener` for `RecordSaving`/`RecordSaved`/`RecordDeleting`/`RecordDeleted`, inside the transaction; `RecordRejected` rejects | `SaftPlugin::recordListeners()` |
| **Database dialect** | `App\DynamicData\Ddl\DdlDialect` (structure) and `App\Reports\Dialects\QueryDialect` (reports) | `config/dynamic_data.php` `dialects`, `QueryDialectResolver` |
| **External database** | `App\ExternalData\ConnectionDriver` with schema inspection and type mapping (PostgreSQL, MySQL/MariaDB, SQL Server) | `ConnectionRegistry` |
| **SSO** | `App\Auth\Sso\SsoDriver` (OIDC, SAML) | `SsoDriverRegistry` |
| **AI provider** | `App\Ai\ChatClient` | `AiConfig` |

Plugins use the API only: records through `Saft::table()` (`DataTable`, with authorization, `withoutAuthorization()`
for background work), writing goes through the writer. A plugin's permissions, routes and translations carry its
`id` as prefix. The **developer context** (`saft:plugin-context`) generates `AGENTS.md`, `api-reference.md`,
`schema.md` and `schema.json` for an installation, so that external developers and AI agents work against the
matching API and business tables.

## 8. Background work

SAFT knows two forms of work outside a request, neither requiring a queue worker:

**Scheduled tasks** (`ScheduledTask`, `TaskRunner`) run at points in time: `tasks:run-due` in cron every minute
executes due tasks, logs every run, prevents duplicate runs and notifies the super admins on failure and recovery.
System tasks (backup, pruning of files, snapshots, runs, work items and activity log, demo reset) come out of the
box; plugins contribute types.

**Work items** (`work_items`, concept [`FOUNDATION_2.md`](concept/FOUNDATION_2.md) §6) are long operations of a
person, split into **resumable steps**: `prepare()` determines state and quantity in the first step, after that a
driver calls `step()` until the type reports "finished". The process may change between two steps; everything the
next step needs is in the state (JSON) or in the work item's folder (`storage/app/private/work/<uuid>`). Every step
runs as the starting person in their app, with the simulated roles if started under "view as role". Three drivers
call the same `WorkRunner::advance()` with a time budget and a cache lock per work item:

| Driver | When | Budget |
|---|---|---|
| Browser (`WorkProgress`, polling every 2 s) | while the starting person keeps the page open; `startAndAdvance()` finishes small quantities within the request | `WORK_BROWSER_BUDGET`, `WORK_INLINE_BUDGET` |
| Scheduler (`work:run`, cron every minute) | always; takes over abandoned work items based on the heartbeat | `WORK_SCHEDULER_BUDGET` |
| Queue (`AdvanceWork`) | only with `WORK_DRIVER=queue` and a queue connection other than `sync`; the job re-queues itself with a 2 s pause | `WORK_QUEUE_BUDGET` |

Additions to the contract: `WritesRecords` runs step and progress in one `RecordWriter::batch()` transaction (data
and cursor are one unit), `LongSteps` allows exactly one indivisibly long step per call (AI requests), `CleansUp`
tidies up on failure or cancellation (import takes back created records, snapshot discards the copy),
`DescribesProgress` supplies the progress text. Results (files) are available for download for 24 h; the system task
`work_prune` tidies hourly. Today export, data import, documents, snapshots, recomputation of computed fields and the
AI app designer run as work items.

## 9. Portability and quality assurance

- **Three databases as equals:** PostgreSQL 16, MySQL 8, MariaDB; CI runs the feature tests on all three, the
  browser tests before a release. No dialect-specific raw SQL outside `DynamicData/Ddl`, `Reports/Dialects` and
  `Database` (architecture test 1 with a justified exception list).
- **Static analysis:** Larastan level 5 (`composer analyse`), baseline for the existing code that may only shrink
  (`StaticAnalysisBaselineTest`); own CI job before the tests. Target level 7 by 1.0.
- **Architecture tests** (`tests/Unit/ArchitectureTest.php`): raw SQL, super admin detection, write path, read
  sites. Also `MigrationNamingTest` (one gapless number sequence across `database/migrations` and
  `database/settings`), `PluginApiContractTest` (API contract), `FormulaSpecTest` and `FormulaConformanceTest`
  (formula language), `ManualStructureTest` (manuals and this document: anchors, same outline per language).
- **Code style:** Pint with preset `laravel` and `declare_strict_types`; run Pint after `code:models`, because Reliese
  allows no custom template.
- **Tests:** suites `Unit`, `Feature`, `Plugins` (PHPUnit, one configuration per database), browser tests with Dusk
  against a dedicated database (`SAFT2_dusk`), test list `docs/test/SAFT_Manuelle_Testfaelle.xlsx`. A
  `TestDatabaseGuard` prevents runs against the development database.
- **Security:** security headers, 2FA, HIBP check, encrypted credentials with masked log, SAML replay protection,
  host and name patterns against DSN injection, "read only" at database level; reports via `SECURITY.md`.

## 10. Decisions

Numbered architecture decisions with date, decision, reason and consequences. What applied before the architecture
review of 23 November 2026 is marked "since the beginning" (November 2026); the concepts under `docs/concept/`
contain the discussion. New decisions are appended here, not rewritten; a superseded one is marked "superseded by
no. n".

1. **Metadata and data separated** (since the beginning). System tables and table designer metadata live on the main
   connection, the physical tables in the `app_data` namespace with its own connection. *Why:* a dedicated database
   user with rights only on `app_data`, backup and verification per namespace, separate databases possible at
   hosting providers. *Consequence:* no distributed transaction (no. 10).
2. **Base-model pattern** (since the beginning). System tables as `Models/Base/*` (Reliese, generated from the
   schema) and `Models/*` (business logic). Designer tables have no model of their own but the generic
   `DynamicRecord` with a registry. *Why:* schema updates never overwrite logic; tables come into being at runtime.
3. **Portability through dialect classes** (since the beginning, test since 23 November 2026). Eloquent and query
   builder everywhere; dialect-specific SQL only in `DynamicData/Ddl`, `Reports/Dialects`, `Database`. *Why:* three
   equal databases, CI on all of them; metadata-driven systems do not tolerate creeping dialect.
4. **Operation without a queue worker as the normal case** (since the beginning; work items 24 November 2026).
   Shared hosting with cron is enough; worker and Redis are optional. *Why:* the target group is small installations.
   *Consequence:* background work as resumable steps with interchangeable drivers (no. 19).
5. **Apps as the business bracket** (B-107). Every object belongs to exactly one app; roles per app; access derived
   from roles; references between apps only through shared interfaces, read-only; table names remain globally
   unique. *Why:* several business applications in one installation without mutual interference; packages and AI
   need a clear boundary.
6. **The database is authoritative, the definition is generated** (B-107). `saft-app/1` and `saft-schema/1` are
   exports, not sources; updating runs through a change plan with three-way comparison and classification instead
   of overwriting. *Why:* no second truth; make destructive changes visible.
7. **Plugin API as a contract** (since the beginning). Guaranteed classes in the `ApiManifest`, contract test against
   `plugin-api.json`, own version number, required tables as a contract, plugins dormant when dependencies are
   missing. *Why:* plugins by external developers and AI agents must be able to rely on signatures.
8. **Permission names bound to the table name** (23 November 2026). `data.<table>.<action>` stays; renaming tables
   remains excluded. *Why:* readable in the log, plugins name tables by name (`requiredTables`); a migration to
   stable keys would cost effort without benefit as long as renaming is not allowed.
9. **Super admin only through `User::isSuperAdmin()`** (23 November 2026). No `hasRole()` and no comparison of
   role names elsewhere; justified exception `RoleSimulation::canSimulate()`. *Why:* "view as role" must apply
   everywhere; architecture test 2.
10. **One transaction on each connection, data first** (23 November 2026). The writer opens transactions on the data
    connection and the main connection, commits the data, then the main connection; if a phase fails, both roll
    back. *Why:* log, notifications and plugin system tables belong to the operation; there is no distributed
    transaction. *Residual risk (accepted):* data committed, commit of the main connection fails, log entry missing.
11. **`RecordWriter` as the only write path** (23 November 2026). Interface, import, plugin API, copying, documents
    and work items call it with `WriteOptions`; `DynamicRecord::save()`/`delete()` forward instead of throwing.
    *Why:* rule engine (B-142), row-level access (B-020) and number ranges (B-135) presuppose an atomic write unit;
    forwarding instead of throwing keeps existing code, seeders and plugins running. Architecture test 3.
12. **Activity log stays on the main connection** (23 November 2026). No second log table in `app_data`. *Why:* one
    log for system and data, Spatie standard; the transaction from no. 10 covers it.
13. **Optimistic locking** (23 November 2026). Version from `updated_at` (designer tables) or from the original
    values read (connected tables without timestamps), checked with `lockForUpdate()` in the writer; message
    "changed in the meantime by …" with "Reload" and "Save anyway" (overwriting is recorded in the log); also for the
    edit pages of the administration. *Why:* no pessimistic locks on shared hosting without Redis; both buttons
    because users need the choice.
14. **Formula language as a specification with a conformance table** (23 November 2026). `FORMULA_SPEC.md`,
    conformance cases as JSON against PHP evaluation and SQL translation on three databases; PHP is the reference,
    deviating SQL is adjusted or the function is blocked for SQL. *Why:* the same formula as a field and as a report
    column must yield the same result; row-level access will become a third evaluation path.
15. **Larastan level 5 with a shrinking baseline** (23 November 2026). Start at level 5, baseline for the existing
    code, a test checks that it only gets smaller; target level 7 by 1.0. *Why:* with AI-assisted development
    reviewing is the bottleneck; level 6 would have produced too large a baseline at the start.
16. **Migrations numbered sequentially, not squashed** (23 November 2026). `0001_01_01_0000NN_<name>.php`, one
    number sequence across `database/migrations` and `database/settings`; `make:migration` assigns the number. *Why:*
    date prefixes from the future broke the order; squashing would carry more risk than benefit with three databases
    and data migrations. *Consequence:* installations before 0.6.0 are set up afresh (there are no productive ones).
17. **`strict_types` through Pint** (23 November 2026). Rule `declare_strict_types` in `pint.json`, also for the base
    models. *Why:* Reliese 1.x allows no custom template.
18. **Pruning the activity log as a task, deletion concept as its own undertaking** (23 November 2026). Task type
    `activity_prune` (days per log name, default 365, sign-ins 90), inactive out of the box with a hint on the
    dashboard. *Why:* the log grew without limit; retention per table needs business decisions per app.
19. **Work items: one system table, resumable steps, three drivers** (24 November 2026). `work_items` with
    parameters, state (cursor), progress, heartbeat and cancel mark; `prepare()` in the first step; the budget belongs
    to the driver, the step sees `remaining()`; no status "stuck" (the next driver takes over based on the heartbeat,
    after three takeovers without progress the work item counts as failed); every step runs as the starting person
    with their simulated roles. *Why:* one model instead of one per feature; export, import, documents, snapshots,
    designer and later runs, outbox and backup build on it.
20. **Writing work items: step and progress in one transaction** (24 November 2026). `WritesRecords` runs the step
    in `RecordWriter::batch()` and stores the cursor in the same transaction of the main connection. *Why:* after an
    abort no block may be written twice or halfway. *Residual risk* as in no. 10: the block runs once more.
21. **Import in blocks with rollback** (24 November 2026). Check phase over the whole file, then blocks with their
    own transaction; created IDs are recorded, `CleansUp::abort()` takes them back on failure or cancellation. *Why:*
    one transaction over everything would hold long locks on MySQL, a staging table would be more effort; behaviour
    remains "all or nothing".
22. **Limits with defaults instead of prohibitions** (24 November 2026). Export 500,000 rows, import 100,000,
    documents unlimited, snapshots 1,000,000; `0` lifts a limit. *Why:* protect disk space under
    `storage/app/private/work` without restricting installations with their own server.
23. **Queue driver only on request** (24 November 2026). The job `AdvanceWork` is active with `WORK_DRIVER=queue`
    and a queue connection other than `sync`; otherwise browser and scheduler drive. *Why:* an accidentally
    configured worker must not change behaviour; whoever has a worker gets shorter pauses and relieves the browser.
24. **Read-site inventory** (24 November 2026). Every read site through `DynamicRecord::forTable()`/`forTableName()`
    is listed in `tests/Fixtures/read-sites.json` with reason and classification `person`/`system`/`none`;
    architecture test 4. *Why:* the row-level access scope (B-020) must know where it applies and where it is
    explicitly switched off; a forgotten site would be a data leak.
25. **Metadata once per request, query budget in tests** (26 November 2026). Tables with their fields, a table's
    details, dependent computed fields, document templates and the person's column selection are read by the core
    through the scoped service `CustomTableLookup` (`byName()`, `remember()`); saving or deleting a `CustomTable`,
    `CustomField`, `ReportLink` or `DocumentTemplate` clears it. `CurrentApp` is scoped as well. `QueryBudgetTest`
    sets an upper limit of queries for the page and the click of the master-detail view (warehouse demo). *Why:* on
    shared hosting every query costs a round trip to the database host (Strato about 2.2 ms); the cascade queried the
    same metadata again for each detail table (B-150: click 58 → 32, page 101 → 47 queries). *Consequence:* whoever
    writes metadata bypassing the model (query builder) must call `CustomTableLookup::forget()`; records are
    deliberately not cached.
26. **Number sequence counters next to the data** (26 November 2026, B-135). Field type "number sequence": the before
    phase of the `RecordWriter` assigns the number on create from the table `saft_number_sequences` (field, period,
    group, last value; primary key over the three columns) in the `app_data` namespace, locked in the same transaction
    as the record; no write path changes an assigned number (origins `import` and `seed` may bring numbers and raise the
    counter). `NumberSequences::ensureTable()` creates the table with the first field of this type; the prefix `saft_`
    is reserved for designer tables and skipped by `app-data:verify`. *Why:* on the main connection a failure between
    the two commits (no. 10) could leave a record with a number but without a raised counter; every further assignment
    would then fail on uniqueness. A migration would be lost with the namespace on `migrate:fresh`. A missing counter
    row is created by an upsert instead of after a `SELECT … FOR UPDATE`, whose gap lock caused deadlocks on MySQL
    with concurrent creation. *Consequence:* no foreign key to `custom_fields`; `DynamicSchemaService` and the demo data
    reset delete counters themselves; `NumberSequenceConcurrencyTest` checks parallel processes on all three databases.
