# Konzept: Apps (Fachanwendungen) in SAFT XT

Stand: 18.11.2026 · Status: Entwurf zur Abstimmung · Backlog: B-107

## 1. Ziel

Auf derselben SAFT-Installation laufen mehrere **Fachanwendungen (Apps)** einer Organisation nebeneinander, z. B.
Lager, Forschung, Personaladministration. Eine App fasst zusammen, was fachlich zusammengehört, damit man es
gemeinsam sieht, freigibt, ausschaltet, überträgt, aktualisiert und entfernt. Personen mit Rollen in mehreren Apps
schalten in der Titelzeile zwischen ihnen um.

Ausdrücklich **kein** Ziel: Mandantenfähigkeit (getrennte Organisationen, getrennte Benutzer oder Datenbanken).

## 2. Entscheidungen

| Frage | Entscheidung |
|---|---|
| Art der Trennung | Fachliche Klammer: Jedes Objekt gehört zu genau einer App; die App bestimmt Sichtbarkeit (Navigation, Dashboard, Listen, KI-Kontext). Den Zugriff regeln weiterhin die Rechte. |
| Gemeinsam für alle Apps | Nur das System: Benutzer, Anmeldung und SSO, App-Einstellungen, KI-Anbindung, Datenbankverbindungen, Systemtabellen, System-Aufgaben, Administration. |
| Tabellen | Jede Tabelle des Table-Designers (auch angebundene) gehört zu genau einer App. Keine gemeinsamen Stammdaten-Tabellen: Braucht eine App die Stammdaten einer anderen, wird besser die erste App erweitert. |
| Verweise zwischen Apps | Erlaubt, aber nur über eine **ausdrücklich freigegebene Schnittstelle** der anbietenden App (Abschnitt 5), nur lesend und robust gegen Änderungen. |
| Rollen | Je App, nicht gemischt. Ausnahmen: die Systemrollen *super-admin* und *benutzer*. *benutzer* gilt in allen Apps und enthält nur app-übergreifende Rechte (Dashboard, KI-Hilfe, Dokumentation …), keine Datenrechte. Rollen lassen sich einer App zuordnen und als Vorlage für eine neue Rolle **kopieren**. |
| Zugang zu einer App | Ergibt sich aus den Rollen: Wer eine Rolle einer App hat, sieht die App. Kein zusätzliches Recht. |
| Umschalten | Ein Filament-Panel; aktuelle App in der Sitzung, Umschalter in der Titelzeile (nur bei mehr als einer App). Adressen bleiben gleich, die App ergibt sich aus der Tabelle bzw. dem Objekt. |
| Verwaltung | Neue Administrationsseite **Apps** für den Super-Admin: anlegen, bearbeiten, aktivieren, deaktivieren, installieren (aus Paket), exportieren, aktualisieren, entfernen. Sie ersetzt „Konfiguration übertragen“. |
| Tabellennamen | Bleiben global eindeutig (ein Namensraum `app_data`). Der Table-Designer schlägt das Präfix der App vor (`lager_…`). |

## 3. Was je App getrennt ist

| Objekt | Zuordnung |
|---|---|
| Tabellen, Felder, Tabellenregeln, Detailtabellen | `app_id` an der Tabelle |
| Menüs | Eine App hat beliebig viele Gruppen und Untermenüs; die Navigation zeigt nur die aktuelle App (plus System). |
| Auswertungen, Tabellenverknüpfungen für Auswertungen | `app_id` |
| Dashboard | je Person **und** App (Kacheln, Auswertungen) |
| Rollen | `app_id`; die Rechtematrix zeigt nur Tabellen und Rechte der App |
| Geplante Aufgaben (nicht System) | `app_id` |
| Plugins | einer App zugeordnet, je App aktiv/inaktiv; Code bleibt plattformweit (keine echte Abschottung möglich) |
| KI-Kontext | Tabellen-, Auswertungs- und Hilfe-Assistent sehen nur die aktuelle App (Tabellen, Menüs, Rollen, Handbücher ihrer Plugins) |
| Protokolle, Datenstände | global, mit Filter nach App |

