# Konzept: REST-Anbindungen (SAFT als Client) in SAFT XT

Stand: 23.11.2026 · Status: **erster Vorschlag zur Diskussion** · Backlog: B-060 (SAFT als Server: B-023)

## 1. Ziel

SAFT tauscht Daten mit fremden Diensten über deren **REST-Schnittstellen** aus, ohne dass dafür ein Plugin nötig ist:

- **Abrufen:** Daten aus einer API in eine Tabelle holen und abgleichen, z. B. Wechselkurse täglich, das
  Postleitzahlen-Verzeichnis, Produkte aus einem Webshop, Termine aus einem Buchungssystem.
- **Senden:** Datensätze an eine API schicken, z. B. neue Mitglieder an den Newsletter-Dienst, eine bestätigte
  Bestellung an den Lieferanten; die Antwort (z. B. die fremde ID) zurück in den Datensatz schreiben.
- **Webhooks:** bei Ereignissen eine Nachricht an eine Adresse schicken, z. B. an Teams, Slack oder einen
  Automatisierungsdienst (n8n, Make, Zapier).

Grundsätze:

- **Konfigurieren statt programmieren** für die häufigen, einfachen Fälle. Eine Anbindung besteht aus Adresse,
  Anmeldung, seitenweisem Abruf und der Zuordnung von JSON zu Feldern, mit Vorschau.
- **Dieselben Wege wie beim Erfassen:** Abgerufene Datensätze gehen durch Prüfungen, Plugins, Regeln und Protokoll.
- **Nichts geht verloren:** Ausgehende Nachrichten laufen über einen **Postausgang** mit Wiederholung. Das funktioniert
  auch ohne Queue-Worker auf einfachem Webspace.
- **Sicher:** Zugangsdaten verschlüsselt und nie im Export; Schutz davor, dass Admins über SAFT interne Adressen
  abfragen (SSRF).

**Nicht Ziel:**

- SAFT als **Server** mit eigener REST-API für Dritte (B-023, eigenes Konzept)
- SOAP, XML-Schnittstellen, Dateiaustausch über SFTP
- Anbindungen mit **komplexer Fachlogik** (Buchhaltung wie bexio oder Abacus, ERP, Shop mit Bestellabwicklung). Diese
  bleiben Plugins, können aber die Verbindungen aus diesem Konzept mitbenutzen (Abschnitt 9).

## 2. Heute

| Baustein | Stand | Bedeutung |
|---|---|---|
| Table-Connector, Datenstände | fremde **Datenbanken** anbinden (nur lesen), Kopien als Datenstände mit Aufbewahrung, Sperre je Tabelle, geplante Aufgabe | Vorbild für Verbindungen, „Verbindung zuordnen“ bei Apps und Datenstände für reine Nachschlagedaten |
| `DatabaseConnection` | Zugangsdaten mit Cast `encrypted` | gleiche Ablage für API-Schlüssel und Secrets |
| Datenimport (`RecordImporter`) | aus Excel/CSV, **nur neue** Datensätze, Spalten über die Überschrift, Werte über `ImportValueParser`, alles oder nichts | Wertumwandlung wiederverwenden; Abgleich (ändern) ist neu |
| Geplante Aufgaben | `TaskType`, Laufprotokoll, Benachrichtigung | Abruf nach Zeitplan; Postausgang abarbeiten |
| Laravel HTTP-Client (Guzzle) | vorhanden, genutzt für SSO | Grundlage, keine weitere Bibliothek nötig |

## 3. Vorhandene Lösungen und Bibliotheken

