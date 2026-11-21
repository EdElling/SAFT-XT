# Vertrieb

## Überblick

Die App **Vertrieb** zeigt, wie zwei Apps zusammenarbeiten: Der Vertrieb führt Kunden, Anfragen und Bestellungen, die
**Artikel** aber kommen aus der App **Lager**. Das Lager gibt seine Artikel über eine **Schnittstelle** frei
(Artikelnummer, Bezeichnung, Einheit, Verkaufspreis); der Vertrieb kann sie lesen und auswählen, aber nicht ändern.
Alle Beträge sind in Euro.

| Tabelle | Inhalt |
|---|---|
| Kunden | Kundennummer, Name, Land, Ort, E-Mail, Telefon, Kunde seit |
| Events | Messen, Hausmessen, Webinare und Kundentage mit Beginn, Ende, Ort, online ja/nein |
| Anfragen | Datum, Kunde, Betreff, Status, Event (woher die Anfrage kam), Bestellung, Summe |
| Anfragepositionen | Anfrage, Position, Artikel aus dem Lager, Menge, Preis, Betrag |
| Bestellungen | Bestellnummer, Kunde, Bestelldatum, Status, Betrag |

## Typische Abläufe

### Anfrage erfassen

1. Wählen Sie unter **Kunden und Events › Kunden** den Kunden aus – darunter erscheinen seine Anfragen.
2. Legen Sie dort mit **„Neuer Datensatz“** die Anfrage an: Datum, Betreff und Status *Offen*. Kam die Anfrage von einer
   Messe oder einem Webinar, wählen Sie das **Event**.
3. Wählen Sie die Anfrage aus und erfassen Sie darunter die **Anfragepositionen**: Artikel und Menge genügen. Die
   Artikel stammen aus dem Lager; der **Preis** wird aus dem Verkaufspreis des Artikels übernommen und lässt sich für
   einen Rabatt ändern.

Den **Betrag** jeder Position (Menge × Preis) und die **Summe** der Anfrage berechnet SAFT beim Speichern selbst.

### Vom Angebot zur Bestellung

Setzen Sie den Status auf *In Bearbeitung* und, sobald das Angebot verschickt ist, auf *Angeboten*. Sagt der Kunde zu,
legen Sie unter **Verkauf › Bestellungen** die Bestellung an, tragen bei der Anfrage die **Bestellung** ein und setzen
den Status auf *Gewonnen*. Abgesagte Anfragen erhalten den Status *Verloren*; sie bleiben für die Auswertungen
erhalten.

### Events planen

Unter **Kunden und Events › Events** erfassen Sie Messen und Webinare. Das Ende darf nicht vor dem Beginn liegen. Beim
Event sehen Sie darunter alle Anfragen, die daraus entstanden sind.

## Auswertungen

- **Anfragen je Status** – Anzahl und Volumen offener, angebotener, gewonnener und verlorener Anfragen.
- **Angefragte Artikel** – welche Artikel des Lagers am häufigsten angefragt werden, mit Menge und Betrag.
- **Gewonnen je Kunde** – das Volumen der gewonnenen Anfragen je Kunde.

## Tipps

- Ein Klick auf eine Zeile zeigt die zugehörigen Daten darunter: beim Kunden Anfragen und Bestellungen, bei der Anfrage
  die Positionen, beim Event die Anfragen.
- Artikel ändern Sie in der App **Lager**. Neue oder geänderte Artikel stehen im Vertrieb sofort zur Auswahl.
- Das **Datenmodell** zeigt, wie die Tabellen zusammenhängen – auch die Verknüpfung auf die Artikel des Lagers.
- Fragen zur Bedienung beantwortet der **Hilfe-Assistent** (Knopf **?** oben rechts) – auch zu diesem Handbuch.