## 4. Die App-Definition als gemeinsame Grundlage

Drei Vorhaben brauchen dasselbe: **Pakete** (Export/Import), **Aktualisieren** bestehender Apps und der spätere
**KI-App-Designer**. Gemeinsame Grundlage wird eine deklarative **App-Definition** (JSON, Format `saft-app/1`), die
eine App vollständig beschreibt: Tabellen mit Feldern, Regeln und Verknüpfungen, Schnittstellen, Auswertungen,
Tabellenverknüpfungen, Rollen mit Rechten, Menüs, Dashboard-Vorgaben, Aufgaben, benötigte Plugins, optional
Beispieldaten. Sie entwickelt das heutige `saft-config/1` weiter.

**Massgeblich ist die Datenbank, nicht das JSON.** Die Definition einer App wird bei Bedarf aus dem aktuellen Stand
erzeugt (Export, KI-Kontext, Diagramm) – sie ist damit immer aktuell, auch nach Änderungen von Hand im Table-Designer.
Gespeichert wird je App nur der **Ausgangsstand**: die Definition, wie sie zuletzt installiert oder angewendet wurde,
mit Version, Zeitpunkt und Person. Er dient dem Erkennen lokaler Änderungen beim Aktualisieren (Abschnitt 6) und
ergibt nebenbei den Verlauf der App-Konfiguration. Ein gespeichertes JSON als zweite Wahrheit, das bei jeder Änderung
von Hand nachgeführt werden müsste, gibt es bewusst nicht.

**Stabile Schlüssel:** Jedes Objekt der Definition bekommt einen dauerhaften Schlüssel (`key`, UUID), der in der
Datenbank mitgespeichert wird und sich beim Umbenennen nicht ändert. Nur so lässt sich später unterscheiden, ob ein
Feld umbenannt oder gelöscht und neu angelegt wurde. **Die Schlüssel werden in Etappe 1 eingeführt**, auch wenn
Aktualisieren erst später kommt, damit vorhandene Installationen von Anfang an vergleichbar sind.

Wer was mit der Definition macht:

```
Fachkonzept (Text) ──KI-App-Designer──▶ App-Definition (JSON) ──▶ Diagramm (Mermaid, wie Datenmodell)
                                              │
 installierte App ──Export──▶ App-Definition   ▼
                                       Änderungsplan (Abschnitt 6) ──Bestätigen──▶ installierte App
```

## 5. Schnittstellen zwischen Apps

Damit Verweise zwischen Apps robust sind (und dieselbe Logik später der REST-API B-023 dient):

- Eine App **gibt** einzelne Tabellen als Schnittstelle **frei**, mit ausgewählten Feldern (z. B. *Lager: Artikel* mit
  Artikelnummer und Bezeichnung). Nur freigegebene Tabellen und Felder sind für andere Apps sichtbar.
- Andere Apps können darauf **verweisen** (Verknüpfung, Anzeige, Auswertung), nur **lesend**: kein Anlegen, Ändern,
  Löschen, kein „Übernehmen aus“ in die fremde Tabelle, keine Formeln über fremde Kind-Tabellen.
- Freigegebene Felder sind **Vertrag**: Solange eine andere App sie nutzt, lassen sie sich nicht löschen, umbenennen
  (technischer Name) oder in einen unverträglichen Typ ändern – wie heute bei Verknüpfungen und Regeln, nur über App-
  Grenzen. Die Seite „Verwendet in“ zeigt die Nutzer.
- Eine App mit genutzter Schnittstelle lässt sich nicht entfernen; Deaktivieren zeigt einen Hinweis (verweisende Felder
  zeigen dann „nicht verfügbar“ statt Fehler).
