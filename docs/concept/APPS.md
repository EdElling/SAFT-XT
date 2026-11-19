# Konzept: Apps (Fachanwendungen) in SAFT XT

Stand: 18.11.2026 · Status: abgestimmt, Etappe 1a in Planung · Backlog: B-107

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
| Gemeinsam für alle Apps | Nur das System: Benutzer, Anmeldung und SSO, Globale Einstellungen, KI-Anbindung, Datenbankverbindungen, Systemtabellen, System-Aufgaben, Administration. |
| Tabellen | Jede Tabelle des Table-Designers (auch angebundene) gehört zu genau einer App. Keine gemeinsamen Stammdaten-Tabellen: Braucht eine App die Stammdaten einer anderen, wird besser die erste App erweitert. |
| Verweise zwischen Apps | Erlaubt, aber nur über eine **ausdrücklich freigegebene Schnittstelle** der anbietenden App (Abschnitt 5), nur lesend und robust gegen Änderungen. |
| Rollen | Je App, nicht gemischt. Ausnahmen: die Systemrollen *super-admin* und *benutzer*. *benutzer* gilt in allen Apps und enthält nur app-übergreifende Rechte (Dashboard, KI-Hilfe, Dokumentation …), keine Datenrechte. Rollen lassen sich einer App zuordnen und als Vorlage für eine neue Rolle **kopieren**. |
| Zugang zu einer App | Ergibt sich aus den Rollen: Wer eine Rolle einer App hat, sieht die App. Kein zusätzliches Recht. |
| Umschalten | Ein Filament-Panel; aktuelle App in der Sitzung, Umschalter in der Titelzeile (nur bei mehr als einer App). Adressen bleiben gleich, die App ergibt sich aus der Tabelle bzw. dem Objekt. |
| Verwaltung | Neue Administrationsseite **Apps** für den Super-Admin: anlegen, bearbeiten, aktivieren, deaktivieren, installieren (aus Paket), exportieren, aktualisieren, entfernen. Sie ersetzt „Konfiguration übertragen“. |
| Umstellung bestehender Installationen | Minimal: Die Migration legt bei vorhandenen Objekten **eine** App „Bestehende Daten“ an und ordnet ihr alles zu (Tabellen, Menüs, Auswertungen, Rollen, Aufgaben) – ohne automatisches Aufteilen. Aufgeteilt wird danach von Hand mit „In andere App verschieben“ (z. B. die Lager-Demo in eine eigene App). Neue Installationen haben keine solche App; die Demo-Seeder legen ihre eigenen Apps an. |
| Verschieben | Tabellen lassen sich samt Zugehörigem **in eine andere App verschieben** (Abschnitt 5a), z. B. um „Bestehende Daten“ aufzuteilen. |
| Alte Pakete | Konfigurationspakete `saft-config/1` bleiben einspielbar; der Import fragt nach der Ziel-App (bestehende oder neue). |
| Tabellennamen | Bleiben global eindeutig (ein Namensraum `app_data`). Der Table-Designer schlägt das Präfix der App vor (`lager_…`). |

## 3. Was je App getrennt ist

