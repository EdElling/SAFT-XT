# Musikschule

## Überblick

Die App **Musikschule** verwaltet den Betrieb einer kleinen Musikschule: Schülerinnen und Schüler mit ihren
Kontaktpersonen, Lehrpersonen, Kurse je Semester, Lektionen mit Präsenzen, Mietinstrumente, Semesterrechnungen mit
Zahlungen sowie Konzerte und Vorspiele. Alle Beträge sind in Franken (CHF).

Sie ist ein Beispiel für eine mittelgrosse Anwendung: 20 Tabellen, entworfen mit dem **KI-App-Designer** und danach
von Hand verfeinert. Die Demo wird aus einer App-Definition installiert – genau wie über **Apps › App installieren**.

| Menü | Tabellen |
|---|---|
| Unterricht | Kurse, Anmeldungen, Lektionen, Präsenzen, Veranstaltungen, Auftritte |
| Personen | Schüler, Kontaktpersonen, Schüler-Kontakte, Lehrpersonen, Unterrichtsfächer der Lehrpersonen |
| Finanzen & Instrumente | Rechnungen, Rechnungspositionen, Zahlungen, Mietinstrumente, Mietverträge |
| Stammdaten | Fächer, Kursarten (mit Semestertarif), Semester, Räume |

Die Beispieldaten zeigen ein abgeschlossenes Semester (**HS 2026**) mit Unterricht, Rechnungen und Zahlungen und ein
begonnenes (**FS 2027**) mit den ersten Kursen und Anmeldungen.

## Typische Abläufe

### Kurs anlegen und belegen

1. Unter **Unterricht › Kurse** einen Kurs mit Fach, Kursart, Lehrperson, Raum, Semester, Wochentag und Uhrzeit
   anlegen. Der **Tarif** wird aus der Kursart übernommen und kann beim Kurs angepasst werden.
2. Unter **Anmeldungen** die Teilnehmenden erfassen – Status *aktiv*, *Warteliste* oder *abgemeldet*, bei Bedarf mit
   einem Rabatt in Prozent (z. B. für Geschwister).
3. Beim Kurs zeigen **Anmeldungen**, **freie Plätze** und **Auslastung**, wie voll er ist; gezählt werden nur aktive
   Anmeldungen.

### Lektionen und Präsenzen

Je Unterrichtstermin eine **Lektion** mit Datum, Dauer und Thema erfassen, darunter die **Präsenzen** (anwesend,
entschuldigt, unentschuldigt). Der **Stundensatz** kommt von der Lehrperson; SAFT rechnet **Stunden** und **Honorar**.
Bei der Schülerin oder dem Schüler zeigt die **Präsenzquote**, wie regelmässig sie oder er kommt.

### Semesterrechnung

1. Unter **Finanzen & Instrumente › Rechnungen** eine Rechnung an die Kontaktperson (bei Minderjährigen) oder an die
   erwachsene Schülerin bzw. den Schüler anlegen, mit Semester und Fälligkeit.
2. Darunter je Kurs eine **Rechnungsposition** der Art *Kurs* – den **Einzelpreis** übernimmt SAFT aus dem Tarif des
   Kurses, ein Rabatt wird abgezogen. Mietinstrumente als Position der Art *Miete* mit der Anzahl Monate.
3. Eingänge unter **Zahlungen** erfassen; **bezahlt** und **offen** zeigen den Stand. Eine stornierte Rechnung hat
   nichts mehr offen.

Kopiert man eine Rechnung (z. B. fürs nächste Semester), kommen ihre Positionen mit, die Zahlungen nicht.

### Instrumente vermieten

Unter **Mietinstrumente** jedes Instrument mit Inventarnummer, Zustand und Mietpreis erfassen, unter
**Mietverträge** die Vermietung mit Beginn, Ende, Kaution und Vertrag (PDF).

### Konzerte und Vorspiele

Unter **Veranstaltungen** einen Anlass anlegen und darunter die **Auftritte** mit Stück, Komponist, Instrument und
Reihenfolge – daraus entsteht das Programm.

## Auswertungen

- **Belegung je Kurs** – Anmeldungen, freie Plätze und Auslastung.
- **Einnahmen je Fach und Semester** – Rechnungsbeträge der Kurspositionen.
- **Offene Rechnungen je Kontaktperson** – wer wie viel noch schuldet.
- **Präsenzquote je Schüler** – Präsenzen, davon anwesend, Quote.
- **Unterrichtsstunden und Honorar je Lehrperson** – Grundlage für die Lohnabrechnung.

## Tipps

- Bei der Kontaktperson zeigt **offener Betrag** die Summe ihrer offenen Rechnungen.
- Geschwister teilen sich ihre Kontaktpersonen; das Häkchen **Rechnungsempfänger** bei den Schüler-Kontakten zeigt,
  wer die Rechnung erhält.
- Die Rolle **Musikschule** hat alle Rechte auf diese App – geeignet für die Schulleitung oder das Sekretariat.
- Fragen zur Bedienung beantwortet der **Hilfe-Assistent** (Knopf **?** oben rechts) – auch zu diesem Handbuch.
