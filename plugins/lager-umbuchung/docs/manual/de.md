# Lager-Umbuchung – Benutzerhandbuch

## Überblick

Das Plugin *Lager-Umbuchung* führt in der Lager-Demo die **Bestände** an jedem Lagerort aus den **Lagerbewegungen**
nach. Sie erfassen nur Bewegungen – Eingang, Ausgang, Umbuchung, Inventur –, den Bestand rechnet SAFT selbst. Dazu kommen
die Maske **Umbuchung**, der Knopf **Umbuchen** in den Beständen, eine Meldung, wenn ein Artikel unter seinen
Mindestbestand fällt, und die Kachel **Lager: Mindestbestand** auf dem Dashboard.

Die Tabellen finden Sie im Menü unter **Lager (Demo)**: *Artikel*, *Lagerorte*, *Bestände* und *Lagerbewegungen*.

## Lagerbewegungen erfassen

Unter **Lager (Demo) › Lager führen › Lagerbewegungen** legen Sie mit **Neu** eine Bewegung an: *Artikel*, *Lagerort*, *Art*, *Menge*,
*Zeitpunkt* (vorbelegt mit jetzt) und bei Bedarf eine *Bemerkung*.

| Art | Wirkung auf den Bestand am Lagerort |
|---|---|
| **Eingang** | erhöht den Bestand um die Menge |
| **Ausgang** | verringert den Bestand um die Menge |
| **Umbuchung** | entsteht über die Maske *Umbuchung* (siehe unten): ein Ausgang am Quellort und ein Eingang am Zielort |
| **Inventur** | korrigiert den Bestand um die eingetragene Differenz und setzt beim Bestand das Datum der *Letzten Zählung* |

Bei **Eingang** und **Ausgang** tragen Sie die Menge ohne Vorzeichen ein; SAFT setzt es selbst (Ausgang immer
negativ). Gibt es am Lagerort noch keinen Bestand für den Artikel, legt der erste Eingang ihn an.

**Bewegungen ändern oder löschen:** Ändern Sie eine Bewegung (z. B. die Menge oder den Lagerort), nimmt SAFT die alte
Buchung zurück und bucht die neue. Eine gelöschte Bewegung wird ausgebucht; stellen Sie sie aus dem Papierkorb wieder
her, wird sie erneut gebucht.

## Bestände ansehen und korrigieren

**Lager (Demo) › Lager führen › Bestände** zeigt je Artikel und Lagerort die *Menge* und das Datum der *Letzten Zählung*.

Bestände **ändern Sie nicht direkt**: Anlegen, Bearbeiten und Löschen in dieser Tabelle – auch beim Import – lehnt SAFT
mit dem Hinweis ab, dass Bestände über Lagerbewegungen geführt werden. So bleibt jeder Bestand nachvollziehbar.

**Bestand korrigieren (Inventur):** Erfassen Sie eine Lagerbewegung der Art **Inventur** mit der **Differenz** zwischen
gezählter und gebuchter Menge – negativ, wenn weniger da ist. Beispiel: Gebucht sind 10, gezählt 8 → Inventur mit
Menge −2. Der Bestand steht danach auf 8, und die *Letzte Zählung* trägt das Datum der Bewegung.

**Kein negativer Bestand:** Würde eine Bewegung den Bestand eines Lagerorts unter 0 bringen, wird sie nicht gespeichert.
Die Meldung nennt Artikel, Lagerort und den vorhandenen Bestand.

## Ware umbuchen

Umbuchen heisst: Ware von einem Lagerort an einen anderen bringen, ohne dass sich der Gesamtbestand ändert.

1. **Lager (Demo) › Lager führen › Umbuchung** öffnen – oder in **Bestände** in der Zeile des Quellorts auf **Umbuchen** klicken; dann
   sind Artikel und Quellort schon gewählt. Ohne Bestand am Lagerort ist der Knopf ausgegraut.
2. **Artikel** wählen. Unter **Von Lagerort** stehen nur Lagerorte mit Bestand dieses Artikels, mit der Menge.
3. **Nach Lagerort** und **Menge** angeben (höchstens der Bestand am Quellort), bei Bedarf eine **Bemerkung**.
4. **Umbuchen**. SAFT erfasst zwei Lagerbewegungen der Art *Umbuchung* – minus am Quellort, plus am Zielort – in einem
   Schritt: Entweder gelingen beide, oder keine.

## Mindestbestand

Jeder Artikel hat einen *Mindestbestand*. Sinkt der **Gesamtbestand** eines Artikels (über alle Lagerorte) durch eine
Bewegung unter diesen Wert, erhält die Person, die die Bewegung erfasst hat, die Meldung **Mindestbestand
unterschritten** in der Glocke. Umbuchungen lösen keine Meldung aus, weil sich der Gesamtbestand nicht ändert;
Erhöhungen ebenfalls nicht.

Die Kachel **Lager: Mindestbestand** auf dem Dashboard zeigt, wie viele Artikel unter dem Mindestbestand liegen, mit
den ersten Namen, und den Gesamtbestand; ein Klick führt zur Umbuchung.

## Rechte

| Wofür | Recht |
|---|---|
| Maske *Umbuchung*, Knopf *Umbuchen*, Kachel | *Lager-Umbuchung: Umbuchungen ausführen* |
| Umbuchen | zusätzlich Datenrechte: *anzeigen* auf Artikel und Lagerorte, *anzeigen*, *anlegen* und *bearbeiten* auf Bestände, *anlegen* auf Lagerbewegungen |
| Bewegungen erfassen | *anlegen* (bzw. *bearbeiten*, *löschen*) auf Lagerbewegungen |

Wer nur Lagerbewegungen erfasst, braucht keine Rechte auf die Bestände – die Bestände führt SAFT selbst. Fehlen für das
Umbuchen Rechte, nennt die Maske *Umbuchung* die betroffenen Tabellen.
