# SAFT XT – Frequently Asked Questions

[Deutsch](FAQ.de.md) · **English** · [Français](FAQ.fr.md) · [Italiano](FAQ.it.md)

**Version:** 0.1 · **Audience:** everyone

**Other guides:** [User guide](USER_GUIDE.md) · [Administrator guide](ADMIN_GUIDE.md) · [System administration](SYSADMIN_GUIDE.md)

---

## Contents

- [Frequently asked questions](#frequently-asked-questions)
   - [I cannot see a table in the menu.](#i-cannot-see-a-table-in-the-menu)
   - [No lines appear below an order.](#no-lines-appear-below-an-order)
   - [I cannot delete a record.](#i-cannot-delete-a-record)
   - [I cannot change a column to another type.](#i-cannot-change-a-column-to-another-type)
   - [I used "Forgot password" but did not receive an email.](#i-used-forgot-password-but-did-not-receive-an-email)
   - [I lost my phone with the authenticator app.](#i-lost-my-phone-with-the-authenticator-app)
   - [In which language does the application appear?](#in-which-language-does-the-application-appear)
   - [I chose English, but a menu entry or a table still has a German name.](#i-chose-english-but-a-menu-entry-or-a-table-still-has-a-german-name)
   - [I do not see an "Export" button.](#i-do-not-see-an-export-button)
   - [A table has no "New record" and "Edit".](#a-table-has-no-new-record-and-edit)
   - [How do I pass on a specific view to someone?](#how-do-i-pass-on-a-specific-view-to-someone)
   - [A file cannot be uploaded.](#a-file-cannot-be-uploaded)
   - [A table is missing from the choice for a relation.](#a-table-is-missing-from-the-choice-for-a-relation)
   - [An app has disappeared from the switcher.](#an-app-has-disappeared-from-the-switcher)
   - [I cannot remove a document from a file field.](#i-cannot-remove-a-document-from-a-file-field)
   - [Checkboxes for selecting are missing in a list.](#checkboxes-for-selecting-are-missing-in-a-list)
   - [A document could not be sent by email.](#a-document-could-not-be-sent-by-email)
   - [The QR bill is missing or the banking app cannot read it.](#the-qr-bill-is-missing-or-the-banking-app-cannot-read-it)
   - [The Word file looks different from the PDF.](#the-word-file-looks-different-from-the-pdf)
   - [Instead of the file, a page “Job” with a progress bar appears.](#instead-of-the-file-a-page-job-with-a-progress-bar-appears)
   - [Importing a large file takes long.](#importing-a-large-file-takes-long)
   - [How do invoices get a consecutive number?](#how-do-invoices-get-a-consecutive-number)
   - [How do I take over existing invoice numbers into a number sequence?](#how-do-i-take-over-existing-invoice-numbers-into-a-number-sequence)
   - [How does an invoice get its number only when it is sent?](#how-does-an-invoice-get-its-number-only-when-it-is-sent)
   - [How do invoices and credit notes get numbers of their own?](#how-do-invoices-and-credit-notes-get-numbers-of-their-own)
   - [How do I change many records at once?](#how-do-i-change-many-records-at-once)
   - [The bulk edit reports “Nothing changed”.](#the-bulk-edit-reports-nothing-changed)
   - [Some records stayed unchanged in the bulk edit.](#some-records-stayed-unchanged-in-the-bulk-edit)
   - [How do I undo a bulk edit?](#how-do-i-undo-a-bulk-edit)
   - [How do I export only some records?](#how-do-i-export-only-some-records)
   - [Which records did a bulk edit change?](#which-records-did-a-bulk-edit-change)

---
## Frequently asked questions

### I cannot see a table in the menu.

You have no read permission for it, there is no menu entry, or your role hides the entry. Contact your administrator
(Roles → Data permissions or Menu entries, or Menus). If the table belongs to another **app**, first switch to
that app with the switcher at the top.

### No lines appear below an order.

Either no details are set for the table (Table designer → Details), or you lack read permission for the order lines.

### I cannot delete a record.

Other records still refer to it (see the message). Delete those first.

### I cannot change a column to another type.

The change would alter existing data. It is only possible with an empty column or for lossless type changes (see
[Administrator guide, chapter 4](ADMIN_GUIDE.md#4-table-designer)).

### I used "Forgot password" but did not receive an email.

The confirmation always appears, even if there is no account for the address. Check the spelling of the address and
your spam folder, and wait a moment before trying again, because repeated requests are blocked. If this does not
help, contact your administrator.

### I lost my phone with the authenticator app.

Sign in with one of your **recovery codes**: after the password, click **"Use a recovery code instead"**. Each code
works only once. Then set up the app on the new phone in *My settings*. If you no longer have the codes either, the
administration resets the second factor; after that the password is enough until you have set it up again.

### In which language does the application appear?

The following order applies: the choice stored in your account, the choice in this session or on this device, the
language of the browser, and finally the administrator's default.

### I chose English, but a menu entry or a table still has a German name.

No English label is maintained for this entry, so the German one appears. The administrator can add it under Menus
or in the table designer. Data such as company names is never translated.

### I do not see an "Export" button.

Either export is not allowed for this table (Table designer → Allow export), or your role lacks the permission
(Roles → Data permissions → Export).

### A table has no "New record" and "Edit".

It is a linked table of an external database (table connector) for which writing is not enabled (table connector →
Writing), or your role lacks the permission (Roles → Data permissions).

### How do I pass on a specific view to someone?

Copy the address from the browser; it contains the table, the column selection and the selected records. The
recipient only sees what they have permissions for.

### A file cannot be uploaded.

The field only allows certain file kinds and a maximum size and number (Table designer → field → Allowed files,
Maximum size per file). The content is checked: a renamed file with the wrong extension is rejected. If even small allowed
files fail, the server's limit is too low (system administration: `upload_max_filesize` and `post_max_size`).

### A table is missing from the choice for a relation.

Relations exist between tables of the same app; tables of another app only appear if that app has **shared them as an
interface** – then with the app name in brackets, and only the shared fields are offered as display fields
(administrator guide, chapter 15). If the table is missing, ask the administration to share it – or create the new
table in that app.

### An app has disappeared from the switcher.

The administration has deactivated it – e.g. for rework or before it is removed. Its data is kept; as soon as it is
activated again, it appears as before. Ask the administration.

### I cannot remove a document from a file field.

Documents that SAFT created from a template and stored with the record (e.g. an invoice) are write-protected: only
those who may delete records of the table may remove them. This keeps it traceable which document was sent. Simply
create a corrected document again – it appears as a new version next to it (user guide, section 4.10). If a document
really has to go, ask someone with the delete permission.

### Checkboxes for selecting are missing in a list.

Checkboxes for selecting several records only appear if there is a bulk action you may use: **“Change …”** (permission
*Edit*, and the administration allows bulk edits for the table; user guide, section 4.11), **“Delete selected”**
(permission *Delete*), **“Export selection”** (permission *Export* and export enabled for the table; section 4.7) or **“Create documents”** (active document template and permission *Documents: generate*; section
4.10). Linked tables have no bulk edit, but they do have “Export selection”.

### A document could not be sent by email.

- **No switch “Send by email”:** the template provides no email, or your role lacks the permission *Documents: send*.
- **“To” is empty or “no valid email address”:** the record or the related customer has no address – or you may not
  read the related table. Enter the address by hand.
- **“The mail server rejects the sender address …”** or another message from the server: this is a setting of the
  administration (email sending in the global settings). The document was created anyway and – if provided – stored.

### The QR bill is missing or the banking app cannot read it.

- **“The QR bill is invalid” or “The letterhead lacks for the QR bill”:** creditor details (postcode, town, IBAN) are
  missing in the letterhead or the IBAN is wrong – the administration corrects the letterhead (Administrator guide,
  chapter 16, “QR bill”).
- **“The amount of the QR bill is not a number”:** the record has no amount or it is not a number.
- **The banking app does not recognise the code:** show the PDF at original size on screen or print it (not scaled down,
  “fit to page” off). The code must be 46 × 46 mm.

### The Word file looks different from the PDF.

The Word version is meant for editing, not as a replacement for the PDF: a letterhead as PDF background, an SVG logo and
the QR bill are only in the PDF; Word instead shows the logo (PNG/JPEG) and sender as text in the header and uses the
Arial font. For sending and storing, SAFT always uses the PDF.

### Instead of the file, a page “Job” with a progress bar appears.

Export, import, “Create documents” for many records and snapshots run as a **job** in steps. Small amounts are done
before you notice, and the file comes as usual. If it takes longer, SAFT shows the job's page: leaving it open is
fastest; but you may also close it – the job continues in the background, the bell reports the end, and the file is
ready for download for 24 hours under **My jobs** (user menu).

### Importing a large file takes long.

Every record is created as if entered by hand – with defaults, computed fields, sums in parent records and the rules of
the plugins. That costs a few milliseconds per record; with tens of thousands of rows this adds up to minutes. The
progress shows **checking** first, then **importing**. You may leave the page; on errors or cancel nothing is created,
or everything created is removed again.

### How do invoices get a consecutive number?

With a field of type **Number sequence**, e.g. with the pattern `R-{YYYY}-{0000}` and a yearly restart. SAFT assigns
the number on create, never twice and not again after deletion; afterwards it cannot be changed. That is why the form
only shows "assigned when saving". Set it up in the table designer, see
[Administrator guide, Number sequences](ADMIN_GUIDE.md#number-sequences).

### How do I take over existing invoice numbers into a number sequence?

Change the existing field (text, integer or choice) to the type **Number sequence** in the table designer. Beforehand,
SAFT shows how many values match the pattern and where the numbers continue; values that do not match stay unchanged.
Empty values can be filled right away or later with **Number now**, and with **Set counter** e.g. the next year starts
at 1000. See [Administrator guide, Converting existing fields](ADMIN_GUIDE.md#converting-existing-fields).

### How does an invoice get its number only when it is sent?

Enter a condition under **Assign when** for the number sequence, e.g. `status <> 'draft'`. The number then stays empty
for drafts and is assigned on the first save on which the condition is true; after that it is fixed. Discarded drafts
therefore leave no gaps. See
[Administrator guide, Assigning only when a condition holds](ADMIN_GUIDE.md#assigning-only-when-a-condition-holds).

### How do invoices and credit notes get numbers of their own?

Under **Group** in the number sequence, choose the select field with the document type and put `{GROUP}` into the
pattern, e.g. `{GROUP}-{YYYY}-{000}`. Enter a code for each choice, e.g. `R` for invoice and `G` for credit note; each
document type then counts on its own (`R-2026-001`, `G-2026-001`). See
[Administrator guide, A counter per group](ADMIN_GUIDE.md#a-counter-per-group).

### How do I change many records at once?

Filter the list, tick the checkboxes (or “Select all N”) and click **“Change …”**. Tick the fields, choose the kind (*set*, *clear*, *calculate*,
*replace*, *formula*) and check the preview; **“Check only”** changes nothing yet. Music school example: filter semester and teacher, select
all courses, set *Teacher* to the new one. Likewise: *calculate* the fee of the courses of a subject “change by %” 5,
rounded to a multiple of 0.05; *replace* “str.” with “strasse” in addresses. See
[User guide 4.11](USER_GUIDE.md#411-changing-several-records-bulk-edit).

### The bulk edit reports “Nothing changed”.

At least one selected record fails the checks – the same as when editing by hand (required field, validation rule, table
rule, plugin). The message names the records with the reason. It is all or nothing: deselect these records or correct
them one by one and start the bulk edit again. If it says “changed by someone else in the meantime”, someone saved
during the bulk edit; it was fully reverted and can simply be repeated. With calculations and formulas there are two more
reasons: **“Division by 0”** (the formula divides by a value of 0 for this record; `if(field = 0, …, …)` avoids it)
and **“The number is too large for the field”** (the result has more digits before the decimal point than the field
allows). The preview already shows both in red. If the message names a conflict with a record you cannot find (e.g. “already teaches on Wednesday 23:00 …”), it lies between two selected records: the check already sees the other selected ones with their new values.

### Some records stayed unchanged in the bulk edit.

That is intended when nothing would change for them: they already had the new value (“already like that”), or when
calculating the previous value was empty, when replacing the search text did not occur, or the formula yielded nothing
(“without a value or match”). Preview and message count both. To fill empty values, filter for “empty” and choose
*set*. If *replace* finds less than expected, check the boxes “Match case” and “Whole word only”: “Str.” as a whole word
does not match in “Bahnhofstr.” because a letter comes before it.

### How do I undo a bulk edit?

Via the link **“Undo”** in the success message or later via **“Recent bulk edits”** above the table. The confirmation
shows beforehand how many records come back and examples with *now → after*. Records where a changed field has a
different value since stay and the message names them. If the button is disabled (“No record would be undone”), all
records were changed again since, often by a later bulk edit: undo that one first. Assigned numbers and sent e-mails
stay. Only those who may view the activity log undo other people's bulk edits. Bulk actions from plugins (e.g. “Generate
lessons”) are not in the list and cannot be undone there. See
[User guide 4.11](USER_GUIDE.md#411-changing-several-records-bulk-edit).

### How do I export only some records?

Tick the records at the start of the rows and choose **“Export selection”** (Excel or CSV) in the bar above the table.
The file contains only the selected records, in the order of the list and with the visible columns. To select across
all pages, use the checkbox in the table header and **“Select all N”**; you can untick single ones afterwards.
“Export” at the top, by contrast, exports all matches of the filter. See
[User guide 4.7](USER_GUIDE.md#47-exporting-data).

### Which records did a bulk edit change?

Open **“Recent bulk edits”** above the table and click **“Show records”** next to the bulk edit (the link is also in
the “Undo” confirmation). The list then shows exactly those records, with a chip “Bulk edit of …” above the table.
Your saved view is kept: the ✕ on the chip or “Reset view” brings your previous filters back. See
[User guide 4.11](USER_GUIDE.md#411-changing-several-records-bulk-edit).
