# Lager-Tafel – Benutzerhandbuch

## Überblick

Das Plugin *Lager-Tafel* ergänzt die Lager-Demo um zwei Ansichten und einige Regeln:

- **Lagerspiegel** – alle Artikel mit ihrem Bestand je Lagerort auf einen Blick.
- **Lagertafel** – Bestand eines Artikels per Ziehen auf einen anderen Lagerort umbuchen.
- Eine Kachel **Lager: Bestand je Lagerort** auf dem Dashboard.
- Regeln für die **Artikelnummer** und zwei Arten **geplanter Aufgaben** (Inventur-Erinnerung, Mindestbestand melden).

Gebucht wird über das Plugin *Lager-Umbuchung*; sein Handbuch beschreibt Bestände, Lagerbewegungen und Inventur. Ist es
ausgeschaltet, zeigen Lagerspiegel und Lagertafel einen Hinweis.

## Lagerspiegel

**Lager (Demo) › Lager führen › Lagerspiegel** zeigt je Artikel eine Zeile und je Lagerort eine Spalte mit dem Bestand; Lagerorte ohne
Bestand stehen grau mit 0. **Gesamt** summiert alle Lagerorte und ist rot, wenn der Artikel unter seinem
**Mindestbestand** liegt.

- **Suchen** nach Artikelnummer oder Bezeichnung über das Suchfeld.
- **Sortieren** nach Nummer, Artikel oder Mindestbestand über die Spaltenköpfe (die Spalten der Lagerorte und *Gesamt*
  lassen sich nicht sortieren).
- **Filter „Nur unter Mindestbestand“** zeigt nur Artikel, deren Gesamtbestand unter dem Mindestbestand liegt.
- Der Knopf **Auf Lagertafel verteilen** in einer Zeile öffnet die Lagertafel für diesen Artikel.

## Lagertafel

**Lager (Demo) › Lager führen › Lagertafel** zeigt für einen Artikel je Lagerort eine Karte mit dem Bestand.

1. **Artikel** wählen (oder aus dem Lagerspiegel bzw. den Artikeln mit **Auf Lagertafel verteilen** kommen).
2. Den Bestand einer Karte mit der Maus **auf eine andere Karte ziehen**.
3. Im Dialog **Menge** prüfen – vorbelegt ist der ganze Bestand am Quellort – und **Umbuchen**. Mehr als vorhanden ist
   nicht möglich.

Gebucht wird wie in der Maske *Umbuchung*: zwei Lagerbewegungen der Art *Umbuchung*, alles oder nichts. Bricht man den
Dialog ab, ändert sich nichts. Ziehen geht nur mit der Maus; mit der Tastatur buchen Sie über **Lager (Demo) › Lager führen ›
Umbuchung** um.

## Artikel: Knöpfe und Regeln

In **Lager (Demo) › Stammdaten › Artikel** gibt es zwei zusätzliche Knöpfe: **Lagerspiegel** über der Tabelle und **Auf Lagertafel
verteilen** in jeder Zeile.

Für die **Artikelnummer** gelten zusätzlich:

| Regel | Wirkung |
|---|---|
| **Einheitliche Schreibweise** | Leerzeichen fallen weg, Buchstaben werden gross: aus „ a-1001 “ wird „A-1001“ |
| **Gesperrt nach Bewegungen** | Gibt es zu einem Artikel schon Lagerbewegungen, lässt sich seine Nummer nicht mehr ändern (eine andere Schreibweise derselben Nummer ist erlaubt). Andere Felder bleiben änderbar |
| **Format A-1234** | Ein Buchstabe, Bindestrich, vier Ziffern – nur wenn die Administration beim Feld unter **Weitere Prüfungen** „Artikelnummer (Lager-Demo)“ gewählt hat |

Die Regeln gelten bei der Erfassung, beim Import und über die Schnittstelle. Eindeutig, Pflicht und höchstens
20 Zeichen legt der Table-Designer fest.

## Kachel und geplante Aufgaben

**Kachel „Lager: Bestand je Lagerort“:** Balkendiagramm mit der Summe aller Bestände je Lagerort auf dem Dashboard.

Zwei Arten geplanter Aufgaben richtet die Administration unter **Administration › Betrieb › Geplante Aufgaben** ein:

| Aufgabe | Was sie tut |
|---|---|
| **Lager: Inventur-Erinnerung** | nennt im Laufprotokoll die Bestände mit Ware, die seit der eingestellten Zahl von Tagen (*Nicht gezählt seit*, Vorgabe 90) nicht gezählt wurden – Anlass für eine Inventur |
| **Lager: Mindestbestand melden** | liegen Artikel unter dem Mindestbestand, erhalten alle Personen mit dem Recht *Lagerspiegel anzeigen* eine Meldung in der Glocke, mit dem Knopf **Lagerspiegel öffnen** |

## Rechte

| Wofür | Recht |
|---|---|
| Lagerspiegel, Kachel, Knopf *Lagerspiegel*, Meldung *Mindestbestand* | *Lager-Tafel: Lagerspiegel anzeigen* |
| Lagertafel, Knopf *Auf Lagertafel verteilen* | *Lager-Tafel: Auf der Lagertafel umbuchen* |
| Lesen | Datenrechte *anzeigen* auf Artikel, Lagerorte und Bestände |
| Umbuchen auf der Tafel | dieselben Datenrechte wie für die Maske *Umbuchung* (siehe Handbuch *Lager-Umbuchung*) |
