# Konzept: Sicherung je App und Wiederherstellen über die Oberfläche

Stand: 23.11.2026 · Status: **erster Vorschlag zur Diskussion** · Backlog: B-141 (Bezug B-109)

## 1. Ziel

Haben die Personen **einer** App etwas „kaputt gemacht“ (falsch importiert, versehentlich gelöscht, eine Sammeländerung
mit falschem Filter), soll sich **nur diese App** auf einen früheren Stand zurücksetzen lassen. Die Arbeit in den
anderen Apps seit der Sicherung bleibt erhalten. Heute geht das nur über die ganze Datenbank, von Hand auf dem Server,
und dann ist die Arbeit **aller** Apps seit der Sicherung verloren.

Beispiel: Im Lager wurde am Nachmittag ein Import mit falscher Zuordnung ausgeführt, 2000 Artikel haben falsche Preise.
Der Vertrieb hat am selben Tag Offerten erfasst, die auf Lager-Artikel verweisen. Das Lager soll auf die Sicherung von
heute Morgen zurück, der Vertrieb soll nichts davon merken, ausser dass die Preise wieder stimmen.

Grundsätze:

- **Die App ist die Einheit.** Gesichert und wiederhergestellt werden die Daten einer App: ihre Tabellen, Datei-Angaben
  und Dateien. Konfiguration und Daten anderer Apps bleiben unberührt.
- **Nichts Fremdes kaputt machen.** Verweise anderer Apps (Schnittstellen) werden geprüft, nicht still geleert.
- **Vorher sehen, was verloren geht:** Anzahl neuer, geänderter und gelöschter Datensätze seit der Sicherung, und wer
  sie geändert hat.
- **Rücknehmbar:** Vor jeder Wiederherstellung wird der aktuelle Stand der App gesichert. Scheitert die
  Wiederherstellung, wird dieser Stand automatisch wieder eingespielt.
- **Portabel:** kein dialektspezifisches SQL, keine Werkzeuge wie `pg_restore` nötig, auch auf Webspaces (Strato).

## 2. Heute

| Baustein | Stand |
|---|---|
| `db:backup` / Aufgabe „Datenbank sichern“ | `DatabaseBackup`: PostgreSQL per `pg_dump --format=custom` (eine Datei, Schema `app_data` inklusive), MySQL/MariaDB per `mysqldump` oder in PHP (`SqlDumper`, `--php`), beide Datenbanken in einer `.sql`. Daneben `<name>_files.zip` mit den Dateien aus Dateifeldern (nur lokale Disk) und den Dateien der Dokumente. Aufräumen nach Anzahl und Tagen. |
| Wiederherstellen | nur von Hand, in eine **neue, leere** Datenbank (`INSTALL_PRODUCTION`, Abschnitt 8). Keine Oberfläche. |
| Daten einer App | Designer-Tabellen im Namensraum `app_data` (`custom_tables.app_id`), Datei-Angaben in `record_files` (Hauptschema, `custom_table_id`), Dateien unter `files/<tabelle>/<feld>/`. Datenstände angebundener Tabellen als `ds_<tabelle>`. |
| Verweise | Verknüpfungsfelder sind echte Fremdschlüssel mit `nullOnDelete`, auch über App-Grenzen (freigegebene Tabellen, B-107 Etappe 5). `created_by`/`updated_by` ohne Fremdschlüssel. |
| Sicherung vor gefährlichen Aktionen | App entfernen (`AppRemover`), App aktualisieren, App-Daten zurücksetzen: jeweils **ganze** Datenbank + Dateien |
| Stabile Schlüssel | App, Tabellen und Felder haben `uuid`; App-Definition `saft-app/1` beschreibt den Aufbau einer App vollständig |

Die heutige Sicherung taugt für den Katastrophenfall, aber nicht für eine einzelne App. Eine `pg_dump`-Datei lässt sich
nur mit `pg_restore` lesen, und `pg_restore` kann eine einzelne Tabelle nur in eine Datenbank zurückschreiben, nicht in
ein anderes Schema. Bei der `.sql`-Datei müsste SAFT MySQL-Skripte zerlegen.

## 3. Entscheidungen (Vorschlag)

