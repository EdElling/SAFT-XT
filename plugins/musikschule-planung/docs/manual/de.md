# Musikschule: Planung – Benutzerhandbuch

## Überblick

Das Plugin *Musikschule: Planung* ergänzt die App **Musikschule** um drei Dinge:

- den **Stundenplan** je Lehrperson, Raum oder Schüler,
- eine **Konfliktprüfung** beim Speichern eines Kurses,
- die **Semester-Fortschreibung**: Kurse und Anmeldungen ins nächste Semester übernehmen.

Die Dauer eines Kurses kommt aus seiner **Kursart** (Feld *Dauer (Minuten)*); fehlt sie, gelten 45 Minuten.

## Stundenplan

Unter **Musikschule › Unterricht › Stundenplan** wählen Sie das **Semester** (vorbelegt: das laufende), die **Ansicht**
– *Lehrperson*, *Raum* oder *Schüler* – und darin die Person bzw. den Raum.

- Jeder Kurs erscheint als **farbiger Block** am Wochentag, von Beginn bis Ende; die Farbe steht für das Fach (Legende
  unter dem Raster).
- Fahren Sie mit der Maus über einen Block, sehen Sie Zeit, Fach, Belegung (z. B. „3 von 8 Plätzen belegt“) und die
  Namen der Teilnehmenden. Ein **Klick** öffnet den Kurs in der Tabelle *Kurse*, mit Anmeldungen und Lektionen darunter.
- In der Ansicht *Schüler* erscheinen die Kurse mit einer aktiven Anmeldung.
- **Überschneidungen** – zwei Kurse zur selben Zeit – stehen nebeneinander und sind **rot umrandet**; über dem Raster
  steht, wie viele es sind.
- Kurse ohne Wochentag oder Zeit stehen unter dem Raster.
- **Drucken** gibt Überschrift, Raster und Legende auf A4 quer aus.

## Konfliktprüfung

Speichern Sie einen Kurs, prüft SAFT im **selben Semester** am **selben Wochentag**, ob sich die Zeit mit einem anderen
Kurs überschneidet, der

- dieselbe **Lehrperson** hat oder
- im selben **Raum** stattfindet.

Dann wird der Kurs **nicht gespeichert**, und die Meldung nennt den anderen Kurs, z. B. „Raum 1 ist am Montag
16:00–16:45 bereits mit ‚Klavier Mia‘ belegt.“ Aneinandergrenzende Kurse (16:00–16:45 und 16:45–17:15) sind erlaubt.
Geprüft wird beim Anlegen und wenn sich Wochentag, Zeit, Kursart, Lehrperson, Raum oder Semester ändern – auch beim
Import und bei der Semester-Fortschreibung.

Lässt ein Kurs **mehr Teilnehmende** zu, als sein Raum **Plätze** hat, wird er trotzdem gespeichert; Sie erhalten einen
Hinweis in der Glocke.

## Semester fortschreiben

Unter **Musikschule › Stammdaten › Semester** übernehmen Sie die Kurse eines Semesters in ein anderes:

- in der Zeile eines Semesters mit dem Symbol **Semester fortschreiben** (das Semester ist als Quelle vorbelegt) oder
- über der Tabelle mit **Semester fortschreiben** (Quelle: das laufende Semester).

Im Dialog wählen Sie das **Ziel** (vorbelegt: das nächste Semester) und:

| Option | Vorgabe | Wirkung |
|---|---|---|
| Wochentag, Zeit und Raum übernehmen | an | sonst bleiben sie leer und werden im neuen Semester geplant |
| Aktive Anmeldungen mitnehmen | an | als **Warteliste** mit dem Datum von heute und dem bisherigen Rabatt |
| Tarif neu aus der Kursart | an | sonst gilt der Tarif des bisherigen Kurses |

Die **Vorschau** im Dialog nennt, wie viele Kurse und Anmeldungen übernommen werden. Kurse, die es im Ziel mit gleicher
Bezeichnung schon gibt, werden **übersprungen** und mit Namen genannt – ein zweiter Lauf legt also nichts doppelt an.

Die Fortschreibung gilt **ganz oder gar nicht**: Lehnt die Konfliktprüfung einen Kurs ab (z. B. weil im Ziel ein Raum
schon belegt ist), wird nichts übernommen und die Meldung nennt den Grund.

**Nach der Fortschreibung:** Bestätigen Sie die Anmeldungen, indem Sie unter **Unterricht › Anmeldungen** den Status
von *Warteliste* auf *aktiv* setzen; wer nicht weitermacht, wird *abgemeldet*.

## Rechte

| Recht | Wirkung |
|---|---|
| Musikschule: Planung: Stundenplan ansehen | Seite *Stundenplan* (dazu das Recht, Kurse zu sehen) |
| Musikschule: Planung: Semester fortschreiben | Knöpfe *Semester fortschreiben* (dazu das Recht, Kurse und Anmeldungen anzulegen) |

Die Konfliktprüfung gilt für alle, die Kurse bearbeiten – sie braucht kein eigenes Recht.
