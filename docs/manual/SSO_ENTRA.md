# Appendix D – Setting up single sign-on with Microsoft Entra ID

**English** · [Deutsch](SSO_ENTRA.de.md) · [Back to the user manual](USER_GUIDE.md)

**Version:** 0.1 · **Audience:** administrators of SAFT and of the Microsoft tenant

This guide walks step by step through sign-in with Microsoft Entra ID (formerly Azure AD): first the app registration
at Microsoft, then the identity provider in SAFT, finally testing and troubleshooting. An overview of all settings is
given in [Administrator guide, chapter 6](ADMIN_GUIDE.md#6-identity-providers-single-sign-on).

---

## Contents

1. [First: decide on address and identifier](#1-first-decide-on-address-and-identifier)
2. [App registration in the Microsoft Entra admin center](#2-app-registration-in-the-microsoft-entra-admin-center)
3. [Create the identity provider in SAFT](#3-create-the-identity-provider-in-saft)
4. [Test the sign-in](#4-test-the-sign-in)
5. [If something goes wrong](#5-if-something-goes-wrong)
6. [Maintenance](#6-maintenance)

---

## 1. First: decide on address and identifier

After signing in, Microsoft redirects back to a **redirect URI** of SAFT. It must be registered in Entra **exactly** as
SAFT uses it:

```
<address of SAFT>/auth/sso/<identifier>/callback
```

- **Identifier:** a short name of the identity provider in SAFT, e.g. `entra`. It is part of the address and should
  not be changed afterwards. This guide uses `entra`.
- **Address of SAFT:** SAFT builds the redirect URI from the address under which it is opened in the browser.
  - **Server:** e.g. `https://saft.example.com` → `https://saft.example.com/auth/sso/entra/callback`. Entra requires
    `https://`.
  - **Test on your own computer:** Entra allows `http://` only for `localhost`. So open SAFT at
    **`http://localhost:8000`**, not at `http://127.0.0.1:8000` → `http://localhost:8000/auth/sso/entra/callback`.
    Stay with this address: `localhost` and `127.0.0.1` have separate sessions; switching in the middle of the sign-in
    ends with "The sign-in has expired or is invalid".

You need an account with administrator rights in the Microsoft tenant (e.g. *Application Administrator*) and a super
admin in SAFT.

---

## 2. App registration in the Microsoft Entra admin center

Sign in at <https://entra.microsoft.com>. The labels follow the English interface; in the German one they are named as
in the [German version](SSO_ENTRA.de.md) of this guide.

**2.1 Note the tenant ID.** Under **Identity → Overview**, copy the **Tenant ID** (a GUID). Also note the **primary
domain**, e.g. `company.onmicrosoft.com` or `company.com`.

**2.2 Register the app.** **Identity → Applications → App registrations → New registration:**

| Field | Value |
|---|---|
| Name | e.g. `SAFT` (visible to users, for instance in the consent dialog) |
| Supported account types | **Accounts in this organizational directory only** (single tenant) |
| Redirect URI | Platform **Web**, the redirect URI from step 1 |

Then **Register**. SAFT only allows its own tenant (not `common` or `organizations`).

**2.3 Note the application ID.** On the overview page of the app, copy the **Application (client) ID**.

**2.4 Create a client secret.** **Certificates & secrets → New client secret**: choose a description and expiry
(e.g. 12 months), **Add**.

> **Important:** Copy the **"Value"** column right away – not the "Secret ID". Microsoft shows the value only this
> once. Note the expiry date (see [section 6](#6-maintenance)).

**2.5 API permissions.** Under **API permissions**, **Microsoft Graph → Delegated → `User.Read`** must be listed – it
is already there for new registrations. Recommended: **"Grant admin consent for …"**, so users see no consent dialog.
SAFT requests `openid`, `profile` and `email` itself.

**2.6 Optional – sign out at Microsoft as well.** If SAFT should also sign out at Microsoft (setting "Also sign out at
the identity provider"), add the sign-in page of SAFT as an additional redirect URI of platform Web
under **Authentication**, e.g. `https://saft.example.com/admin/login` or `http://localhost:8000/admin/login`. Note:
this also ends the browser session with all other Microsoft services (Outlook, Teams …).

**2.7 Optional – take over roles from Entra.** SAFT can grant or revoke roles at every sign-in. **App roles** are
recommended:

1. **App roles → Create app role:** display name (e.g. "SAFT case work"), allowed member types **Users/Groups**,
   **value** (e.g. `saft-casework`), enable.
2. **Identity → Applications → Enterprise applications → SAFT → Users and groups → Add user/group:** assign people or
   groups to the app role.

Alternatively **groups:** **Token configuration → Add groups claim** (security groups, as *Group ID* in the ID
token). With more than 200 groups per person Entra sends no groups in the token – then rather choose *"Groups assigned
to the application"*.

**2.8 Test people.** Under **Users → New user** you can create test accounts, e.g. `anna@company.onmicrosoft.com`.

> **Good to know:** accounts without a mailbox (without a Microsoft 365 licence) have no e-mail address in Entra. SAFT
> then uses the **sign-in name** (e.g. `anna@company.onmicrosoft.com`) as e-mail – important for the user assignment
> and the allowed domains in step 3.

---

## 3. Create the identity provider in SAFT

Sign in as super admin at the address from step 1, then **Administration → Users and access → Identity providers → Create**.

**3.1 General and Entra settings:**

| Field | Value |
|---|---|
| Type | **Microsoft Entra ID** |
| Name | e.g. `Microsoft` – the button then reads "Sign in with Microsoft" |
| Identifier (URL) | `entra` – exactly as in the redirect URI |
| Active | on |
| Directory (tenant) ID | tenant ID from 2.1 (the domain works as well) |
| Application (client) ID | from 2.3 |
| Client secret | **value** from 2.4 |
| Authority, Graph endpoint | leave empty (only for national clouds such as `login.microsoftonline.us`) |

Compare the **redirect URI** shown in the form (a click copies it) character by character with the one registered in
Entra.

**3.2 User assignment** – one of the two variants:

- **Link existing users:** people already have an account in SAFT with **the same e-mail** as in Entra (for accounts
  without a mailbox: the sign-in name). Switch on **"Link existing users by email"**. At the first Microsoft sign-in
  the account is linked; afterwards the immutable object ID from Entra counts.
- **Create automatically:** switch on **"Create unknown users automatically"**, choose a **default role** (e.g.
  `benutzer`) and enter your own domain under **Allowed email domains** (e.g. `company.onmicrosoft.com`), so that
  nobody from outside gets an account. If the default role is left empty, the global default role under
  **Administration → Users and access → Authentication** applies (the field shows which one). It is kept at later sign-ins; roles from
  the role mapping (3.3) are granted in addition. If a person should have only the mapped roles, set the global
  default role to "None".

**3.3 Optional – role mapping** (see 2.7): switch on **"Take roles from the identity provider"**, choose the
source **app roles** (or groups) and add mappings, e.g. `saft-casework` → role "Case work" (for groups the object ID of
the group). Only roles mapped this way are granted or revoked at every sign-in; all other roles of a person remain
unchanged. **"Only allow sign-in with a matching mapping"** restricts access to these people.

**3.4 Save and "Test connection".** The expected message is **"OpenID configuration loaded and client credentials
valid"**. The test also remembers the tenant GUID that SAFT checks for role mapping – test again after changing the
tenant, client ID or secret.

**3.5 Sign-in mode.** Under **Administration → Users and access → Authentication**:

- **Hybrid** – password *and* Microsoft button; recommended to start with, so you do not lock yourself out.
- **Single sign-on** – Microsoft only; available as soon as an identity provider is active and tested successfully.
  Super admins keep the emergency sign-in with password.

In `.env`, `AUTH_FORCE_LOCAL` must not be `true` (otherwise local sign-in always applies).

---

## 4. Test the sign-in

1. Open a **private browser window** (no existing Microsoft session) and go to the sign-in page of SAFT, e.g.
   `http://localhost:8000/admin/login`.
2. Click **"Sign in with Microsoft"** and sign in with a test person; consent the first time if asked.
3. The dashboard appears; "My last sign-in" names the method. Under **Users** the person appears with the linked
   Microsoft account, under **Activity log** the sign-in.

With role mapping: the person then has exactly the mapped roles; a change in Entra takes effect at the next sign-in.

---

## 5. If something goes wrong

| Message | Cause and remedy |
|---|---|
| **AADSTS50011** – redirect URI does not match | The address in Entra differs: `127.0.0.1` instead of `localhost`, `http` instead of `https`, another identifier or a typo. Register the redirect URI shown in SAFT. |
| **AADSTS7000215** – invalid secret | The secret ID was entered instead of the **value**, or the secret has expired. |
| **AADSTS700016** – application not found | Wrong application ID or app registered in another tenant. |
| **AADSTS50020** – account not in tenant | The person belongs to another tenant; SAFT only allows its own. |
| "Your account is deactivated" | The account is inactive in SAFT: switch on "Active" under **Users**. |
| "Your account has been removed" | The account is in the trash in SAFT. Restore it (old account with its roles) or delete it permanently (a new account is created at the next sign-in), see [Administrator guide, chapter 1](ADMIN_GUIDE.md#1-users). |
| "No access has been set up for your account" | Neither linking by e-mail nor automatic creation is on – or the e-mail differs (for accounts without a mailbox check the sign-in name). |
| "Your email domain is not allowed for this sign-in" | The person's domain is missing under "Allowed email domains". |
| "The sign-in has expired or is invalid" | Switched between `localhost` and `127.0.0.1`, cookies blocked or waited too long – start the sign-in again. |
| Connection test: client ID or secret invalid | Check the values from 2.3/2.4; create and enter a new secret. |
| Roles do not change | Run the connection test again (tenant GUID); is the app role or group assigned to the person? For groups: more than 200 groups (see 2.7). |

Technical details (without secrets) are written to `storage/logs/laravel.log`.

---

## 6. Maintenance

- **Renew the secret:** before it expires, create a new secret in Entra, enter it in SAFT, "Test connection", then
  delete the old one in Entra. If it has expired, every Microsoft sign-in fails; super admins get in via the emergency
  sign-in.
- **New address of SAFT:** additionally register the new redirect URI in Entra, remove the old one only after the
  move.
- **Blocking people:** accounts disabled in Entra can no longer sign in. People who can also sign in with a password
  (mode hybrid) are additionally deactivated in SAFT.
- **Do not change the identifier:** it is part of the redirect URI; a change requires a new URI in Entra.