| Kandidat | Bewertung |
|---|---|
| **Laravel HTTP-Client** | **Grundlage.** Timeouts, Wiederholung, Bearer/Basic, Faking in Tests. |
| `saloonphp/saloon` | Gut, um SDKs für **eine bestimmte** API zu schreiben (Klassen je Endpunkt). Passt für Plugins, nicht für Anbindungen, die zur Laufzeit konfiguriert werden. |
| `spatie/laravel-webhook-server` | Signierte Webhooks mit Wiederholung, braucht aber einen Queue-Worker. Wir übernehmen das Verfahren (HMAC-Signatur, Backoff) und bauen es auf dem eigenen Postausgang, der auch über den Scheduler bzw. Web-Cron (B-117) läuft. |
| `league/oauth2-client` | OAuth2 mit Anmeldung der Person (Authorization Code, z. B. Google, Microsoft). Erst für Etappe A4 nötig. Client Credentials gehen mit dem HTTP-Client allein. |
| JSONPath-Bibliotheken | Für die Zuordnung genügt die Punkt-Schreibweise von Laravel (`data_get`: `kunde.adresse.ort`, `tags.0`, `positionen.*.menge`). Eine Bibliothek ist erst nötig, wenn Filter in Pfaden gebraucht werden. |
| iPaaS (n8n, Make, Zapier) | Externe Dienste mit eigener Oberfläche. Keine Grundlage, aber **Gegenstelle**: Webhooks aus SAFT und später die REST-API (B-023) machen SAFT dort anschliessbar. |

## 4. Bausteine

```
Verbindung (Adresse, Anmeldung, Grenzen)
   ├── Abruf    (Endpunkt → Zuordnung → Zieltabelle, Abgleich)        von Hand · Zeitplan
   ├── Senden   (Datensatz → Zuordnung → Endpunkt, Antwort → Felder)  Regel · Sammelaktion · Lauf
   └── Webhook  (Ereignis → Standard-Nachricht → Adresse)             Regel
                     ↓
               Postausgang (Wiederholung, Protokoll)
```

Alle Bausteine gehören zu einer **App**. Ihre Definition (ohne Geheimnisse) ist JSON nach `saft-rest/1` mit stabilen
Schlüsseln und geht in die App-Definition (Abschnitt 10).

## 5. Verbindung

*Administration › Schnittstellen › Verbindungen*, Recht *Schnittstellen: verwalten*:

| Angabe | Inhalt |
|---|---|
| Name, Basisadresse | `https://api.example.com/v2` (HTTPS Pflicht, Ausnahmen nur per Konfiguration) |
| Anmeldung | keine · API-Schlüssel (im Header oder als Parameter) · Bearer-Token · Benutzer/Passwort (Basic) · **OAuth2 Client Credentials** (Token-Adresse, Client-ID, Secret, Scope; das Token wird bis zum Ablauf zwischengespeichert) · später OAuth2 mit Anmeldung (A4) |
| Feste Header | z. B. `Accept`, `X-Tenant`; Werte können geheim sein (verschlüsselt) |
| Grenzen | Timeout (Vorgabe 30 s), höchstens N Anfragen je Minute, grösste Antwort (Vorgabe 20 MB) |
| Verbindung prüfen | Testanfrage an eine wählbare Adresse mit Status und Antwortzeit, wie bei Datenbankverbindungen |

Geheimnisse liegen verschlüsselt in der Datenbank. Sie werden nie angezeigt (nur „gesetzt“) und erscheinen nie im
Protokoll (`Authorization` wird maskiert).

## 6. Abruf: Daten holen und abgleichen

### 6.1 Angaben

