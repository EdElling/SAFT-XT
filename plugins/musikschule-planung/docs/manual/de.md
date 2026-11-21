# Musikschule: Planung – Benutzerhandbuch

## Überblick

Das Plugin *Musikschule: Planung* ergänzt die App **Musikschule** um:

- den **Stundenplan** je Lehrperson, Raum oder Schüler,
- eine **Konfliktprüfung** beim Speichern eines Kurses,
- die **Semester-Fortschreibung**: Kurse und Anmeldungen ins nächste Semester übernehmen,
- **Lektionen erzeugen**: die wöchentlichen Lektionen eines Semesters auf einmal anlegen, ohne Ferien,
- **Präsenz erfassen**: zu einer Lektion alle Angemeldeten auf einer Seite,
- einen **Hinweis für die Warteliste**, sobald in einem Kurs ein Platz frei wird.

Lektionen erzeugen und Präsenz erfassen brauchen die Tabellen *Lektionen* und *Präsenzen*; die Tabelle **Ferien** gibt
es ab Version 1.1 der App.

Die Dauer eines Kurses kommt aus seiner **Kursart** (Feld *Dauer (Minuten)*); fehlt sie, gelten 45 Minuten.

## Stundenplan

Unter **Musikschule › Unterricht › Stundenplan** wählen Sie das **Semester** (vorbelegt: das laufende), die **Ansicht**
– *Lehrperson*, *Raum* oder *Schüler* – und darin die Person bzw. den Raum.

- Jeder Kurs erscheint als **farbiger Block** am Wochentag, von Beginn bis Ende; die Farbe steht für das Fach (Legende
  unter dem Raster).
- Fahren Sie mit der Maus über einen Block, sehen Sie Zeit, Fach, Belegung (z. B. „3 von 8 Plätzen belegt“), die Anzahl
  auf der Warteliste und die Namen der Teilnehmenden. Ein **Klick** öffnet den Kurs in der Tabelle *Kurse*,
  mit Anmeldungen und Lektionen darunter.
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

## Lektionen erzeugen

Statt jede Lektion einzeln zu erfassen, legt SAFT sie für einen Zeitraum **wöchentlich** an – am Wochentag des Kurses,
mit seiner Lehrperson und der Dauer der Kursart. Den Dialog **Lektionen erzeugen** gibt es an drei Stellen:

- in der Zeile eines **Kurses** (nur dieser Kurs),
- in der Zeile eines **Semesters** (alle seine Kurse),
- über der Tabelle **Kurse** (alle Kurse des gewählten Semesters, vorbelegt das laufende).

Im Dialog wählen Sie **Von** und **Bis** – vorbelegt ab heute, wenn das Semester läuft, sonst ab Semesterbeginn, bis
Semesterende – und ob **Ferien und Feiertage übersprungen** werden. Die **Vorschau** nennt vorher, was geschieht, z. B.
„8 Lektionen in 1 Kurs. 2 Termine fallen in Ferien. 3 Termine haben schon eine Lektion.“

- Lektionen entstehen nur innerhalb des Semesters des Kurses, auch wenn der Zeitraum darüber hinausgeht.
- Termine, die schon eine Lektion haben, werden übersprungen – Sie können den Dialog also ohne Doppel **wiederholen**,
  z. B. nach dem Anlegen eines neuen Kurses.
- Kurse ohne Wochentag werden übersprungen und genannt.
- Es gilt **ganz oder gar nicht**: Scheitert eine Lektion, wird keine angelegt.

**Ferien** erfassen Sie unter **Musikschule › Stammdaten › Ferien** mit Bezeichnung, *Von* und *Bis*; ein einzelner
Feiertag hat *Von* = *Bis*.

## Präsenz erfassen

Unter **Musikschule › Unterricht › Präsenz erfassen** – oder mit dem Klemmbrett-Symbol in der Zeile einer **Lektion** –
erfassen Sie die Präsenzen einer Lektion auf einer Seite.

- Oben wählen Sie die **Lektion**; zur Auswahl stehen die Lektionen ihres Semesters, z. B. „Mo 17.08.2026 · Klavier Mia
  · Mia Huber“. Ohne Vorgabe ist die nächste Lektion von heute gewählt, sonst die letzte vergangene.
- Darunter stehen alle **aktiv Angemeldeten** des Kurses mit drei Knöpfen *anwesend* (Vorgabe), *entschuldigt* und
  *unentschuldigt* sowie einer **Bemerkung**. **Alle anwesend** setzt alle Zeilen zurück auf *anwesend*.
- Schon erfasste Präsenzen werden geladen – auch von Schülern, die inzwischen nicht mehr aktiv angemeldet sind (mit
  Vermerk).
- **Speichern** legt fehlende Präsenzen an und ändert geänderte; doppelt angelegt wird nichts. Die Meldung nennt, wie
  viele neu und wie viele geändert sind.

## Hinweis für die Warteliste

Wird in einem Kurs ein **Platz frei** und stehen Anmeldungen auf der **Warteliste**, erhalten Sie einen Hinweis in der
Glocke, z. B. „Klavier Mia: 1 Platz frei“ – mit den Wartenden in der Reihenfolge ihres Anmeldedatums und dem Knopf
**Anmeldungen ansehen**, der den Kurs öffnet. Ein Platz wird frei, wenn

- eine aktive Anmeldung *abgemeldet* oder auf *Warteliste* gesetzt wird,
- eine aktive Anmeldung gelöscht wird oder
- die **Höchstzahl** des Kurses steigt.

Nachgerückt wird nicht automatisch: Wer den Platz erhält, klären Sie mit den Familien und setzen dann den Status auf
*aktiv*.

## Rechte

| Recht | Wirkung |
|---|---|
| Musikschule: Planung: Stundenplan ansehen | Seite *Stundenplan* (dazu das Recht, Kurse zu sehen) |
| Musikschule: Planung: Semester fortschreiben | Knöpfe *Semester fortschreiben* (dazu das Recht, Kurse und Anmeldungen anzulegen) |
| Musikschule: Planung: Lektionen erzeugen | Knöpfe *Lektionen erzeugen* (dazu das Recht, Lektionen anzulegen) |
| Musikschule: Planung: Präsenz erfassen | Seite *Präsenz erfassen* (dazu das Recht, Präsenzen zu sehen; zum Speichern anzulegen und zu ändern) |

Die Konfliktprüfung und der Hinweis für die Warteliste gelten für alle, die Kurse und Anmeldungen bearbeiten – sie
brauchen kein eigenes Recht.
