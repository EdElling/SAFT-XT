# SAFT XT – Developer guide: plugins

**English** · [Deutsch](DEVELOPER_GUIDE.de.md)

**Version:** 0.1 · **Audience:** programmers who extend SAFT with their own pages and functions

**Other guides:** [Administrator guide](ADMIN_GUIDE.md) · [System administration](SYSADMIN_GUIDE.md) · [Installation on a development machine](INSTALL_DEVELOPMENT.md)

---

## Contents

- [1. Overview](#1-overview)
- [2. Developing your own applications on SAFT](#2-developing-your-own-applications-on-saft)
- [3. Getting started](#3-getting-started)
- [4. Structure of a plugin](#4-structure-of-a-plugin)
- [5. The plugin class](#5-the-plugin-class)
- [6. Pages, resources and widgets](#6-pages-resources-and-widgets)
- [7. Permissions and menu](#7-permissions-and-menu)
- [8. Translations, views and own tables](#8-translations-views-and-own-tables)
- [9. Accessing data](#9-accessing-data)
- [10. Reacting to changes (events)](#10-reacting-to-changes-events)
- [11. Actions in data views](#11-actions-in-data-views)
- [12. Formula functions and validation rules](#12-formula-functions-and-validation-rules)
- [13. Installing, updating, removing](#13-installing-updating-removing)
- [14. Testing](#14-testing)
- [15. Context for external development and AI agents](#15-context-for-external-development-and-ai-agents)
- [16. Security](#16-security)
- [17. Examples: stock transfer, stock board and Swiss checks](#17-examples-stock-transfer-stock-board-and-swiss-checks)
- [18. API versions and change log](#18-api-versions-and-change-log)
- [Appendix A – Step-by-step guides](#appendix-a--step-by-step-guides)

---

## 1. Overview

SAFT covers the common tasks of a business application through configuration: tables in the table designer,
permissions, menus, reports, import and export. Anything beyond that – a specialised entry form, a process spanning
several tables, business rules on saving – is added as a **plugin**, without changing the core of SAFT. SAFT updates
therefore remain free of conflicts.

A plugin is an ordinary **Composer package** with a plugin class and a service provider. It can bring:

| What | How |
|---|---|
| Pages, resources, widgets | Filament classes under `src/Pages`, `src/Resources`, `src/Widgets` – discovered automatically |
| Permissions | in the plugin class; SAFT creates them after every `migrate`, they appear in role management |
| Menu entries | as suggestions; the administration adopts them on the **Plugins** page with one click |
| Access to table designer tables | `Saft::table(…)` – with permissions, validation rules and activity log |
| Business rules on saving and deleting | listeners for events; they can reject changes |
| Buttons in data views | above the table or per row |
| Dashboard tiles | widgets under `src/Widgets`, labelled through `DashboardWidget` |
| Formula functions | additional functions for computed fields, e.g. `rappen(amount)` |
| Validation rules for fields | selectable in the table designer under “Further checks”, e.g. IBAN |
| Translations, views | under `lang/` and `resources/views/`, in the namespace of the plugin id |
| Own tables | migrations under `database/migrations/` |
| Kinds of scheduled tasks | classes implementing `App\Tasks\TaskType` |

**What is guaranteed:** the programming interface for plugins (plugin API) has its **own version number**,
independent of SAFT – currently **1.8**. Guaranteed are the classes `App\Plugins\SaftPlugin`,
`App\Plugins\PluginServiceProvider`, `App\Plugins\MenuSuggestion`, all classes under `App\Plugins\Api` (except
`ApiManifest`), the test base `App\Plugins\Testing\PluginTestCase`, for kinds of scheduled tasks
`App\Tasks\TaskType`, `App\Tasks\TaskResult` and `App\Tasks\TaskSkipped`, and the trait
`App\Filament\Concerns\LimitsFormWidth`; of `App\Models\DynamicRecord` (records) and
`App\Models\User` only the class as a type with its Eloquent behaviour. Everything stays compatible within an API major
version. All other SAFT classes are internal – plugins do not use them (a test checks this). Details:
[chapter 18](#18-api-versions-and-change-log).

**Step by step:** [Appendix A](#appendix-a--step-by-step-guides) takes the most common tasks – custom table view,
custom form, widget, button in a data view, listener, validation rule, menu suggestion, scheduled task – from an empty
file to the
test, using the example plugin *Lager-Tafel*.

## 2. Developing your own applications on SAFT

A business application on SAFT is built mostly through **configuration**; program code in the form of **plugins** is
only needed for what cannot be configured. This overview shows the way from the requirements to operation and links to
the chapters with the details. Work happens in two installations: the application is built and tried out in the
**test system** and runs in the **production system**.

1. **Set up a development and test system.** For plugins a development installation (Git clone with dev packages, see
   [Installation on a development machine](INSTALL_DEVELOPMENT.md)); for acceptance by the business department a test
   system set up like the later production system ([Installation on a production server](INSTALL_PRODUCTION.md)).

2. **Define the table structure.** The requirements analysis turns into tables in the
   [table designer](ADMIN_GUIDE.md#4-table-designer): fields ([field types](USER_GUIDE.md#appendix-a--field-types)),
   relations and detail tables, required fields and validation rules, table rules, computed fields and defaults when
   creating. Existing lists can be [imported from a file](ADMIN_GUIDE.md#import-a-table-from-a-file) as a table.
   Existing databases are not rebuilt but connected: [database connections](ADMIN_GUIDE.md#9-database-connections) and
   [table connector](ADMIN_GUIDE.md#10-table-connector).

3. **Set up roles, data permissions and menus.** Roles get data permissions per table (view, create, update, delete,
   export, import) and general permissions ([roles and permissions](ADMIN_GUIDE.md#2-roles-and-permissions)); the
   [menu](ADMIN_GUIDE.md#3-menus) arranges tables and pages in groups.

4. **Configure reports and the dashboard.** Reports are built in the report designer ([reports](USER_GUIDE.md#49-reports)),
   across several tables with [table links](ADMIN_GUIDE.md#11-table-links); pinned, they appear as a tile on the
   [dashboard](USER_GUIDE.md#3-interface-and-dashboard). For connected tables,
   [data snapshots](ADMIN_GUIDE.md#data-snapshots-for-reports) keep reports fast, refreshed by
   [scheduled tasks](ADMIN_GUIDE.md#12-scheduled-tasks). This too is configuration, not a plugin.

5. **Plugins for what configuration does not cover.** Business rules across tables (listeners), custom table views and
   entry forms, buttons in data views, tiles, validation rules, formula functions and kinds of scheduled tasks. Start:
   [getting started](#3-getting-started); every building block step by step in
   [appendix A](#appendix-a--step-by-step-guides). External developers and AI agents receive the installation's rules and
   schema as [developer context](#15-context-for-external-development-and-ai-agents).

6. **Test and refine in the test system.** With sample or existing data ([importing data](USER_GUIDE.md#48-importing-data))
   and with people in the later roles. Plugins are also checked automatically: [tests](#14-testing) on PostgreSQL, MySQL
   and MariaDB as well as `php artisan saft:plugins --check`.

7. **Production system: install plugins, then import the configuration.** The order matters:
   1. Install the plugins ([installing, updating, removing](#13-installing-updating-removing),
      [system administration, chapter 7](SYSADMIN_GUIDE.md#7-plugins)) – with a backup and in maintenance mode.
      Afterwards they are switched on; their permissions exist from now on.
   2. In the test system [export the configuration](ADMIN_GUIDE.md#15-transfer-configuration) and import it in the
      production system: tables, reports, menu entries and roles. The preview reports missing plugins as
      “prerequisite missing”.
   3. On the [Plugins](ADMIN_GUIDE.md#14-plugins) page check that all are *active*, and adopt menu suggestions that did
      not come with the package.

8. **Production system: set up what is not in the package.** Database connections with their credentials, scheduled
   tasks and global settings; create [users](ADMIN_GUIDE.md#1-users) or set up single sign-on
   ([authentication](ADMIN_GUIDE.md#5-authentication),
   [identity providers](ADMIN_GUIDE.md#6-identity-providers-single-sign-on)); import existing data. For operation: the
   scheduler via cron ([scheduled tasks and cron](SYSADMIN_GUIDE.md#4-scheduled-tasks-and-cron)) and
   [backups](INSTALL_PRODUCTION.md#8-backups).

9. **Develop further.** Changes are again made in the test system. The configuration transfer imports **only what is
   new**: entries that already exist in the production system and differ are skipped. Apply changes to existing tables,
   reports or roles in the production system by hand. Plugins are updated with a new version
   ([chapter 13](#13-installing-updating-removing)); whether they fit a new SAFT is shown by the
   [plugin API version](#18-api-versions-and-change-log).

## 3. Getting started

**Requirements:** a development installation of SAFT (Git clone with `composer install` including dev packages, see
[Installation on a development machine](INSTALL_DEVELOPMENT.md)) with a test database, plus knowledge of Laravel,
Filament and Livewire. This guide only describes what SAFT contributes.

**1. Create the scaffold** – in SAFT's main folder:

```bash
php artisan saft:make-plugin customer-portal --label="Customer portal" --vendor=acme --namespace="Acme\CustomerPortal"
```

This creates `plugins/customer-portal/`: `composer.json` with the current plugin API, plugin class, service provider, a
start page with permission and menu suggestion, translations (German, English), a test and a `README.md`. Without
options, label, vendor (`saft`) and namespace are derived from the ID.

**2. Install and try it:**

```bash
composer require acme/customer-portal             # includes plugins/customer-portal (symbolic link)
php artisan saft:plugins                          # creates the permissions
```

Then adopt the menu suggestion under **Administration › Application › Plugins** and grant the permission *Use Customer portal* to a
role (super admins have it anyway). Code changes take effect immediately; run `composer require` again only after
changing the plugin's `composer.json`.

**3. Test and check:**

```bash
vendor/bin/phpunit --testsuite Plugins --filter CustomerPortalTest
php artisan saft:plugins --check customer-portal
```

The check reports invalid settings, missing routes and permissions, unmet dependencies and SAFT classes that are not
part of the plugin API. On errors it ends with a non-zero exit code, so it also fits the plugin's CI.

**4. Own repository:** the `plugins/` folder is ignored in SAFT's repository (except the example plugins). Keep the
plugin in its own Git repository:

```bash
cd plugins/customer-portal && git init && git add . && git commit -m "First version"
```

`composer require` adds the plugin to SAFT's `composer.json` and `composer.lock`. In your development installation that
is intended – but do not commit these two changes to SAFT's repository. For delivery see
[chapter 13](#13-installing-updating-removing).

## 4. Structure of a plugin

```
plugins/lager-umbuchung/
  composer.json
  src/
    LagerUmbuchungPlugin.php            plugin class
    LagerUmbuchungServiceProvider.php   registers the plugin
    Pages/Umbuchung.php                 Filament page
  lang/{de,en,fr,it}/umbuchung.php      translations
  tests/LagerUmbuchungTest.php          tests (test suite "Plugins")
  resources/views/                      (optional)
  database/migrations/                  (optional)
```

The `composer.json` names the service provider (Laravel discovers it automatically) and the required plugin API version:

```json
{
    "name": "saft/lager-umbuchung",
    "description": "Example plugin for SAFT: transfer between storage locations",
    "type": "library",
    "version": "1.2.0",
    "require": { "php": "^8.4", "filament/filament": "^5.0" },
    "autoload": { "psr-4": { "Saft\\LagerUmbuchung\\": "src/" } },
    "autoload-dev": { "psr-4": { "Saft\\LagerUmbuchung\\Tests\\": "tests/" } },
    "extra": {
        "laravel": { "providers": ["Saft\\LagerUmbuchung\\LagerUmbuchungServiceProvider"] },
        "saft": { "api": "^1.0" }
    }
}
```

- `extra.saft.api` is a Composer version constraint for the plugin API (see [chapter 18](#18-api-versions-and-change-log)).
  The **Plugins** page shows whether the plugin fits this installation. Only if a plugin relies on behaviour of a
  specific SAFT version, also state `"requires"` with the SAFT version.
- `filament/filament` in `require`: pages, resources and widgets are Filament classes. With the Filament major version,
  Composer prevents installing the plugin on a SAFT with another Filament major version – the plugin API does not cover
  that.
- `version` appears on the **Plugins** page; for packages from a Git repository it otherwise comes from the tag.
- `autoload-dev` names the namespace of the tests; SAFT loads it when testing (see [chapter 14](#14-testing)).
- You do not need to add SAFT's own packages to `require` – the plugin runs inside SAFT. **Other plugins** whose
  classes you use do belong there, e.g. `"saft/lager-umbuchung": "^1.3"` in the plugin Lager-Tafel. SAFT also reads
  from it which plugins your plugin requires: if one of them is switched off or resting, your plugin rests as well (see
  [chapter 13](#13-installing-updating-removing)).

The service provider is a single method:

```php
final class LagerUmbuchungServiceProvider extends PluginServiceProvider
{
    protected function plugin(): string
    {
        return LagerUmbuchungPlugin::class;
    }
}
```

## 5. The plugin class

```php
final class LagerUmbuchungPlugin extends SaftPlugin
{
    public const string ID = 'lager-umbuchung';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('lager-umbuchung::umbuchung.plugin');
    }

    public function permissions(): array
    {
        return ['execute' => __('lager-umbuchung::umbuchung.permission')];
    }

    public function menuSuggestions(): array
    {
        return [new MenuSuggestion(
            route: Umbuchung::ROUTE,
            label: ['de' => 'Umbuchung', 'en' => 'Transfer', 'fr' => 'Transfert', 'it' => 'Trasferimento'],
            icon: 'heroicon-o-arrows-right-left',
            permission: $this->permissionName('execute'),
            group: ['de' => 'Lager (Demo)', 'en' => 'Warehouse (demo)', 'fr' => 'Entrepôt (démo)', 'it' => 'Magazzino (demo)'],
            sortOrder: 30,
            subgroup: ['de' => 'Lager führen', 'en' => 'Warehouse operations', 'fr' => 'Gestion de l’entrepôt', 'it' => 'Gestione del magazzino'],
        )];
    }
}
```

| Method | Required | Meaning |
|---|---|---|
| `id()` | yes | Unique identifier of lowercase letters, digits and hyphens, e.g. `lager-umbuchung`. Prefix for permissions, translations and views; do not change it after the first installation |
| `label()` | yes | Name in the current language |
| `description()` | no | Short description; taken from `composer.json` if omitted |
| `permissions()` | no | action => label; the permission is named `<id>.<action>` (`permissionName('execute')`) |
| `menuSuggestions()` | no | list of `MenuSuggestion`, see [chapter 7](#7-permissions-and-menu) |
| `taskTypes()` | no | classes of additional kinds of scheduled tasks, see [appendix A.8](#a8-scheduled-tasks) |
| `requiredTables()` | no | tables and fields of the table designer the plugin needs, see [chapter 9](#9-accessing-data) |
| `recordListeners()` | no | listeners for changes to tables, see [chapter 10](#10-reacting-to-changes-events) |
| `dataActions()` | no | buttons in data views, see [chapter 11](#11-actions-in-data-views) |
| `formulaFunctions()` | no | formula functions for computed fields, see [chapter 12](#12-formula-functions-and-validation-rules) |
| `fieldRules()` | no | validation rules for fields, see [chapter 12](#12-formula-functions-and-validation-rules) |
| `manuals()` | no | manuals for online help and help assistant, see [appendix A.9](#a9-shipping-a-manual) |
| `register(Panel)` | no | Default: pages, resources and widgets from the subfolders; override only for special cases and then call `parent::register($panel)` |

## 6. Pages, resources and widgets

Pages, resources and widgets are ordinary Filament classes (Filament 5). Three rules apply to plugins:

1. **Start the address with the id** so that it does not collide with SAFT or other plugins:
   `protected static ?string $slug = 'lager-umbuchung';` (route `filament.admin.pages.lager-umbuchung`) or
   `lager-umbuchung/transfers` for further pages.
2. **Do not add yourself to the navigation:** `protected static bool $shouldRegisterNavigation = false;`. SAFT's menu
   comes from the database; your page gets there through a menu suggestion.
3. **Check access:** `canAccess()` asks for the plugin's permission, e.g.
   `auth()->user()?->can('lager-umbuchung.execute') ?? false`. Check permissions again in every action that changes
   something – Livewire calls can also be triggered without the user interface.

For form pages there is the trait `App\Filament\Concerns\LimitsFormWidth` (1280 px, left-aligned, like SAFT's forms).
Show messages with `Filament\Notifications\Notification`.

Style views of your own with Filament components and a `<style>` block: Tailwind classes from a plugin's views are not
included in SAFT's CSS. Step by step: custom table view in [appendix A.1](#a1-custom-table-view), custom form with drag
and drop in [appendix A.2](#a2-custom-edit-form).

### Widgets

A widget under `src/Widgets` appears as a tile on the **dashboard** – next to SAFT's tiles, movable and hideable. So
that the tile has a label when customising the dashboard, the widget implements `App\Plugins\Api\DashboardWidget`:

```php
final class MinimumStockWidget extends StatsOverviewWidget implements DashboardWidget
{
    protected int|string|array $columnSpan = 1;   // half width; 'full' = wide area below

    public static function dashboardLabel(): string
    {
        return __('lager-umbuchung::umbuchung.widget.label');
    }

    public static function canView(): bool
    {
        return auth()->user()?->can('lager-umbuchung.execute') ?? false;
    }

    protected function getStats(): array { /* data through Saft::table(…) */ }
}
```

- **Who sees the tile** is decided by `canView()`: the plugin's permission, the data permissions if needed
  (`Saft::table('…')->can('view')`) and whether the required tables exist. Read data with permission checks.
- **Width:** `$columnSpan = 1` for one column, `'full'` for the wide area.
- **Only on your own page instead of the dashboard:** `protected static bool $isDiscovered = false;` in the widget and
  `getHeaderWidgets()` or `getFooterWidgets()` on the page.
- Step by step with a chart: [appendix A.3](#a3-widget).


## 7. Permissions and menu

**Permissions** are named `<id>.<action>`. SAFT creates them after every `php artisan migrate` and with
`php artisan saft:plugins` and never deletes them automatically. In role management they appear as
“<plugin name>: <label>”. Roles receive them there like any other permission; the super admin has them automatically.

**Apps (B-107):** if the plugin belongs to an app (all tables from `requiredTables()` in one app), role management
offers its permissions only to roles of that app; roles of other apps see existing permissions only for removal. If the
app is **deactivated**, data permissions on its tables only apply to the super admin (`->can()` otherwise returns
`false`), and scheduled tasks of the app are skipped. An app whose tables an installed plugin needs cannot be removed –
uninstall the plugin first.

If you also need **data permissions** on table designer tables (e.g. “Stock levels: edit”), check them in your code
(see [chapter 9](#9-accessing-data)) and mention them in the plugin description so that the administration grants
them as well.

**Menu suggestions** (`App\Plugins\MenuSuggestion`):

| Parameter | Meaning |
|---|---|
| `route` | route name of the page |
| `label` | label per language (`de`, `en`, `fr`, `it`) |
| `icon` | Heroicon, e.g. `heroicon-o-arrows-right-left` |
| `permission` | permission that makes the entry visible (full name) |
| `group` | menu group per language; found by its German label, otherwise created; `null` = top level |
| `subgroup` | submenu within the group per language (since API 1.7), e.g. "Warehouse operations"; found by its German label, otherwise created; `null` = directly in the group |
| `sortOrder` | order within the group or submenu |

The suggestions are only adopted when the administration chooses “Create menu entries” on the **Plugins** page.
Afterwards they are ordinary menu entries that can be moved and renamed. Step by step:
[appendix A.7](#a7-menu-suggestions).

## 8. Translations, views and own tables

- **Translations** live under `lang/<language>/<file>.php` and are called with `__('<id>::<file>.<key>')`. Provide
  German and English; French and Italian otherwise fall back to English. German texts use Swiss spelling (ss instead
  of ß), like the rest of SAFT.
- **Views** under `resources/views/` are called with `view('<id>::<name>')`.
- **Own tables** are created with migrations under `database/migrations/`; they run with `php artisan migrate`. SAFT's
  rules apply:
  - No dialect-specific SQL – only the schema builder, Eloquent and the query builder (SAFT runs on PostgreSQL, MySQL
    and MariaDB).
  - Models follow the base model pattern: schema definition in `src/Models/Base/`, logic in `src/Models/`.
  - Every business-relevant model logs changes with `Spatie\Activitylog\Traits\LogsActivity` and `logOnlyDirty()`; the
    person making the change is recorded automatically as the causer.
  - Table names with a prefix derived from the id, e.g. `lager_umbuchung_…`.

## 9. Accessing data

Table designer tables have no fixed model. Plugins access them through `App\Plugins\Api\Saft`:

```php
use App\Plugins\Api\Saft;

$stocks = Saft::table('lager_bestaende');                       // InvalidArgumentException if it does not exist
$stocks->query()->where('artikel_id', $id)->get();              // Eloquent query (without the recycle bin)
$stocks->find($id);
$movement = Saft::table('lager_bewegungen')->create(['artikel_id' => $id, 'art' => 'Eingang', 'menge' => 5]);
Saft::table('lager_bewegungen')->update($movement, ['menge' => 4]);
Saft::table('lager_bewegungen')->delete($movement);

Saft::transaction(function (): void { /* several changes: all or nothing */ });
```

| Method | Meaning |
|---|---|
| `Saft::hasTable($name)` | Does the table exist? Check this before showing a page and show a hint otherwise |
| `Saft::table($name)` | table designer table (connected tables read-only) |
| `->label()`, `->name()`, `->fieldNames()` | label, internal name, technical names of the active fields |
| `->query()`, `->find($id)` | reading; records in the recycle bin are excluded |
| `->create($values)`, `->update($record, $values)`, `->delete($record)` | writing; `$record` as model or key |
| `->validate($values, $existing)` | validate without saving |
| `->can('update')`, `->authorize('update')` | data permissions of the signed-in person (`view`, `create`, `update`, `delete`, `export`, `import`) |
| `->formFields(only: [...], except: [...])` | form fields from the table designer for your own forms |
| `->files($record, 'field')`, `->attachFile($record, 'field', $file, $name)`, `->detachFile($record, 'field', $id)` | read, attach and remove files of a field of type “File” (since API 1.8, see below) |
| `->withoutAuthorization()` | copy without permission checks, for background work |
| `Saft::transaction(fn …)` | several changes as one unit |
| `Saft::notify($person, $notification)` | notification in the bell (see below) |

**Permissions:** every method checks the data permissions of the signed-in person and otherwise throws an
`Illuminate\Auth\Access\AuthorizationException`, which SAFT shows as a message. Without a signed-in person (console,
scheduled task) or for deliberate system bookings, switch the check off with `withoutAuthorization()` – only where it
is intended.

**Validation rules:** `create` and `update` check the same rules as data entry in SAFT: required fields, length,
minimum and maximum, e-mail and URL, unique values, valid values of select lists, existing linked records and the
table's rules. Violations throw an `Illuminate\Validation\ValidationException` with messages per field name. There is
deliberately no way to bypass the rules: if a plugin must write values the rules forbid, adapt the rules in the table
designer. Unknown and computed fields are rejected.

**Values:** relations expect the key of the linked record, select lists the stored value (not the label), date and time
a text such as `2026-11-15` or `2026-11-15 10:30:00` or a date object. SAFT sets computed fields, sequences and “copy
from” itself; all changes go to the activity log.

**Connected tables** (table connector) are read-only through the interface.

**Declaring required tables and fields:** columns are named like the technical names in the table designer – if the
administration renames or deletes a field, the plugin stops working. Therefore declare in the plugin class what it
needs:

```php
public function requiredTables(): array
{
    return [
        'lager_bestaende' => ['artikel_id', 'lagerort_id', 'menge'],
        'lager_orte' => ['bezeichnung'],
    ];
}
```

- As long as the plugin is installed, these tables and fields cannot be deleted, deactivated or renamed in the table
  designer, and the fields' type is fixed. The field list marks them with a puzzle icon.
- If something is missing or deactivated (e.g. because the plugin was installed before the warehouse demo), the plugin
  **rests**: its listeners and data actions do not apply, and the **Plugins** page warns with a list of what is missing.
- Your pages check this with `$plugin->missingDependencies()` (list of readable messages, empty = all present) and show
  a hint instead of an error otherwise. The list also names plugins from `require` that are switched off or resting
  (“Plugin Stock transfer is switched off”).
- Only declare what the plugin cannot work without. Check optional fields (in the example *minimum stock*) when
  accessing them – if they are missing, only that function is skipped.

**Form fields for your own forms:** `formFields()` returns Filament fields with label, validation rules, select lists
and relation pickers as defined in the table designer. Each field's name is the technical name; the fields can be
adjusted like other Filament fields:

```php
...array_map(fn ($field) => $field->columnSpanFull(), Saft::table('lager_bewegungen')->formFields(only: ['bemerkung'])),
```

Show errors of the interface in forms as a message, as the field names need not match your form:
`catch (ValidationException $e) { Notification::make()->danger()->body(implode("\n", Arr::flatten($e->errors())))->send(); }`.

**Files from file fields** (since API 1.8): `files()` returns the files of a field as `StoredFile` (ID, name, type,
size, `contents()`, `stream()`, `url()`). `attachFile()` attaches a file – as a path on the server or as an uploaded
file – and checks file kind (by content), maximum size and number like the form; violations come as
`ValidationException`. `detachFile()` removes a file and deletes it. Both need the **update** permission and are
recorded in the activity log. File fields cannot be set through `create()` and `update()`:

```php
$table = Saft::table('lager_lieferanten');
$file = $table->attachFile($supplier, 'vertrag', storage_path('app/import/contract-17.pdf'), 'Framework contract.pdf');
foreach ($table->files($supplier, 'vertrag') as $existing) {
    $content = $existing->contents();
}
$table->detachFile($supplier, 'vertrag', $file->id);
```

**Notifications in the bell** are sent with `Saft::notify($person, Notification::make()->…)`. Filament's own
`sendToDatabase()` puts them in a queue that is never processed without a queue worker.

## 10. Reacting to changes (events)

For every change to a record of a table designer table – through the data view, the import, the interface or a
scheduled task – SAFT fires events (`App\Plugins\Api\Events`):

| Event | When | Typical use |
|---|---|---|
| `RecordSaving` | before saving, after the defaults (sequence, copy from) and **before** computed fields | complete or correct values, reject the change |
| `RecordSaved` | after saving | follow-up bookings in other tables, notifications |
| `RecordDeleting` | before deleting (also to the recycle bin) | reject deletion |
| `RecordDeleted` | after deleting | reverse follow-up bookings |

A plugin registers its listeners in the plugin class; they only run for the given table:

```php
public function recordListeners(): array
{
    return [
        new RecordListener('lager_bewegungen', RecordSaving::class, CheckStock::class),       // class with __invoke
        new RecordListener('lager_bestaende', RecordSaved::class, fn (RecordSaved $e) => …),  // or closure
    ];
}
```

The event provides `$event->table` (internal name), `$event->record` (the record), `$event->value('field')`,
`$event->previous('field')` (value before the change) and, for `RecordSaving`/`RecordSaved`, `$event->creating()` (new
or changed).

- **Set values** in `RecordSaving`: `$event->record->setAttribute('menge', -abs($quantity))`. Computed fields see the new
  value.
- **Reject** in `RecordSaving` or `RecordDeleting`: `throw new RecordRejected('Message for display');`. SAFT saves
  nothing and shows “Action not possible” with your message; in an import it appears as an error of the row and the
  whole import is not carried out.
- **Follow-up changes** in `RecordSaved` and `RecordDeleted` are written through `Saft::table(…)`. They run in the same
  transaction if the triggering change is in one; if you trigger events yourself, beware of endless loops.
- **Demo data:** while demo data is being loaded (`db:seed --class=…DemoSeeder`), events for plugins are switched off –
  your rules do not apply there.
- **Deleting related records** is rejected by SAFT itself as long as records of other tables refer to them – no
  listener is needed for that.
- On the **Plugins** page the administration sees which tables a plugin listens to.
- Step by step, with setting values and rejecting: [appendix A.5](#a5-listeners).

## 11. Actions in data views

Buttons in the data view of a table (also in detail tables) are registered by the plugin class:

```php
public function dataActions(): array
{
    return [
        new DataAction('lager_bestaende', DataAction::RECORD, fn (DataTable $table): Action => Action::make('lagerUmbuchung')
            ->label('Transfer')
            ->icon('heroicon-o-arrows-right-left')
            ->iconButton()
            ->visible(fn (): bool => Umbuchung::canAccess())
            ->disabled(fn (DynamicRecord $record): bool => (int) $record->getAttribute('menge') <= 0)
            ->url(fn (DynamicRecord $record): string => Umbuchung::getUrl(['artikel' => $record->getAttribute('artikel_id')]))),
    ];
}
```

- `DataAction::TABLE` puts the button above the table (next to “New” and “Export”), `DataAction::RECORD` in every row
  (next to “Details”); there closures receive the record as `$record`.
- The action is an ordinary Filament action: with `url()` it opens a page, with `action()` and `schema()` a dialog.
  Check visibility and permissions in the action itself.
- Rows without a key of their own (e.g. views) get no row actions.
- Step by step: [appendix A.4](#a4-action-in-an-existing-table-view).

## 12. Formula functions and validation rules

Two building blocks extend the table designer without your plugin needing a page of its own.

**Formula functions** for computed fields come from `formulaFunctions()`:

```php
public function formulaFunctions(): array
{
    return [new FormulaFunction(
        name: 'rappen',
        minArguments: 1,
        maxArguments: 1,
        evaluate: static fn (array $args): string => SwissChecks::roundToFiveRappen($args[0]),
        description: __('ch-pruefungen::pruefungen.functions.rappen'),   // tooltip in the input help
    )];
}
```

- Formulas calculate with decimals: arguments and result are **numbers as text** ("12.35"); calculate exactly
  (`bcmath`), not with `float`. `null` as the result means empty. If an argument is empty, the function is not called –
  the result is empty.
- **Name:** lowercase letters, digits and `_`; built-in functions (`round`, `sum` …) cannot be replaced. If two plugins
  use the same name, the first by ID applies – `saft:plugins --check` reports the conflict.
- The function appears in the formula field's input help under **Functions from plugins**. It is not available in the
  report designer's expressions (these become SQL); computed fields using it can be reported on normally, their value
  is stored.
- If the function throws an exception, the result stays empty and the error is logged – saving does not fail.

**Validation rules** for fields come from `fieldRules()`:

```php
public function fieldRules(): array
{
    return [new FieldRule(
        name: 'iban',
        label: __('ch-pruefungen::pruefungen.rules.iban'),
        types: ['string'],                                    // string, text, integer, decimal
        check: static fn (mixed $value): ?string => SwissChecks::iban((string) $value)
            ? null : __('ch-pruefungen::pruefungen.invalid.iban'),
        description: __('ch-pruefungen::pruefungen.descriptions.iban'),   // what the rule requires (since API 1.6)
    )];
}
```

- The administration selects the rule in the table designer on the field under **Further checks**; it is stored as
  `<plugin-id>.<name>`, e.g. `ch-pruefungen.iban`. Name: lowercase letters, digits and hyphens.
- `check` returns the error message or `null`. Empty values are not checked (that is what "required" is for).
- The rule applies when entering data, when importing and through `Saft::table(…)`.
- `description` (optional) says in one sentence what the rule requires. It appears in the table designer as help text
  for the selected rule, in the data model for the help assistant and in the developer context.
- Step by step: [appendix A.6](#a6-validation-rules).

**If the plugin is missing or resting:** validation rules are skipped, formulas using its functions return empty. The
table designer shows "not available" on the field, and `php artisan saft:plugins --check` lists the affected fields
under **Table designer**.

## 13. Installing, updating, removing

SAFT includes every package under `plugins/` as a Composer repository. Plugins are installed, updated and removed by
the system administration on the console (see [System administration, chapter 7](SYSADMIN_GUIDE.md#7-plugins)):

```bash
composer require saft/lager-umbuchung       # install (folder plugins/lager-umbuchung)
php artisan migrate --force                 # own tables and permissions
php artisan optimize && php artisan filament:optimize
```

For a plugin from its own Git repository, also add it under `repositories` in SAFT's `composer.json`
(`{"type": "vcs", "url": "…"}`). Before delivery: `php artisan saft:plugins --check <id>` without errors, version
increased in `composer.json` or as a Git tag.

After installation, **Administration › Application › Plugins** shows the plugin with version, compatibility and extensions; there the
administration adopts the menu entries and then grants the permissions in role management.

**Switching off:** the administration can switch off an installed plugin on the **Plugins** page, system
administration with `php artisan saft:plugins --disable=<id>` (`--enable=<id>` switches it on again). A switched-off
plugin stays installed but does not take effect: pages, resources and tiles return “not found” (SAFT blocks all Livewire
components in the namespace of the plugin class), menu entries are hidden, listeners, data actions, validation rules
and formula functions rest, scheduled tasks of its kinds are skipped. Plugins that require it through `require` rest as
well. Your plugin does not need to do anything for this. If you need another plugin only optionally, check it with
`Saft::plugin('…')->isEnabled()`; if it is not installed, `Saft::plugin()` throws an `InvalidArgumentException`.

**Removing:** first roll back own tables (`php artisan migrate:rollback --path=…` on the plugin's migrations), then
`composer remove <package>` and `php artisan optimize`. The plugin's menu entries are then hidden automatically;
together with the permissions they can be deleted on the **Plugins** page under **Clean up**. Data the plugin wrote to
table designer tables is kept; its listeners and actions no longer apply.

## 14. Testing

A plugin's tests live in its `tests/` folder and run with SAFT's **Plugins** test suite – on PostgreSQL, MySQL and
MariaDB like SAFT's own tests. SAFT loads the namespace from `autoload-dev` in the plugin's `composer.json`; tests of
plugins that are not installed are skipped.

```bash
vendor/bin/phpunit --testsuite Plugins                           # all plugins
vendor/bin/phpunit --testsuite Plugins --filter CustomerPortal   # only one
vendor/bin/phpunit -c phpunit.mysql.xml --testsuite Plugins      # on MySQL (phpunit.mariadb.xml: MariaDB)
```

Test classes extend `App\Plugins\Testing\PluginTestCase` (part of the plugin API). Every test starts with an empty test
database including roles, permissions, menu and an app “Test app” (B-107): tables, roles and menu groups without an
app end up there. If a test has several apps (e.g. after seeding a demo), pass the app when creating a table
(`DynamicSchemaService::createTable(['app_id' => …, 'name' => …])`); without it SAFT then refuses with
“Please choose an app”.

| Helper | Purpose |
|---|---|
| `$this->actingAsSuperAdmin()` | signs in a person with all permissions |
| `$this->actingWithPermissions(['customer-portal.use', 'data.customers.view'])` | signs in a person with exactly these permissions |
| `$this->createTable('customers', 'Customers', [...])` | creates a table designer table (fields as in the table designer) and returns its `DataTable` |
| `$this->withoutPluginEvents(fn () => …)` | creates test data without listeners reacting – e.g. to bypass your own rules |
| `$this->withoutPlugin('lager-umbuchung', fn () => …)` | switches one plugin off only for the duration of the closure, without a database entry – e.g. to check that your plugin rests when a required plugin is switched off |
| `$this->createTablesFromSchema($path, ['customers'])` | creates tables from the developer context's `schema.json`, see [chapter 15](#15-context-for-external-development-and-ai-agents) |
| `$this->dataView('customers')` | data view as a Livewire test, e.g. for the plugin's buttons (`TestAction::make('…')->table($record)`) |

```php
final class CustomerPortalTest extends PluginTestCase
{
    public function test_start_page_needs_the_permission(): void
    {
        $this->actingWithPermissions([]);
        $this->get(StartPage::getUrl())->assertForbidden();

        $this->actingWithPermissions(['customer-portal.use']);
        $this->get(StartPage::getUrl())->assertOk();
    }
}
```

For forms: `Livewire::test(MyPage::class)->fillForm([...])->call('action')->assertNotified()`. Listeners of an installed
plugin are active in tests.

Example: `plugins/lager-umbuchung/tests/LagerUmbuchungTest.php`.

## 15. Context for external development and AI agents

Whoever writes a plugin for a specific installation – an external company or an AI agent – needs the rules, the plugin
API and the schema of that installation's business tables. SAFT generates this as the **developer context** (in
English, which agents and many developers work with best):

| File | Content |
|---|---|
| `AGENTS.md` | entry point: mandatory rules, environment (versions, languages, databases), commands, definition of done, installed plugins, available formula functions and validation rules |
| `api-reference.md` | every guaranteed class and method with signatures and doc comments – generated from the code, so it always matches the installation |
| `schema.md` | the business tables in compact form: fields, types, select values, relations, formulas, checks, table rules |
| `schema.json` | the same, exact (format `saft-schema/1`), e.g. for `createTablesFromSchema()` |

Records, users and credentials of external connections are never included; connected tables appear with their fields
only. Table and field names may still be confidential.

**Generate:**

```bash
php artisan saft:plugin-context --output=plugins/customer-portal/context   # folder (default: storage/app/plugin-context)
php artisan saft:plugin-context --tables=customers --tables=orders         # only these tables
php artisan saft:plugin-context --zip --output=context.zip                 # as ZIP; --without-external without connected ones
```

In the user interface: **Administration › Application › Plugins › "Download developer context"** (permission *Plugins: manage*) with
a selection of tables – this way the administration of a customer installation passes on the context without granting
access to the database. The download is recorded in the activity log.

**Giving it to an AI agent:** the scaffold (`saft:make-plugin`) puts an `AGENTS.md` into the plugin that points to
`context/`; agents such as Claude Code, Codex or Cursor read it on their own. Put the context into
`plugins/<id>/context/` (in the plugin's `.gitignore`) and start the task with "Read AGENTS.md". If the agent works in the
development installation, it can refresh the context with the command above itself.

**Example prompt** for an agent working in the plugin's folder (`plugins/customer-portal`) with the context in
`context/`:

```text
You are developing the SAFT plugin in this folder. First read AGENTS.md and context/AGENTS.md
and follow the rules stated there. The plugin API is described in context/api-reference.md,
the business tables in context/schema.md.

Task: create a page "Open orders" that lists all orders with status "open" for a selected
customer (tables customers and orders) and sets the status to "done" with a "Done" button.
- Access only with the plugin permission customer-portal.use; data only through Saft::table(…).
- Offer a menu suggestion and declare the required tables and fields in requiredTables().
- Texts in German and English.
- Write tests with PluginTestCase; create the tables with
  createTablesFromSchema(__DIR__.'/../context/schema.json', ['customers', 'orders']).

The task is done when
  vendor/bin/phpunit --testsuite Plugins --filter CustomerPortal
passes and
  php artisan saft:plugins --check customer-portal
reports no errors (both commands in SAFT's main folder). Finally, summarise which files you
changed and what the administration has to set up (adopt the menu suggestion, grant permissions).
```

What works well: describe the task in business terms (which tables, what should happen), name the permissions and
state the acceptance commands as the end. An agent without access to the customer installation works with `context/`
only; it creates the tables for its tests with `createTablesFromSchema()`.

**Testing against another installation's schema:** `createTablesFromSchema()` creates the tables from `schema.json` in
your own development SAFT (in passes, including mutual relations and formulas):

```php
public function test_orders_page(): void
{
    $this->createTablesFromSchema(__DIR__.'/../context/schema.json', ['customers', 'orders']);
    $this->actingWithPermissions(['customer-portal.use', 'data.customers.view', 'data.orders.view']);
    // …
}
```

### App definition (`saft-app/1`)

Since B-107 a whole app can be exported as a definition (**Apps** page › action menu › **Export …**, permission
*Apps: export*). The file describes the app completely and is the common basis for packages, later updates and AI
tools; the database remains authoritative, the definition is generated anew with every export
(`App\Apps\Definition\AppDefinition`).

| Key | Content |
|---|---|
| `format`, `saft`, `api`, `exported_at` | `saft-app/1`, SAFT and API version, time |
| `app` | `key`, name and description per language, icon, prefix, version, manual per language (Markdown) |
| `plugins` | required plugins with installed version |
| `tables` | tables in the input format of the table designer like `saft-schema/1` (fields with options and field rules, table rules, details), plus `key` per table and field |
| `external_tables` | connected tables only as a reference (name, label, name of the connection – without credentials) |
| `report_links`, `reports`, `menus`, `roles`, `tasks` | as in the configuration package, each with `key`; menus with path (“Lager › Artikel”), parents before children |

Every object carries its **stable key** (`key`, UUID), which does not change on renaming. References are given as names
(“bestellungen.kunde_id”, menu path) so that the file stays readable. Not included are records, credentials, system
menus, system roles and system tasks. `App\Apps\Definition\AppDefinitionValidator::errors()` checks a definition
(required data, keys, field types, references within the app) and reports errors with a path, e.g.
`tables[2].fields[5].type: Unknown field type “foo”.`

## 16. Security

A plugin is program code that runs with all of SAFT's rights. The administration should only install plugins from a
trusted source; for your own plugins check at least:

- Every page has `canAccess()`, every changing action checks the permission again.
- Access to tables only through `Saft::table(…)`; `withoutAuthorization()` only where intended, with a comment.
- Input is validated; no unchecked values in queries, file paths or shell calls.
- No raw SQL, no shell calls, no `eval`; files only under `storage/`.
- Listeners only reject what is wrong in business terms – they apply in all data views and in imports.
- Credentials and keys never in code, but in `.env` or encrypted settings.

## 17. Examples: stock transfer, stock board and Swiss checks

Three example plugins ship with SAFT: *Lager-Umbuchung* (stock transfer) shows a business application with a form,
business rules and a tile, *Lager-Tafel* (stock board) is the example for the guides in appendix A, *CH-Prüfungen*
(Swiss checks) shows formula functions and validation rules without pages of its own.

### 17.1 Stock transfer

The plugin `plugins/lager-umbuchung` ships with SAFT and is active in development and demo installations (not in
production with `--no-dev`). It shows every building block on the warehouse demo:

| Building block | File | What it does |
|---|---|---|
| Business form | `src/Pages/Umbuchung.php` | choose an item; “From location” only with stock; quantity at most the stock; note as a field from the table designer; prefilled via `?artikel=…&von=…` |
| Process | `src/StockTransfer.php` | writes two stock movements of the kind “Transfer” (−/+) in one transaction |
| Listener `StockLedger` | `src/StockLedger.php` | every new, changed, deleted or restored stock movement books the stock at the location; issues always negative, receipts always positive; rejects if a stock level would become negative; a stocktaking sets the last count |
| Listener `StockGuard` | `src/StockGuard.php` | stock levels can only be changed through stock movements – creating, editing or deleting them directly is rejected |
| Listener `MinimumStockAlert` | `src/MinimumStockAlert.php` | if an item's total stock falls below its minimum stock, a notification goes to the bell (not for transfers) |
| Row action | `LagerUmbuchungPlugin::dataActions()` | button “Transfer” in every row of *Stock levels*; greyed out without stock |
| Dependencies | `LagerUmbuchungPlugin::requiredTables()` | four tables of the warehouse demo with ten fields; without them the form shows what is missing |

**Permissions:** the page needs the plugin permission *Stock transfer: Perform transfers* and the data permissions
*view* on items and locations, *view*, *create* and *update* on stock levels and *create* on stock movements. The
listener books stock levels without a permission check – whoever only records movements in the data view needs no
permissions on stock levels.

To try it: load the warehouse demo (`php artisan db:seed --class=LagerDemoSeeder`), choose “Create menu entries” under
**Administration › Application › Plugins**, then **Warehouse (demo) › Warehouse operations › Transfer** or the “Transfer” button in **Warehouse (demo) ›
Warehouse operations › Stock levels**. A stock movement “Issue” shows how the stock is booked and – below the minimum stock – the notification
in the bell. The tile **Warehouse: minimum stock** (`src/Widgets/MinimumStockWidget.php`) on the dashboard shows the
items below minimum stock. The user guide (`docs/manual/`) appears as the tab *Stock transfer* in the online help.

### 17.2 Stock board

The plugin `plugins/lager-tafel` (only in development and demo installations) implements every building block from
[appendix A](#appendix-a--step-by-step-guides) once on the warehouse demo. It requires the plugin *Lager-Umbuchung*,
through whose procedure it books.

| Building block | File | What it does |
|---|---|---|
| Table view | `src/Pages/Lagerspiegel.php` | one row per item, one column per location; search, filter “Below minimum stock only” |
| Form with drag and drop | `src/Pages/Lagertafel.php`, `resources/views/lagertafel.blade.php` | drag stock onto another location, quantity in a dialog, booking through `StockTransfer` |
| Tile | `src/Widgets/StockByLocationChart.php` | bar chart: stock by location |
| Buttons | `LagerTafelPlugin::dataActions()` | in the items data view: “Stock matrix” above the table, “Distribute on stock board” per row |
| Listeners | `src/Listeners/` | store the item number in one spelling; locked once stock movements exist |
| Validation rule | `src/ArticleNumber.php`, `LagerTafelPlugin::fieldRules()` | “Item number (warehouse demo)”: one letter, hyphen, four digits |
| Menu suggestions | `LagerTafelPlugin::menuSuggestions()` | stock matrix and stock board in the group *Warehouse (demo)* |
| User guide | `docs/manual/{de,en,fr,it}.md`, `LagerTafelPlugin::manuals()` | tab *Stock board* in the online help, knowledge for the help assistant |
| Scheduled tasks | `src/Tasks/` | “Warehouse: stocktaking reminder” (stock not counted for N days) and “Warehouse: report minimum stock” (bell) |

**Permissions:** *Stock board: View stock matrix* for the stock matrix and the tile, *Stock board: Transfer on the stock
board* for the stock board; plus the data permissions as for Lager-Umbuchung.

### 17.3 Swiss checks

The plugin `plugins/ch-pruefungen` (likewise only in development and demo installations) shows formula functions and
validation rules without pages of its own – so it needs neither permissions nor menu suggestions, and requires no
tables:

| Building block | File | What it does |
|---|---|---|
| Validation rules | `ChPruefungenPlugin::fieldRules()` | AHV number (756.xxxx.xxxx.xx with check digit), IBAN (checksum, CH/LI 21 characters), UID (CHE-xxx.xxx.xxx with check digit), Swiss postal code |
| Formula function | `ChPruefungenPlugin::formulaFunctions()` | `rappen(amount)`: commercial rounding to 5 centimes |
| Check digits | `src/SwissChecks.php` | the algorithms, independent of SAFT – easy to test |
| Translations | `lang/{de,en,fr,it}/pruefungen.php` | labels of the rules, error messages, description of `rappen()` |
| Tests | `tests/ChPruefungenTest.php` | check digits, rules through the interface and in the data view, `rappen` in a computed field |

To try it: in the table designer select “IBAN” under **Further checks** on a text field, or create a computed field
with the formula `rappen(amount * 1.081)`.

## 18. API versions and change log

The plugin API has its own version number **major.minor** (currently **1.8**). It is set in `config/saft.php`
(`api_versions`) and shown under **Documentation › About SAFT XT** and on the **Plugins** page.

| Change | Version | Effect on plugins |
|---|---|---|
| New classes, methods, events, optional parameters | **minor** (1.0 → 1.1) | none – existing plugins keep working |
| Removing or changing a guaranteed method, event or behaviour | **major** (1 → 2) | adapt the plugin |
| Internal SAFT classes, new SAFT versions | none | none, as long as the API version stays the same |

**Rules SAFT follows:**

- Breaking changes only with a new API major version. What is to be removed is first marked as *deprecated* in a minor
  version (`@deprecated` in the code, note in this chapter).
- When API 2 appears, SAFT keeps shipping API 1 for at least twelve months or until the next SAFT major version (both
  versions are then listed in `api_versions`). Plugins with `"api": "^1.0"` count as compatible during that time.
- The guaranteed classes and their public methods, properties and constants, as well as their protected methods (for
  subclasses), are recorded in
  `tests/Feature/Plugins/plugin-api.json`. The test `PluginApiContractTest` fails if any of it changes without a fitting
  increase of the API version, and if a plugin under `plugins/` uses other SAFT classes.

**For plugin developers:** state the lowest minor version whose features you use – e.g. `"api": "^1.1"` if your tests
use `PluginTestCase` – and test your plugin when a new API major version appears, not with every SAFT release. Only use
the guaranteed classes; `php artisan saft:plugins --check` checks this.

### Change log

| API | SAFT | Changes |
|---|---|---|
| **1.8** | 0.3.0 | `DataTable::files()`, `attachFile()` and `detachFile()` plus `StoredFile` – read, attach (with the form's checks) and remove files of fields of type “File” |
| **1.7** | 0.1 | `MenuSuggestion` with optional `subgroup` – menu suggestion into a submenu of the group (third level) |
| **1.6** | 0.1 | `FieldRule` with optional `description`; `SaftPlugin::manuals()` and `Manual` – manuals from plugins in online help and help assistant; `saft:plugins --check` checks them |
| **1.5** | 0.1 | `App\Tasks\TaskType`, `TaskResult` and `TaskSkipped` belong to the API – kinds of scheduled tasks from plugins (`SaftPlugin::taskTypes()`) thus pass `saft:plugins --check` |
| **1.4** | 0.1 | `SaftPlugin::isEnabled()`, `PluginTestCase::withoutPlugin()`. Plugins can be switched off (**Plugins** page, `saft:plugins --disable`); plugins in `require` of `composer.json` count as required – if one is resting or missing, the dependent plugin rests as well |
| **1.3** | 0.1 | `PluginTestCase::createTablesFromSchema()`. Tools (not part of the API): developer context (`saft:plugin-context`, download in plugin administration), `AGENTS.md` in the scaffold |
| **1.2** | 0.1 | `DashboardWidget` (tile label), `FormulaFunction` and `SaftPlugin::formulaFunctions()`, `FieldRule` and `SaftPlugin::fieldRules()` |
| **1.1** | 0.1 | Test base `PluginTestCase` (`actingAsSuperAdmin`, `actingWithPermissions`, `createTable`, `withoutPluginEvents`, `dataView`); protected methods are part of the contract (`PluginServiceProvider::plugin()`). Tools (not part of the API): `saft:make-plugin`, `saft:plugins --check`, test suite "Plugins" |
| **1.0** | 0.1 | First version: `SaftPlugin` (permissions, menu suggestions, task kinds, `requiredTables()`, `recordListeners()`, `dataActions()`), `PluginServiceProvider`, `MenuSuggestion`; `Saft` (`table`, `hasTable`, `plugin`, `transaction`, `notify`), `DataTable` (reading, writing with permissions and validation rules, `formFields`, `withoutAuthorization`), events `RecordSaving`, `RecordSaved`, `RecordDeleting`, `RecordDeleted`, `RecordRejected`, `RecordListener`, `DataAction`; trait `LimitsFormWidth` |

## Appendix A – Step-by-step guides

Chapters 1–18 describe each building block on its own. This appendix takes the nine most common tasks from an empty
file to a tested result. All examples come from the example plugin **Lager-Tafel** (stock board, `plugins/lager-tafel`).
Its code is covered by tests on PostgreSQL, MySQL and MariaDB, so the excerpts here run as shown. Table and field names
are those of the warehouse demo (German, e.g. `lager_artikel` = items, `menge` = quantity).

| Guide | Building block in the plugin | File |
|---|---|---|
| [A.1 Custom table view](#a1-custom-table-view) | Stock matrix: one row per item, one column per location | `src/Pages/Lagerspiegel.php` |
| [A.2 Custom edit form](#a2-custom-edit-form) | Stock board: transfer stock by drag and drop | `src/Pages/Lagertafel.php`, `resources/views/lagertafel.blade.php` |
| [A.3 Widget](#a3-widget) | Tile “Warehouse: stock by location” | `src/Widgets/StockByLocationChart.php` |
| [A.4 Action in an existing table view](#a4-action-in-an-existing-table-view) | Buttons in the items data view | `LagerTafelPlugin::dataActions()` |
| [A.5 Listeners](#a5-listeners) | Normalise and lock the item number | `src/Listeners/` |
| [A.6 Validation rules](#a6-validation-rules) | “Item number (warehouse demo)” | `src/ArticleNumber.php`, `LagerTafelPlugin::fieldRules()` |
| [A.7 Menu suggestions](#a7-menu-suggestions) | Stock matrix and stock board in the menu | `LagerTafelPlugin::menuSuggestions()` |
| [A.8 Scheduled tasks](#a8-scheduled-tasks) | Stocktaking reminder, report minimum stock | `src/Tasks/` |
| [A.9 Shipping a manual](#a9-shipping-a-manual) | User guide in four languages | `docs/manual/`, `LagerTafelPlugin::manuals()` |

**To try it:** load the warehouse demo (`php artisan db:seed --class=LagerDemoSeeder`), choose “Create menu entries”
for *Stock board* under **Administration › Application › Plugins**, then **Warehouse (demo) › Warehouse operations › Stock matrix** or **Stock board**. The
tests: `vendor/bin/phpunit --testsuite Plugins --filter LagerTafelTest`.

### Type-safe programming

Plugins run inside SAFT – otherwise a typo in a table or permission name only shows up in production. All examples
therefore follow the same rules:

- **`declare(strict_types=1);`** in every file, classes **`final`**, properties, parameters and return values **typed**.
- **Names as constants:** the plugin id (`LagerTafelPlugin::ID`), routes (`Lagerspiegel::ROUTE`), tables
  (`Warehouse::ARTICLES`) and the translation prefix (`private const string T = 'lager-tafel::tafel.';`). A wrong name
  is then an IDE error, not an empty result at run time.
- **Array shapes in PHPDoc** where PHP itself has no more precise type:
  `@return array<int, array<int, int>>` or `@return list<array{id: int, label: string, quantity: int}>`.
- **Convert values from records:** `getAttribute()` returns `mixed` – depending on the database, numbers may come as
  strings. Convert right away: `(int) $record->getAttribute('menge')`.
- **Business logic without reference to SAFT** goes into classes of its own (here `ArticleNumber`): easy to test, no
  database needed.
- **Keep read access in one place:** `Warehouse` encapsulates all queries on the warehouse tables. Pages, tile and
  listeners only call its methods; if a field name changes, there is one place to fix.

**Depending on another plugin:** the stock board books through `StockTransfer` of the plugin *Lager-Umbuchung* instead
of copying the procedure. Unlike SAFT itself, another plugin belongs in `require` of `composer.json`:

```json
"require": { "php": "^8.4", "filament/filament": "^5.0", "saft/lager-umbuchung": "^1.3" }
```

If *Lager-Umbuchung* is switched off, Lager-Tafel rests as well: `missingDependencies()` reports “Plugin Stock transfer
is switched off”, and the pages show the hint from A.1. The test for this uses `withoutPlugin()`:

```php
$this->withoutPlugin('lager-umbuchung', function (): void {
    Livewire::withQueryParams(['artikel' => $this->screw])->test(Lagertafel::class)
        ->assertSee('Plugin Lager-Umbuchung ist ausgeschaltet')
        ->assertDontSeeHtml('wire:sort:group-id');
});
```

### A.1 Custom table view

**What for:** SAFT's data view shows a table as it is defined in the table designer. A custom table view is worth it
when rows or columns are formed differently – here the *stock matrix*: one row per item, one column per location with
the stock, plus total and minimum stock. The location columns are created at run time. You **do not need** a custom
view for other columns, filters or sorting of the same table – the administration sets that up in the table designer or
with a report.

**Step 1 – create a page with a table** (`src/Pages/Lagerspiegel.php`). A Filament page with `HasTable` and
`InteractsWithTable`; start the address with the id, do not add it to the navigation yourself, check access with the
plugin's permission:

```php
final class Lagerspiegel extends Page implements HasTable
{
    use InteractsWithTable;

    public const string ROUTE = 'filament.admin.pages.lager-tafel.lagerspiegel';

    private const string T = 'lager-tafel::tafel.matrix.';

    protected static ?string $slug = 'lager-tafel/lagerspiegel';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<int, array<int, int>>|null stock per item and location, loaded once per request */
    private ?array $matrix = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can(LagerTafelPlugin::ID.'.view') ?? false;
    }
```

**Step 2 – a hint instead of an error.** If tables of the warehouse demo or data permissions are missing, the page
shows a hint and no table. `content()` defines what the page shows; `EmbeddedTable` is the table:

```php
public function content(Schema $schema): Schema
{
    if (! $this->warehouse()->available()) {
        return $schema->components([Callout::make(__('lager-tafel::tafel.unavailable_title'))
            ->description(__('lager-tafel::tafel.unavailable').' '.implode('; ', $this->warehouse()->missing()))
            ->warning()]);
    }

    if (! $this->warehouse()->canRead()) {
        return $schema->components([Callout::make(__('lager-tafel::tafel.no_rights'))->danger()]);
    }

    return $schema->components([EmbeddedTable::make()]);
}
```

**Step 3 – query and columns.** The rows come from `Saft::table(…)->query()` – so data permissions apply, and records
in the recycle bin are excluded. Columns that exist in the table are ordinary `TextColumn`s with `searchable()` and
`sortable()`. Computed columns get their value through `state()`; the page loads the stock **once** for all rows
(`$this->matrix`), not one query per row:

```php
public function table(Table $table): Table
{
    $warehouse = $this->warehouse();

    if (! $warehouse->available() || ! $warehouse->canRead()) {
        return $table->records(static fn (): array => []);     // content() shows the hint
    }

    $locationColumns = [];

    foreach ($warehouse->locations() as $id => $label) {
        $locationColumns[] = TextColumn::make('lagerort_'.$id)
            ->label($label)
            ->state(fn (DynamicRecord $record): int => $this->stocks($record)[$id] ?? 0)
            ->numeric()
            ->alignEnd()
            ->color(static fn (int $state): ?string => $state === 0 ? 'gray' : null);
    }

    $hasMinimum = $warehouse->hasMinimum();

    return $table
        ->query(static fn (): Builder => Saft::table(Warehouse::ARTICLES)->query())
        ->defaultSort('artikelnummer')
        ->columns([
            TextColumn::make('artikelnummer')->label(__(self::T.'number'))->searchable()->sortable(),
            TextColumn::make('bezeichnung')->label(__(self::T.'article'))->searchable()->sortable()->wrap(),
            ...$locationColumns,
            TextColumn::make('gesamt')
                ->label(__(self::T.'total'))
                ->state(fn (DynamicRecord $record): int => array_sum($this->stocks($record)))
                ->numeric()->alignEnd()->weight('bold'),
            TextColumn::make('mindestbestand')->label(__(self::T.'minimum'))->numeric()->alignEnd()->sortable()
                ->visible($hasMinimum),
        ])
        // …
}
```

**Step 4 – filters and row actions.** A filter changes the query; here `Warehouse` returns the keys of the items below
minimum stock. The row action opens the stock board (A.2) for the item:

```php
        ->filters([
            Filter::make('unter_mindestbestand')
                ->label(__(self::T.'below_minimum'))
                ->toggle()
                ->visible($hasMinimum)
                ->query(fn (Builder $query): Builder => $query->whereKey($this->warehouse()->belowMinimum())),
        ])
        ->recordActions([
            Action::make('verteilen')
                ->label(__('lager-tafel::tafel.actions.board'))
                ->icon('heroicon-o-view-columns')
                ->iconButton()
                ->visible(static fn (): bool => Lagertafel::canAccess())
                ->url(static fn (DynamicRecord $record): string => Lagertafel::getUrl(['artikel' => $record->getKey()])),
        ]);
```

**Step 5 – add it to the menu and grant permissions:** menu suggestion (A.7), then in role management the permission
*Stock board: View stock matrix* and the data permissions *view* on items, locations and stock levels.

**Testing:**

```php
Livewire::test(Lagerspiegel::class)
    ->assertCanSeeTableRecords([$screw, $nail])
    ->assertTableColumnStateSet('lagerort_'.$north, 10, $screw->getKey())
    ->searchTable('Nagel')
    ->assertCanNotSeeTableRecords([$screw])
    ->searchTable('')
    ->filterTable('unter_mindestbestand')
    ->assertCanSeeTableRecords([$nail]);
```

**Pitfalls:**

- **Searching and sorting** only work for columns that exist in the table. Computed columns (`state()`) can be neither
  searched nor sorted – that would need the values in the database.
- **No query per row:** `state()` runs for every cell. Load additional data once into a property.
- **Relations** show the stored key in the table. If you need the label, load the key → label mapping once (like
  `Warehouse::locations()`) and output it in `state()`.
- `Saft::table()` throws an `AuthorizationException` without read permission. Check `can('view')` first and show a
  hint instead of letting the page fail.

### A.2 Custom edit form

**What for:** SAFT's form edits one record of a table. You need a form of your own when an operation changes several
records or tables at once or should be operated differently. The *stock board* shows one card per location with the
stock of an item. Dragging the stock onto another card asks for the quantity in a dialog; two stock movements are
booked in one transaction.

> A plugin does not replace the standard form of a table. A custom form is always a page of its own, opened by a menu
> entry (A.7) or a button in the data view (A.4).

**Step 1 – page with its own view** (`src/Pages/Lagertafel.php`). Drag and drop needs HTML of its own, hence a view from
the plugin (`view('lager-tafel::lagertafel')` is `resources/views/lagertafel.blade.php`):

```php
final class Lagertafel extends Page
{
    public const string ROUTE = 'filament.admin.pages.lager-tafel.lagertafel';

    private const string T = 'lager-tafel::tafel.board.';

    protected static ?string $slug = 'lager-tafel/lagertafel';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'lager-tafel::lagertafel';

    /** @var array{artikel?: int|string|null} form state (selected item) */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(LagerTafelPlugin::ID.'.transfer') ?? false;
    }

    /** Prefilled from the button “Distribute on stock board” (?artikel=…). */
    public function mount(): void
    {
        $this->form->fill(['artikel' => request()->integer('artikel') ?: null]);
    }
```

**Step 2 – form for the selection.** An ordinary Filament form; `live()` redraws the board as soon as an item is
selected:

```php
public function form(Schema $schema): Schema
{
    return $schema
        ->statePath('data')
        ->components($this->ready() ? [
            Select::make('artikel')
                ->label(__(self::T.'article'))
                ->options(fn (): array => $this->warehouse()->articles())
                ->searchable()
                ->live(),
        ] : []);
}
```

**Step 3 – data for the view.** Public methods of the page can be called in the view as `$this->…()`. `board()` returns
one card per location – also without stock, because you should be able to drag there:

```php
/** @return list<array{id: int, label: string, quantity: int}> */
public function board(): array
{
    if (($article = $this->article()) === null) {
        return [];
    }

    $stocks = $this->warehouse()->stocksOf($article);
    $cards = [];

    foreach ($this->warehouse()->locations() as $id => $label) {
        $cards[] = ['id' => $id, 'label' => $label, 'quantity' => $stocks[$id] ?? 0];
    }

    return $cards;
}
```

**Step 4 – drag and drop in the view.** Livewire 4 includes `wire:sort`; no additional JavaScript is needed. All cards
form one group (`wire:sort:group`) so that you can drag between them. Each card states its identifier
(`wire:sort:group-id`), the draggable element its key (`wire:sort:item`, here the source location):

```blade
<x-filament-panels::page>
    {{ $this->content }}

    @if ($this->article() !== null)
        <div class="lager-tafel-board">
            @foreach ($this->board() as $card)
                <x-filament::section :heading="$card['label']" compact wire:key="lager-tafel-card-{{ $card['id'] }}">
                    <div class="lager-tafel-drop"
                         wire:sort="drop"
                         wire:sort:group="lager-tafel"
                         wire:sort:group-id="{{ $card['id'] }}">
                        @if ($card['quantity'] > 0)
                            <div class="lager-tafel-item"
                                 wire:key="lager-tafel-item-{{ $card['id'] }}-{{ $card['quantity'] }}"
                                 wire:sort:item="{{ $card['id'] }}">
                                <x-filament::badge size="lg" icon="heroicon-m-cube">{{ $card['quantity'] }}</x-filament::badge>
                            </div>
                        @else
                            <span class="lager-tafel-empty" wire:sort:ignore>{{ __('lager-tafel::tafel.board.empty') }}</span>
                        @endif
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
```

**Styling:** SAFT ships the user interface with Filament's precompiled CSS. Tailwind classes from a plugin's views are
**not** included and have no effect. Use Filament components (`<x-filament::section>`, `<x-filament::badge>`,
`<x-filament::button>` …) and for the rest a `<style>` block in the view, prefixed with the plugin id and using
Filament's colour variables (`var(--gray-300)`, `var(--primary-500)`) so that dark mode fits:

```css
.lager-tafel-drop { min-height: 4.5rem; border: 2px dashed var(--gray-300); border-radius: .75rem; /* … */ }
.dark .lager-tafel-drop { border-color: var(--gray-600); }
```

**Step 5 – receive the drop.** On drop, Livewire calls the method named in `wire:sort` with the element's key, the
position and the identifier of the target card. The page does not book yet but opens a dialog:

```php
public function drop(int|string $item, int $position, int|string $target): void
{
    abort_unless(self::canAccess(), 403);                 // Livewire calls can be triggered without the UI too
    [$from, $to] = [(int) $item, (int) $target];

    if ($from === $to || $this->article() === null) {
        return;
    }

    $this->mountAction('transfer', ['from' => $from, 'to' => $to]);
}
```

**Step 6 – dialog and booking.** An action method `transferAction()` belongs to the page automatically. The arguments
from `mountAction()` are in `$arguments`; `maxValue()` prevents too large quantities in the dialog already:

```php
public function transferAction(): Action
{
    return Action::make('transfer')
        ->modalHeading(fn (array $arguments): string => __(self::T.'modal', [
            'from' => $this->warehouse()->locations()[(int) $arguments['from']] ?? '?',
            'to' => $this->warehouse()->locations()[(int) $arguments['to']] ?? '?',
        ]))
        ->modalSubmitActionLabel(__(self::T.'submit'))
        ->fillForm(fn (array $arguments): array => ['menge' => $this->available((int) $arguments['from'])])
        ->schema(fn (array $arguments): array => [
            TextInput::make('menge')
                ->label(__(self::T.'quantity'))
                ->integer()
                ->required()
                ->minValue(1)
                ->maxValue($this->available((int) $arguments['from'])),
        ])
        ->action(function (array $data, array $arguments): void {
            abort_unless(self::canAccess(), 403);
            $this->transfer((int) $arguments['from'], (int) $arguments['to'], (int) $data['menge']);
        });
}
```

The booking itself is done by `StockTransfer` of the plugin *Lager-Umbuchung* (two stock movements in
`Saft::transaction()`, see [chapter 17.1](#171-stock-transfer)). The page shows errors as notifications – including a
listener's rejection (`RecordRejected`) and violations of validation rules (`ValidationException`):

```php
try {
    $transfers->transfer((int) $this->article(), $from, $to, $quantity);
} catch (TransferRejected|RecordRejected $e) {
    Notification::make()->danger()->title($e->getMessage())->send();

    return;
} catch (ValidationException $e) {
    Notification::make()->danger()->title(__(self::T.'invalid'))->body(implode("\n", Arr::flatten($e->errors())))->send();

    return;
}
```

After the action Livewire redraws the page; the board shows the new stock.

**Testing:** a feature test cannot drag, but it can make the call Livewire makes on drop:

```php
Livewire::withQueryParams(['artikel' => $screw])->test(Lagertafel::class)
    ->assertSeeHtml('wire:sort:group-id="'.$south.'"')
    ->call('drop', (string) $north, 0, (string) $south)
    ->assertActionMounted(TestAction::make('transfer')->arguments(['from' => $north, 'to' => $south]))
    ->fillForm(['menge' => 11])
    ->callMountedAction()
    ->assertHasFormErrors(['menge' => 'max'])
    ->fillForm(['menge' => 4])
    ->callMountedAction()
    ->assertNotified();
```

**Pitfalls:**

- **`wire:key` with the content:** the browser moves the dragged element into the target card. A key that contains the
  quantity makes Livewire redraw it cleanly after the booking – also when the dialog is cancelled.
- **Check permissions twice:** in `canAccess()` and in every method that changes something (`drop()`, `action()`).
- **Operation without a mouse:** drag and drop does not work with the keyboard. Offer the same operation as a form as
  well – here the page *Transfer* of the plugin Lager-Umbuchung.
- **Changes to several tables** go into `Saft::transaction()` so that nothing is booked halfway.

### A.3 Widget

**What for:** a tile on the **dashboard** shows a figure or a chart without opening a page. The tile *Warehouse: stock
by location* sums up the stock of all items per location as a bar chart.

**Step 1 – create a class under `src/Widgets`.** SAFT finds it there by itself. Filament offers `StatsOverviewWidget`
(figures), `ChartWidget` (charts with Chart.js) and `TableWidget` (table) as a base. The interface `DashboardWidget`
provides the label for “Customize dashboard”:

```php
final class StockByLocationChart extends ChartWidget implements DashboardWidget
{
    protected static ?int $sort = 51;                     // order when first shown

    protected int|string|array $columnSpan = 1;           // half width; 'full' = wide area at the bottom

    protected ?string $maxHeight = '16rem';

    public static function dashboardLabel(): string
    {
        return __('lager-tafel::tafel.widget.label');
    }
```

**Step 2 – who sees the tile.** `canView()` checks the plugin's permission, whether the warehouse demo is there, and the
data permissions. Without this check the dashboard fails for everyone who lacks a permission:

```php
public static function canView(): bool
{
    $warehouse = app(Warehouse::class);

    return Lagerspiegel::canAccess() && $warehouse->available() && $warehouse->canRead();
}
```

**Step 3 – provide the data.** A `ChartWidget` returns data series and labels in Chart.js format:

```php
protected function getType(): string
{
    return 'bar';
}

/** @return array{datasets: list<array{label: string, data: list<int>}>, labels: list<string>} */
protected function getData(): array
{
    $warehouse = app(Warehouse::class);
    $totals = [];

    foreach ($warehouse->matrix() as $stocks) {
        foreach ($stocks as $location => $quantity) {
            $totals[$location] = ($totals[$location] ?? 0) + $quantity;
        }
    }

    $locations = $warehouse->locations();

    return [
        'datasets' => [[
            'label' => __('lager-tafel::tafel.widget.quantity'),
            'data' => array_values(array_map(static fn (int $id): int => $totals[$id] ?? 0, array_keys($locations))),
        ]],
        'labels' => array_values($locations),
    ];
}
```

**Using it:** after installation the tile appears on the dashboard of everyone for whom `canView()` is true. Everyone
can move or hide it under **Customize dashboard**. The administration does not need a separate permission for the tile
– it follows the plugin's permission.

**Testing:**

```php
$this->assertFalse(StockByLocationChart::canView(), 'Without the warehouse demo.');
$data = (fn (): array => $this->getData())->call(new StockByLocationChart);
$this->assertSame(['Nord', 'Süd'], $data['labels']);
$key = 'plugin:Saft.LagerTafel.Widgets.StockByLocationChart';                 // key on the dashboard
$this->assertSame('Warehouse: stock by location', Livewire::test(Dashboard::class)->instance()->cards()[$key]['label']);
```

**Pitfalls:**

- **Only on your own page:** `protected static bool $isDiscovered = false;` in the widget, `getHeaderWidgets()` or
  `getFooterWidgets()` on the page.
- **Expensive queries** run every time the dashboard is opened. Read all values with one query, not one per bar.

### A.4 Action in an existing table view

**What for:** a button in the data view of a SAFT table leads from there into your plugin – without the administration
having to set anything up. Lager-Tafel puts two buttons into the data view **Items**: *Stock matrix* above the table and
*Distribute on stock board* in every row.

**Step 1 – register the buttons in the plugin class.** `DataAction` names the table, the placement and a closure that
builds a Filament action:

```php
public function dataActions(): array
{
    return [
        new DataAction(Warehouse::ARTICLES, DataAction::TABLE, static fn (): Action => Action::make('lagerTafelSpiegel')
            ->label(__(self::T.'actions.matrix'))
            ->icon('heroicon-o-table-cells')
            ->color('gray')
            ->visible(static fn (): bool => Lagerspiegel::canAccess())
            ->url(static fn (): string => Lagerspiegel::getUrl())),
        new DataAction(Warehouse::ARTICLES, DataAction::RECORD, static fn (): Action => Action::make('lagerTafelVerteilen')
            ->label(__(self::T.'actions.board'))
            ->tooltip(__(self::T.'actions.board'))
            ->icon('heroicon-o-view-columns')
            ->iconButton()
            ->visible(static fn (): bool => Lagertafel::canAccess())
            ->url(static fn (DynamicRecord $record): string => Lagertafel::getUrl(['artikel' => $record->getKey()]))),
    ];
}
```

- `DataAction::TABLE` – above the table, next to “New” and “Export”.
- `DataAction::RECORD` – in every row, next to “Details”; closures receive the record as `DynamicRecord $record`.
- **Action names** with a prefix derived from the id (`lagerTafel…`) so they do not collide with actions of SAFT or
  other plugins.
- **Origin:** SAFT appends "From the plugin …" to the tooltip (your own tooltip stays first). Likewise, pages of the
  plugin carry the label "Plugin: …" below the title and dashboard cards below the card, scheduled task types the
  addition "(plugin …)". The assignment is made via the namespace of the classes; the plugin does not need to do
  anything.

**Step 2 – receive the target.** The page reads the prefill from the address, see `mount()` in A.2.

**Open a dialog instead of a page:** with `schema()` and `action()` instead of `url()` the button opens a dialog in the
data view – e.g. for a short input saved through `Saft::table(…)`. Then check the permission in `action()` as well.

**Testing** with `dataView()` from `PluginTestCase`:

```php
$this->dataView('lager_artikel')
    ->assertActionVisible(TestAction::make('lagerTafelSpiegel')->table())
    ->assertActionHasUrl(TestAction::make('lagerTafelVerteilen')->table($screw), Lagertafel::getUrl(['artikel' => $screw->getKey()]));

$this->actingWithPermissions(['lager-tafel.view', 'data.lager_artikel.view']);
$this->dataView('lager_artikel')->assertActionHidden(TestAction::make('lagerTafelVerteilen')->table($screw));
```

**Pitfalls:**

- **Hide or disable:** `visible()` for buttons a person may never use; `disabled()` for buttons that only do not work
  for this record (like “Transfer” without stock in the plugin Lager-Umbuchung) – that keeps the row buttons aligned.
- **Rows without a key** (e.g. from views) get no row actions.
- **If the plugin is resting** (missing tables or fields), its buttons do not appear.

### A.5 Listeners

**What for:** a listener enforces a business rule that applies **everywhere** records of a table are changed: in the
data view, on import, through the API and in scheduled tasks. A plugin page cannot do this – it only sees its own
input. Typical tasks:

| Task | Event | Example |
|---|---|---|
| Normalise or complete values | `RecordSaving` | item number in capitals |
| Reject a change that would be wrong across tables | `RecordSaving`, `RecordDeleting` | number locked once stock movements exist |
| Follow-up bookings in other tables | `RecordSaved`, `RecordDeleted` | stock movement books the stock (Lager-Umbuchung) |
| Notify | `RecordSaved` | minimum stock undercut (Lager-Umbuchung) |

You **do not need** a listener for checks of a single field (use a validation rule, A.6, or the table designer's rules)
or for deleting related records – SAFT rejects that by itself as long as records of other tables refer to them.

**Step 1 – set values** (`src/Listeners/NormalizeArticleNumber.php`). A listener is a class with `__invoke()` that
receives the event. In `RecordSaving` you change the record directly; computed fields then see the new value:

```php
final class NormalizeArticleNumber
{
    public function __invoke(RecordSaving $event): void
    {
        $number = $event->value('artikelnummer');

        if (is_string($number) && $number !== '') {
            $event->record->setAttribute('artikelnummer', ArticleNumber::normalize($number));
        }
    }
}
```

**Step 2 – reject** (`src/Listeners/LockArticleNumber.php`). `previous()` returns the value before the change,
`creating()` says whether the record is new. A `RecordRejected` stops the save; SAFT shows the message, on import as
an error of the row:

```php
final class LockArticleNumber
{
    private const string MOVEMENTS = 'lager_bewegungen';

    public function __invoke(RecordSaving $event): void
    {
        if ($event->creating() || ! Saft::hasTable(self::MOVEMENTS)) {
            return;
        }

        $before = ArticleNumber::normalize((string) $event->previous('artikelnummer'));
        $after = ArticleNumber::normalize((string) $event->value('artikelnummer'));

        if ($before === $after) {
            return;
        }

        // Read without permission check: the rule also applies to people who may not see stock movements.
        $used = Saft::table(self::MOVEMENTS)->withoutAuthorization()->query()
            ->where('artikel_id', $event->record->getKey())
            ->exists();

        if ($used) {
            throw new RecordRejected(__('lager-tafel::tafel.errors.number_locked', ['number' => $before]));
        }
    }
}
```

**Step 3 – register in the plugin class.** One `RecordListener` per table and event; several listeners for the same
event run in the order given:

```php
public function recordListeners(): array
{
    return [
        new RecordListener(Warehouse::ARTICLES, RecordSaving::class, NormalizeArticleNumber::class),
        new RecordListener(Warehouse::ARTICLES, RecordSaving::class, LockArticleNumber::class),
    ];
}
```

**Using it:** nothing to set up – after installation the listener takes effect immediately, for everyone. On the
**Plugins** page the administration sees which tables a plugin listens to.

**Testing:** listeners of an installed plugin are active in tests. Create test data that would have to break your own
rule with `$this->withoutPluginEvents(fn () => …)`. To test your plugin without the listeners of *one* other plugin,
switch off only that one with `$this->withoutPlugin('<id>', fn () => …)`.

```php
$item = Saft::table('lager_artikel')->create(['artikelnummer' => ' b-2001 ', 'bezeichnung' => 'Dübel']);
$this->assertSame('B-2001', $item->fresh()->getAttribute('artikelnummer'));

$this->expectException(RecordRejected::class);
Saft::table('lager_artikel')->update($screw, ['artikelnummer' => 'A-9999']);   // has stock movements
```

**Pitfalls:**

- **Permissions:** a listener runs with the permissions of the person saving. Where the rule must apply to everyone,
  read or write with `withoutAuthorization()` – and give the reason in a comment.
- **Endless loops:** a listener in `RecordSaved` that writes to the same table triggers itself again. Remember in a
  static property that you are writing (like `StockLedger::$writing` in the plugin Lager-Umbuchung).
- **Only reject what is wrong in business terms:** the rejection hits every data view and every import, even an entire
  import because of one row.
- **Demo data:** while the demo data is loaded, events for plugins are switched off.

### A.6 Validation rules

**What for:** a validation rule checks the value of **one field**, e.g. an IBAN or the format of an item number. Unlike a
listener it only applies where the administration selects it in the table designer – the plugin provides the rule, the
administration decides where it applies.

**Step 1 – the check without reference to SAFT** (`src/ArticleNumber.php`). That way it can be tested without a
database and shared with the listener (A.5):

```php
final class ArticleNumber
{
    private const string PATTERN = '/^[A-Z]-\d{4}$/';

    /** Normalise the spelling: remove spaces, capitals (“ a-1001 ” → “A-1001”). */
    public static function normalize(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', $value) ?? $value);
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, self::normalize($value)) === 1;
    }
}
```

**Step 2 – register the rule in the plugin class.** `check` returns the error message, or `null` if the value is fine.
`types` determines for which field types the rule can be selected (`string`, `text`, `integer`, `decimal`):

```php
public function fieldRules(): array
{
    return [new FieldRule(
        name: 'artikelnummer',
        label: __(self::T.'rules.article_number'),
        types: ['string'],
        check: static fn (mixed $value): ?string => ArticleNumber::isValid((string) $value)
            ? null
            : __(self::T.'rules.article_number_invalid'),
        description: __(self::T.'rules.article_number_description'),   // appears in the table designer
    )];
}
```

**Step 3 – texts in all languages.** Label and message come from `lang/{de,en,fr,it}/tafel.php`. The label appears in
the table designer, the message at the field.

**Using it:** in the **table designer**, select “Item number (warehouse demo)” for the field *Item number* under
**Further checks**. The rule is stored as `lager-tafel.artikelnummer`. It then applies when entering data, on import
and through `Saft::table(…)`; it does not check empty values (that is what “required” is for).

**Testing:** in `createTable()` you select the rule like the table designer does:

```php
$this->createTable('lager_artikel', 'Artikel', [
    ['name' => 'artikelnummer', 'label' => 'Nummer', 'type' => 'string', 'options' => ['length' => 20],
     'validation_rules' => ['checks' => ['lager-tafel.artikelnummer']]],
    // …
]);

$this->expectException(ValidationException::class);
Saft::table('lager_artikel')->create(['artikelnummer' => '1001', 'bezeichnung' => 'Falsch']);
```

**Pitfalls:**

- **Name:** lowercase letters, digits and hyphens; do not change it after the first installation, otherwise the fields
  lose their rule.
- **Keep checking and saving apart:** the rule does not change a value. If a different spelling should be stored, a
  listener does that (A.5). That is why `isValid()` checks the normalised value – “a-1001” passes, “A-1001” is stored.
- **If the plugin is missing or resting,** the rule is skipped; the table designer shows “not available” at the field.

### A.7 Menu suggestions

**What for:** SAFT's menu comes from the database and belongs to the administration. A plugin therefore does not add
itself but **suggests** entries; the administration adopts them with one click and can then move, rename or delete them
like any other entry.

**Step 1 – keep pages out of Filament's navigation:** in every page
`protected static bool $shouldRegisterNavigation = false;` (see A.1 and A.2).

**Step 2 – suggestions in the plugin class:**

```php
public function menuSuggestions(): array
{
    $group = ['de' => 'Lager (Demo)', 'en' => 'Warehouse (demo)', 'fr' => 'Entrepôt (démo)', 'it' => 'Magazzino (demo)'];
    $operations = ['de' => 'Lager führen', 'en' => 'Warehouse operations', 'fr' => 'Gestion de l’entrepôt', 'it' => 'Gestione del magazzino']; // submenu of the warehouse demo (since API 1.7)

    return [
        new MenuSuggestion(
            route: Lagerspiegel::ROUTE,
            label: ['de' => 'Lagerspiegel', 'en' => 'Stock matrix', 'fr' => 'Matrice des stocks', 'it' => 'Matrice delle giacenze'],
            icon: 'heroicon-o-table-cells',
            permission: $this->permissionName('view'),
            group: $group,
            sortOrder: 50,
            subgroup: $operations,
        ),
        new MenuSuggestion(
            route: Lagertafel::ROUTE,
            label: ['de' => 'Lagertafel', 'en' => 'Stock board', 'fr' => 'Tableau des stocks', 'it' => 'Tabellone delle giacenze'],
            icon: 'heroicon-o-view-columns',
            permission: $this->permissionName('transfer'),
            group: $group,
            sortOrder: 40,
            subgroup: $operations,
        ),
    ];
}
```

- **`route`** as a constant of the page – a typo shows up when checking (`saft:plugins --check` reports missing routes).
- **`permission`** through `permissionName()` – the entry only appears for people with this permission.
- **`group`** is found by its German label; if the group already exists (here from the warehouse demo and the plugin
  Lager-Umbuchung), the entries go into it, otherwise SAFT creates it. `null` = top level.
- **`subgroup`** (since API 1.7) puts the entry into a submenu of the group, here “Warehouse operations” of the warehouse
  demo; it is also found by its German label or created. Submenus appear in the sidebar with an arrow ›; a click opens
  their first page and expands them.
- **`sortOrder`** orders within the submenu; here after “Transfer” (30), in the order of the warehouse demo.
- **`label`** in all four languages (`de`, `en`, `fr`, `it`).

**Using it:** under **Administration › Application › Plugins** choose **Create menu entries** for the plugin, then grant the
permissions in **role management**. Adopting a second time creates nothing twice. If the plugin is removed, SAFT hides
its entries; under **Clean up** they can be deleted together with the permissions.

**Testing:**

```php
$suggestions = Saft::plugin(LagerTafelPlugin::ID)->menuSuggestions();
$this->assertSame([Lagerspiegel::ROUTE, Lagertafel::ROUTE], array_map(static fn ($s): string => $s->route, $suggestions));
$this->assertSame(['lager-tafel.view', 'lager-tafel.transfer'], array_map(static fn ($s): ?string => $s->permission, $suggestions));
```

**Pitfalls:**

- **One permission per purpose:** Lager-Tafel separates *view* (stock matrix, tile) and *transfer* (stock board) – so the
  administration can allow reading without allowing bookings.
- **Changing suggestions** does not affect entries already adopted; the administration adopts new suggestions with
  another click.

### A.8 Scheduled tasks

**What for:** a scheduled task does work on a schedule without anyone opening a page – checking, reminding, notifying,
synchronising data. The plugin provides the **kind** of task; the administration creates tasks of that kind under
**Administration › Operations › Scheduled tasks › New**, with parameters and schedule, and can start them right away with “Run now”.
Lager-Tafel brings two kinds:

| Kind | Parameters | Result |
|---|---|---|
| **Warehouse: stocktaking reminder** (`StocktakingReminderTask`) | Not counted for (days), default 90 | stock with goods whose last count is older or missing, in the run log |
| **Warehouse: report minimum stock** (`MinimumStockReportTask`) | – | notification in the bell to all active people with the permission *View stock matrix* when items are below minimum stock |

You **do not need** a task to react to individual changes – that is what listeners are for (A.5).

**Step 1 – create the class** (`src/Tasks/StocktakingReminderTask.php`). A kind implements `App\Tasks\TaskType` (since
plugin API 1.5). SAFT creates the class through the container, so no data belongs in properties:

```php
final readonly class StocktakingReminderTask implements TaskType
{
    private const string T = 'lager-tafel::tafel.tasks.inventory.';

    public const int DEFAULT_DAYS = 90;

    /** Identifier, stored with every task – start it with the plugin id and never change it. */
    public function key(): string
    {
        return 'lager_tafel_inventory_reminder';
    }

    public function label(): string
    {
        return __(self::T.'label');
    }

    /** true: the administration can create tasks of this kind under “New”. */
    public function creatable(): bool
    {
        return true;
    }
```

**Step 2 – parameters.** `parameterFields()` returns Filament fields; SAFT shows them in the task form as soon as the
kind is selected and fills them with their defaults. `describe()` is the short description in the list:

```php
public function parameterFields(): array
{
    return [
        TextInput::make('days')
            ->label(__(self::T.'days'))
            ->helperText(__(self::T.'days_help'))
            ->integer()
            ->minValue(1)
            ->maxValue(3650)
            ->default(self::DEFAULT_DAYS)
            ->required(),
    ];
}

public function describe(array $parameters): string
{
    return __(self::T.'describe', ['days' => $this->days($parameters)]);
}

/** @param  array<string, mixed>  $parameters  values from the form arrive as text */
private function days(array $parameters): int
{
    $days = $parameters['days'] ?? self::DEFAULT_DAYS;

    return is_numeric($days) ? max(1, (int) $days) : self::DEFAULT_DAYS;
}
```

**Step 3 – run.** `run()` runs **without a logged-in person** – through the scheduler or “Run now”. So read without a
permission check (here through `Warehouse::system()`, which uses `withoutAuthorization()`). The result is a
`TaskResult` with a message for the run log and figures; if the task cannot run right now, it throws `TaskSkipped` – the
run then counts as “skipped”, not as an error:

```php
public function run(array $parameters): TaskResult
{
    $warehouse = Warehouse::system();

    if (! $warehouse->available()) {
        throw new TaskSkipped(__('lager-tafel::tafel.tasks.unavailable', ['missing' => implode('; ', $warehouse->missing())]));
    }

    $days = $this->days($parameters);
    $due = $warehouse->stocksNotCountedSince(now()->subDays($days)->toDateString());
    // … build the names “item (location)”

    if ($names === []) {
        return new TaskResult(__(self::T.'none', ['days' => $days]), ['count' => 0]);
    }

    return new TaskResult(trans_choice(self::T.'found', count($names), [/* … */]), ['count' => count($names)]);
}
```

Any other exception makes the run **fail**; the message in the log is then generic, the details are in the application
log, and SAFT notifies the active super admins in the bell.

**Notify instead of only logging** (`MinimumStockReportTask`): the task itself decides who receives a notification –
here all active people with the plugin's read permission (super admins always have it):

```php
$recipients = User::query()->where('is_active', true)->orderBy('id')->get()
    ->filter(static fn (User $user): bool => $user->can(LagerTafelPlugin::ID.'.view'))
    ->values()
    ->all();

foreach ($recipients as $user) {
    Saft::notify($user, Notification::make()
        ->warning()
        ->title(trans_choice(self::T.'title', count($below), ['count' => count($below)]))
        ->body($list)
        ->actions([Action::make('open')->label(__(self::T.'open'))->url(Lagerspiegel::getUrl())]));
}
```

`Saft::notify()` delivers the notification immediately, without a queue worker (see [chapter 9](#9-accessing-data)).

**Step 4 – register in the plugin class:**

```php
public function taskTypes(): array
{
    return [StocktakingReminderTask::class, MinimumStockReportTask::class];
}
```

**Using it:** under **Administration › Operations › Scheduled tasks › New** the kinds appear in “Type”; choose parameters and
schedule, save. SAFT's scheduler (`php artisan schedule:run` via cron, see system administration) starts them at the
chosen time; “Run now” starts them immediately. If the plugin is switched off or resting, its tasks are skipped.

**Testing:** call `run()` directly – without a logged-in person, as in operation:

```php
$this->actingAsSuperAdmin();
$this->demoTables();
auth()->logout();                                          // scheduled tasks run without a logged-in person

$result = (new StocktakingReminderTask)->run(['days' => '30']);
$this->assertSame(['count' => 2], $result->data);

$this->expectException(TaskSkipped::class);                // e.g. without the warehouse demo
```

**Pitfalls:**

- **Identifier (`key()`)**: start it with the plugin id (`lager_tafel_…`) and do not change it after the first
  installation – it is stored with every task created.
- **Parameters arrive as text** from the form and come back as stored values. Convert them in `run()` and allow for
  missing values (older tasks, new parameters).
- **No person's permissions:** `Saft::table(…)` without `withoutAuthorization()` fails in `run()`. In turn, what the task
  writes to the log or sends is seen by someone who may not be allowed to see the data – choose recipients deliberately.
- **Long runs:** a run has no time limit. Process large amounts of data in chunks and keep the result short.

### A.9 Shipping a manual

**What for:** a plugin brings functions that are not in SAFT's manuals. A shipped **user guide** appears as a tab of its
own in the **online help** (with contents and search) and is knowledge for the **help assistant** – it then also answers
questions like “How do I correct a stock level?” from your text and refers to its sections. Lager-Umbuchung and
Lager-Tafel each ship a manual.

**Step 1 – create the files:** one Markdown file per language in a folder of the plugin, here `docs/manual/`:

```
plugins/lager-tafel/docs/manual/
  de.md
  en.md        required – if the interface language is missing, English is shown
  fr.md
  it.md
```

Structure of a file:

```markdown
# Stock board – user guide              ← omitted in the display (the tab carries the title)

## Overview                             ← levels 2 and 3: contents and anchors
…
## Stock matrix
…
```

- Write for the **users**, in the terms of the interface (menu entries, buttons, fields as in the respective language),
  with procedures as numbered steps.
- Name the **permissions** a function needs – the help assistant passes them on.
- Links within the manual point to the anchors of its headings, links to SAFT's manuals to their file and anchor – as
  in SAFT's own manuals.

**Step 2 – register in the plugin class:**

```php
public function manuals(): array
{
    return [new Manual(
        id: 'manual',
        title: ['de' => 'Lager-Tafel', 'en' => 'Stock board', 'fr' => 'Tableau des stocks', 'it' => 'Tabellone delle giacenze'],
        path: 'docs/manual',
        permissions: $this->permissionNames(),      // visible with one of these permissions; [] = for everyone
    )];
}
```

- **`id`**: lowercase letters, digits, hyphens; part of the anchors (`plugin-lager-tafel-manual-stock-matrix`) – do not
  change it after the first delivery, otherwise references in stored answers of the assistant break.
- **`permissions`**: whoever has one of these permissions sees the manual. Lager-Umbuchung lists, besides its own
  permission, the data permissions *view* on stock movements and stock levels – so the manual is also visible to people
  who only record movements.
- If the plugin is resting or switched off, its manual disappears from online help and assistant.
- **App (B-107):** if all tables from `requiredTables()` are in one app, the plugin belongs to it; its manual then only
  appears while that app is the current one. Plugins without tables apply to all apps.

**Step 3 – check:** `php artisan saft:plugins --check lager-tafel` reports “Manual “manual” in de, en, fr, it” or missing
files. Then look at the tab under **Documentation**.

**Testing:**

```php
$this->actingWithPermissions(['lager-tafel.view']);
$this->get(route('filament.admin.pages.documentation'))->assertSee('data-doc-tab="plugin-lager-tafel-manual"', false);
```

**Pitfalls:**

- **Only levels 2 and 3** form the contents; another level 1 in the text appears as a normal heading.
- **Size:** the help assistant sends the manuals a person sees in full. Keep the manual concise and factual – background
  for developers belongs in the plugin's `README.md`.
- **Validation rules** are additionally described with `description:` on `FieldRule`
  ([chapter 12](#12-formula-functions-and-validation-rules)): the description appears in the table designer and in the
  data model, even without a manual.