| Angabe | Beispiel |
|---|---|
| Endpunkt, Methode | `GET /products`, für Such-APIs auch `POST` mit Körper |
| Parameter | `?status=active&updated_since={{last_success}}`. Platzhalter: `{{today}}`, `{{last_success}}` (Zeitpunkt des letzten erfolgreichen Abrufs, für **inkrementelle** Abrufe), Parameter eines Laufs |
| Seitenweise | keine · Seite/Grösse (`page`, `per_page`) · Versatz (`offset`, `limit`) · **Cursor** (nächster Wert aus der Antwort, z. B. `meta.next_cursor`) · **Link-Header** (`rel="next"`). Höchstzahl Seiten als Sicherung. |
| Datensätze in der Antwort | Pfad zur Liste, z. B. `data` bzw. `items`; leer = die Antwort ist die Liste |
| Zieltabelle | Tabelle der App |
| **Zuordnung** | je Feld: Pfad in einem Eintrag (`sku`, `price.amount`, `attributes.color`), optional eine **Formel** zur Umwandlung (z. B. `round(wert * 1.081, 2)`, `if(wert = 'A', 'aktiv', 'inaktiv')`). Werte werden wie beim Import umgewandelt (Datum ISO 8601, Dezimalpunkt, Ja/Nein). |
| **Schlüssel für den Abgleich** | ein eindeutiges Feld der Zieltabelle mit der fremden ID (`shop_id`) |
| Verknüpfungen | Verknüpfungsfeld über einen Schlüssel der verknüpften Tabelle auflösen, z. B. `category_id` → *Kategorien* über `shop_id` |

### 6.2 Abgleich

Je Eintrag der Antwort: Datensatz mit diesem Schlüssel **vorhanden → ändern** (nur zugeordnete Felder; unveränderte
werden übersprungen), **nicht vorhanden → anlegen**. Für Datensätze, die **in der Quelle fehlen**, ist wählbar, nur
bei vollständigem (nicht inkrementellem) Abruf:

- nichts tun (Vorgabe)
- ein Feld setzen, z. B. `aktiv = Nein`
- in den Papierkorb legen

Die **zugeordneten Felder gehören der Schnittstelle.** In der Oberfläche bleiben sie änderbar, werden aber beim
nächsten Abruf überschrieben. Optional lassen sie sich als „von *Shop* gepflegt“ schreibgeschützt anzeigen
(Abschnitt 13).

### 6.3 Vorschau und Ausführung

- **Vorschau:** Die erste Seite wird geholt und zeigt die rohe Antwort (gekürzt) neben der Zuordnung, die ersten 10
  Einträge als *Feld · Wert*, Fehler der Umwandlung je Zelle (wie bei der Import-Analyse) und die Anzahl
  *neu · geändert · unverändert*.
- **Automatische Zuordnung:** Pfade mit gleichem Namen wie Felder werden vorgeschlagen (`name` → *Name*), die KI hilft
  bei den übrigen (Abschnitt 11).
- **Ausführung:** Alle Seiten werden geholt und dann gespeichert, **Datensatz für Datensatz über das Modell**. So
  greifen Vorgaben, Prüfungen, Plugins, Regeln (B-142) und das Protokoll mit dem Zusatz „via Schnittstelle *Shop*“.
  **Eine Transaktion**, alles oder nichts, wie beim Import. Obergrenze 10 000 Einträge je Abruf (einstellbar). Darüber
  hinaus: inkrementell abrufen oder ein Plugin.
- **Auslösen:** „Jetzt abrufen“ (Menü der Tabelle, Recht je Abruf) oder Aufgabenart **„REST-Abruf“** nach Zeitplan.
  Eine Sperre je Abruf verhindert, dass zwei Abrufe gleichzeitig laufen (wie bei den Datenständen).
- **Protokoll je Lauf:** Zeitpunkt, Seiten, Anfragen, neu/geändert/unverändert/entfernt, Fehler mit HTTP-Status und
  gekürzter Antwort.

### 6.4 Nur nachschlagen: als angebundene Tabelle

Für **reine Nachschlagedaten** (Wechselkurse, PLZ, Länder), die niemand in SAFT bearbeitet, gibt es eine Alternative
zum Abgleich: Der Abruf füllt eine **nur lesbare Tabelle mit Datenständen**, wie der Table-Connector bei Datenbanken
(Kopie, Aufbewahrung der letzten N Stände, Rückfall auf den vorherigen Stand bei Fehlern). So entstehen keine
Protokolleinträge für Tausende Zeilen, und der alte Stand bleibt nachvollziehbar. Diese Variante kommt in Etappe A3.