| Frage | Vorschlag |
|---|---|
| Format der App-Sicherung | **Eigenes, portables Format `saft-appdata/1`**: ZIP mit Manifest, App-Definition, je Tabelle JSON Lines, Datei-Angaben und Dateien. Geschrieben und gelesen nur über Query Builder und Storage. Begründung in Abschnitt 4. |
| Vollsicherung | **bleibt wie sie ist** (Katastrophenfall, alle Systemtabellen, Konfiguration). Für App-Wiederherstellung wird sie nicht verwendet. Wiederherstellen der ganzen Datenbank über die Oberfläche erst in Etappe W4 (Abschnitt 10). |
| Wann App-Sicherungen entstehen | Aufgabe „Datenbank sichern“ mit neuer Option **„Apps zusätzlich einzeln sichern“** (Vorgabe an); Knopf **„Jetzt sichern“** je App auf der Seite „Apps“; automatisch vor Wiederherstellen, Aktualisieren, App-Daten zurücksetzen; `php artisan app:backup {app?}` |
| Was wiederhergestellt wird | **Daten** der App: alle Designer-Tabellen (ohne angebundene), deren `record_files`-Zeilen und Dateien. **Nicht:** Aufbau (Tabellen, Felder), Menüs, Auswertungen, Rollen, Aufgaben, Änderungsprotokoll, Datenstände `ds_*`. |
| Art des Einspielens | **Abgleich statt Austausch:** über die Schlüssel `id` Datensätze ändern, fehlende einfügen, überzählige löschen. Kein „alles löschen, neu einfügen“, weil `nullOnDelete` dabei Verweise anderer Apps leeren würde (Abschnitt 6). |
| Während der Wiederherstellung | **Nur die App ist gesperrt**, nicht die Installation. Andere Apps arbeiten weiter. |
| Wer | **Super-Admin**, wie Entfernen einer App. Delegieren an App-Admins: offene Frage 1. |
| Ausführung | im Hintergrund mit Fortschritt (Muster wie Aufgaben bzw. Lauf der Regel-Engine), auch ohne Queue-Worker über den Scheduler oder als Folge-Request; bei kleinen Apps synchron |

## 4. Format `saft-appdata/1`

Datei: `storage/backups/apps/<präfix>_<JJJJMMTT_HHMMSS>.saftapp.zip`, Rechte 0600. Inhalt:

```
manifest.json          Format, SAFT-Version, letzte Migration, Datenbankart der Quelle, Zeitpunkt, Person,
                       App (uuid, Name, Präfix, Version, interface_version), je Tabelle: uuid, Name, Anzahl Zeilen,
                       SHA-256 der Datei, Spalten mit Feld-uuid und Typ
definition.json        App-Definition saft-app/1 zum Zeitpunkt der Sicherung (nur Aufbau, ohne Daten)
tables/<uuid>.jsonl    je Zeile ein Datensatz: alle Spalten inkl. id, created_by, updated_by, Zeitstempel, deleted_at
record_files.jsonl     Datei-Angaben der Tabellen (custom_table über die Tabellen-uuid statt id)
files/…                Dateien wie im Speicher (files/<tabelle>/<feld>/<uuid>.<ext>), ohne Vorschaubilder
```

Werte: Datum und Zeit als ISO 8601, Dezimalzahlen als Text (keine Rundung über `float`), Ja/Nein als `true`/`false`,
Dateifelder und Mehrfachauswahl als JSON wie in der Tabelle. Berechnete Felder ohne Wert, sie werden nach dem Einspielen
neu berechnet (`RecomputeComputedFieldsCommand`).

Warum ein eigenes Format und nicht die Vollsicherung in eine Hilfsdatenbank laden:

| | eigenes Format | Vollsicherung in Hilfsdatenbank/-schema |
|---|---|---|
| Werkzeuge | keine | `pg_restore`, `mysql`; fehlen oft auf Webspaces |
| Rechte | keine zusätzlichen | `CREATE DATABASE`, bei Hostern meist nicht erlaubt |
| Dialekt | Query Builder | `pg_restore` kann nicht in anderes Schema; MySQL-Skript umschreiben |
| Grösse und Zeit | nur die App | ganze Datenbank laden, um eine App zu holen |
| Aufbau geändert | Zuordnung über Feld-uuid im Manifest | nur über Spaltennamen |
| Nebennutzen | Umzug in eigene Datenbank (B-109), App mit Daten kopieren, Wechsel PostgreSQL ↔ MySQL | – |

Nachteil: Bestehende Vollsicherungen von vor Etappe W1 lassen sich nicht je App einspielen. Mit der Option in der
Aufgabe „Datenbank sichern“ (Vorgabe an) gibt es ab dem ersten Lauf nach dem Update App-Sicherungen.