- Rechte: Lesen über die Schnittstelle braucht weiterhin das Leserecht auf die Tabelle; eine Rolle einer App kann dafür
  ausdrücklich Leserechte auf freigegebene Tabellen anderer Apps erhalten (sonst bleiben Rollen je App getrennt).
- Die Schnittstelle ist Teil der App-Definition und hat eine **Version**; inkompatible Änderungen erhöhen sie und
  werden beim Aktualisieren als Bruch gemeldet.

## 6. Aktualisieren: Änderungsplan statt Überschreiben

Grundlage für das Einspielen neuer Versionen einer App, für B-001 und für Änderungen durch den KI-App-Designer:

1. **Vergleich** von gewünschter Definition (Paket oder KI-Vorschlag) und installiertem Stand über die stabilen
   Schlüssel.
2. **Änderungsplan** je Objekt: neu, geändert (feldgenau), entfernt, unverändert. Jede Änderung wird eingestuft:
   - *sicher* (neue Tabelle, neues Feld, Bezeichnung, neue Auswertung),
   - *heikel* (Typänderung mit Umwandlung, Pflichtfeld bei vorhandenen Daten, Regel, die Bestand verletzt),
   - *zerstörend* (Feld oder Tabelle mit Daten entfernen).
3. **Lokale Änderungen erkennen (Paket-Updates):** Verglichen werden drei Stände – Ausgangsstand (zuletzt
   installiert), aktueller Stand (aus der Datenbank erzeugt) und neue Paketversion. Was nur das Paket ändert, wird
   übernommen; was jemand seither in SAFT geändert hat und das Paket ebenfalls ändert, ist ein **Konflikt**; je
   Konflikt wählt man „behalten“ oder „übernehmen“. Für Änderungen durch den KI-Assistenten genügen zwei Stände
   (aktuell und Vorschlag), denn der Vorschlag entsteht aus dem aktuellen Stand.
4. **Vorschau** in Worten (wie beim Tabellen-Assistenten), Auswahl was übernommen wird; zerstörende Schritte nur mit
   ausdrücklicher Bestätigung.
5. **Ausführen** mit Sicherung vorher, über die bestehenden Schutzregeln des Table-Designers
   (`DynamicSchemaService`), protokolliert; scheitert ein Schritt, bleibt der Plan teilweise angewendet und meldet das
   – Schemaänderungen lassen sich nicht in jeder Datenbank zurückrollen.

Die Schritte des Plans sind die Operationen, die es heute schon von Hand gibt (Tabelle bzw. Feld anlegen, ändern,
deaktivieren, löschen; Auswertung, Rolle, Menü anlegen oder ändern) – mit denselben Schutzregeln. Neu sind Vergleich,
Einstufung, Vorschau und Konflikterkennung. Nach dem Ausführen wird der neue Stand als Ausgangsstand gespeichert.

Der Plan ist ein eigenständiger Baustein (Eingabe: zwei Definitionen; Ausgabe: Schritte), damit Pakete, KI-Designer
und später die REST-API dieselbe Logik nutzen.

## 7. KI-App-Designer (Perspektive, nicht Teil der ersten Etappen)

Aus einem Fachkonzept (Text) entwirft der Assistent eine App-Definition, zuerst nur das Datenmodell. SAFT zeigt sie als
Diagramm (Renderer des Datenmodells, ohne dass etwas angelegt ist). Assistent und Person klären Details im Dialog; die
Definition wird schrittweise ergänzt (Auswertungen, Rollen, Menüs). „Anlegen“ erzeugt daraus eine neue App über den
Änderungsplan – derselbe Weg wie beim Installieren eines Pakets.