### 6.5 Verschachtelte Daten (Etappe A3)

Eine Liste in einem Eintrag, z. B. `lines[]` einer Bestellung, lässt sich einer **Kind-Tabelle** zuordnen: Pfad der
Liste, Kind-Tabelle, Zuordnung der Felder, Schlüssel für den Abgleich innerhalb des Masters (z. B. `line_id`).
Entfernte Positionen werden gelöscht. Mehr als eine Ebene ist ein Fall für ein Plugin.

## 7. Senden

| Angabe | Inhalt |
|---|---|
| Endpunkt, Methode | `POST /contacts` (neu), `PUT /contacts/{{mailchimp_id}}` (ändern), Platzhalter aus dem Datensatz |
| **Körper** | Zuordnung **Pfad ← Formel** (Umkehrung des Abrufs): `email_address ← email`, `merge_fields.FNAME ← vorname`, `status ← 'subscribed'`. SAFT baut daraus JSON; Texte werden dabei richtig maskiert. Eine freie Vorlage (Mustache wie bei Dokumenten) wäre fehleranfällig und kommt höchstens später dazu. |
| **Antwort** | Pfade aus der Antwort in Felder schreiben, z. B. `id` → `mailchimp_id`, dazu ein Feld für Status bzw. Zeitpunkt der Übertragung |
| Auslöser | **Regel** (Aktion `send` im Konzept Regel-Engine), **Sammelaktion** für ausgewählte Datensätze (Konzept Sammeländerung, Abschnitt 8), **Lauf** (Konzept Regel-Engine, Abschnitt 8) |

Senden geht immer über den **Postausgang** (Abschnitt 8). Der Datensatz wird gespeichert, auch wenn der fremde Dienst
gerade nicht erreichbar ist; die Nachricht folgt mit Wiederholung. Das Schreiben der Antwort in den Datensatz ist eine
eigene, kleine Änderung „via Schnittstelle“. Sie löst bewusst **keine** Regeln mit Auslöser *geändert* aus, die auf
diese Antwortfelder hören, sonst entstünden Schleifen.

## 8. Webhooks und Postausgang

### 8.1 Webhook

Ein Webhook ist ein **Senden mit festem Format**: Eine Regel (Aktion `webhook`) schickt bei einem Ereignis eine
Standard-Nachricht an eine Adresse.

```json
{
  "format": "saft-event/1",
  "event": "record.updated",
  "app": "musikschule",
  "table": "anmeldungen",
  "key": 4711,
  "changes": { "status": { "old": "Warteliste", "new": "aktiv" } },
  "record": { "status": "aktiv", "kurs": 12, "schueler": 88 },
  "url": "https://saft.example.ch/data/anmeldungen/4711",
  "caused_by": "anna.muster@example.ch",
  "at": "2026-11-23T14:05:00+01:00"
}
```

- **Signatur:** Header `X-Saft-Signature: sha256=<HMAC des Körpers mit dem Secret des Webhooks>` und Zeitstempel. Der
  Empfänger prüft damit Echtheit und Alter.
- **Inhalt:** wählbar „nur Schlüssel und Änderungen“ (Vorgabe, wenig Daten nach aussen) oder „mit allen Feldern“.
- **Vorlagen** für Teams und Slack (eigenes Nachrichtenformat mit Text und Link) neben dem Standardformat.

### 8.2 Postausgang

Tabelle `outbox_messages`: Ziel (Senden bzw. Webhook), Körper, Status (*wartet, gesendet, fehlgeschlagen,
aufgegeben*), Versuche, nächster Versuch, letzte Antwort (gekürzt), Bezug (Tabelle, Datensatz, Regel).

