# SAFT XT – Administrator Guide

[Deutsch](ADMIN_GUIDE.de.md) · **English** · [Français](ADMIN_GUIDE.fr.md) · [Italiano](ADMIN_GUIDE.it.md)

**Version:** 0.1 · **Audience:** administrators within SAFT

**Other guides:** [User guide](USER_GUIDE.md) · [Frequently asked questions](FAQ.md) · [System administration](SYSADMIN_GUIDE.md)

> **About the figures:** The figures are screenshots of the application with the demo data. The red numbers in the
> figures refer to the explanations in the text.

---

## Contents

- [1. Users](#1-users)
- [2. Roles and permissions](#2-roles-and-permissions)
- [3. Menus](#3-menus)
- [4. Table designer](#4-table-designer)
- [5. Authentication](#5-authentication)
- [6. Identity providers (single sign-on)](#6-identity-providers-single-sign-on)
- [7. Global settings](#7-global-settings)
- [8. Activity log](#8-activity-log)
- [9. Database connections](#9-database-connections)
- [10. Table connector](#10-table-connector)
- [11. Table links](#11-table-links)
- [12. Scheduled tasks](#12-scheduled-tasks)
- [13. AI functions](#13-ai-functions)
- [14. Plugins](#14-plugins)
- [15. Transfer configuration](#15-transfer-configuration)
- [16. Apps (business applications)](#16-apps-business-applications)
- [Appendix A – Permissions at a glance](#appendix-a--permissions-at-a-glance)

---

You find the following functions in the menu under **Administration**. They are only visible with the corresponding
permissions. Here, too, lists and tables are only as wide as their columns (see [User guide 4.1](USER_GUIDE.md#41-the-table-view)).

## 1. Users

**Administration → Users and access → Users**

- **List** with name, email, roles, active and last sign-in. Filters: active, role, **without role**,
  **SSO link**, deleted users.
- **Create / edit:** name, email, password (required when creating, except in pure SSO mode), active, roles. The
  **default role** (see [chapter 5](#5-authentication)) is preselected when creating.
- **Password rule:** new passwords need at least 12 characters. SAFT rejects passwords from known data leaks (checked
  without sending the password). Existing passwords stay valid until they are changed.
- **Reset two-factor:** if someone has lost their phone and recovery codes, this action at the top of the edit form
  deletes the authenticator app that was set up. The person then signs in with the password and sets up the second
  factor again. Setting up, switching off and resetting appear in the activity log.
- **Inactive users** cannot sign in, neither with a password nor with SSO.
- **SSO links:** in the user you see which single sign-on accounts they are linked to, and you can remove a link.
- **Change history:** every change to the user can be traced at the bottom of the page.
- **Language:** every user chooses their language with the language icon; it is stored with their account and also
  applies to emails sent to them, e.g. for resetting the password.
- **Deleted users** can be shown with the filter, restored or deleted permanently. Their entries in the activity log
  are kept (see [chapter 8](#8-activity-log)).
- **Inactive, deleted or permanently deleted?**

  | | Inactive | Deleted (trash) | Permanently deleted |
  |---|---|---|---|
  | Sign-in | blocked | blocked | – |
  | In the list | visible | only with the filter "Deleted records" | gone |
  | Undo | "Active" switch | "Restore" | not possible |
  | Name in the activity log | yes | yes, marked as deleted | no, only the user number |
  | New SSO sign-in | refused | refused ("Your account has been removed") | may create a **new** account |

  "Inactive" is meant for temporary blocks. A deleted account deliberately stays blocked: otherwise a person who still
  exists at the identity provider would get a new account right away at the next sign-in through auto-provisioning.
  **Delete permanently** (row action for deleted accounts, also as bulk action) is meant for data-protection
  deletions – or when a person should deliberately get a new account via SSO.

Protection rules: nobody can delete or deactivate themselves. Super-admin accounts can only be edited by super
admins, and other users are not even offered the `super-admin` role.

## 2. Roles and permissions

**Administration → Users and access → Roles**

![Roles and data permissions](images/en/07-roles-data-permissions.png)

A role bundles permissions. Users receive permissions exclusively through roles.

- **Permissions**: permissions for functions of the application, e.g. "Users: edit", "Table designer: view" or
  **dashboard widgets**.
- **Data permissions**: a matrix with all tables of the table designer and the columns **View, Create, Edit,
  Delete, Export, Import**. New tables appear here automatically. **Import** only works together with **Create** and
  if import is allowed for the table. **Export** only takes effect if export is allowed for the
  table (see [chapter 4](#4-table-designer)) and the role may also view the table. Linked external tables (see [chapter 10](#10-table-connector))
  offer **Create, Edit** and **Delete** only if this is enabled for the table; otherwise "–" is shown.
  The matrix has the tabs **Own tables** (grouped by menu group, technical name shown discreetly next to it) and
  **Connected tables** (grouped by database connection). **All** in a row sets all permissions of the table, the box
  above a column ("whole column") sets the permission for all visible tables. The **search field** filters the rows
  by label or technical name.

- **Menu entries**: all menu entries of type "Data table", e.g. "Data › Customers". Without a tick the role does not
  see the entry in the menu. New entries and new roles are ticked, i.e. visible. This is display only: whether the
  table may be viewed is set by the data permissions. A hidden entry can still be reached through links, the
  master-detail view and the preview. With several roles, one role with read permission that shows the entry is
  enough; `super-admin` always sees all entries.

The areas are saved independently; a change in one does not alter anything in the other.

Changed permissions apply immediately, also for users who are already signed in. Anyone who has lost a permission
gets "Forbidden" on the next click; the menu is up to date after reloading the page.

**Special roles:**

| Role | Meaning |
|---|---|
| `super-admin` | Has **all** permissions, also for future functions. Can neither be deleted nor renamed, and is never assigned automatically through SSO. |
| `benutzer` | Default role for new users, without admin permissions; sees the dashboard with the personal widgets. As long as it is set as the default role, it cannot be deleted. |

**View as role (check permissions):** Super admins choose **"View as role …"** in the user menu at the top right and
one or more roles – as a person has them (the default role is preselected, usually a functional role is added).
Afterwards they see SAFT exclusively with their permissions – menu, pages, data permissions, reports and widgets –
like another person with exactly these roles: their own reports do not count as their own. A yellow bar at the top names the role; **End** in the bar restores your own
permissions (signing out also ends the view). The database stays unchanged; start and end are recorded in the
activity log. Note: what the role is allowed to do is really saved during the view – with you as the causer.

**App and copy** (B-107): each role belongs to an [app](#16-apps-business-applications); the form shows data
permissions and menu entries only for tables of that app. The system roles *super-admin* and *benutzer* belong to no
app and contain only app-wide permissions (e.g. dashboard, help); grant data permissions through the roles of the apps.
**Copy** (in the list and when editing) creates a new role with a name and target app – with the same permissions (data
permissions only for tables of the target app) and hidden menu entries, without members.

## 3. Menus

**Administration → Application → Menus**

This is where the menu on the left is maintained. Every entry has a **type**:

| Type | Use |
|---|---|
| **Group** | Heading for child entries (e.g. "Data") |
| **Internal page** | A page of the application (e.g. Dashboard, Users) |
| **External URL** | A link to an outside address; opens in a new tab |
| **Data table** | A table of the table designer with its own column selection |
| **Data model** | Graphical overview of the data tables of this group and their relations (see below) |
| **Report** | A shared report (see [User guide 4.9](USER_GUIDE.md#49-reports)); appears only for people allowed to run it with their data permissions |

![Menu entry of type data table](images/en/08-menu-data-table.png)

**Menu entry of type "Data table"** (1):

- Choose the **data table**; only active tables of the table designer are offered.
- Choose the **further displayed columns** (2). **Required fields** are always shown and are therefore not
  offered.
- The necessary read permission is set automatically: the entry is only visible to users who may view the table.
- **Several entries** with different columns can be created for the same table.
- If you change the type later, the automatically set read permission is removed again.
- The entry can be hidden for individual roles without removing the read permission (Roles → Menu entries, see
  [chapter 2](#2-roles-and-permissions)).

**Menu entry of type "Data model":** The page shows the data tables of the group the entry is in – including all
subgroups – as a diagram with their relations. If the entry is on the top level, all data tables appear. The page is
generic: the same type can be placed in several groups, and when an entry is moved it automatically shows the new
group. The following applies:

- Shown are tables with a menu entry of type "Data table" in the group, and only those the user may see (read
  permission, not hidden for their roles, active). Tables without a menu entry are missing.
- If a user sees **no table at all** in the group, the "Data model" entry does not appear in their menu. If the group
  would then be empty, it is hidden as well.
- Related tables **outside the group** appear as border nodes with a dashed frame, with their name only. Relations
  to tables without read permission are left out completely.
- **Lines:** solid if the relation is set up as a detail table in the table designer, otherwise dashed. The ends
  show the cardinality: many records refer to exactly one (required field) or to no or one master record.
- **"Show fields"** switches without reloading between the overview with table names and the view with all active
  fields (type, technical name, PK/FK/UK, label, `*` = required field). The browser remembers the choice.
- Linked tables from the table connector have an orange frame and name their connection.
- Like every entry, this one can be restricted by a **permission**. The diagram follows the colour scheme
  (light/dark); wide diagrams can be scrolled sideways.

**Label per language:** Every entry has a field for **Deutsch** (required) and one each for **English**,
**Français** and **Italiano**. If a field stays empty, the German label appears in that language. The entries
supplied with the application are already named in all four languages. Entries that the table designer creates for
a table take over its display name in all languages and follow it whenever it changes.

**Icons:** The button on the right of the **Icon** field opens a dialog with all available icons, sorted by style on
the tabs **Outline**, **Solid**, **Mini** and **Micro**. The search field filters immediately (e.g. "user"), the
number on each tab shows the matches per style; a click takes the name into the field. The current icon is
highlighted and appears as a preview on the left of the field. You can also type the name, e.g. `heroicon-o-users`;
the form only accepts known icons. The same dialog is available for the table icon in the table designer. An entry
with child entries that has no valid icon gets a folder icon.

**Further rules:** A menu has at most three levels: group (heading), entry and sub-entry. An entry with sub-entries is
a **submenu** and appears with an arrow **›**; a click opens its first page and expands it. This is how the
administration is structured (*Users and access*, *Database*, *Application*, *Operations*) and the warehouse demo
(*Warehouse operations*, *Master data*, *Reports*); entries can be sorted by drag and drop. When an entry is deleted,
its child entries are deleted as well.

> The **details** (master-detail) are not set in the menu but in the table designer.

**App:** top-level entries and groups belong to an [app](#16-apps-business-applications) or – without one – to the
system; entries inside a group take its app. For data entries the form only offers tables of that app.

## 4. Table designer

**Administration → Database → Table designer**

This is where you create your own tables, without programming.

![Table designer](images/en/09-table-designer.png)

**Multilingual:** The display name and description of a table, the label of every field and the display of
selection values have one field per language. German is required for the display name and the label; the other
languages are optional, and without them the German text appears. The technical name and the stored selection
values apply to all languages.

**Creating a table:**

1. **New table** → enter the display name. The technical name is suggested
   (e.g. "Größe in m²" → `groesse_in_m2`) and **cannot be changed later**. Technical names are unique across all
   tables – own, imported and connected. If the name is taken, SAFT says so as soon as you leave the field and names
   the table, e.g. "The name “personen” is already taken: connected table “Personen” (connection Forschung)." If
   another table has the same **label**, only a note appears – allowed, but hard to tell apart in menus and
   selection lists. The same applies to import and the table connector.
2. Optionally create **start fields** and choose the menu group under **"Menu entry under"**. "Data" is preselected;
   all groups are offered, subgroups indented with their path (e.g. "Data › Research"). "No menu entry" creates
   none.
3. Save; the table can be used immediately. Its **data permissions** (View/Create/Edit/Delete) are created
   automatically in role management.

**Allow export:** This switch in the master data of the table allows export (see [User guide 4.7](USER_GUIDE.md#47-exporting-data)). It is
initially off. Even super admins only see "Export" once it is on; everyone else also needs the **Export**
permission in their role.

**Allow import:** This switch allows data import – new records from an Excel or CSV file, e.g. from a downloaded
template (see [User guide 4.8](USER_GUIDE.md#48-importing-data)). It is initially off. Then "Import" appears in the
list for roles with the permissions **Create** and **Import**. The import never changes existing records; it checks as
when entering data by hand, and it is all or nothing. At most 10,000 rows per file
(`APP_DATA_IMPORT_MAX_RECORD_ROWS`). Linked tables of the table connector cannot be imported into.

**Editing fields** (area *Fields*, (2)): for every field you set:

| Setting | Meaning |
|---|---|
| Label / column name | Display name and technical name. **Renaming keeps the data.** |
| Type | see [User guide, Appendix A](USER_GUIDE.md#appendix-a--field-types) |
| Type settings | Length (text), digits (decimal), values (selection list), related table, display fields and separator (relation), file kinds, maximum size and number (file, see below) |
| Validation | Minimum/maximum length, minimum/maximum, email, URL; **Further checks** from plugins, e.g. AHV number or IBAN (only shown when a plugin offers validation rules for the field type; they apply when entering data, when importing and through the interface, empty values are not checked). If the plugin is later missing or resting, the check is skipped and shown as “not available” on the field |
| Required | must be filled in; always shown in lists |
| Unique | may only occur once (deleted records do not count) |
| Default when creating (integer) | **Suggest next number:** in the "New record" dialog the highest existing value + 1 is filled in and can be changed; if the field stays empty, SAFT sets the value when saving. With **Count per** (a relation, e.g. *Order*) it counts per master record: below an order with two lines, 3 is suggested; in the list the suggestion appears as soon as the order is chosen. Deleted records do not count. |
| Default when creating (date, date and time) | **Prefill with today's date** or **Prefill with the current date and time:** in the "New record" dialog the field shows the current date (or date and time to the minute) when it opens and stays editable. When editing, importing and through the API SAFT sets nothing. Not together with "Copy from". |
| Copy from | Take the value from the master record, e.g. *unit price* from **Item → Price**: as soon as an item is chosen in the form (or immediately below an item as detail table), its price is filled in and can be changed. When the item changes, the value follows as long as it was not changed by hand; fields saved empty are filled by SAFT. A copy: later changes to the item do not change existing lines. Only fitting fields are offered (number ← number, date ← date, text ← text/selection, yes/no ← yes/no). A source field cannot be deleted while a field copies from it; renaming is carried over. |
| Searchable | is included in the search |
| Create index | speeds up sorting and filtering in large tables |
| Show in table / Show in form | Default for lists and forms |

**Display fields of a relation:** Instead of the stored number, a relation shows fields of the related record – one
or several, in the chosen order and joined with a **separator** (space, comma, dash, dot or slash), e.g. first and
last name to "Anna Keller" or name and city to "ETH Zürich, Zürich". Empty fields are skipped. This applies in lists,
details, preview, filters, export and in the selection list when editing; there, typing searches all display fields.
Sorting uses the first display field. If you rename a display field, the relation follows; as long as a field serves
as display field anywhere, it cannot be deleted permanently.

**File fields:** A field of type **File** takes attachments. Settings:

- **Allowed files:** images (JPEG, PNG, GIF, WebP), PDF, Office documents (Word, Excel, PowerPoint, OpenDocument),
  text and CSV, or **Any files**. The actual content is checked, not just the extension. Ticking a specific kind removes
  "Any files" and vice versa. SVG images are not supported (they can contain scripts).
- **Maximum size per file** in MB (default 10, at most 200). If the server's (PHP) limit is lower, the field shows a
  note; the smaller value applies.
- **Number of files** (default 1, at most 50).

The files are not stored in the database but in the server's file storage (or in S3, see
[System administration](SYSADMIN_GUIDE.md#1-operations)); the record only stores their ID and name. Replaced or
removed files are deleted on saving, as well as when a record is deleted permanently or the field or table is deleted.
Deleted records in the trash keep their files (visible only with delete permission). File fields are not unique, not
sortable and have no index; they are not available in the data import, in reports or as a source for "Copy from".
The export contains the file names; the history shows which files were added or removed.

**Rules** (figure below, 1): Checks across fields when saving a record, e.g. "Delivery date ≥ Order date" or "Quantity > 0".
For every rule you choose the **field**, the **comparison** and whether it is compared with **another field** or a
**fixed value**:

![Rules](images/en/18-table-rules.png)

- Numbers and dates allow all comparisons (=, ≠, <, ≤, >, ≥) – a number only with a number, a date only with a date.
  Text, selection lists and yes/no can only be checked for equality (= and ≠). Relations and long text cannot be
  used.
- **Only check if both sides are filled** (default): an empty field is not checked. If switched off, an empty field
  counts as a violation.
- **Own message** (optional, per language). Otherwise the standard text appears at the field, e.g. "must not be
  before “Order date”".
- The rule applies in every form of the table (list, detail tables, details → edit). Existing records stay
  unchanged; if some already violate a new rule, a note shows how many – they are checked the next time they are
  edited.
- Renaming a field updates its rules. A field used in a rule cannot be deleted, and its type can only be changed to
  a type that still fits the rule.

**App and prefix:** when creating a table you choose its [app](#16-apps-business-applications) (required if there
are several apps). If the app has a prefix, it is put in front of the suggested technical name; changing the app
replaces it. Relations are only possible to tables of the same app.

### Computed fields

A field of type **Computed** is not entered but calculated when saving from other fields of the same record or from
its child records, e.g. *amount* from quantity and unit price, or *total* as the sum of the amounts of all lines of an
order. The value is stored and can therefore be sorted, filtered, exported and used in
rules.

- **Result:** integer or decimal (with digits and decimal places); the result is rounded to it.
- **Formula:** number fields of the table (by column name), numbers, `+ - * /` and brackets as well as the functions
  `round(x, digits)`, `min(a, b, …)`, `max(a, b, …)`, `abs(x)` and `coalesce(a, fallback)`. Example:
  `round(quantity * unit_price, 2)`. The function names are the same in all languages. Plugins can bring further
  functions, e.g. `rappen(amount)` (rounding to 5 centimes); they are listed in the input help under **Functions from
  plugins**, and the description appears when pointing at the button. They are not available in report expressions.
  If the plugin is later missing, the result stays empty.
- **Over child records** (tables pointing to this table with a relation): `sum(table.field)`, `avg(table.field)`,
  `min(table.field)`, `max(table.field)` and `count(table)`, e.g. total = `sum(bestellpositionen.betrag)`, VAT =
  `round(gesamtpreis * 0.081, 2)`. Deleted child records do not count; without child records sum and count are 0. If a
  child table points to this table with several relations, name the one you want as second value:
  `sum(bestellpositionen.betrag, bestellung_id)`.
- **Expression per child record:** Instead of a field, `sum`, `avg`, `min` and `max` may also contain a calculation with
  fields of the child table; it is calculated for every child record and then summarised, e.g.
  `sum(bestellpositionen.einzelpreis * bestellpositionen.menge)` or
  `sum(round(bestellpositionen.menge * bestellpositionen.einzelpreis * 0.95, 2))`. The expression only contains fields of
  this one child table (no fields of the master record, no nested summaries). If the result for a child record is
  empty, it does not count – use `coalesce(…, 0)` to control this.
- **Input help:** Below the formula field the number fields of the table appear as buttons, e.g. "Quantity
  (quantity)"; a click inserts the technical name at the cursor. Each child table gets its own line with
  `count(table)` and its number fields (`table.field`), plus buttons for operators, functions and `sum`, `avg`,
  `count`. When creating a new table only the fields further up in the form are listed.
- **Check:** While you type, SAFT reports errors with their position (e.g. "Character 9: Unknown field “price”") or
  confirms what the result depends on. Circular references are rejected; the message names the formulas of the
  fields involved, e.g. "Circular reference: mwst → einkaufspreis → mwst. The formulas: mwst = …; einkaufspreis = …".
- **Empty values:** If a field used is empty, the result is empty – except with `coalesce(field, 0)`. Division by 0 also
  gives an empty result. Calculations are exact (no rounding errors with amounts of money).
- **When it is calculated:** when a computed field is added, for all existing records; when a record is created (also
  by import) or changed (the new values appear in the activity log); when a
  child record is created, changed, deleted or restored for its master record – when the order changes for the old
  and the new one – and further up (e.g. a customer's turnover as the sum of their orders); for all records of the table
  and the ones above after a formula has been changed; and with `php artisan computed:recompute` if data was changed
  outside SAFT.
- In the form a computed field is only shown ("calculated when saving"). Rules that check a computed field therefore
  take effect the next time the record is edited.
- If a field used is renamed (also in a child table), the formula changes along; deleting it and changing it to a
  non-number type are blocked while a formula uses it. A child table that is summed cannot be deleted.
- The demo data contains *amount* (order lines) as well as *total* and *VAT 8.1 %* (orders).

**Details (master-detail)** (1): Here you choose which related tables appear below a selected record (see
[User guide 4.3](USER_GUIDE.md#43-related-tables-the-master-detail-view)). All relation fields of other tables that point to this table are
offered. If there are several relations from the same table (e.g. "Customer" and "Invoice to"), they can be chosen
individually.

**Further buttons:** *Open data* (to the data view) and *Create menu entry*. It asks for the group and also creates a
further entry if the table should appear in a second place in the menu.

**What is possible – and what is not:**

| Change | Rule |
|---|---|
| Rename a field | at any time, data is kept |
| Change the type | at any time if lossless (e.g. integer → decimal, text → multi-line text, date → date and time); otherwise only if the column is empty |
| Text length | increase at any time, reduce only if the column is empty |
| Switch on "Unique" | only if the existing data contains no duplicates |
| Remove a value from a selection list | at any time; if the value is in use, a warning shows the number of records. They keep their value, but a valid one must be chosen when they are next edited. |
| Delete a field | first **deactivate**, then "Delete permanently" by entering the column name |
| Delete a table | first **deactivate**, then "Delete permanently" by entering the table name; not possible while other tables refer to it |
| Relation | only to other tables of the table designer, never to system tables |

> **Deactivate instead of delete:** A deactivated field or table is invisible to users, but the data is kept in
> full. You bring a field back with "Activate" without confirmation. Deleting permanently cannot be undone.

Under **Physical table** you see the storage location, the number of records and any mismatch between the settings
and the database.

### Used in

The tab **Used in** (next to *Fields* and *Activity log*) shows where the table appears; the number on the tab gives
the count. It is for information only, e.g. before renaming or deleting:

| Area | What appears |
|---|---|
| Plugin | plugins that require the table, check changes to it or show buttons in its data view |
| Report | reports with the table as base table or through a relation |
| Link (report) | links between reports with the table as source or target |
| Menu | menu entries opening the data view (with the path in the menu) |
| Relation | fields of other tables referring to this table |
| Formula | computed fields of other tables summarising over this table, e.g. `sum(orders.amount)` |
| Copy | fields of other tables copying a value from this table |
| Role | roles with data permissions on the table, with the permissions |
| Scheduled task | "Refresh snapshot" tasks for the table |

The name is a link if you may open its page; the filter **Area** narrows the list.

### Delete a table or field permanently

Deleting permanently removes the column or table **including its data** from the database. To prevent this from
happening by accident, it takes two steps; until then the **"Delete permanently"** button is visible but locked
(the hint appears when you hover over it):

| | Table | Field |
|---|---|---|
| 1. Deactivate | edit the table, switch **"Active"** off, **Save** | in the field list **"Deactivate"** |
| 2. Delete | **"Delete permanently"** at the top | the **trash icon** in the row ("Delete permanently") |
| 3. Confirm | enter the technical table name | enter the column name |

When a table is deleted, SAFT also removes its data permissions and menu entries; the activity log is kept. The
permission **Table designer: delete** is required. If another table refers to it through a relation field, the
message names that field – it must be removed first. If the table is used elsewhere (reports, menus, roles …), the
dialog shows a warning with the count per area; deleting remains possible, details are in the **Used in** tab. If the table or field
contains files (type *File*), the dialog names their number and size; they are deleted too, including those of records
in the trash.

### Import a table from a file

**Table designer → Import** creates a new table from a CSV or Excel file and takes over the data. It requires the
permission **Table designer: import**. The wizard has four steps:

1. **File:** CSV (`.csv`, `.txt`, `.tsv`) or Excel (`.xlsx`), at most 20 MB or 50,000 data rows. For Excel files
   with several sheets you choose the sheet. SAFT checks the file; if it has errors, the wizard stays here and
   lists **all** problems found with row and column.
2. **Check:** summary (rows, columns, for CSV delimiter and character set) and notes, e.g. about a suspected total
   row.
3. **Columns:** per column "Import", field name, label and type. Only types that fit the data are offered (text is
   always possible).
4. **Table and menu:** label, technical table name and the menu group for the table's entry.

**An importable list** has the column titles in row 1 and only data rows below:

| Allowed | Leads to cancellation |
|---|---|
| titles with spaces and special characters ("Customer no.", "Street") | column without title, duplicate titles |
| empty cells | title or blank row above the list, blank rows within the list |
| blank rows at the end | values in columns without title, CSV rows with a different number of columns |
| formulas (the stored result counts) | merged cells, error cells such as `#DIV/0!` |

**Field names** are derived from the titles: "Straße & Nr." → `strasse_nr`, "2024" → `f_2024`. The title itself
becomes the label. System names and reserved words ("ID", "Order") get the suffix `_import`. If two titles result
in the same name ("Customer No" and "Customer-No."), the check names both columns.

**Types** are only detected if **all** values of a column fit:

| Values | Type |
|---|---|
| whole numbers | Integer |
| numbers with decimals (`1,234.50` or `1.234,50`) | Decimal |
| `DD.MM.YYYY` or `YYYY-MM-DD`, optionally with time | Date or date and time |
| yes/no, true/false, ja/nein | Yes/No |
| numbers with a leading zero (postcode `01067`) | Text – the zero is kept |
| everything else | Text; over 255 characters or multi-line: long text |

Whether `1.234` is read as 1234 or as 1.234 is decided for CSV by the delimiter (semicolon: German format, comma:
English); the column preview names the chosen format. SAFT does not guess keys: every table gets its own record number as always; an
"ID" column of the file is imported as a normal field `id_import`.

The import is all or nothing: if a value does not fit, nothing is created and the message names the cell. The
**activity log** contains one entry "imported" with file, row and column count, not one entry per record. At most
ten columns are created as sortable; you enable more in the table designer.

**Imported by mistake?** Open the table in the table designer, switch "Active" off and save, then confirm "Delete
permanently" with the table name (see [Delete a table or field permanently](#delete-a-table-or-field-permanently)).
Afterwards the file can be imported again.

## 5. Authentication

**Administration → Users and access → Authentication**

| Setting | Meaning |
|---|---|
| **Sign-in mode** | **Local** (password only), **Single sign-on** (identity provider only) or **Hybrid** (both) |
| **Emergency sign-in for super admins** | Allows super admins in SSO mode to sign in with a password via `…/admin/login?local=1`, in case the identity provider fails |
| **Offer "Forgot password"** | Email for resetting the password (only in Local or Hybrid mode; requires configured email delivery) |
| **Default role for new users** | Preselected when creating users and assigned to SSO users created automatically |
| **Two-factor authentication** | **Optional**, **Required for administration** (default) or **Required for everyone**. Administration means: super admin or any permission on users, roles, menus, identity providers, settings, table designer, database connections, table connector, table links, scheduled tasks, plugins or transfer configuration. Anyone covered is taken to the setup after signing in. Exempt are sign-ins via SSO and accounts without a password (second factor at the identity provider) as well as *View as role*. |

Protection against lock-out: the **Single sign-on** mode can only be saved if at least one active identity provider
has a **successful connection test**. For additional emergency exits in operations see
[System administration, chapter 1](SYSADMIN_GUIDE.md#1-operations).

## 6. Identity providers (single sign-on)

**Administration → Users and access → Identity providers**

![Identity provider](images/en/11-identity-provider.png)

**Microsoft Entra ID** and **SAML 2.0** (e.g. ADFS, Keycloak) are supported. Several providers are possible; each
appears as its own button on the sign-in page. A step-by-step guide for Entra ID with the app registration at
Microsoft and troubleshooting is given in System administration, [Appendix D](SSO_ENTRA.md). The addresses of a SAML provider must start with `https://`; a
provider that can only be reached via http cannot be connected.

**Setting up – procedure:**

1. Create the provider, choose the type, assign a display name and an identifier.
2. Enter the displayed **values for the identity provider** (2), e.g. the redirect URI or the ACS URL and the SP
   metadata URL, at the provider. Clicking a value copies the address.
3. Enter the provider's data:
   - **Entra ID:** directory ID (tenant), application ID (client), client secret. Only your own tenant is
     allowed (not `common`/`organizations`).
   - **SAML:** IdP metadata URL *or* entity ID, SSO URL and certificate. For signed requests, generate an SP
     certificate with one click.
4. Save and **"Test connection"** (1). Secrets are stored encrypted and never shown again.
5. Under *Authentication*, choose the **Hybrid** or **Single sign-on** mode.

**User matching:**

| Setting | Effect |
|---|---|
| Link existing users by email | A known user is matched by email at the first SSO sign-in |
| Create unknown users automatically | New people get an account (with the default role) |
| Default role for new users | Granted only when the account is created automatically and kept afterwards. Empty = global default role from [chapter 5](#5-authentication) (the field shows it); mapped roles are granted in addition |
| Allowed email domains | Only these domains may sign in (empty = all) |

**Role mapping:** Roles can be taken from the identity provider: with Entra from **app roles** (recommended) or
**groups**, with SAML from an attribute. Mappings read "external value → role". At every sign-in the mapped roles are
granted or revoked; other roles remain untouched. `super-admin` can never be mapped. Optional: "Only allow sign-in
with a matching mapping".

**Sign-out (single logout):** On request, signing out of SAFT also signs out at the provider. With Entra this
affects all Microsoft services in the browser (Outlook, Teams …), so it is off by default.

## 7. Global settings

**Administration → Application → Global settings**

| Setting | Meaning |
|---|---|
| **Display name of the application** | At most 60 characters. Appears in the header, in the browser tab, on the sign-in page and as the sender of emails |
| **Colour scheme** | Main colour for everyone: **Indigo** (default), **Red (HfH)**, **Green** or **Grey**. It colours buttons, active menu entries, links and the marker of the selected row; background, text and layout stay unchanged. Applies immediately after saving |

![Colour scheme](images/en/16-color-scheme.png)

In the **Appearance** section (1), each scheme shows two samples: buttons and the marker of the selected row.
All colour schemes meet WCAG AA (4.5:1) in light and dark mode. **Red (HfH)** follows the web style guide of the
HfH: buttons in #BE1925, the selected row marked in the corporate colour #E74011 (it is not used as a button
background because white text on it only reaches 4.1:1). High contrast is a personal setting (see
[User guide, chapter 3](USER_GUIDE.md#3-interface-and-dashboard)).

The section **AI assistant for reports** sets up provider, model and key of the AI functions (see
[chapter 13](#13-ai-functions)).

## 8. Activity log

**Administration → Operations → Activity log**

Every change in the application is logged: who, when, what, with **before/after**. This includes records, users,
roles and permissions, menus, table structures, settings as well as sign-ins, sign-outs and failed sign-in attempts.
Secrets (passwords, keys) are never logged.

- **Filters:** user, event, object type, period. The events are: created, updated, deleted, restored, sign-in,
  sign-in failed, sign-out and exported.
- **Exports:** table, format, number of rows, filter and search are recorded, never the exported data.
- **User:** "System" only appears where really no user was involved. Deleted users appear with their name and
  "(deleted)", permanently deleted ones as "Deleted user #12".
- **Detail view:** in form width; for changes a table with all changed fields (before → after), for creation and
  deletion the fields compactly in blocks (field and value side by side). Long values wrap. Labels in several
  languages appear with all languages, e.g. `{"de":"Kunden","en":"Customers"}`.
- **Times** are shown in the time zone of the application (see [System administration, chapter 1](SYSADMIN_GUIDE.md#1-operations)).
- In the dashboard widget **"My recent changes"**, clicking an entry leads directly to the changed record; for tables
  of the table designer into the data view with the record selected.
- The log cannot be edited or deleted, not even by super admins.

## 9. Database connections

**Administration → Database → Database connections**

This is where you set up connections to external databases, such as an ERP or research system. The **table
connector** (see [chapter 10](#10-table-connector)) links tables of these databases so that they can be read like the
tables of the table designer. **PostgreSQL**, **MySQL** (8.0.16 or newer), **MariaDB** (10.11 or newer) and **Microsoft
SQL Server** (2019 or newer, Express as well) are supported, regardless of which database SAFT itself uses. For SQL Server the SAFT server needs the
PHP extension `pdo_sqlsrv` and the Microsoft ODBC Driver 18; without them the type is disabled in the selection.

![Database connection](images/en/12-database-connection.png)

| Setting | Meaning |
|---|---|
| **Name** | The connection is later offered under this name in the table connector. |
| **Database type, server, port, database** | Where the database is located, e.g. `db.example.com`, port 5432. |
| **Default schema** (2) | The schema with the tables you want; empty means `public` for PostgreSQL, `dbo` for SQL Server and the connection's database for MySQL and MariaDB (where schemas are called databases). After a successful test the existing schemas are offered for selection. |
| **User, password** | Sign-in to the database. The password is stored encrypted and never shown again; an empty field keeps it. |
| **TLS encryption** | `prefer` encrypts if the server offers it; `verify-full` also checks the certificate and host name. MySQL, MariaDB and SQL Server: `require` (default) encrypts without checking the certificate (e.g. with a self-signed certificate), `verify-full` checks the certificate, `disable` connects unencrypted. |
| **Timeouts** | For establishing the connection (default 5 seconds) and per query (default 30 seconds). This way a slow or unreachable database does not block pages. |
| **Read only** | Default for new connections. The database itself then rejects every change through this connection. Switch off to edit data. SQL Server cannot enforce this per connection; there SAFT blocks every write – in addition, a user that may only read (role `db_datareader`) is recommended. |
| **Active** | Inactive connections are not offered in the table connector. |

**Setting up:**

1. Choose **Create** and enter the data. After saving, the edit page opens.
2. **"Test connection"** (1) saves the data shown and checks it. If successful, the message names the server
   version, the number of tables and views in the default schema and all existing schemas.
3. If the test fails, the message names the reason, e.g. "Sign-in rejected", "The server … cannot be reached" or
   "The schema … does not exist in this database".

If you later change server, database, user, password, schema or TLS, the connection counts as "not tested yet"
again. Every change is recorded in the change history; for the password only the fact that it was changed.

> **Security:** Create a dedicated database user for SAFT that may only see and, if necessary, change the required
> schemas and tables. Nobody receives the permissions "Database connections: view/create/edit/delete"
> automatically; anyone allowed to create connections can address any server.

**Deleting:** If no tables are linked through the connection, the usual confirmation is enough. Otherwise the dialog
**"Delete connection and linked tables"** lists all affected tables (name in SAFT, label, external table, kind).
After entering the connection name to confirm, the connection and its links are removed in one step – as with
"Remove link" in the table connector: settings, permissions, menu entries and relations of other tables to them. The
external database stays unchanged. This also requires the permission "Table connector: delete"; without it the
dialog only shows the list.

**Bulk link:** When editing a connection there are two tabs: **Connection** with the previous settings and **Bulk
link** to link many tables at once. The second tab shows its table only after a successful connection test; before
that, it shows a hint.

1. Choose the schema (filter "Schema", preset with the default schema). The list shows all tables and views with
   type, key, the proposed name in SAFT and – if already linked – the existing name. Tables already linked cannot be
   selected again.
2. Tick the tables you want (or "Select all") and click **"Link selected …"**.
3. In the dialog the settings apply to all selected tables: **prefix** for the technical names (e.g. `erp_`),
   **active**, **allow export**, **allow create/edit/delete**, **menu entry under** a group (or no menu entry) and
   optionally **roles** that receive the view permission and the enabled permissions (only visible with the
   permission to edit roles). **"Set up master-detail from foreign keys"** (default: on) enters, for each newly
   linked table, the tables of the same connection that refer to it as details – e.g. below a storage location its
   stock and movements (see [User guide 4.3](USER_GUIDE.md#43-related-tables-the-master-detail-view)). At most 5 per table: if more tables
   refer to it (typical for lookup tables such as "countries"), nothing is set up there and the result names it.
   Tables with details chosen already stay unchanged.
4. A progress bar shows the state; the tables are linked in portions. Afterwards the **result** lists per table:
   linked, read-only (with reason), skipped (e.g. name already taken) or error.

For each table the same applies as when linking a single table: keys, column types, database rules and value lists
are taken over. **Relations** via foreign keys are determined once at the end for all tables of the connection,
including between the newly linked ones. The **name in SAFT** is derived from the table name: prefix, lower case,
camel case and special characters become `_` (`tblOrder` → `tbl_order`); the label can be changed afterwards in the
table connector. Writing applies only where possible: views, tables without a unique key and connections set to
"read only" stay read-only. Very large databases are linked with the command `php artisan table-connector:connect`
(see [System administration, chapter 1](SYSADMIN_GUIDE.md#1-operations)); it sets up the details as well, with `--no-details` it does not.

## 10. Table connector

**Administration → Database → Table connector**

The table connector links tables and views of a database connection. They then appear under **Data** like the
tables of the table designer: with menu entry, permissions, list, search, filters, sorting, "Details" and export.
Linked tables are initially **read only**; create, edit and delete can be enabled per table (see *Writing* below).
The table connector never changes the structure of the external database.

![Link table](images/en/13-table-connector-create.png)

**Linking a table:**

1. Choose **"Link table"**, then **database connection**, **schema** and **table or view** (1). Views and tables
   without a key are marked in the list.
2. Below, you see what will be taken over (2): number of columns, the key and columns SAFT cannot display (e.g.
   binary data).
3. **Display name** and **description** are prefilled (description from the table comment, if any) and can be
   changed per language.
4. The **technical name** (suggested e.g. `forschung_projekte`) determines address and permissions. It is
   independent of the name in the external database and cannot be changed later.
5. Under **"Menu entry under"** choose the menu group (preselected "Data", subgroups indented) or "No menu entry".
   Later, the **"Create menu entry"** button adds further entries.
6. Save. The table can be used in the menu right away; the permissions **View** and **Export** appear in the
   roles.

![Columns of a linked table](images/en/14-table-connector-columns.png)

**Columns** (1): type and original type come from the database and are fixed. Per column you can choose the
**label** (per language), **In table** (list), **Searchable** and **Visible** (hidden columns appear nowhere, not even
in details and export); the order can be changed. The **key** and **generated** columns (2) that the database fills
itself (sequence, identity, computed column) are marked.

| Column type in the database | Display in SAFT |
|---|---|
| Text (`varchar`, `char`, `text`, SQL Server also `nvarchar`, `nchar`, `ntext`) | Text |
| Integer, `numeric(p,s)` (SQL Server also `money`) | Number or decimal |
| `boolean` (SQL Server `bit`), `date`, `timestamp` (SQL Server `datetime2`) | Yes/No, date, date and time |
| `uuid`, `json`, `time`, `interval` and similar (SQL Server `datetimeoffset`, `float`, `xml`; MariaDB `inet4`/`inet6`) | as text only; initially not in the list, but can be shown |
| Binary data, arrays, geometry (SQL Server also `rowversion`, `hierarchyid`) | not offered |

**Key:** SAFT uses the key to find a single record. It is taken over from the primary key and may consist of several
columns, e.g. `publikation_id` and `person_id` in an assignment table for many-to-many relations. With a key there are
details and editing; with a single key column also selecting a row (master-detail) and relations to the table. Under
**Key** you can choose one or more columns yourself, e.g. for views. Without a key there are only list and export.

**Relations:** A column such as `projektleiter_id` can point to another linked table of the **same database
connection**. Lists and details then show a field of the related record instead of the number, e.g. the name;
clicking it opens the preview (see [User guide 4.5](USER_GUIDE.md#45-taking-a-quick-look-at-a-related-record-preview)).

- **Automatic:** foreign keys of the database are taken over as relations as soon as both tables are linked, in any
  order. A naming field (name, title, label …) is suggested as display field.
- **Manual:** in the columns, via *Edit*, choose related table, one or several **display fields** and, for several,
  the **separator** in the **Relation** area (as in the table designer, see [chapter 4](#4-table-designer)), or "No
  relation". A relation removed by hand is not set again automatically. Possible for integer or text columns,
  including UUID.
- If the link of a related table is removed, columns pointing to it show their value again.

**Details (master-detail):** As in the table designer, choose in the edit page of the linked table which related
tables appear below a selected record (see [User guide 4.3](USER_GUIDE.md#43-related-tables-the-master-detail-view)), e.g. below a project its
publications and below those their author assignments. This also works with UUID keys; tables without a single key
(e.g. assignment tables) appear as detail tables with details and editing, but their rows cannot be selected further. There are no relations between designer tables
and linked tables.

**If the external database cannot be reached** or the connection is inactive, the data view shows "Data not
available" instead of the list.

![Enable writing](images/en/15-table-connector-writing.png)

**Writing:** In the **Writing** section (1) of the linked table you enable **Create, Edit** and **Delete** individually.
This is only possible if the database connection is not set to "Read only", the table is a real table (not a view)
and the database guarantees that its key is unique (primary key or unique index on exactly these columns). A key
chosen by hand without this guarantee is enough for reading, not for writing. The role also needs the data
permissions.

- **Assignment tables (many-to-many):** Below a publication, "New record" creates an assignment; the publication is
  preset and you choose the person. Key columns that the database does not fill itself are always offered when
  creating, even if they are set to "Read only". The key columns are fixed afterwards: to assign another person, delete the
  assignment and create a new one. Editing changes only the remaining columns (e.g. the order). Relation columns with
  a UUID are writable through the selection.

- **Form:** It contains the writable columns. Columns filled by the database (sequence, identity, computed),
  text-only values (uuid, json …; except relations) and columns marked **"Read only"** are left out; you set "Read only" per column,
  e.g. if a trigger writes the value, as well as **"Show in form"**.
- **Key:** If the database assigns the key itself, SAFT reads it back after saving. A natural key (e.g. a country
  code) is entered when creating; afterwards it is fixed.
- **Validation** comes from the database schema: required (`NOT NULL` without default), length, digits, unique values
  and valid relations. If a column with a default stays empty, the database sets its default. Rules such as CHECK
  constraints, triggers and foreign keys are checked by the database; violations appear as a message, e.g. "A value
  violates a database rule."
- **Deleting is permanent** – there is no restore; the confirmation says so explicitly. If linked tables still refer
  to the record, SAFT blocks the deletion and names the reason.
- **Activity log and history** record every change made through SAFT. SAFT does not see changes made to the external
  database by other programs.
- If two people edit the same record at the same time, the last saved change wins.

**When the external table changes** (e.g. a column was added, removed or changed by its owner), SAFT notices it:
the list of linked tables shows the number of differences under **"Sync"** ("up to date" if there are none), and the
linked table shows the note **"The external table has changed"** with the list of differences. **"Re-read"** shows
the differences again and applies them after **"Apply"**:

- **New columns** are added visible and marked **"new"** in the column list; the marker disappears as soon as you edit
  the column.
- **Missing columns** are hidden and marked **"missing"**; they are not deleted, so labels and settings are kept if
  the column comes back. **"Remove permanently"** deletes only the setting in SAFT. Relations that used a missing
  column as display field are adjusted.
- **Changed columns** (type, length, required, default value, unique, generated) are updated; a relation that no
  longer fits the new type is removed. If the primary key changes, it is taken over; if the database no longer
  guarantees that the key is unique, writing is disabled. New foreign keys become relations as when linking.
- A **renamed column** appears as missing plus new; set the label of the new column again.
- If the **table itself is missing**, nothing is changed.

Until the changes are applied, the data view shows "The external table … has changed. Please re-read it in the Table
Connector." instead of a database error if columns are missing. `php artisan table-connector:verify` checks all
linked tables (exit code 1 for differences, e.g. for monitoring); with `--apply` it applies the differences like
"Re-read".

**Database rules (CHECK constraints):** SAFT reads the CHECK constraints of the external table when linking and when
re-reading. The section **Database rules** of the linked table lists them, each marked **checked in SAFT** or
**database only**:

| Rule in the database | In SAFT | Example |
|---|---|---|
| Column limited to a list of values (`IN (…)`) | Selection instead of free text, also as list filter; the column list shows "Value list (n)", the values as tooltip | Status: planned, running … |
| Column compared with a fixed number (`>=`, `<=`, `BETWEEN`) | Min./max. of the field | Year 1900–2100, budget ≥ 0 |
| Two columns compared, also with "is empty or" | Check at the field, e.g. "must not be before “Start”" | End ≥ start |
| Anything else (patterns, functions, nested conditions) | only the database checks when saving | Website must start with http(s):// |

As in the database, empty values are not checked. If a rule that only the database checks is violated, the message
names the field and the rule, e.g. "A value in “Website” violates the database rule “organisationen_webseite_check”".
Changed or removed rules appear under "Sync" as differences and are applied with "Re-read". Tables linked before
this version show their rules as differences once; "Re-read" or `php artisan table-connector:verify --apply` applies
them. Rules are translated for PostgreSQL, MySQL, MariaDB and SQL Server. With MySQL and MariaDB, the values of an
`enum` column are added as a list of values; they appear as the rule "<column> (enum)". JSON columns of MariaDB
(internally text with the rule `json_valid`) appear as JSON; this rule is not listed separately. SQL Server usually compares text without distinguishing
upper and lower case (like MySQL); this then also applies to unique values.

### Data snapshots for reports

The **Data snapshots** tab of a connected table manages the copies that [reports](USER_GUIDE.md#49-reports) work with:

- **Refresh now** reads the whole external table and creates a new snapshot. If that fails (connection lost, table too
  large – limit `REPORTS_SNAPSHOT_MAX_ROWS`, default 200,000 rows), the previous snapshot stays valid; the failed
  attempt is listed with its message until the next snapshot succeeds.
- **Retention:** how many snapshots are kept (default 5) and optionally for at most how many days. The newest snapshot
  is always kept. The limit in days is also enforced by the daily system task "Clean up snapshots".
- **Schedule:** above the tab you see which [scheduled tasks](#12-scheduled-tasks) refresh the table – on its own or
  for its whole connection – with the next date and a link. **Set up schedule** creates a prefilled task (permission
  "Scheduled tasks: create"). Snapshots taken automatically show **automatic** under "Triggered by". With a schedule,
  the report editor only asks once a snapshot is older than two intervals.
- **Delete** removes one snapshot, **Delete all snapshots** all of them; reports on the table only run again after the
  next refresh.

A snapshot never changes: changes and deletions in the source take effect with the next snapshot. New columns are
added with the next snapshot ("Re-read" first). Refreshing and deleting are recorded in the table's change log. Roles
with "Table connector: edit" use this tab. In addition, anyone with read permission on the table can create a
snapshot by choosing it in the report editor; deleting and retention remain with the table connector.

**"Remove link"** removes the table from SAFT – settings, permissions, menu entries and data snapshots. The data in the
external database remains unchanged.

> **Permissions:** Only roles with "Table connector: view/create/edit/delete" can use the table connector,
> independently of the table designer. Who may see the data is set by the data permissions of the respective table.

## 11. Table links

**Administration › Database › Table links** joins tables **without foreign keys** – also where no relation field is possible: a
SAFT table with a connected table, two connected tables of different connections, or two SAFT tables related only by a
number (e.g. after an import). Usable in **reports** and as **details in the master-detail view**. Nothing is created
in the source databases: reports are computed in SAFT's database, connected tables through their
[data snapshot](#data-snapshots-for-reports); the master-detail view reads live.

Like a relation field, a link points from **many to one**:

| Setting | Meaning |
|---|---|
| Label | shown in the field tree of the report editor and in the heading of the details, e.g. "Person (Research)" (per language) |
| Source: table, field | the field holding the value to look up – integer, text or selection |
| Target: table, unique field | SAFT table: `id` or a field marked "unique"; connected table: its key. This ensures no row is multiplied. Only fields matching the source field are offered (integer to integer, text to text). |
| Matches | shows while creating how many source rows with a value find a target, e.g. "1,840 of 1,900 (97 %)" – a low value hints at a wrongly chosen field. For connected tables the data snapshot counts. |

In the report editor, the target table's fields appear under the link's label, up to three levels deep and mixed with
ordinary relations (e.g. "Contacts › Person (Research) › Employer › Country"). The base table selection counts the
link as a connection; missing snapshots are created on selection as usual. Values without a match give empty target
fields. As always, the report requires read permission on every table involved. If a link is deleted, every report
using it names the missing link; if the source or target table or field is deleted, the link is removed too. Changes
are recorded in the activity log.

**As details (master-detail):** under "Details (master-detail)" of the **target table** (table designer or table
connector) the table links can be chosen, e.g. "Contacts (via table link "Person (Research)")". Selecting a record of
the target table then shows all rows of the source table with the same value below it – live, also between a SAFT
table and a connected table or two connections. **New record** in this detail table prefills the source field with
the value. Unlike relation fields, a table link does **not protect** the target record **from deletion** (there is no
foreign key). For large connected tables the source field should have an index in the source database. In the
[data model](#3-menus) table links appear with "⇄" before their label.

> **Permissions:** "Table links: view/create/edit/delete".

## 12. Scheduled tasks

**Administration › Operations › Scheduled tasks** lists what SAFT runs by itself on a schedule. Each task has a **type**, its
**parameters** and a **schedule**:

| Type | Parameters | Effect |
|---|---|---|
| Refresh snapshot | connected table | new [data snapshot](#data-snapshots-for-reports) of this table |
| Refresh snapshots of a connection | database connection; "Only tables that already have a snapshot" (on by default) | new snapshots of all (or the already used) connected tables of the connection, one after another. If a table fails, the others continue; the run then counts as failed and names the tables |
| Clean up snapshots (system) | – | enforces the retention of all snapshots, also without a new refresh, and cleans up aborted runs; default daily 03:15 |
| Clean up run log (system) | keep runs (days, default 90) | deletes older entries in the run log; default daily 03:30 |
| Back up database (system) | include files from file fields (default on) | backup like `php artisan db:backup` to `storage/backups`, the files from file fields as a ZIP alongside (only if they are stored on the server); if it fails, the run names the reason and the bell notifies the super admins; default daily 02:30 |
| Clean up backups (system) | keep backups (days, default 14); always keep at least (default 3) | deletes older backups in `storage/backups` (a backup and its ZIP count as one), the newest always stay; default daily 02:45 |
| Clean up files (system) | – | deletes orphaned files from file fields: uploads from cancelled forms, files of records that no longer exist and files without entry – each only after 24 hours; the run only names missing files; default daily 03:00 |
| Reset demo data (system) | demos (Shop, Warehouse, Research); back up first (default on) | deletes the **data** of the chosen demos and reloads the delivered state – tables, menus, reports and permissions stay; attached files of the demo tables are deleted (backed up first). For public demos; delivered switched off and only effective if the server allows it with `DEMO_RESET=true` (otherwise "skipped"); default daily 04:00 |

Plugins can bring further kinds; they appear under **New** in "Type". The example plugin *Stock board* brings
**Warehouse: stocktaking reminder** (reports stock not counted for a configurable number of days in the run log) and
**Warehouse: report minimum stock** (notification in the bell to everyone with the permission *Stock board: View stock
matrix* when items are below minimum stock). Tasks of a switched-off or resting plugin are skipped.

Several tasks for the same table or connection are possible, e.g. weekdays at 06:00 and additionally on Sundays.
**System tasks** can be adjusted and switched off, but not deleted; if they are missing, `php artisan db:seed` creates
them again.

**Schedule:** hourly (at minute …), every N hours (2, 3, 4, 6, 8 or 12, starting at a time – e.g. from 06:00 every
4 hours: 06, 10, 14, 18, 22, 02), daily, weekdays (Mon–Fri) or weekly (day and time), in the application's time zone.
When the clocks change, wall-clock time applies; a time that does not exist (02:30 in March) runs an hour later. If the
server was down, a missed date is caught up **once**. "Active" off: the task no longer runs on schedule.

**Run now** (list or edit page) starts a task immediately; the schedule stays. The **Runs** tab shows every run with
time, trigger (schedule, manually, console), status, duration and message. A task never runs twice: if it is still
running, the new run is "skipped"; the same applies when the same table is being refreshed manually.

**Investigating a failure:** for an unexpected error the message only names a **reference**, e.g. "The task failed
(reference K7Q2M9XA)" – technical details can contain paths, table names and values. Anyone with the permission
*Scheduled tasks: see technical details* (super admins always) opens them at the run with **Details**: error, message,
location in the program, causes and trace. The same error is described in more detail under the reference in the log
file on the server (`storage/logs/laravel.log`).

**System log:** *Administration › Operations › System log* (permission *System log: view*; super admins always) shows the newest
entries of this log file, at most 500 and for large files only its end: time, level (error, warning, info …), message
and reference. Search (also for a reference) and the level filter narrow it down, **Show** opens an entry completely,
**Download extract** saves the part that was read. From the details of a run, **Show in the system log** leads there
directly. The entries may contain paths, SQL with values, email and IP addresses; every visit is recorded in the
activity log.

**Notification:** if a task fails, **all super admins** get a notification (bell, in their language) – on the first
failure and again when it succeeds for the first time afterwards; repeated failures in between are counted in the
column "Failures in a row". The dashboard widget **Scheduled tasks** shows a traffic light (all fine, failed tasks,
scheduler not running) and the latest runs.

**Requirement:** the tasks are run by the scheduler through a cron entry on the server (see
[system administration](SYSADMIN_GUIDE.md#4-scheduled-tasks-and-cron)). Without it, the list says "Scheduled tasks are
currently not running".

> **Permissions:** "Scheduled tasks: view/create/edit/delete"; "Run now" with "edit". For the widget additionally
> "Dashboard widget: Scheduled tasks (status)". Changes to tasks are recorded in the activity log.

**App:** tasks for a table belong to its [app](#16-apps-business-applications); for other tasks you choose the
app. System tasks belong to no app.

---

## 13. AI functions

SAFT has two assistants that turn descriptions in your own words into a proposal. Both only propose: the result lands
in the editor or form, is checked there and only takes effect with **Save** or **Create**.

| Assistant | Where | Permission | Can |
|---|---|---|---|
| **Reports** | Report editor, field "Describe the report" | Reports: use assistant | Choose base table, rows, columns, values, filters, sorting, number of rows and display; build calculated values and columns as well as figures from values with formulas; ask back; change a proposal on request |
| **Tables** | Table designer: "Describe the table" when creating, **Adjust with AI** for existing tables | Table designer: use assistant | New table with fields, types (including file fields with kinds, size and number), choices, relations to existing tables and labels in all four languages; for existing tables new fields, labels, required/unique/searchable/sortable, further choices and display fields of relations (preview in words, then **Apply**). Changing types, renaming and deleting stay manual |
| **Help** | **?** button in the header; **Documentation** › tab **AI assistant** | Help: use assistant | Answers questions about using SAFT from the manuals the person may see, with references to the sections; proposes nothing and changes nothing |

### Setup

1. **Prepare the provider:** For Anthropic create an API key in the Claude Console (*API Keys*); a Claude subscription
   (Pro/Max) alone is not enough, the API is billed separately by usage. Alternatively Amazon Bedrock or Google Vertex
   AI (setup see [System administration](SYSADMIN_GUIDE.md#5-ai-functions)).
2. **Administration → Application → Global settings → AI assistant for reports:**

   | Setting | Meaning |
   |---|---|
   | **Switch on the AI assistant** | Main switch for the report and table assistants |
   | **Provider, model** | Anthropic, Amazon Bedrock or Google Vertex AI; model ID, e.g. `claude-opus-5-5` (with Bedrock prefixed `anthropic.`). Empty = value from `.env` |
   | **Thinking depth** | Low, medium (default), high – higher is more thorough but slower and more expensive |
   | **API key** | Stored encrypted and never shown again; leave empty to keep it, "Delete the stored key" removes it. The activity log shows it masked |
   | **Processing region** | Optional (Anthropic), e.g. `us`; empty = workspace default |
   | **Requests per person and day** | Default for everyone (default 50) |
   | **Send the values of selection fields** | Improves filters such as "only regular customers", but passes the entries of selection lists to the provider |
   | **Switch on help assistant** | Separate switch for the help assistant, independent of the main switch above |
   | **Help assistant knows the data model** | Also sends the tables and fields the person may see (for questions like “Where do I enter suppliers?”); default off |

3. **Test connection** checks key and model via the provider's free model query, without saving.
4. **Grant permissions:** *Reports: use assistant* or *Table designer: use assistant* to the desired roles.
   For the help assistant: *Help: use assistant*.
5. **Limit per person** (optional): under **Users** in the field **AI requests per day** – empty = default, 0 = blocked.
   The limit is shared by all assistants.

### What is transmitted – privacy

- **Transmitted** are the text entered, the last rounds of the conversation (for follow-ups like "only 2026") and the
  **data model**: for the report assistant the tables and fields the person may evaluate (labels, technical names,
  types, relations; values of selection fields only with the setting above), for the table assistant the structure of
  the table designer's tables.
- **Help assistant:** the question, the last six messages of the conversation, title and address of the page the
  person is on, and the manuals they see in the online help (user guide, frequently asked questions, with an
  administration permission the administrator guide), their **own roles and permissions** (so the assistant can say
  which permission is missing for something – in role simulation those of the simulated role) and their **menu** (paths
  and addresses of the entries they see); with the switch above
  also the data model as for the report assistant.
- **Never transmitted** are records, report results, user data or credentials.
- Above the input field people see which provider receives their description.
- The contract with the provider (data processing agreement, storage location) is the organisation's responsibility;
  the **processing region** or choosing Bedrock or Vertex in a specific region helps with this.

### How a proposal is made

The assistant sends the model three things:

1. **Fixed instructions** (system instructions, stored in the source code). They explain the structure of a report or
   table, the notation of field references, the allowed filters, formulas and displays, and rules such as "use only
   tables and fields from the data model", "ask one short question when unclear" or "prefer stored fields such as
   *purchase value* over a formula".
2. **The data model** (see above). The provider caches it for a few minutes, which makes follow-up requests faster and
   cheaper.
3. **The request** with date, language and the current state of the editor – so "as a line" changes the existing
   proposal instead of starting over.

The model answers in a **fixed JSON schema** (no free text). SAFT checks the proposal like any manual input: do the
tables and fields exist, may the person use them, do filters, formulas and display fit? Errors go back to the model
**once** for correction; if the proposal is still invalid, SAFT reports "No valid proposal" and changes nothing. So the
model cannot create anything the person could not also create by hand.

**Good descriptions** say what is counted or summed, what to group and filter by and what the result should look like:
"purchase volume per supplier for 2026, descending, as bars". The assistant answers ambiguous terms ("revenue" in a
warehouse) with a question. It cannot invent what is missing in the data model – then an additional (computed) field
in the table designer helps, e.g. *purchase value* = `round(quantity * purchase_price, 2)`.

### Help assistant

The help assistant answers questions about using SAFT, e.g. “How do I export a table to Excel?”. It answers only from
the manuals the person sees in the online help, in the language of the interface, and refers to the sections; a click
opens the section in the documentation. For questions like “where can I …?” it links the page from the person's menu.
SAFT removes references to sections or pages that do not exist or that the person does not see, as well as external
links. If something is not in
the manuals, it says so and refers to the administration.

- **Conversations** are stored and can be reopened, continued and deleted; each person sees only their own. Only the
  last six messages go to the model, the manuals are cached.
- **Permissions:** the person's permissions apply – also in **role simulation**: if the simulated role lacks the
  permission *Help: use assistant*, the button is missing; otherwise the assistant sees the manuals and tables of that
  role. Conversations and the daily limit belong to the real person.
- **Plugins:** the assistant knows validation rules and fields from plugins through the data model only by name, not
  their exact rule.

### Log and costs

- Every request is recorded in the **activity log** (event **AI assistant**) with person, request text, result
  (proposal, question, error), model and the **tokens** used (input, output, from cache).
- **Costs** are charged by the provider per token; current prices are on its website. Most of the input is the data
  model, which becomes cheaper for follow-up requests thanks to the cache. Costs are controlled by model, thinking
  depth and the daily limit; a spending limit in the provider account caps them additionally.

> **Outlook:** Organisation-specific terms (e.g. "for us, revenue means the purchase value of receipts") could in future
> be stored as **notes for the AI assistant** in the global settings and sent along with the fixed instructions. Until
> then, meaningful labels and descriptions of tables and fields help.

## 14. Plugins

Plugins extend SAFT with their own pages and functions, e.g. a specialised entry form. They are installed by the
system administration (see [System administration, chapter 7](SYSADMIN_GUIDE.md#7-plugins)); how to write a plugin is
described in the [developer guide](DEVELOPER_GUIDE.md). Plugins are program code with full rights – only have plugins
from a trusted source installed.

**How to recognise parts from plugins:** pages of a plugin carry the label **"Plugin: …"** below the title (with a
puzzle icon; anyone allowed to see this page gets here with one click), dashboard cards carry it below the card.
Buttons in data tables name the plugin in their tooltip, scheduled task types carry the addition "(plugin …)", field
rules under *Further checks* show icon and plugin, formula functions in the formula editor name the plugin in their
tooltip.

The page **Administration › Application › Plugins** (permission *Plugins: view*) lists the installed plugins:

| Column | Meaning |
|---|---|
| **Name**, **Identifier** | name and unique identifier; below it the description, the installation location (file of the plugin class, e.g. `plugins/lager-umbuchung/src/LagerUmbuchungPlugin.php`) and the Composer package. The plugin's permissions start with the identifier |
| **Version** | installed version |
| **Status** | *active*; *resting* if something the plugin needs is missing (see **Dependencies**); *switched off* if the administration has switched it off (see below) |
| **Compatible** | *Yes* if the plugin fits the plugin interface (API) of this installation (or the SAFT version, if it requires one); below it its requirement, e.g. “API ^1.0”; *No* with a hint to what it requires and what is provided; *not specified* if the plugin states nothing. The provided API version is also shown under **Documentation › About SAFT XT** |
| **Permissions** | permissions the plugin brings |
| **Extensions** | which tables the plugin checks changes on, where it shows buttons in data views, and its dashboard tiles, formula functions and validation rules |
| **Dependencies** | whether the tables and fields of the table designer the plugin needs are present (e.g. “4 tables present”), otherwise what is missing; the button next to it opens the complete list with labels and state (present, missing, deactivated). If the plugin requires another plugin (e.g. “Requires plugin: Stock transfer”), it is shown here as well – if that one is switched off or resting, this one rests too |
| **Menu entries** | how many of the suggested entries are already in the menu |

**Putting a new plugin into operation** (permission *Plugins: manage*):

1. **Create menu entries** adopts the plugin's suggestions into the menu, with a new group if needed. Afterwards they
   are ordinary menu entries that you can move and rename under **Menus**.
2. Grant the plugin's permissions under **Roles**; they are listed among the general permissions as
   “<plugin name>: <permission>”. If the plugin works with table designer tables, the role also needs the matching
   data permissions – which ones is stated in the plugin's description.

**Dependencies and protection in the table designer:** tables and fields an installed plugin needs cannot be deleted,
deactivated or renamed in the table designer; for fields the type is fixed as well. The field list marks them with a
puzzle icon, *Deactivate* and *Delete* are greyed out, and the hint names the plugin. Labels, required, display and
rules remain editable. If a plugin lacks something – for example because it was installed before the warehouse demo –,
the page reports **Plugins are resting**: the plugin's buttons and checks do not apply, and its pages show what is
missing.

**Manuals from plugins:** a plugin can ship its own user guide. It appears under **Documentation** as a further tab –
for people with one of the permissions the plugin names for it – and is knowledge for the help assistant. If the plugin
is resting or switched off, the manual disappears. The example plugins *Stock transfer* and *Stock board* each ship one
in four languages. Validation rules from plugins also show in the table designer under **Further checks** what they
require.

**Switching plugins off** (permission *Plugins: manage*): **Switch off** in the plugin's row shuts it down without
removing it – for example for tests or when two plugins overlap in function. The dialog shows beforehand what goes
away. While a plugin is switched off,

- its pages cannot be reached (not even through a saved address), its tiles disappear from the dashboard and its menu
  entries are hidden;
- its checks of changes, its buttons in data views, validation rules and formula functions do not apply, and scheduled
  tasks of its kinds are skipped;
- plugins that require it rest (the dialog names them);
- permissions, menu entries and data are kept.

**Switch on** restores everything immediately. Take care with plugins that check or complete changes (column
**Extensions**, “Checks changes”): what is entered in the meantime is not checked or completed by the plugin later
either – with the example *Stock transfer*, stock movements book no stock during that time. Every switching off and on
is recorded in the change log. System administration can also switch plugins on the console
([System administration, chapter 7](SYSADMIN_GUIDE.md#7-plugins)).

**What plugins do in data views:** a plugin can show additional buttons above a table or in its rows and check
changes. If it rejects a change, “Action not possible” appears with the plugin's reason and nothing is saved – in the
data view as well as in imports. These checks do not apply while demo data is being loaded.

**Download developer context** (permission *Plugins: manage*): a ZIP file for external developers or AI agents writing
a plugin for this installation – rules, a description of the plugin interface and the schema of the selected tables
(fields, types, select values, relations, formulas, rules), in English. Records, users and credentials are never
included, connected tables only with their fields. Table and field names can still reveal business secrets – only pass
the file on to authorised people. Every download is recorded in the activity log.

**After a plugin has been removed**, its menu entries (hidden) and permissions remain. The page then reports them as
**Leftovers of removed plugins**; **Clean up** deletes the selected items. Menu entries are preselected, permissions
are not: roles lose deleted permissions for good, even if the plugin is installed again later. The dialog names the
roles that still hold each permission. If plugins are only missing temporarily – e.g. after an update without the
development packages – install them again first instead of cleaning up.

> **Example:** the plugin *Stock transfer* (in development and demo installations) extends the warehouse demo: stock
> movements book the stock at the location (issues always negative, receipts positive; a stocktaking sets the last
> count), stock levels can only be changed through stock movements, the form *Transfer* and the “Transfer” button in
> *Stock levels* move goods between locations, and if an item's total stock falls below its minimum stock, a
> notification appears in the bell. The tile *Warehouse: minimum stock* on the dashboard shows the affected items.
> The plugin *Stock board* requires *Stock transfer* and shows the *Stock matrix* (stock per item and location), the *Stock board* (transfer stock by dragging) and the tile *Warehouse: stock by location* as well as two kinds of scheduled tasks (see [chapter 12](#12-scheduled-tasks)). The plugin *Swiss checks* brings the validation rules AHV number, IBAN, UID and postal code as well as the formula
> function `rappen()`.

## 15. Transfer configuration

**Administration › Application › Transfer configuration** brings tables, reports, menu entries and roles from one installation to
another – typically from the test to the production installation. Instead of rebuilding everything by hand, you export
a **package** (a JSON file) and import it in the other installation.

**What goes into the package** and what does not:

| In the package | Not in the package |
|---|---|
| table designer tables with fields, checks, formulas, table rules and detail tables | records (including files from file fields; the preview names such fields under *Notes*) |
| links for reports | users and their dashboard layout |
| reports – shared ones and your own | other people's private reports |
| menu entries (with their parent groups) | database connections and credentials |
| roles with their permissions and hidden menu entries | global settings, scheduled tasks |

All references are stored as **names**, not internal numbers – tables and fields by technical name, menu entries by
path (e.g. "Warehouse (demo) › Warehouse operations › Stock levels"), reports by name. So the package fits any installation.

**Export** (permissions *Configuration: view* and *export*), tab *Export*:

1. Select tables, reports, menu entries and roles on the tabs of the same name; the number on a tab gives the selected
   entries. Tables and reports are grouped by menu group (reports by the group
   of their base table), menu entries by their parent entry; below each report it says which tables it needs, below each
   menu entry its target.
2. **Include dependencies** (default): a report brings its tables, a menu entry its table or report and parent groups,
   a table the tables of its relations and formulas. If the switch is off, reports are greyed out as long as not all their
   tables are selected, and menu entries as long as their table or report is missing (below each it shows the target and
   what is missing); a note names entries already ticked – they are not added to the package.
3. Enter a name (e.g. "Sales version 3") and **Download package**. The export is recorded in the activity log.

**Import** (permissions *Configuration: view* and *import*), tab *Import*:

1. Upload the package file. The **preview** shows every entry with its status:

   | Status | Meaning |
   |---|---|
   | **new** | will be created |
   | **identical** | already exists exactly like this – nothing to do |
   | **different – skipped** | already exists, but differently; stays unchanged |
   | **prerequisite missing** | e.g. a table, a parent menu entry or a plugin's page is missing; the *Notes* column says what |

   Missing plugins and database connections are listed at the top under **Missing prerequisites**. Permissions that do
   not exist in the installation (e.g. of a missing plugin) are skipped for roles and mentioned as a note.
2. **Create new entries** creates all entries with status *new* – tables, links, reports, menus and roles in this order.
   Imported reports belong to you; whether they are shared comes from the package.
3. Afterwards the preview shows the created entries as *identical*. The import is recorded in the activity log.

Existing entries are **never changed** – not even when they differ. To change a table in the target installation, adapt
it there in the table designer. Connected tables (table connector) must already exist in the target installation; the
package lists them as a prerequisite.

> **Tip:** import a package into a test installation first and check the preview. For recurring transfers there are the
> commands `saft:config-export` and `saft:config-import` (see [System administration](SYSADMIN_GUIDE.md)).

**Target app when importing:** tables, top-level menu groups and roles of the package go into a chosen
[app](#16-apps-business-applications) – an existing or a new one (suggested with the package's label); on the console
`saft:config-import --app=<ID>` or `--new-app="Name"`. When exporting, tables and reports are listed under
“App › menu group”.

## 16. Apps (business applications)

An **app** groups what belongs together: tables, menus, reports, table links, roles and scheduled tasks, e.g.
“Warehouse” or “HR”. Each of these objects belongs to exactly one app; users, sign-in, global settings, AI connection,
database connections and the rest of the administration remain shared by all apps. Background and next steps
(switching between apps, own dashboard, moving, packages): [concept](../concept/APPS.md) (in German).

**Administration → Application → Apps** (permissions *Apps: view* and *manage*) lists the apps with the number of their
tables, menus, reports, links, roles and tasks:

| Setting | Meaning |
|---|---|
| Name, description | in all languages |
| Table prefix | put in front of the technical name of new tables, e.g. `hr` → `hr_absences`. Suggested from the name when an app is created; a later change only applies to new tables |
| Icon, sort order | display in lists |

Only an empty app can be deleted.

**Where the app is chosen – and where it follows:**

| Object | App |
|---|---|
| Table (table designer, import from file, table connector) | chosen when creating; required if there are several apps. Changing the app replaces the prefix in the suggested name |
| Top-level menu entry or menu group | chosen; without an app the entry belongs to the system (e.g. documentation) |
| Menu entry inside a group | app of the group; data entries the app of their table |
| Report | app of its base table |
| Table link | app of the source table |
| Role | chosen (see [Roles](#2-roles-and-permissions)); *super-admin* and *benutzer* are system roles without an app |
| Scheduled task | for a table: its app; otherwise chosen; system tasks without an app |

**Boundary between apps:** relation fields, table links for reports and therefore also “Copy from” and formulas over
child tables are only possible within one app. The table designer only offers tables of the same app; the AI table
assistant only sees the data model of the chosen app.

**After the update:** existing installations get an app “Existing data” with all existing tables, menus, reports,
roles and tasks; system menus, system roles and system tasks stay without an app. New installations start without an
app; the demo data create one app each (Shop, Warehouse, Research).

## Appendix A – Permissions at a glance

| Area | Permissions |
|---|---|
| Users, roles, menus, identity providers, table designer, table connector, database connections | view, create, edit, delete; table designer also import and use assistant (AI) |
| Activity log | view |
| Authentication settings, global settings | view, edit |
| Dashboard widgets | per widget |
| Reports | view, create (own), edit and delete (also other people's), use assistant (AI) |
| Table links | view, create, edit, delete |
| Scheduled tasks | view, create, edit (also "Run now"), delete, see technical details (error details of failed runs) |
| System log | view (log file of the server, read only) |
| Plugins | view; manage (create menu entries, clean up); the plugins' own permissions are listed under their name |
| Configuration | view (page and menu entry); export; import |
| Apps | view; manage (create, edit, delete empty ones) |
| Help | use assistant (help assistant) |
| Data per table (data permissions matrix) | view, create, edit, delete, export, import; for linked external tables write permissions only if enabled |