**Gleichbleibender Stand beim Lesen:** Alle Tabellen einer App werden in einer Lese-Transaktion mit
`REPEATABLE READ` gelesen, über eine neue Methode des DDL-Dialekts (`consistentRead()`), damit Kopf und Positionen
zusammenpassen. Gelesen wird in Blöcken nach `id` (`lazyById`), damit grosse Tabellen den Speicher nicht füllen.

**Dateien auf S3:** Anders als `_files.zip` liest die App-Sicherung über die Storage-Disk und enthält die Dateien auch
dann. Option „ohne Dateien“ für sehr grosse Bestände; das Manifest vermerkt es, und das Einspielen lässt Dateien dann
unberührt.

**Verschlüsseln:** Die Sicherungen enthalten Personendaten. Vorschlag: optional ZIP mit AES-256 und einem Kennwort aus
der `.env` (`APP_BACKUP_PASSWORD`); ohne Kennwort wie heute nur Dateirechte 0600. Siehe offene Frage 4.

**Aufräumen:** wie bei der Vollsicherung, aber je App: die neuesten N bleiben immer, ältere als X Tage werden gelöscht.
Werte in der Aufgabe „Datenbank sichern“.

## 5. Ablauf „Wiederherstellen …“

Seite **„Apps“** → Aktion **„Sicherungen …“** je App. Liste der App-Sicherungen: Zeitpunkt, Art (geplant, von Hand,
vor Aktualisieren …), Grösse, SAFT-Version, Anzahl Datensätze, Person. Dazu „Jetzt sichern“, „Herunterladen“ (nur
Super-Admin), „Hochladen“ (Sicherung von einem anderen System, Prüfung des Manifests) und **„Wiederherstellen …“**.

Der Dialog hat vier Schritte:

1. **Prüfen** (ohne etwas zu ändern): Manifest, Prüfsummen, Format-Version, Vergleich des Aufbaus mit dem aktuellen
   (Abschnitt 7), Verweise (Abschnitt 6). Hindernisse werden mit Grund gezeigt; mit Hindernissen geht es nicht weiter.
2. **Vorschau:** je Tabelle

   | Tabelle | heute | in der Sicherung | wird geändert | kommt zurück | wird gelöscht |
   |---|---|---|---|---|---|
   | Artikel | 4 210 | 4 198 | 2 031 | 3 | 15 |

   „Wird gelöscht“ sind Datensätze, die seit der Sicherung neu angelegt wurden. Darunter: **„Änderungen seit der
   Sicherung“** aus dem Änderungsprotokoll, je Person und Tabelle („M. Keller: 2 012 Änderungen in Artikel, Import
   14:32“). Damit sieht man, wessen Arbeit verloren geht, und kann sie bitten, sie nachzutragen. Die Liste lässt sich als
   Excel herunterladen (W2).
3. **Bestätigen:** Name der App eintippen (wie beim Entfernen), Hinweis „Die App ist während der Wiederherstellung für
   alle gesperrt“. Optional eine Nachricht an die Personen der App (Benachrichtigung in SAFT).
4. **Ausführen** im Hintergrund mit Fortschrittsbalken, am Ende ein Bericht: was eingespielt, was übersprungen, welche
   Verweise geleert wurden. Der Bericht steht auch im Protokoll.

## 6. Einspielen

**Ablauf:**

1. **App sperren** (`apps.locked_at`, `locked_reason`): Datenansichten, Formulare, Import, Export und Plugins der App
   zeigen „Wiederherstellung läuft“; Aufgaben und Regeln der App warten; Auswertungen anderer Apps zeigen freigegebene
   Felder als „nicht verfügbar“, wie bei einer deaktivierten App. Laufende Bearbeitungen verlieren ihre Änderungen beim
   Speichern mit einer Meldung, nicht still.
2. **Aktuellen Stand der App sichern** (`saft-appdata/1`, Art „vor Wiederherstellen“).
3. **Sicherung in Arbeitstabellen laden:** je Tabelle `rs_<tabelle>` in `app_data`, angelegt nach dem **aktuellen**
   Aufbau (DDL über `DynamicSchemaService`, ohne Fremdschlüssel und eindeutige Indizes), Werte über die Feld-uuid
   zugeordnet (Abschnitt 7). Hier ist noch nichts an der App verändert, ein Abbruch räumt nur die Arbeitstabellen weg.
4. **Prüfen gegen die Arbeitstabellen:** Verweise nach aussen und von aussen (unten), eindeutige Felder, Pflichtfelder
   nach neuem Aufbau.