**Änderungen an bestehenden Apps:** Der Assistent erhält die aus der Datenbank erzeugte aktuelle Definition (inkl.
Änderungen von Hand), schlägt eine geänderte Definition vor („füge Lieferanten hinzu“, „Bestellung braucht ein
Lieferdatum“), SAFT zeigt Diagramm und Änderungsplan, nach Bestätigung wird er ausgeführt. Der heutige
Tabellen-Assistent („Mit KI anpassen“) ist eine Vorstufe davon für eine einzelne Tabelle.

Voraussetzungen aus den Etappen davor: App-Definition mit Schema für strukturierte KI-Antworten (wie heute beim
Tabellen-Assistenten), Diagramm aus einer Definition, Änderungsplan.

## 8. Etappen

| Etappe | Inhalt | Grösse |
|---|---|---|
| 1 Fundament | Tabelle `apps`; `app_id` an Tabellen, Menüs, Auswertungen, Tabellenverknüpfungen, Rollen, Aufgaben; **stabile Schlüssel** an allen Objekten; Migration: bestehende Objekte in eine Standard-App, Demos (Shop, Lager, Forschung) in eigene Apps; Seite „Apps“ (anlegen, bearbeiten); App-Auswahl in Formularen, Filter in Listen; Präfix-Vorschlag im Table-Designer | gross |
| 2 Umschalten | aktuelle App, Umschalter in der Titelzeile, Navigation und Dashboard je App (Navigations-Cache je App), Zugang über Rollen; Rollen je App mit **Rolle kopieren** | mittel |
| 3 Lebenszyklus | aktivieren, deaktivieren, entfernen (Abhängigkeiten, Sicherung, Bestätigung); „Demo-Daten zurücksetzen“ wird „App-Daten zurücksetzen“ | mittel |
| 4 App-Definition | Format `saft-app/1`: aus dem aktuellen Stand erzeugen, exportieren, als neue App installieren, Ausgangsstand speichern; Diagramm aus einer Definition; Seite „Apps“ ersetzt „Konfiguration übertragen“ | mittel |
| 5 Schnittstellen | Freigabe von Tabellen/Feldern, Verweise aus anderen Apps nur darauf, Vertragsschutz, „Verwendet in“ über Apps | mittel |
| 6 Änderungsplan | Vergleich, Einstufung, Konflikte, Vorschau, Ausführen; Aktualisieren einer installierten App aus einem Paket (löst B-001 ab) | gross |
| 7 Plugins und KI | Plugin-Zuordnung und -Aktivierung je App, Plugin-API 1.9 (Plugin schlägt seine App vor), KI-Kontext je App | mittel |
| 8 KI-App-Designer | siehe Abschnitt 7 | gross |

Jede Etappe ist für sich auslieferbar; ab Etappe 2 ist der Nutzen sichtbar. Etappe 1 legt mit den stabilen
Schlüsseln die Grundlage für 4, 6 und 8.

## 9. Risiken

- **Breite:** Fast jede Liste und jedes Formular der Administration ist betroffen; Browser-Tests und Handbücher in vier
  Sprachen entsprechend.
- **Migration bestehender Installationen** (auch Strato): Zuordnung zur Standard-App und Aufteilung der Demos muss
  verlässlich sein; vorher Sicherung, Probelauf (`--dry-run`).
- **Verweise zwischen Apps** in bestehenden Daten: Gibt es sie nach der Migration (z. B. eine eigene Tabelle verweist
  auf eine Demo-Tabelle), werden die Ziele automatisch als Schnittstelle freigegeben und gemeldet.
- **Plugins** sehen technisch weiterhin alle Tabellen; „je App“ betrifft Zuordnung, Sichtbarkeit und Aktivierung.
- **Änderungsplan** ist der anspruchsvollste Teil (Konflikte, teilweise angewendete Pläne); er kommt bewusst spät, auf
  einem stabilen Fundament.

## 10. Offene Punkte

- Name der Standard-App bei der Migration (z. B. „Allgemein“).
- Was wird aus Konfigurationspaketen im alten Format `saft-config/1`: Einspielen weiterhin möglich (als neue App)?
