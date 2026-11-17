# Anhang D – Single Sign-On mit Microsoft Entra ID einrichten

[English](SSO_ENTRA.md) · **Deutsch** · [Zurück zum Benutzerhandbuch](USER_GUIDE.de.md)

**Stand:** Version 0.1 · **Zielgruppe:** Administratorinnen und Administratoren von SAFT und des Microsoft-Mandanten

Diese Anleitung führt Schritt für Schritt durch die Anmeldung über Microsoft Entra ID (früher Azure AD): erst die
App-Registrierung bei Microsoft, dann der Identity Provider in SAFT, zum Schluss Test und Fehlersuche. Einen Überblick
über alle Einstellungen gibt [Administratorhandbuch, Kapitel 6](ADMIN_GUIDE.de.md#6-identity-provider-single-sign-on).

---

## Inhalt

1. [Vorab: Adresse und Kennung festlegen](#1-vorab-adresse-und-kennung-festlegen)
2. [App-Registrierung im Microsoft Entra Admin Center](#2-app-registrierung-im-microsoft-entra-admin-center)
3. [Identity Provider in SAFT anlegen](#3-identity-provider-in-saft-anlegen)
4. [Anmeldung testen](#4-anmeldung-testen)
5. [Wenn es nicht klappt](#5-wenn-es-nicht-klappt)
6. [Pflege im Betrieb](#6-pflege-im-betrieb)

---

## 1. Vorab: Adresse und Kennung festlegen

Microsoft leitet nach der Anmeldung zu einer **Redirect-URI** von SAFT zurück. Sie muss in Entra **genau** so
eingetragen sein, wie SAFT sie verwendet:

```
<Adresse von SAFT>/auth/sso/<Kennung>/callback
```

- **Kennung:** ein kurzer Name des Identity Providers in SAFT, z. B. `entra`. Er ist Teil der Adresse und sollte
  danach nicht mehr geändert werden. In dieser Anleitung heisst er `entra`.
- **Adresse von SAFT:** SAFT bildet die Redirect-URI aus der Adresse, unter der es im Browser aufgerufen wird.
  - **Server:** z. B. `https://saft.example.ch` → `https://saft.example.ch/auth/sso/entra/callback`. Entra verlangt
    `https://`.
  - **Test auf dem eigenen Rechner:** Entra erlaubt `http://` nur für `localhost`. Rufen Sie SAFT deshalb unter
    **`http://localhost:8000`** auf, nicht unter `http://127.0.0.1:8000` →
    `http://localhost:8000/auth/sso/entra/callback`. Bleiben Sie bei dieser Adresse: `localhost` und `127.0.0.1`
    haben getrennte Sitzungen; ein Wechsel mitten in der Anmeldung endet mit „Die Anmeldung ist abgelaufen oder ungültig“.

Sie brauchen ein Konto mit Administratorrechten im Microsoft-Mandanten (z. B. *Anwendungsadministrator*) und in SAFT
einen Super-Admin.

---

## 2. App-Registrierung im Microsoft Entra Admin Center

Melden Sie sich unter <https://entra.microsoft.com> an. Die Bezeichnungen folgen der deutschen Oberfläche; in der
englischen heissen sie wie in der [englischen Fassung](SSO_ENTRA.md) dieser Anleitung.

**2.1 Mandanten-ID notieren.** Unter **Identität → Übersicht** die **Mandanten-ID** (eine GUID) kopieren. Notieren
Sie auch die **primäre Domäne**, z. B. `firma.onmicrosoft.com` oder `firma.ch`.

**2.2 App registrieren.** **Identität → Anwendungen → App-Registrierungen → Neue Registrierung:**

| Feld | Wert |
|---|---|
| Name | z. B. `SAFT` (für Benutzer sichtbar, etwa im Zustimmungsdialog) |
| Unterstützte Kontotypen | **Nur Konten in diesem Organisationsverzeichnis** (einzelner Mandant) |
| Umleitungs-URI | Plattform **Web**, die Redirect-URI aus Schritt 1 |

Dann **Registrieren**. SAFT erlaubt nur den eigenen Mandanten (nicht `common` oder `organizations`).

**2.3 Anwendungs-ID notieren.** Auf der Übersichtsseite der App die **Anwendungs-ID (Client)** kopieren.

**2.4 Geheimen Clientschlüssel anlegen.** **Zertifikate & Geheimnisse → Neuer geheimer Clientschlüssel**:
Beschreibung und Laufzeit wählen (z. B. 12 Monate), **Hinzufügen**.

> **Wichtig:** Kopieren Sie sofort die Spalte **„Wert“** – nicht die „Geheimnis-ID“. Microsoft zeigt den Wert nur
> dieses eine Mal an. Notieren Sie sich das Ablaufdatum (siehe [Abschnitt 6](#6-pflege-im-betrieb)).

**2.5 API-Berechtigungen.** Unter **API-Berechtigungen** muss **Microsoft Graph → Delegiert → `User.Read`** stehen –
bei neuen Registrierungen ist das bereits eingetragen. Empfohlen: **„Administratorzustimmung für … erteilen“**, dann
sehen Benutzer keinen Zustimmungsdialog. `openid`, `profile` und `email` fordert SAFT selbst an.

**2.6 Optional – Abmeldung auch bei Microsoft.** Soll SAFT beim Abmelden auch bei Microsoft abmelden (Einstellung
„Beim Abmelden auch beim Identity Provider abmelden“), unter **Authentifizierung** zusätzlich die Anmeldeseite von
SAFT als Umleitungs-URI der Plattform Web eintragen, z. B. `https://saft.example.ch/admin/login` bzw.
`http://localhost:8000/admin/login`. Achtung: Das beendet im Browser auch die Sitzung bei allen anderen
Microsoft-Diensten (Outlook, Teams …).

**2.7 Optional – Rollen aus Entra übernehmen.** SAFT kann Rollen bei jeder Anmeldung vergeben oder entziehen.
Empfohlen sind **App-Rollen**:

1. **App-Rollen → App-Rolle erstellen:** Anzeigename (z. B. „SAFT Sachbearbeitung“), zulässige Mitgliedstypen
   **Benutzer/Gruppen**, **Wert** (z. B. `saft-sachbearbeitung`), aktivieren.
2. **Identität → Anwendungen → Unternehmensanwendungen → SAFT → Benutzer und Gruppen → Benutzer/Gruppe
   hinzufügen:** Personen oder Gruppen der App-Rolle zuweisen.

Alternativ **Gruppen:** **Tokenkonfiguration → Gruppenanspruch hinzufügen** (Sicherheitsgruppen, im ID-Token als
*Gruppen-ID*). Bei mehr als 200 Gruppen je Person liefert Entra keine Gruppen im Token – dann besser *„Der Anwendung
zugewiesene Gruppen“* wählen.

**2.8 Testpersonen.** Unter **Benutzer → Neuer Benutzer** lassen sich Testkonten anlegen, z. B.
`anna@firma.onmicrosoft.com`.

> **Gut zu wissen:** Konten ohne Postfach (ohne Microsoft-365-Lizenz) haben in Entra keine E-Mail-Adresse. SAFT
> verwendet dann den **Anmeldenamen** (z. B. `anna@firma.onmicrosoft.com`) als E-Mail – wichtig für die
> Benutzerzuordnung und die erlaubten Domains in Schritt 3.

---

## 3. Identity Provider in SAFT anlegen

Unter der Adresse aus Schritt 1 als Super-Admin anmelden, dann **Administration → Benutzer und Zugriff → Identity Provider → Erstellen**.

**3.1 Allgemein und Entra-Angaben:**

| Feld | Wert |
|---|---|
| Typ | **Microsoft Entra ID** |
| Name | z. B. `Microsoft` – die Schaltfläche heisst dann „Anmelden mit Microsoft“ |
| Kennung (URL) | `entra` – genau wie in der Redirect-URI |
| Aktiv | an |
| Verzeichnis-ID (Tenant) | Mandanten-ID aus 2.1 (die Domäne geht ebenfalls) |
| Anwendungs-ID (Client) | aus 2.3 |
| Geheimer Clientschlüssel | **Wert** aus 2.4 |
| Authority, Graph-Endpunkt | leer lassen (nur für nationale Clouds wie `login.microsoftonline.us`) |

Vergleichen Sie die im Formular angezeigte **Redirect-URI** (Klick kopiert sie) Zeichen für Zeichen mit der in Entra
eingetragenen.

**3.2 Benutzerzuordnung** – eine der beiden Varianten:

- **Vorhandene Benutzer verknüpfen:** Personen haben in SAFT bereits ein Konto mit **derselben E-Mail** wie in Entra
  (bei Konten ohne Postfach: der Anmeldename). **„Vorhandene Benutzer per E-Mail verknüpfen“** einschalten. Beim
  ersten Microsoft-Login wird das Konto verknüpft; danach zählt die unveränderliche Objekt-ID aus Entra.
- **Automatisch anlegen:** **„Unbekannte Benutzer automatisch anlegen“** einschalten, eine **Standardrolle** wählen
  (z. B. `benutzer`) und unter **Erlaubte E-Mail-Domains** die eigene Domäne eintragen (z. B.
  `firma.onmicrosoft.com`), damit niemand von aussen ein Konto erhält. Bleibt die Standardrolle leer, gilt die
  globale Standardrolle unter **Administration → Benutzer und Zugriff → Authentifizierung** (das Feld zeigt an, welche). Sie bleibt bei
  späteren Anmeldungen erhalten; Rollen aus der Rollen-Zuordnung (3.3) kommen zusätzlich dazu. Soll eine Person
  ausschliesslich die zugeordneten Rollen haben, die globale Standardrolle auf „Keine“ stellen.

**3.3 Optional – Rollen-Zuordnung** (zu 2.7): **„Rollen aus dem Identity Provider übernehmen“** einschalten, Quelle
**App-Rollen** (bzw. Gruppen) wählen und Zuordnungen anlegen, z. B. `saft-sachbearbeitung` → Rolle „Sachbearbeitung“
(bei Gruppen die Objekt-ID der Gruppe). Nur so zugeordnete Rollen werden bei jeder Anmeldung vergeben bzw. entzogen;
alle übrigen Rollen einer Person bleiben unverändert. Mit **„Anmeldung nur mit zutreffender Zuordnung erlauben“**
lässt sich der Zugang auf diese Personen beschränken.

**3.4 Speichern und „Verbindung testen“.** Erwartet wird **„OpenID-Konfiguration geladen und Client-Anmeldedaten
gültig“**. Der Test merkt sich dabei die Mandanten-GUID, die SAFT für die Rollen-Übernahme prüft – nach einer
Änderung von Tenant, Client-ID oder Geheimnis bitte erneut testen.

**3.5 Anmeldemodus.** Unter **Administration → Benutzer und Zugriff → Authentifizierung**:

- **Hybrid** – Passwort *und* Microsoft-Schaltfläche; empfohlen für den Anfang, so sperren Sie sich nicht aus.
- **Single Sign-On** – nur noch Microsoft; wählbar, sobald ein Identity Provider aktiv und erfolgreich getestet ist.
  Für Super-Admins bleibt die Notfall-Anmeldung mit Passwort.

In der `.env` darf `AUTH_FORCE_LOCAL` nicht auf `true` stehen (sonst gilt immer die lokale Anmeldung).

---

## 4. Anmeldung testen

1. Ein **privates Browserfenster** öffnen (keine bestehende Microsoft-Sitzung) und die Anmeldeseite von SAFT
   aufrufen, z. B. `http://localhost:8000/admin/login`.
2. **„Anmelden mit Microsoft“** klicken und mit einer Testperson anmelden; beim ersten Mal ggf. zustimmen.
3. Danach erscheint das Dashboard; „Meine letzte Anmeldung“ nennt die Methode. Unter **Benutzer** ist die Person
   mit dem verknüpften Microsoft-Konto zu sehen, unter **Änderungsprotokoll** die Anmeldung.

Bei Rollen-Übernahme: Die Person hat danach genau die zugeordneten Rollen; eine Änderung in Entra wirkt bei der
nächsten Anmeldung.

---

## 5. Wenn es nicht klappt

| Meldung | Ursache und Abhilfe |
|---|---|
| **AADSTS50011** – Redirect-URI passt nicht | Adresse in Entra weicht ab: `127.0.0.1` statt `localhost`, `http` statt `https`, andere Kennung oder Tippfehler. Die in SAFT angezeigte Redirect-URI eintragen. |
| **AADSTS7000215** – ungültiges Geheimnis | Statt des **Werts** wurde die Geheimnis-ID eingetragen, oder das Geheimnis ist abgelaufen. |
| **AADSTS700016** – Anwendung nicht gefunden | Anwendungs-ID falsch oder App in einem anderen Mandanten registriert. |
| **AADSTS50020** – Konto nicht im Mandanten | Person gehört zu einem anderen Mandanten; SAFT erlaubt nur den eigenen. |
| „Ihr Benutzerkonto ist deaktiviert“ | Das Konto ist in SAFT inaktiv: unter **Benutzer** den Schalter „Aktiv“ einschalten. |
| „Ihr Benutzerkonto wurde entfernt“ | Das Konto liegt in SAFT im Papierkorb. Wiederherstellen (altes Konto mit seinen Rollen) oder endgültig löschen (bei der nächsten Anmeldung entsteht ein neues Konto), siehe [Administratorhandbuch, Kapitel 1](ADMIN_GUIDE.de.md#1-benutzer). |
| „Für Ihr Konto ist kein Zugang eingerichtet“ | Weder Verknüpfung per E-Mail noch automatisches Anlegen aktiv – oder die E-Mail weicht ab (bei Konten ohne Postfach den Anmeldenamen prüfen). |
| „Ihre E-Mail-Domain ist für diese Anmeldung nicht zugelassen“ | Die Domäne der Person fehlt unter „Erlaubte E-Mail-Domains“. |
| „Die Anmeldung ist abgelaufen oder ungültig“ | Zwischen `localhost` und `127.0.0.1` gewechselt, Cookies blockiert oder zu lange gewartet – Anmeldung neu beginnen. |
| Verbindungstest: Client-ID oder Secret ungültig | Werte aus 2.3/2.4 prüfen; neues Geheimnis anlegen und eintragen. |
| Rollen ändern sich nicht | Verbindungstest erneut ausführen (Mandanten-GUID); App-Rolle bzw. Gruppe der Person zugewiesen? Bei Gruppen: mehr als 200 Gruppen (siehe 2.7). |

Technische Einzelheiten (ohne Geheimnisse) stehen in `storage/logs/laravel.log`.

---

## 6. Pflege im Betrieb

- **Geheimnis erneuern:** Vor dem Ablauf in Entra ein neues Geheimnis anlegen, in SAFT eintragen, „Verbindung
  testen“, dann das alte in Entra löschen. Ist es abgelaufen, schlägt jede Microsoft-Anmeldung fehl; Super-Admins
  kommen über die Notfall-Anmeldung hinein.
- **Neue Adresse von SAFT:** Die neue Redirect-URI in Entra zusätzlich eintragen, erst nach dem Umzug die alte
  entfernen.
- **Personen sperren:** In Entra deaktivierte Konten können sich nicht mehr anmelden. Wer sich auch mit Passwort
  anmelden kann (Modus Hybrid), wird zusätzlich in SAFT deaktiviert.
- **Kennung nicht ändern:** Sie ist Teil der Redirect-URI; eine Änderung erfordert eine neue URI in Entra.