5. **Abgleich in einer Transaktion** auf der Verbindung `app_data`, je Tabelle in der Reihenfolge der Verknüpfungen:
   - **einfügen**, was in der Sicherung ist und heute fehlt (zuerst ohne Verknüpfungen, damit Kreise und Verweise auf
     sich selbst gehen; danach Verknüpfungen setzen),
   - **ändern**, was sich unterscheidet; Gleiches wird nicht angefasst,
   - **löschen**, was heute da ist und in der Sicherung fehlt, zuletzt und von den Detail- zu den Mastertabellen.

   Datensätze, die seit der Sicherung nur weich gelöscht wurden, kommen über „ändern“ zurück (`deleted_at` leer).
   Einfügen über `insertUsing` aus den Arbeitstabellen. Ändern blockweise: je 1000 ids beide Seiten lesen, in PHP
   vergleichen, nur abweichende Zeilen mit `update()` schreiben (`updateFrom` gibt es nur für PostgreSQL). Löschen über
   `whereNotExists`. Alles mit dem Query Builder, nicht über Eloquent: Es ist eine Wiederherstellung, keine fachliche
   Änderung. Regeln (B-142), Plugin-Listener,
   Webhooks (B-060) und Benachrichtigungen laufen deshalb **nicht**.
6. **Datei-Angaben** in `record_files` (Hauptverbindung) im selben Muster abgleichen, danach die Dateien aus der
   Sicherung auf die Disk kopieren. Dateien, die danach keine Angabe mehr haben, löscht später `files:prune` wie andere
   verwaiste Dateien; bis dahin sind sie noch da, falls etwas schiefgeht.
7. **Zähler der Schlüssel** je Tabelle auf `max(heutiger Zähler, grösste id)` setzen, **nie zurück**: Neue Datensätze
   dürfen keine ids erhalten, auf die das Protokoll oder andere Systeme noch zeigen. Neue Dialekt-Methode
   `ensureIdentityAtLeast()`.
8. Berechnete Felder und Summen im Master neu berechnen, Arbeitstabellen löschen, **App entsperren**, Eintrag im
   Protokoll.

**Zwei Verbindungen:** `app_data` und Hauptschema sind getrennte Verbindungen (bei MySQL mit eigenem Server möglich).
Eine gemeinsame Transaktion gibt es nicht. Deshalb gilt: Scheitert Schritt 5 oder 6, wird die Sicherung aus Schritt 2
mit **demselben Verfahren** eingespielt. Dieser Weg wird bei jedem Test der Wiederherstellung mitgeprüft.

**Verweise von aussen** (andere Apps zeigen über eine Schnittstelle auf Datensätze dieser App):

| Fall | Verhalten |
|---|---|
| Datensatz bleibt bestehen (gleiche `id`) | nichts zu tun. Darum wird abgeglichen statt ersetzt. |
| Datensatz wird gelöscht (seit der Sicherung neu), eine andere App zeigt darauf | **Hindernis**, mit Liste: „Vertrieb, Offertpositionen: 4 Datensätze zeigen auf Artikel, die seit der Sicherung neu sind“. Wahlweise „Verweise in anderen Apps leeren“ (ausdrücklich angehakt, mit Liste im Bericht und Protokolleintrag in der anderen App). Vorgabe: abbrechen. |
| Feld ist in der Sicherung nicht freigegeben, heute schon | unverändert: Freigaben sind Konfiguration, nicht Daten |

**Verweise nach aussen** (diese App zeigt auf freigegebene Tabellen anderer Apps): Zeigt ein Datensatz der Sicherung
auf einen Datensatz, den es in der anderen App nicht mehr gibt, wird der Verweis **geleert** und im Bericht genannt.
Die andere App wird nicht angefasst. Ohne Leeren würde der Fremdschlüssel das Einfügen ablehnen.

**Personen:** `created_by`/`updated_by` bleiben wie gesichert, auch wenn die Person inzwischen gelöscht ist (kein
Fremdschlüssel, Anzeige „gelöschte Person“). Ein künftiges Benutzer-Feld (B-020) wird wie ein Verweis nach aussen
behandelt.

## 7. Aufbau hat sich seit der Sicherung geändert

Spalten werden über die **Feld-uuid** zugeordnet, nicht über den Namen. Ein seither umbenanntes Feld findet seine
Werte also wieder. Je Fall:

