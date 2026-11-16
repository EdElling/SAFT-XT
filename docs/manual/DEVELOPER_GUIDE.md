# SAFT XT – Developer guide: plugins

**English** · [Deutsch](DEVELOPER_GUIDE.de.md)

**Version:** 0.1 · **Audience:** programmers who extend SAFT with their own pages and functions

**Other guides:** [Administrator guide](ADMIN_GUIDE.md) · [System administration](SYSADMIN_GUIDE.md) · [Installation on a development machine](INSTALL_DEVELOPMENT.md)

---

## Contents

- [1. Overview](#1-overview)
- [2. Getting started](#2-getting-started)
- [3. Structure of a plugin](#3-structure-of-a-plugin)
- [4. The plugin class](#4-the-plugin-class)
- [5. Pages, resources and widgets](#5-pages-resources-and-widgets)
- [6. Permissions and menu](#6-permissions-and-menu)
- [7. Translations, views and own tables](#7-translations-views-and-own-tables)
- [8. Accessing data](#8-accessing-data)
- [9. Reacting to changes (events)](#9-reacting-to-changes-events)
- [10. Actions in data views](#10-actions-in-data-views)
- [11. Formula functions and validation rules](#11-formula-functions-and-validation-rules)
- [12. Installing, updating, removing](#12-installing-updating-removing)
- [13. Testing](#13-testing)
- [14. Context for external development and AI agents](#14-context-for-external-development-and-ai-agents)
- [15. Security](#15-security)
- [16. Examples: stock transfer and Swiss checks](#16-examples-stock-transfer-and-swiss-checks)
- [17. API versions and change log](#17-api-versions-and-change-log)

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
independent of SAFT – currently **1.3**. Guaranteed are the classes `App\Plugins\SaftPlugin`,
`App\Plugins\PluginServiceProvider`, `App\Plugins\MenuSuggestion`, all classes under `App\Plugins\Api` (except
`ApiManifest`), the test base `App\Plugins\Testing\PluginTestCase` and the trait
`App\Filament\Concerns\LimitsFormWidth`; of `App\Models\DynamicRecord` (records) and
`App\Models\User` only the class as a type with its Eloquent behaviour. Everything stays compatible within an API major
version. All other SAFT classes are internal – plugins do not use them (a test checks this). Details:
[chapter 17](#17-api-versions-and-change-log).

## 2. Getting started

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

Then adopt the menu suggestion under **Administration › Plugins** and grant the permission *Use Customer portal* to a
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
[chapter 12](#12-installing-updating-removing).

## 3. Structure of a plugin

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

- `extra.saft.api` is a Composer version constraint for the plugin API (see [chapter 17](#17-api-versions-and-change-log)).
  The **Plugins** page shows whether the plugin fits this installation. Only if a plugin relies on behaviour of a
  specific SAFT version, also state `"requires"` with the SAFT version.
- `filament/filament` in `require`: pages, resources and widgets are Filament classes. With the Filament major version,
  Composer prevents installing the plugin on a SAFT with another Filament major version – the plugin API does not cover
  that.
- `version` appears on the **Plugins** page; for packages from a Git repository it otherwise comes from the tag.
- `autoload-dev` names the namespace of the tests; SAFT loads it when testing (see [chapter 13](#13-testing)).
- You do not need to add SAFT's own packages to `require` – the plugin runs inside SAFT.

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

## 4. The plugin class

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
| `menuSuggestions()` | no | list of `MenuSuggestion`, see [chapter 6](#6-permissions-and-menu) |
| `taskTypes()` | no | classes of additional kinds of scheduled tasks |
| `requiredTables()` | no | tables and fields of the table designer the plugin needs, see [chapter 8](#8-accessing-data) |
| `recordListeners()` | no | listeners for changes to tables, see [chapter 9](#9-reacting-to-changes-events) |
| `dataActions()` | no | buttons in data views, see [chapter 10](#10-actions-in-data-views) |
| `formulaFunctions()` | no | formula functions for computed fields, see [chapter 11](#11-formula-functions-and-validation-rules) |
| `fieldRules()` | no | validation rules for fields, see [chapter 11](#11-formula-functions-and-validation-rules) |
| `register(Panel)` | no | Default: pages, resources and widgets from the subfolders; override only for special cases and then call `parent::register($panel)` |

## 5. Pages, resources and widgets

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


## 6. Permissions and menu

**Permissions** are named `<id>.<action>`. SAFT creates them after every `php artisan migrate` and with
`php artisan saft:plugins` and never deletes them automatically. In role management they appear as
“<plugin name>: <label>”. Roles receive them there like any other permission; the super admin has them automatically.

If you also need **data permissions** on table designer tables (e.g. “Stock levels: edit”), check them in your code
(see [chapter 8](#8-accessing-data)) and mention them in the plugin description so that the administration grants
them as well.

**Menu suggestions** (`App\Plugins\MenuSuggestion`):

| Parameter | Meaning |
|---|---|
| `route` | route name of the page |
| `label` | label per language (`de`, `en`, `fr`, `it`) |
| `icon` | Heroicon, e.g. `heroicon-o-arrows-right-left` |
| `permission` | permission that makes the entry visible (full name) |
| `group` | menu group per language; found by its German label, otherwise created; `null` = top level |
| `sortOrder` | order within the group |

The suggestions are only adopted when the administration chooses “Create menu entries” on the **Plugins** page.
Afterwards they are ordinary menu entries that can be moved and renamed.

## 7. Translations, views and own tables

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

## 8. Accessing data

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
  a hint instead of an error otherwise.
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

**Notifications in the bell** are sent with `Saft::notify($person, Notification::make()->…)`. Filament's own
`sendToDatabase()` puts them in a queue that is never processed without a queue worker.

## 9. Reacting to changes (events)

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
- On the **Plugins** page the administration sees which tables a plugin listens to.

## 10. Actions in data views

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

## 11. Formula functions and validation rules

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
    )];
}
```

- The administration selects the rule in the table designer on the field under **Further checks**; it is stored as
  `<plugin-id>.<name>`, e.g. `ch-pruefungen.iban`. Name: lowercase letters, digits and hyphens.
- `check` returns the error message or `null`. Empty values are not checked (that is what "required" is for).
- The rule applies when entering data, when importing and through `Saft::table(…)`.

**If the plugin is missing or resting:** validation rules are skipped, formulas using its functions return empty. The
table designer shows "not available" on the field, and `php artisan saft:plugins --check` lists the affected fields
under **Table designer**.

## 12. Installing, updating, removing

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

After installation, **Administration › Plugins** shows the plugin with version, compatibility and extensions; there the
administration adopts the menu entries and then grants the permissions in role management.

**Removing:** first roll back own tables (`php artisan migrate:rollback --path=…` on the plugin's migrations), then
`composer remove <package>` and `php artisan optimize`. The plugin's menu entries are then hidden automatically;
together with the permissions they can be deleted on the **Plugins** page under **Clean up**. Data the plugin wrote to
table designer tables is kept; its listeners and actions no longer apply.

## 13. Testing

A plugin's tests live in its `tests/` folder and run with SAFT's **Plugins** test suite – on PostgreSQL, MySQL and
MariaDB like SAFT's own tests. SAFT loads the namespace from `autoload-dev` in the plugin's `composer.json`; tests of
plugins that are not installed are skipped.

```bash
vendor/bin/phpunit --testsuite Plugins                           # all plugins
vendor/bin/phpunit --testsuite Plugins --filter CustomerPortal   # only one
vendor/bin/phpunit -c phpunit.mysql.xml --testsuite Plugins      # on MySQL (phpunit.mariadb.xml: MariaDB)
```

Test classes extend `App\Plugins\Testing\PluginTestCase` (part of the plugin API). Every test starts with an empty test
database including roles, permissions and menu.

| Helper | Purpose |
|---|---|
| `$this->actingAsSuperAdmin()` | signs in a person with all permissions |
| `$this->actingWithPermissions(['customer-portal.use', 'data.customers.view'])` | signs in a person with exactly these permissions |
| `$this->createTable('customers', 'Customers', [...])` | creates a table designer table (fields as in the table designer) and returns its `DataTable` |
| `$this->withoutPluginEvents(fn () => …)` | creates test data without listeners reacting – e.g. to bypass your own rules |
| `$this->createTablesFromSchema($path, ['customers'])` | creates tables from the developer context's `schema.json`, see [chapter 14](#14-context-for-external-development-and-ai-agents) |
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

## 14. Context for external development and AI agents

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

In the user interface: **Administration › Plugins › "Download developer context"** (permission *Plugins: manage*) with
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

## 15. Security

A plugin is program code that runs with all of SAFT's rights. The administration should only install plugins from a
trusted source; for your own plugins check at least:

- Every page has `canAccess()`, every changing action checks the permission again.
- Access to tables only through `Saft::table(…)`; `withoutAuthorization()` only where intended, with a comment.
- Input is validated; no unchecked values in queries, file paths or shell calls.
- No raw SQL, no shell calls, no `eval`; files only under `storage/`.
- Listeners only reject what is wrong in business terms – they apply in all data views and in imports.
- Credentials and keys never in code, but in `.env` or encrypted settings.

## 16. Examples: stock transfer and Swiss checks

Two example plugins ship with SAFT: *Lager-Umbuchung* (stock transfer) shows a business application with a form,
business rules and a tile, *CH-Prüfungen* (Swiss checks) shows formula functions and validation rules without pages of
its own.

### 16.1 Stock transfer

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
*view* on items, locations and stock levels and *create* on stock movements. The listener books stock levels without a
permission check – whoever may record movements does not need the right to edit stock levels.

To try it: load the warehouse demo (`php artisan db:seed --class=LagerDemoSeeder`), choose “Create menu entries” under
**Administration › Plugins**, then **Warehouse (demo) › Transfer** or the “Transfer” button in **Warehouse (demo) ›
Stock levels**. A stock movement “Issue” shows how the stock is booked and – below the minimum stock – the notification
in the bell. The tile **Warehouse: minimum stock** (`src/Widgets/MinimumStockWidget.php`) on the dashboard shows the
items below minimum stock.

### 16.2 Swiss checks

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

## 17. API versions and change log

The plugin API has its own version number **major.minor** (currently **1.3**). It is set in `config/saft.php`
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
| **1.3** | 0.1 | `PluginTestCase::createTablesFromSchema()`. Tools (not part of the API): developer context (`saft:plugin-context`, download in plugin administration), `AGENTS.md` in the scaffold |
| **1.2** | 0.1 | `DashboardWidget` (tile label), `FormulaFunction` and `SaftPlugin::formulaFunctions()`, `FieldRule` and `SaftPlugin::fieldRules()` |
| **1.1** | 0.1 | Test base `PluginTestCase` (`actingAsSuperAdmin`, `actingWithPermissions`, `createTable`, `withoutPluginEvents`, `dataView`); protected methods are part of the contract (`PluginServiceProvider::plugin()`). Tools (not part of the API): `saft:make-plugin`, `saft:plugins --check`, test suite "Plugins" |
| **1.0** | 0.1 | First version: `SaftPlugin` (permissions, menu suggestions, task kinds, `requiredTables()`, `recordListeners()`, `dataActions()`), `PluginServiceProvider`, `MenuSuggestion`; `Saft` (`table`, `hasTable`, `plugin`, `transaction`, `notify`), `DataTable` (reading, writing with permissions and validation rules, `formFields`, `withoutAuthorization`), events `RecordSaving`, `RecordSaved`, `RecordDeleting`, `RecordDeleted`, `RecordRejected`, `RecordListener`, `DataAction`; trait `LimitsFormWidth` |
