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

Relations are only possible between tables of the same app (administrator guide, chapter 16). If the table you want
belongs to another app, create the new table in that app – or extend that app.

### An app has disappeared from the switcher.

The administration has deactivated it – e.g. for rework or before it is removed. Its data is kept; as soon as it is
activated again, it appears as before. Ask the administration.