| Fall | Verhalten |
|---|---|
| Feld seither neu | Wert aus der Vorgabe des Feldes, sonst leer. Ist das Feld Pflicht ohne Vorgabe: Hindernis mit Hinweis „Vorgabe setzen oder Pflicht vorübergehend aufheben“ |
| Feld seither entfernt | Werte der Sicherung entfallen, Hinweis in der Vorschau |
| Typ seither geändert | übernehmen, wenn alle Werte passen (dieselbe Prüfung wie beim Aktualisieren, B-107 Etappe 6a: Typ, Länge, Pflicht, eindeutig, Feldregeln), sonst Hindernis mit Beispielen |
| Tabelle seither neu | bleibt unverändert. Zeigt sie auf Datensätze, die gelöscht würden: Hindernis wie bei Verweisen von aussen |
| Tabelle seither entfernt | Daten der Sicherung entfallen, Hinweis |
| angebundene Tabelle | nie Teil der Sicherung |

Will man den **Aufbau** auch zurück, geht das in zwei Schritten: zuerst „Aktualisieren …“ mit der `definition.json`
der Sicherung als Paket (Änderungsplan aus B-107 Etappe 6, mit allen Schutzregeln), danach die Daten wiederherstellen.
Der Dialog bietet das an, wenn er Unterschiede im Aufbau findet (W3).

**SAFT-Version:** Sicherungen mit gleicher oder älterer Format-Version lassen sich einspielen, neuere nicht. Die
Systemspalten der Designer-Tabellen ändern sich selten; kommt eine dazu, liefert die Format-Version eine Umsetzung mit.

## 8. Was bewusst nicht wiederhergestellt wird

| Bereich | Warum |
|---|---|
| Änderungsprotokoll | Es dokumentiert, was geschah, auch die „kaputte“ Änderung. Die Wiederherstellung kommt als eigener Eintrag dazu (je Tabelle mit Anzahl, Kennung der Sicherung). Die Verlaufsanzeige eines Datensatzes zeigt danach „wiederhergestellt aus Sicherung vom …“. |
| Nummernkreise (B-135) | Zähler bleiben beim heutigen Stand: Nummern seit der Sicherung wurden vielleicht schon verschickt (Rechnungen). Lücken sind erlaubt, doppelte Nummern nicht. |
| Postausgang (B-060), Sammeländerungen (B-133), Läufe (B-142) | Verlauf, keine Daten. Ein „Rückgängig“ einer Sammeländerung vor dem Zeitpunkt der Wiederherstellung wird gesperrt. |
| Datenstände `ds_*` | Kopien einer externen Quelle, jederzeit neu zu ziehen |
| Konfiguration | über Aktualisieren mit der Definition der Sicherung (Abschnitt 7) |

## 9. Zusammenspiel

| Bereich | Verhalten |
|---|---|
| Apps (B-107) | Seite „Apps“ mit Aktion „Sicherungen …“; Sperre ähnlich „deaktiviert“, aber für alle, auch den Super-Admin; Aktualisieren, App-Daten zurücksetzen und Entfernen sichern künftig zusätzlich die App in `saft-appdata/1` |
| Eigene Datenbank je App (B-109) | `saft-appdata/1` ist die „Sicherung und Wiederherstellung je App“ unabhängig vom Speicherort und der Weg für den **Umzug** einer App in eine eigene Datenbank: sichern, Verbindung umstellen, einspielen |
| App mit Daten kopieren | „Sicherung als Kopie einspielen“: App aus `definition.json` als Kopie installieren (neues Präfix, Etappe 4b) und die Daten hineinladen, Zuordnung der Felder über die Zuordnung alt → neu des Installierens. Gibt eine Testkopie der produktiven App oder eine Nur-lesen-Ansicht des alten Stands neben dem aktuellen (W3). |
| Zeilenrechte (B-020) | gelten nicht: Wiederherstellen ist eine Aktion des Super-Admins für die ganze App |
| Regeln (B-142), Plugins | laufen beim Einspielen nicht. Plugins können über ein Ereignis `AppDataRestored` eigene Zwischenspeicher leeren (Minor der Plugin-API). |
| Aufgaben | Aufgabe „Datenbank sichern“ mit Option „Apps zusätzlich einzeln sichern“ und Aufbewahrung je App; neue Aufgabenart „App sichern“ (eine App, eigener Zeitplan, z. B. stündlich für eine besonders aktive App) |
| CLI | `app:backup {app?} {--without-files}`, `app:restore {app} {datei} {--dry-run}` für Server ohne Oberfläche und für Tests |
| Handbücher | Admin-Handbuch (neuer Abschnitt Sicherung und Wiederherstellung je App), `INSTALL_PRODUCTION` (App-Sicherungen mit an anderen Ort kopieren), FAQ „Ich habe Daten kaputt gemacht“, in vier Sprachen |