| Objekt | Zuordnung |
|---|---|
| Tabellen, Felder, Tabellenregeln, Detailtabellen | `app_id` an der Tabelle |
| Menüs | Eine App hat beliebig viele Gruppen und Untermenüs; die Navigation zeigt nur die aktuelle App (plus System). |
| Auswertungen, Tabellenverknüpfungen für Auswertungen | `app_id` |
| Dashboard | je Person **und** App (Kacheln, Auswertungen) |
| Rollen | `app_id`; beim Erstellen und Bearbeiten richtet sich alles nach der gewählten App: Datenrechte-Matrix und Daten-Menü nur mit Tabellen dieser App (ohne gewählte App keine Tabellen, sondern ein Hinweis), Rechte von Plugins nur für Plugins dieser App; allgemeine Rechte von SAFT bleiben sichtbar. Nicht angezeigte Rechte bleiben beim Speichern unverändert |
| Table-Connector | angebundene Tabellen gehören wie Designer-Tabellen zu einer App; die Übersicht zeigt die App als Spalte mit Filter |
| Lebenszyklus | aktiv/inaktiv (inaktiv: nur der Super-Admin sieht und nutzt sie, Aufgaben ruhen); Entfernen samt Inhalt nur durch den Super-Admin (kein zuteilbares Recht), nur deaktiviert, zweifach bestätigt, frühestens 10 Minuten nach dem Vormerken (bis dahin abbrechbar), vorher Sicherung von Datenbank und Dateien |
| Geplante Aufgaben (nicht System) | `app_id` |
| Plugins | einer App zugeordnet, je App aktiv/inaktiv; Code bleibt plattformweit (keine echte Abschottung möglich) |
| KI-Kontext | Tabellen-, Auswertungs- und Hilfe-Assistent sehen nur die aktuelle App (Tabellen, Menüs, Rollen, Handbücher ihrer Plugins) |
| Handbücher | System-Handbücher überall; Handbuch der App (Markdown je Sprache, Seite „Apps“) und Handbücher ihrer Plugins nur in dieser App – in „Dokumentation“ und im Hilfe-Assistenten. Teil der App-Definition (Etappe 4); ein KI-App-Designer kann es erzeugen |
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

## 5a. In andere App verschieben

Verschoben wird eine **Auswahl von Tabellen in einem Schritt** (Mehrfachauswahl im Table-Designer oder eine ganze
Menügruppe), jeweils mit allem, was nur an ihnen hängt. Alle Prüfungen beziehen sich auf den **Endzustand nach dem
Verschieben**, nicht auf Zwischenschritte: Ein Verweis zwischen zwei Tabellen, die beide verschoben werden, ist danach
ein Verweis innerhalb der neuen App und braucht keine Schnittstelle. Der Dialog zeigt vorher, was mitgeht und was zu
klären ist:

- **Mit:** Menüeinträge, die nur diese Tabelle öffnen (Ziel: eine Gruppe der neuen App, wählbar), Auswertungen mit
  dieser Tabelle als Grundtabelle, Tabellenverknüpfungen für Auswertungen von ihr aus, geplante Aufgaben zu ihr,
  Datenstände, Dateien (bleiben einfach liegen, sie hängen an der Tabelle).
- **Verknüpfte Tabellen:** Verweist eine gewählte Tabelle auf Tabellen, die in der alten App bleiben würden (oder
  umgekehrt), bietet der Dialog an, sie mit einem Klick **zur Auswahl hinzuzufügen** – bis zur ganzen zusammenhängenden
  Gruppe (z. B. Bestellungen mit Positionen und Kunden). Nur Verweise, die nach dem Verschieben tatsächlich über die
  App-Grenze gehen, werden zu Verweisen über eine Schnittstelle (Abschnitt 5): Die Zieltabelle wird freigegeben, der
  Dialog nennt das ausdrücklich. Vor Etappe 5 verhindern solche verbleibenden Verweise das Verschieben.
- **Auswertungen über mehrere Apps:** Nutzt eine Auswertung der alten App die verschobene Tabelle, braucht sie die
  Schnittstelle (wie oben) – sonst wird sie mitverschoben, wenn sie nur diese Tabelle und mitverschobene nutzt.
- **Rollen:** Datenrechte auf die Tabelle gehören zu Rollen der alten App und werden dort entfernt. Der Dialog bietet
  an, sie einer Rolle der neuen App zu übertragen (bestehende wählen oder neue anlegen). Wer dadurch die Tabelle nicht
  mehr sieht, nennt der Dialog vorher (Anzahl Personen je Rolle).
- **Daten** bleiben unverändert; der technische Tabellenname auch (kein Umbenennen auf das neue Präfix, das wäre ein
  eigener Schritt).