- Nachrichten werden **in derselben Transaktion** wie die auslösende Änderung eingetragen. Wird diese zurückgerollt,
  geht nichts hinaus; wird sie gespeichert, geht nichts verloren (Outbox-Muster).
- **Abarbeiten:** gleich nach dem Commit ein Versuch (synchron, mit kurzem Timeout, damit Teams-Nachrichten sofort
  kommen), sonst durch die System-Aufgabe **„Postausgang senden“** jede Minute über den Scheduler, auf einfachem
  Webspace über den Web-Cron (B-117). Mit Queue-Worker später über die Warteschlange.
- **Wiederholung** mit wachsendem Abstand (1 min, 5 min, 30 min, 2 h, 12 h); `429` bzw. `Retry-After` wird beachtet.
  Nach dem letzten Versuch: *aufgegeben*, mit Benachrichtigung an die Admins der App.
- **Reihenfolge** je Datensatz und Ziel bleibt erhalten: Eine spätere Nachricht wartet, bis die frühere gesendet oder
  aufgegeben ist.
- **Oberfläche:** *Schnittstellen › Postausgang* mit Filter, „erneut senden“ und „verwerfen“. Gesendete Nachrichten
  werden nach einer Frist gelöscht (System-Aufgabe).

## 9. Sicherheit

- **SSRF-Schutz:** Vor jeder Anfrage löst SAFT den Namen auf und lehnt Adressen in privaten, Loopback-, Link-Local-
  und Metadaten-Bereichen ab (`10/8`, `172.16/12`, `192.168/16`, `127/8`, `169.254/16`, `::1`, `fc00::/7`).
  Umleitungen werden höchstens dreimal verfolgt und jedes Mal neu geprüft. Interne Dienste lassen sich über eine
  **Freigabeliste** in der Konfiguration (`rest.allowed_hosts`) erlauben.
- **TLS** wird immer geprüft, HTTPS ist Pflicht (Ausnahmen über die Freigabeliste).
- **Grenzen** gegen hängende oder riesige Antworten: Timeout, grösste Antwort, Höchstzahl Seiten und Einträge.
- **Rechte:**
  - Verbindungen, Abrufe und Webhooks verwalten: *Schnittstellen: verwalten* je App
  - Abruf von Hand ausführen: Recht je Abruf
  - Geschrieben wird als System, wie bei Aufgaben; im Protokoll stehen die auslösende Person bzw. die Aufgabe und die
    Schnittstelle.
- **Was nach aussen geht,** bestimmt nur die Zuordnung bzw. die Wahl „nur Änderungen“. Felder ohne Zuordnung verlassen
  SAFT nicht. Das Admin-Handbuch weist auf den Datenschutz hin (Auftragsverarbeitung beim fremden Dienst).
- **Plugin-API:** `Saft::connection('shop')->http()` liefert einen fertig angemeldeten HTTP-Client mit allen Schutz-
  und Grenzwerten. So speichern Plugins keine eigenen Geheimnisse (Minor-Version der Plugin-API).

## 10. Apps und App-Definition

- Verbindungen, Abrufe, Senden und Webhooks gehören zur App und gehen in die App-Definition, **ohne Geheimnisse**.
  Beim Installieren bleibt die Verbindung ohne Zugangsdaten und inaktiv, bis jemand sie einträgt („Zugangsdaten
  eingeben“, wie „Verbindung zuordnen“ beim Table-Connector).
- Felder und Tabellen in Zuordnungen werden über stabile Schlüssel aufgelöst. Wird ein Feld umbenannt, wird die
  Zuordnung nachgeführt; ein genutztes Feld lässt sich nicht löschen (Nutzung wie bei Formeln).
- Eine Kopie einer App auf demselben System (anderes Präfix) übernimmt die Verbindung **nicht**: Zwei Apps, die
  unbemerkt dieselbe fremde Datenbasis abgleichen, wären gefährlich.

## 11. KI