## 10. Etappen

| Etappe | Inhalt | Umfang |
|---|---|---|
| **W1 App-Sicherung** | Format `saft-appdata/1` mit Manifest, Definition, Tabellen, Datei-Angaben und Dateien; `app:backup`; Option in „Datenbank sichern“; „Jetzt sichern“ und Liste der Sicherungen auf der Seite „Apps“; Herunterladen; Aufräumen je App; Sicherung vor Aktualisieren, Zurücksetzen und Entfernen | mittel |
| **W2 App wiederherstellen** | Prüfen, Vorschau mit „Änderungen seit der Sicherung“, Sperre der App, Arbeitstabellen, Abgleich, Verweise von aussen und nach aussen, Zuordnung über Feld-uuid, Zähler, automatische Rücknahme bei Fehlern, Bericht und Protokoll, `app:restore` mit `--dry-run`, Hintergrund mit Fortschritt | gross |
| **W3 Erweiterungen** | Hochladen von Sicherungen anderer Systeme; Aufbau mit zurückholen (Aktualisieren mit `definition.json`); Sicherung als Kopie der App einspielen; einzelne Tabellen statt ganzer App; Excel „Änderungen seit der Sicherung“; Verschlüsselung | mittel |
| **W4 Ganze Datenbank** | Wiederherstellen der Vollsicherung über die Oberfläche: Wartungsmodus für alle, Sicherung des aktuellen Stands, Ausführen ausserhalb des Web-Requests (`db:restore` als Prozess), Prüfung des Migrationsstands (neuer → ablehnen, älter → danach `migrate`), Seitendatei `<name>.json` mit SAFT-Version und Migration bei jeder neuen Vollsicherung | mittel |

W1 ist auch ohne W2 nützlich: Die Sicherungen entstehen ab sofort, und im Notfall kann ein Entwickler sie mit
`app:restore` einspielen, sobald W2 da ist. W4 ist bewusst zuletzt: Wer die ganze Datenbank zurück will, braucht
meist ohnehin den Server, und für den häufigen Fall „eine App hat etwas kaputt gemacht“ ist W2 der richtige Weg.

## 11. Offene Fragen zur Diskussion

1. **Wer darf wiederherstellen?** Vorschlag W2: nur der Super-Admin. Später ein Recht *Apps: wiederherstellen* je
   App für App-Admins, aber nur aus Sicherungen dieser App und nie mit „Verweise in anderen Apps leeren“.
2. **Einzelne Datensätze zurückholen** statt ganzer App: Oft sind nur 20 Datensätze betroffen. Möglich wäre in der
   Verlaufsanzeige „Stand aus Sicherung vom … anzeigen und übernehmen“. Das wäre eine fachliche Änderung mit allen
   Prüfungen und Regeln, also eher die Sammeländerung (B-133) mit der Sicherung als Quelle. Eigenes Thema?
3. **Häufigkeit:** Reicht die nächtliche App-Sicherung, oder braucht es für aktive Apps stündliche (Aufgabenart
   „App sichern“)? Bei stündlichen Sicherungen wird die Aufbewahrung wichtiger (z. B. 24 stündliche, 14 tägliche).
4. **Verschlüsselung** der App-Sicherungen: Pflicht, optional oder gar nicht? Die Vollsicherung ist heute
   unverschlüsselt; ein Kennwort in der `.env` schützt nur gegen Diebstahl der Sicherung, nicht gegen Zugriff auf den
   Server.
5. **Laufende Bearbeitungen** während der Sperre: Reicht die Meldung beim Speichern, oder soll SAFT vor dem Sperren
   warten bzw. die angemeldeten Personen der App vorher sehen lassen (z. B. „3 Personen arbeiten gerade in der App“)?
6. **Vollsicherungen vor W1:** Lohnt sich ein Weg, eine alte PostgreSQL-Vollsicherung mit `pg_restore` in eine
   Hilfsdatenbank zu laden und daraus eine App-Sicherung zu erzeugen (nur wo `pg_restore` und `CREATE DATABASE`
   verfügbar sind)? Vorschlag: nein, nur als Anleitung im Admin-Handbuch.
