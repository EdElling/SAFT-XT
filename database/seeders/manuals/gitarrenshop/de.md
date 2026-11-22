# Gitarrenshop

## Überblick

Die App **Gitarrenshop** ist eine einfache Ein- und Ausgabenrechnung mit Mehrwertsteuer für einen kleinen Handel mit
Einzelstücken: Instrumente, Zubehör, Dienstleistungen und Sonstiges. Alle Beträge sind in Franken (CHF).

| Tabelle | Inhalt |
|---|---|
| Kunden | Kundennummer, Vorname, Nachname oder Firma, Adresse, Land, E-Mail, Newsletter ja/nein, Telefon |
| Lieferanten | Name, Land, Ort, E-Mail, Telefon, Webseite, unsere Kundennummer beim Lieferanten |
| Artikel | Inventarnummer, Bezeichnung, Kategorie, Status, Beschreibung, bis zu 10 Bilder; berechnet: Kosten, Erlös, Ergebnis |
| Belege | Kaufbelege, Quittungen und Rechnungen: Nummer, Datum, Typ (Einnahme/Ausgabe), Kunde bzw. Lieferant, Belegdateien (PDF oder Bild); berechnet: Summe, bezahlt, offen |
| Buchungen | Positionen eines Belegs: Artikel, Art, Text, Netto, MWST-Satz; berechnet: MWST, Brutto, Einnahme, Ausgabe, Saldo |
| Zahlungen | Zahlungen zu einem Beleg mit Datum, Betrag, Zahlungsart und Kassenbeleg |

## Typische Abläufe

### Ware im Ausland einkaufen

1. Unter **Buchhaltung › Belege** einen Beleg vom Typ *Ausgabe* anlegen, den **Lieferanten** wählen und dessen
   Rechnung als PDF anhängen. Neue Lieferanten erfassen Sie unter **Stammdaten › Lieferanten**.
2. Darunter je Artikel eine **Buchung** mit der Art *Warenwert* erfassen (MWST-Satz 0 %, da im Ausland gekauft).
3. Kommt die Ware an, einen zweiten Beleg für den Zoll anlegen: je Artikel eine Buchung *Zoll* und eine Buchung
   *Einfuhr-MWST* (der Betrag laut Zollquittung, Satz 0 %).
4. Die Zahlung unter **Zahlungen** erfassen – beim Beleg darunter oder in der Tabelle selbst.

### Verkaufen

Einen Beleg vom Typ *Einnahme* mit dem **Kunden** anlegen (neue Kunden unter **Stammdaten › Kunden**), darunter eine Buchung mit der Art **Verkauf**, dem Nettopreis und dem
MWST-Satz (in der Regel 8,1 %). SAFT rechnet MWST und Brutto. Beim Artikel den Status auf *Verkauft* setzen.

Die **Rechnung** für den Kunden erstellen Sie in der Zeile des Belegs mit **„Dokument erstellen“** (Vorlage „Rechnung“):
SAFT lädt sie als PDF herunter und legt sie schreibgeschützt in den Belegdateien ab. Mit **„Per E-Mail senden“**
(Recht *Dokumente: versenden*) geht sie gleich an die E-Mail-Adresse des Kunden.

Unten auf der Rechnung steht die **QR-Rechnung** mit dem offenen Betrag und der Belegnummer als Referenz; passt sie
nicht mehr auf die Seite, kommt sie auf eine eigene. Zahlungsempfänger ist der Absender des Briefpapiers – die Demo
trägt dort die Beispiel-IBAN von SIX ein, wenn noch keine IBAN steht. Für echte Rechnungen die eigene IBAN (oder
QR-IBAN) im Briefpapier eintragen.

Im Dialog lässt sich als **Format** auch **Word** wählen – eine .docx-Datei zum Nachbearbeiten, ohne QR-Rechnung; sie
wird nur heruntergeladen.

### Teil- und Ratenzahlung

Zu einem Beleg beliebig viele Zahlungen erfassen, je mit eigenem Kassenbeleg. Beim Beleg zeigen **bezahlt** und
**offen**, was noch aussteht.

### Artikel kopieren

Ein ähnliches Instrument erfassen Sie am schnellsten mit **Kopieren** in der Zeile des Artikels: Alle Angaben und
Bilder werden übernommen, die Inventarnummer bleibt leer. Kopiert man einen Beleg, kommen seine Buchungen mit, die
Zahlungen nicht.

## Wichtig: Einnahme oder Ausgabe

Ob eine Buchung eine Einnahme oder eine Ausgabe ist, ergibt sich aus ihrer **Art**: *Verkauf* ist eine Einnahme, alle
anderen Arten (Warenwert, Zoll, Einfuhr-MWST, Dienstleistung, Sonstiges) sind Ausgaben. Der Typ des Belegs dient der
Übersicht.

## Auswertungen

- **Einnahmen und Ausgaben je Jahr** – mit dem Saldo (Gewinn oder Verlust) je Jahr des Belegdatums.
- **Gesamtsaldo** – eine Kennzahl über alle Buchungen; positiv = Gewinn, negativ = Verlust.
- **Alle Buchungen** – die neusten zuerst, mit Beleg, Artikel, Art und Beträgen.
- **Ein- und Ausgaben je Artikel** – Kosten, Erlös und Ergebnis je Artikel; Buchungen ohne Artikel (z. B. Fracht)
  erscheinen in einer eigenen Zeile ohne Artikel.

## Tipps

- Beim Artikel zeigen **Kosten**, **Erlös** und **Ergebnis** sofort, was ein Stück gekostet und eingebracht hat; die
  zugehörigen Buchungen stehen darunter.
- Unter **Kunden** zeigt das Häkchen **Newsletter**, wer E-Mails erhalten möchte; mit dem Filter danach und
  **Exportieren** entsteht die Adressliste für den Versand.
- Beim Kunden bzw. Lieferanten stehen seine Belege darunter.
- Die Rolle **Gitarrenshop** hat alle Rechte auf diese App – geeignet für die Person, die den Shop führt.
- Fragen zur Bedienung beantwortet der **Hilfe-Assistent** (Knopf **?** oben rechts) – auch zu diesem Handbuch.
