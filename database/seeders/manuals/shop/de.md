# Shop

## Überblick

Die App **Shop** zeigt an einem kleinen Online-Shop, wie Daten in SAFT zusammenhängen: Kunden bestellen Artikel, jede
Bestellung besteht aus Bestellpositionen. Alle Beträge sind in Euro.

| Tabelle | Inhalt |
|---|---|
| Artikel | Artikelnummer, Bezeichnung, Kategorie, Preis, Lagerbestand, aktiv ja/nein |
| Kunden | Kundennummer, Firma, Ansprechperson, E-Mail, Ort, Land, Kunde seit |
| Bestellungen | Bestellnummer, Kunde, Bestelldatum, Status, Lieferdatum, Gesamtpreis und MWST |
| Bestellpositionen | Bestellung, Position, Artikel, Menge, Einzelpreis, Betrag |

## Typische Abläufe

### Neuen Kunden erfassen

Öffnen Sie **Kunden** und klicken Sie auf **„Neuer Datensatz“**. Kundennummer und Firma sind Pflicht; die
Kundennummer darf nur einmal vorkommen. Die E-Mail-Adresse wird beim Speichern auf ihr Format geprüft.

### Bestellung aufnehmen

1. Wählen Sie unter **Kunden** den Kunden aus – darunter erscheinen seine Bestellungen.
2. Legen Sie dort mit **„Neuer Datensatz“** die Bestellung an. Der Kunde ist bereits eingetragen; als Status wählen
   Sie zunächst *Neu*.
3. Wählen Sie die neue Bestellung aus und erfassen Sie darunter die **Bestellpositionen**: Artikel und Menge genügen.
   Der **Einzelpreis** wird aus dem Artikel übernommen und lässt sich bei Bedarf ändern (z. B. Rabatt).

Den **Betrag** jeder Position (Menge × Einzelpreis) sowie **Gesamtpreis** und **MWST 8,1 %** der Bestellung berechnet
SAFT beim Speichern selbst.

### Bestellung versenden

Setzen Sie den **Status** auf *Versendet* und tragen Sie das **Lieferdatum** ein. Das Lieferdatum darf nicht vor dem
Bestelldatum liegen – sonst lehnt SAFT das Speichern mit einem Hinweis am Feld ab. Stornierte Bestellungen erhalten den
Status *Storniert*; sie bleiben für die Auswertungen erhalten.

### Artikel pflegen

Preise und Lagerbestand ändern Sie direkt beim Artikel. Eine Preisänderung wirkt nur auf neue Bestellpositionen;
bestehende behalten ihren Einzelpreis. Nicht mehr verkaufte Artikel setzen Sie auf *nicht aktiv*, statt sie zu
löschen – Artikel mit Bestellpositionen lassen sich ohnehin nicht löschen.

## Auswertungen

Unter **Auswertungen** zeigt **„Umsatz je Monat und Kategorie“** die Beträge der Bestellpositionen je Monat und
Artikelkategorie. Sie können eigene Auswertungen anlegen, z. B. Umsatz je Kunde oder je Land; die Grundtabelle für
Umsätze ist **Bestellpositionen**.

## Tipps

- Ein Klick auf eine Zeile wählt sie aus und zeigt die zugehörigen Daten darunter: beim Kunden die Bestellungen, bei
  der Bestellung die Positionen, beim Artikel, in welchen Bestellungen er vorkommt.
- **Exportieren** speichert die aktuelle Liste als Excel- oder CSV-Datei, mit Ihren Filtern und Spalten.
- Fragen zur Bedienung beantwortet der **Hilfe-Assistent** (Knopf **?** oben rechts) – auch zu diesem Handbuch.