- **Zuordnung vorschlagen:** aus einer **Beispielantwort** (die Vorschau oder eingefügtes JSON) und der Zieltabelle;
  auch eine passende **neue Tabelle** über den Tabellen-Assistenten („Lege eine Tabelle für diese Produkte an“).
- **Aus einer OpenAPI-Beschreibung** (Adresse oder Datei): Endpunkte und Schemata zur Auswahl, Seitenweise und
  Anmeldung vorbelegt (Etappe A4).
- Die KI sieht dabei nur Struktur und Beispielwerte der Antwort, nie die Zugangsdaten. Wie beim Hilfe-Assistenten
  (B-034) gilt: Beispieldaten nur mit Zustimmung; sonst nur die Struktur.

## 12. Etappen

| Etappe | Inhalt | Umfang |
|---|---|---|
| **A1 Abrufen** | Verbindungen (keine Anmeldung, API-Schlüssel, Bearer, Basic, OAuth2 Client Credentials), Prüfen, SSRF-Schutz und Grenzen; Abruf mit Parametern, Seitenweise (alle vier Arten), Pfad zur Liste, Zuordnung mit Formel, Verknüpfungen über Schlüssel, Abgleich mit Schlüsselfeld, fehlende Datensätze, Vorschau, automatische Zuordnung, Ausführung in einer Transaktion, „Jetzt abrufen“, Aufgabenart „REST-Abruf“, Laufprotokoll; App-Definition ohne Geheimnisse | gross |
| **A2 Senden und Webhooks** | Postausgang mit Wiederholung und Oberfläche, System-Aufgabe „Postausgang senden“; Senden mit Körper aus der Zuordnung und Antwort in Felder; Webhooks `saft-event/1` mit Signatur, Vorlagen Teams und Slack; Auslöser: Regel-Aktionen `send`/`webhook` (braucht B-142 R2) und Sammelaktion | gross |
| **A3 Erweiterungen Abruf** | inkrementell über `{{last_success}}`, verschachtelte Listen in Kind-Tabellen, Nachschlagetabelle mit Datenständen, Felder „von Schnittstelle gepflegt“ | mittel |
| **A4 Komfort** | OAuth2 mit Anmeldung der Person (Refresh-Token), OpenAPI-Import, KI-Zuordnung, `Saft::connection()` für Plugins, eingehender Auslöser „Abruf jetzt starten“ per geheimer Adresse (für Dienste, die SAFT bei Änderungen benachrichtigen) | mittel |

Ohne Regel-Engine ist A2 nur über die Sammelaktion auslösbar. A1 hängt von nichts ab und hat sofort Nutzen.

## 13. Offene Fragen zur Diskussion

1. **Feldhoheit:** Zugeordnete Felder beim Abgleich in SAFT schreibschützen („von *Shop* gepflegt“) oder änderbar
   lassen und überschreiben (Vorschlag A1: änderbar mit Hinweis; Schreibschutz als Option in A3)?
2. **Obergrenze und Transaktion:** 10 000 Einträge alles oder nichts genug? Oder grosse Abrufe seitenweise speichern
   (Teilerfolg möglich, dafür kein Rückrollen)?
3. **Eingehende Daten** ausser dem Auslöser in A4: über die REST-API (B-023) abdecken, oder soll SAFT auch fremde
   Webhooks mit Inhalt **annehmen** und direkt zuordnen (Webhook-Empfang)?
4. **Datenschutz-Hinweis:** Reicht ein Hinweis im Handbuch, oder soll SAFT beim Anlegen einer Verbindung zu einem
   fremden Dienst eine Bestätigung verlangen („Daten verlassen SAFT, Auftragsverarbeitung geregelt?“)?
5. **CSV bzw. Excel von einer Adresse** (z. B. offene Daten des Bundes) als einfachster Fall: über den vorhandenen
   Datenimport mit „Datei von Adresse“ statt über REST-Abruf? Vorschlag: ja, als kleiner Zusatz in A1.