- Alles in einem Schritt, im Änderungsprotokoll festgehalten; die Ausgangsstände beider Apps werden als „lokal
  geändert“ markiert (für spätere Paket-Updates).

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
| 1a Fundament | Tabelle `apps`; `app_id` an Tabellen, Menüs, Auswertungen, Tabellenverknüpfungen, Rollen, Aufgaben; **stabile Schlüssel** an allen Objekten; minimale Umstellung („Bestehende Daten“); Demo-Seeder legen ihre Apps an; Seite „Apps“; App-Auswahl in Formularen (nur wo nicht ableitbar), Filter in Listen; Grenze zwischen Apps (Verknüpfungen, Übernehmen aus, Formeln, Auswertungen nur innerhalb einer App); Präfix-Vorschlag; **Rolle kopieren**; Import alter Pakete mit Ziel-App | gross |
| 1b Verschieben | **In andere App verschieben** (Abschnitt 5a: mehrere Tabellen oder eine Menügruppe in einem Schritt, Prüfung auf den Endzustand, Rollenrechte übertragen); verbleibende Verweise über die App-Grenze verhindern das Verschieben, bis Etappe 5 sie erlaubt | mittel |
| 2 Umschalten | aktuelle App, Umschalter in der Titelzeile, Navigation und Dashboard je App (Navigations-Cache je App), Zugang über Rollen, Datenrechte und Plugin-Rechte; Rechte der Auswertungen je App; Handbuch je App; Plugins und Datenmodell des Hilfe-Assistenten je App (Plugin-App aus requiredTables) | mittel |
| 3 Lebenszyklus | aktivieren, deaktivieren, entfernen (Abhängigkeiten, Sicherung, Bestätigung); „Demo-Daten zurücksetzen“ wird „App-Daten zurücksetzen“; Rollenformular ganz nach der gewählten App (Tabellen und Plugin-Rechte; Rechte ausserhalb der App nur zum Entfernen), App-Spalte und Filter im Table-Connector; KI-Tabellenassistent wählt die App (aus dem Text, sonst Rückfrage) und ein Symbol | mittel |
| 4 App-Definition | Format `saft-app/1`: aus dem aktuellen Stand erzeugen, exportieren, als neue App installieren, Ausgangsstand speichern; Diagramm aus einer Definition; Seite „Apps“ ersetzt „Konfiguration übertragen“. Beim Export **zuerst die App wählen**, dann ihre Objekte; System-Menüs (Dashboard, Dokumentation, Auswertungen, Administration) und Systemrollen werden nie angeboten. In zwei Teilen: **4a** Format, Export, Version, Datenmodell je App; **4b** Installieren (auch **als Kopie** mit neuen Schlüsseln und anderem Präfix; dieselbe App ein zweites Mal sonst abgelehnt), Ausgangsstand, eigene Rechte *Apps: exportieren* und *Apps: installieren*, „Konfiguration übertragen“ wird abgelöst (alte Pakete weiter einspielbar) | mittel |
| 5 Schnittstellen | Freigabe von Tabellen/Feldern, Verweise aus anderen Apps nur darauf, Vertragsschutz, „Verwendet in“ über Apps | mittel |
| 6 Änderungsplan | Vergleich, Einstufung, Konflikte, Vorschau, Ausführen; Aktualisieren einer installierten App aus einem Paket (löst B-001 ab) | gross |
| 7 Plugins und KI | Plugin-Zuordnung und -Aktivierung je App, Plugin-API 1.9 (Plugin schlägt seine App vor), KI-Kontext je App | mittel |
| 8 KI-App-Designer | siehe Abschnitt 7 | gross |

Jede Etappe ist für sich auslieferbar; ab Etappe 2 ist der Nutzen sichtbar. Etappe 1 legt mit den stabilen
Schlüsseln die Grundlage für 4, 6 und 8.

## 9. Risiken

- **Breite:** Fast jede Liste und jedes Formular der Administration ist betroffen; Browser-Tests und Handbücher in vier
  Sprachen entsprechend.
- **Umstellung bestehender Installationen** (auch Strato): bewusst minimal (alles in „Bestehende Daten“); das Aufteilen
  geschieht von Hand mit „In andere App verschieben“ und ist damit sichtbar und kontrolliert.
- **Plugins** sehen technisch weiterhin alle Tabellen; „je App“ betrifft Zuordnung, Sichtbarkeit und Aktivierung.
- **Änderungsplan** ist der anspruchsvollste Teil (Konflikte, teilweise angewendete Pläne); er kommt bewusst spät, auf
  einem stabilen Fundament.

## 10. Offene Punkte

Keine – Etappe 1a ist abgestimmt (18.11.2026).
