# Produkt-Spezifikation Projektliste (lösungsneutral)

Stand: 26.11.2026 · Status: **Entwurf zur Diskussion**, offene Fragen in Abschnitt 10 · Grundlage: Excel-Arbeitsmappe «Projektliste – Laufende u. Pipeline» (Stand der Datei 14.8.2026)

## Inhalt

- [1. Ausgangslage: Aufbau der Excel-Datei](#1-ausgangslage-aufbau-der-excel-datei)
- [2. Ziele, Nutzen und Abgrenzung](#2-ziele-nutzen-und-abgrenzung)
- [3. Benutzergruppen und Rollen](#3-benutzergruppen-und-rollen)
- [4. Fachliches Datenmodell](#4-fachliches-datenmodell)
- [5. Funktionale Anforderungen](#5-funktionale-anforderungen)
- [6. Berechnungen und Geschäftsregeln](#6-berechnungen-und-geschäftsregeln)
- [7. Auswertungen und Ausgaben](#7-auswertungen-und-ausgaben)
- [8. Nicht-funktionale Anforderungen](#8-nicht-funktionale-anforderungen)
- [9. Datenübernahme](#9-datenübernahme)
- [10. Offene Fragen und Entscheidungen](#10-offene-fragen-und-entscheidungen)

## 1. Ausgangslage: Aufbau der Excel-Datei

Die Arbeitsmappe «Projektliste – Laufende u. Pipeline» ist eine einzige Erfassungsliste mit 31 Vorhaben und 37 Spalten, aus der fünf gefilterte Sichten und ein Zeitstrahl abgeleitet werden. Sie ist laut Anleitung der einzige Ort, an dem aufwändigere Projekte und Neuanforderungen rund um die Geschäftsapplikation priorisiert werden.

| Blatt | Rolle | Inhalt |
| --- | --- | --- |
| in Bearb. Anleitung | Hilfe | Zweck der Liste, Schritte zum Erfassen eines Projekts (Zeile kopieren, nächste Nummer ablesen, Ordner anlegen, Link eintragen) |
| Projektliste | Erfassung | 31 Vorhaben, 30 Eingabe- und 7 Formelspalten, Filter, Farblegende, Datum «Letzte Aktualisierung», Anzeige «Nächste freie Nr.», drei Links auf Ablagen |
| 1.1 Laufend u. Pipeline nach Prio | Sicht (Pivot) | Aktive Vorhaben, sortiert nach Must-do und Priorität; Datenquelle des Zeitstrahls |
| 1.2 Chart Laufende u. Pipeline | Zeitstrahl | Gantt-Darstellung aus Sicht 1.1, zugekaufte Vorlage, maximal 57 Zeilen |
| 1.3 Laufende nach Start sort. | Sicht (Pivot) | Aktive Vorhaben, sortiert nach Start |
| 1.4 Laufende nach OE gruppiert | Sicht (Pivot) | Aktive Vorhaben, gruppiert nach Organisationseinheit |
| 2. Erledigte | Sicht (Pivot) | Vorhaben mit Status «Erledigt» |
| 3. Zurückgestellt_Abgelehnt | Sicht (Pivot) | Vorhaben mit Status «Zurückgestellt» oder «Abgelehnt» |
| Drop-downs | Stammdaten | 14 Status mit Erklärung und Zuordnung zu einer Sicht; 7 Typen mit Zweck, Auslöser, Systemänderung, Umfang, Beispielen |
| Gantt_Help & Settings (ausgeblendet) | Einstellungen | Prioritätsstufen, Wochenende, Datumsformat, Hervorhebung überfälliger Termine |
| Holidays (ausgeblendet) | Stammdaten | 65 Feiertage ab 2025 |

Der Ablauf heute: Vorhaben in der Projektliste erfassen, jede Pivot-Sicht von Hand aktualisieren, danach zeigt der Zeitstrahl den neuen Stand.

### Beobachtete Schwächen

- **Freitext in typisierten Feldern:** Start, Ende und Soll-Termin enthalten neben Daten auch «???», «tbd» oder «MUSS 7./8.2.26»; Nutzen und Aufwand enthalten «??? HR muss einschätzen». Dauer und Zeitstrahl rechnen dann mit 0.
- **Keine eindeutige Kennung:** Die Projektnummer enthält Platzhalter («nicht nötig», «xxx», «tbd», «kein Projekt.»), und «P009.1» kommt zweimal vor.
- **Mehrfachwerte als Text:** «Für OE» enthält «HR, FC»; die Gruppierung nach OE bildet daraus eine eigene Gruppe.
- **Farbmarkierung von Hand:** Die vier Markierungsfarben werden manuell gesetzt und weichen vom Status ab (ein «Abgelehnt» ist gelb wie «Erledigt», «in Abklärung» trägt drei verschiedene Farben).
- **Manuelle Aktualisierung:** Pivots, das Datum «Letzte Aktualisierung» und «Stand aktual. am» (Datum und Kürzel gemischt) werden von Hand nachgeführt.
- **Zerbrechliche Struktur:** Spaltenreihenfolge und Überschriften dürfen nicht geändert werden; neue Zeilen entstehen durch Kopieren einer Formelzeile; die Anleitung nennt noch alte Blattnamen.
- **Verlauf im Freitext:** Notizen und nächste Schritte sind datierte Einträge mit Kürzel und Erledigt-Zeichen in einer einzigen Zelle; wer wann was geändert hat, ist nicht nachvollziehbar.
- **Ungenutzte Vorlagenteile:** Fortschritt in Prozent, Vorgänger, Gliederungsebenen und Zuständige der Gantt-Vorlage sind leer.

## 2. Ziele, Nutzen und Abgrenzung

Die Applikation ersetzt die Arbeitsmappe als einzigen, verbindlichen Ort für die Priorisierung und Grobplanung der Vorhaben rund um die Geschäftsapplikation.

**Ziele**

1. Alle Vorhaben (Projekte, Erweiterungen, Change Requests, grössere Service Requests) an einem Ort erfassen und nachführen.
2. Vorhaben nach Must-do, Nutzen, Aufwand und Priorität vergleichbar machen.
3. Auf einem Zeitstrahl zeigen, was parallel geplant ist.
4. Jederzeit aktuelle Sichten liefern: laufend und Pipeline, erledigt, zurückgestellt oder abgelehnt.
5. Zeigen, welche Organisationseinheiten und Partner je Vorhaben zu involvieren oder zu informieren sind.
6. Den Stand für die übergeordnete Themenliste (Projektportfolio) ohne Abtippen bereitstellen.

**Nutzen gegenüber heute**

- Sichten und Zeitstrahl sind ohne manuelles Aktualisieren aktuell.
- Eingaben sind geprüft; Termine, Bewertungen und Kennungen sind auswertbar.
- Mehrere Personen arbeiten gleichzeitig, Änderungen sind nachvollziehbar.
- Neue Vorhaben entstehen ohne Kopieren von Formelzeilen.

**Nicht im Umfang**

- Detailplanung mit Arbeitspaketen, Abhängigkeitsnetz, Ressourcen- oder Budgetplanung.
- Führen der Incident- und Neuanforderungsliste selbst; diese wird nur referenziert.
- Dokumentenablage; Projektordner werden nur verlinkt.

## 3. Benutzergruppen und Rollen

Die Excel-Datei kennt keine Rollen; die folgenden vier ergeben sich aus der Nutzung und sind zu bestätigen (siehe offene Fragen).

| Rolle | Wer | Darf |
| --- | --- | --- |
| Verantwortliche der Liste | Applikationsteam (heute zwei bis drei Personen, erkennbar an den Kürzeln) | Vorhaben erfassen, bewerten, planen, Status setzen, Notizen führen |
| Fachbereich | Vertretungen der Organisationseinheiten (z. B. Finanzen, Personal, Informatik) | Eigene Vorhaben lesen; Nutzen und Aufwand einschätzen, falls gewünscht |
| Leitung / Portfolio | Empfänger der übergeordneten Themenliste | Sichten und Zeitstrahl lesen, exportieren |
| Verwaltung | Eine Person des Teams | Wertelisten, Organisationseinheiten, beteiligte Stellen, Feiertage und Berechtigungen pflegen |

Externe Partner (Implementierungs- und Softwarepartner) sind heute nur als Beteiligte erfasst und haben keinen Zugriff.

## 4. Fachliches Datenmodell

Kern ist das Vorhaben; darum liegen sieben weitere Objekte, die heute als Spalten, Farben oder Freitext in derselben Zeile stehen.

| Objekt | Bedeutung | Beziehung |
| --- | --- | --- |
| Vorhaben | Ein Projekt, eine Erweiterung oder eine grössere Anforderung | – |
| Organisationseinheit (OE) | Auftraggebende Einheit, heute 8 Werte | Vorhaben gehört zu einer oder mehreren OE |
| Beteiligte Stelle | Interne Einheit oder externer Partner, die zu involvieren oder zu informieren ist; heute 7 feste Spalten | Vorhaben hat 0 bis n Beteiligungen, je mit Ansprechperson oder Bemerkung |
| Status | Bearbeitungsstand, 14 Werte mit Erklärung | Vorhaben hat genau einen Status; jeder Status gehört zu genau einer Statusgruppe |
| Typ | Art des Vorhabens, 7 Werte mit Zweck, Auslöser, Systemänderung, Umfang, Beispielen | Vorhaben hat höchstens einen Typ |
| Referenz | Kennung in der Neuanforderungs- oder Incident-Liste (z. B. NA-008, IN-060) | Vorhaben hat 0 bis n Referenzen |
| Notiz / nächster Schritt | Datierter Eintrag mit Verfasser; nächste Schritte zusätzlich mit Termin und Erledigt-Kennzeichen | Vorhaben hat 0 bis n Einträge |
| Feiertag | Arbeitsfreier Tag für den Zeitstrahl | unabhängig |

### Attribute des Vorhabens

| Attribut | Art | Pflicht | Heutige Spalte / Bemerkung |
| --- | --- | --- | --- |
| Projektnummer | Kennung, Muster Pnnn, optional mit Unternummer (.1, .3) | nein | #Proj; nur Vorhaben mit eigenem Ordner erhalten eine Nummer |
| Link zum Ordner | Verweis auf Ablage | nein | Link zum Ordner |
| Typ | Auswahl | ja | Typ; Erklärung wird angezeigt |
| Für OE | Auswahl, mehrfach | ja | Für OE |
| Herkunft | Auswahl: von extern, Anforderung der Linie | nein | Kategorie; nur 9 von 31 gefüllt |
| Thema / Projekt | Text, lang | ja | Thema/Projekt |
| Kurzbeschrieb | Text, mehrzeilig | nein | Kurzbeschrieb |
| Kurztitel | Text, kurz | ja für aktive Vorhaben | Titel für Chart |
| Erwarteter Nutzen / Ziel | Text, mehrzeilig | nein | Effizienz, Zufriedenheit, Risikoreduktion, Qualität |
| Must-do | Ja/Nein, mit Begründung | ja | regulatorisch, externe Frist, vertraglich |
| Nutzen | Auswahl: Tief, Mittel, Hoch, offen | nein | «offen» mit Angabe, wer einschätzen muss |
| Aufwand | Auswahl: Tief, Mittel, Hoch, offen | nein | Zeit, Ressourcen, Komplexität |
| Priorität | Auswahl: Tief, Mittel, Hoch | ja bei Must-do | Prio |
| Abhängigkeiten | Text | nein | von Projekten, Personen, Systemen |
| Status | Auswahl | ja | Status |
| Start (Grobplan) | Datum oder «noch offen» | nein | Start |
| Ende (Grobplan) | Datum oder «noch offen» | nein | Ende |
| Soll-Termin | Datum, optional mit Vermerk | nein | Soll Termin |
| Auf Portfolioliste vorhanden | Auswahl: Ja, Nein, Noch nicht, Nicht nötig | nein | Abgleich mit übergeordneter Themenliste |
| Stand aktualisiert | Datum und Person, automatisch | – | Stand aktual. am |

Abgeleitet und nicht zu erfassen: Typ- und Status-Erklärung, Priorität als Zahl, Statusgruppe, Dauer, Markierungsfarbe (Abschnitt 6).

### Wertelisten

| Statusgruppe | Status |
| --- | --- |
| Laufend und Pipeline | Neu, Idee, in Abklärung, Initialisierung, Wartend, in Planung, in Umsetzung, Beauftragt, Testen, Einführen, unbekannt (???) |
| Erledigt | Erledigt |
| Zurückgestellt oder abgelehnt | Zurückgestellt, Abgelehnt |

Typen: Incident, Service Request, Change Request (funktionale Änderung), Erweiterung, Projekt, Beratung, noch offen (tbd). Alle Wertelisten sind durch die Verwaltung ohne Entwicklung änderbar.

## 5. Funktionale Anforderungen

Jede Anforderung nennt, was möglich sein muss, nicht wie es gebaut wird.

| Nr. | Anforderung | Entspricht heute |
| --- | --- | --- |
| F-01 | Vorhaben erfassen, ändern und löschen (Löschen nur durch die Verantwortlichen) | Zeile in der Projektliste |
| F-02 | Beim Erfassen wird auf Wunsch die nächste freie Projektnummer vergeben; Unternummern sind möglich | Zelle «Nächste freie Nr.» |
| F-03 | Auswahlfelder lassen nur Werte der Wertelisten zu; «noch offen» ist ein eigener Wert, kein Freitext | Freitext wie «???», «tbd» |
| F-04 | Zu Typ und Status wird die Erklärung angezeigt | Formelspalten, Zellkommentar |
| F-05 | Je Vorhaben mehrere OE und mehrere Beteiligte mit Ansprechperson erfassen | Spalten «Für OE» und «Involvieren/informieren» |
| F-06 | Notizen und nächste Schritte als einzelne, datierte Einträge mit Verfasser führen; nächste Schritte als erledigt kennzeichnen | Sammelzellen mit Kürzel, Datum, ❌ / ✔ |
| F-07 | Liste aller Vorhaben nach jedem Attribut filtern, sortieren und im Volltext durchsuchen; Kennung und Kurztitel bleiben beim Blättern sichtbar | Autofilter, fixierte Spalten |
| F-08 | Link zum Projektordner und zu den übergeordneten Ablagen öffnen | Spalte B, drei Links im Kopf |
| F-09 | Statuswechsel verschiebt das Vorhaben sofort in die passende Sicht | Pivot von Hand aktualisieren |
| F-10 | Jede Änderung wird mit Person, Zeitpunkt, altem und neuem Wert festgehalten; der letzte Änderungsstand je Vorhaben und der Liste insgesamt ist sichtbar | «Stand aktual. am», «Letzte Aktualisierung» |
| F-11 | Hinweise bei unvollständigen Angaben: Must-do ohne Priorität, aktives Vorhaben ohne Kurztitel oder Termine, doppelte Projektnummer | Rote Zelle bei Must-do ohne Prio |
| F-12 | Wertelisten, OE, beteiligte Stellen und Feiertage pflegen | Blätter Drop-downs, Holidays |
| F-13 | Eine Hilfe erklärt Zweck, Felder und das Vorgehen beim Erfassen | Blatt Anleitung |

## 6. Berechnungen und Geschäftsregeln

Die Excel-Datei enthält sieben Formelspalten und eine Nummernformel; daraus ergeben sich acht Regeln.

| Nr. | Regel | Herkunft |
| --- | --- | --- |
| R-01 | Nächste Projektnummer = höchste vergebene Nummer mit Präfix «P» plus 1, dreistellig (z. B. P018) | Formel «Nächste freie Nr.» |
| R-02 | Priorität als Zahl: Must-do = 3; sonst Hoch = 3, Mittel = 2, Tief = 1, fehlt = 0 | Spalte Q |
| R-03 | Must-do hat immer hohe Priorität; ein Must-do ohne Priorität wird als unvollständig markiert | Zellkommentar, bedingte Formatierung |
| R-04 | Jeder Status gehört zu genau einer Statusgruppe; die Gruppe bestimmt, in welcher Sicht das Vorhaben erscheint | Spalten AB–AD, Blatt Drop-downs |
| R-05 | Dauer in Kalendertagen = Ende − Start; ohne beide Daten keine Dauer (heute 0) | Spalte AG |
| R-06 | Erklärung zu Typ und Status stammt aus der Werteliste | Spalten F, AA |
| R-07 | Balkenfarbe im Zeitstrahl: Must-do kräftig rot, Priorität 3 dunkelrot, 2 orange, 1 grau | Gantt, Spalte Color |
| R-08 | Anzahl Vorhaben je Sicht wird angezeigt | Zählformel je Pivot-Blatt |

**Markierungsfarbe.** Die Legende kennt vier Farben, die auch die Ordnerfarben der Ablage sind: Läuft (rot), Bestätigt – wird kommen (rosa), Erledigt (gelb), On-hold / nicht umsetzbar (grau). Sie soll künftig aus dem Status abgeleitet werden; die Zuordnung der elf aktiven Status zu «Läuft» oder «Bestätigt» ist offen (Abschnitt 10).

## 7. Auswertungen und Ausgaben

Fünf Listen und ein Zeitstrahl sind bereitzustellen; alle zeigen ohne Zutun den aktuellen Stand, das Datum der letzten Änderung und die Anzahl Vorhaben.

| Nr. | Sicht | Auswahl | Ordnung |
| --- | --- | --- | --- |
| A-01 | Laufend und Pipeline nach Priorität | Statusgruppe «Laufend und Pipeline» | Must-do zuerst, dann Priorität absteigend, dann Start |
| A-02 | Laufend und Pipeline nach Start | wie A-01 | Start, Ende; Vorhaben ohne Termin am Schluss |
| A-03 | Laufend und Pipeline nach OE | wie A-01 | gruppiert nach OE, darin wie A-01; ein Vorhaben mit mehreren OE erscheint bei jeder |
| A-04 | Erledigte | Statusgruppe «Erledigt» | wie A-01 |
| A-05 | Zurückgestellt oder abgelehnt | Statusgruppe «Zurückgestellt oder abgelehnt» | wie A-01 |
| A-06 | Zeitstrahl | wie A-01, nur Vorhaben mit Start und Ende | wie A-01 |

**Spalten der Listen:** Must-do, Priorität (als Symbol), Start, Ende, Dauer, Soll-Termin, Projektnummer, Referenz, Kurztitel, OE, Status, Thema; in A-01 zusätzlich «Auf Portfolioliste vorhanden» und nächster Schritt.

**Zeitstrahl (A-06)**

- Ein Balken je Vorhaben von Start bis Ende, daneben Projektnummer, Kurztitel, OE, Priorität und Must-do.
- Farbe nach Regel R-07; der heutige Tag ist markiert.
- Zeitraster wählbar: Woche, Monat (heute eingestellt), Quartal; Beginn des Ausschnitts wählbar.
- Vorhaben ohne Termine werden unter dem Zeitstrahl aufgeführt statt weggelassen.
- Keine Obergrenze für die Anzahl Vorhaben (heute 57 Zeilen).
- Optional: Soll-Termin als Marke, überfällige Vorhaben hervorgehoben (in der Vorlage vorhanden, heute ausgeschaltet).

**Ausgaben**

- Jede Liste als Tabellendatei exportieren; Zeitstrahl druckbar bzw. als Bild oder PDF.
- Die Angaben für die übergeordnete Themenliste (Kurztitel, Status, Termine, nächster Schritt) so ausgeben, dass sie ohne Nachbearbeitung übernommen werden können.

## 8. Nicht-funktionale Anforderungen

Die Mengen sind klein (heute 31 Vorhaben, eine Handvoll Benutzer); massgebend sind Nachvollziehbarkeit, einfache Bedienung und Pflege ohne Entwicklung.

| Nr. | Anforderung |
| --- | --- |
| N-01 | Mehrere Personen arbeiten gleichzeitig; niemand überschreibt unbemerkt die Änderung einer anderen Person |
| N-02 | Zugriff nur für angemeldete Personen der Organisation, Rechte nach Rolle (Abschnitt 3); Anmeldung mit dem bestehenden Organisationskonto |
| N-03 | Lückenlose Änderungshistorie je Vorhaben, mindestens so lange einsehbar wie das Vorhaben besteht |
| N-04 | Bedienung ohne Schulung für Personen, die die Excel-Liste kennen; Begriffe und Feldnamen bleiben erhalten |
| N-05 | Oberfläche deutsch; Datumsformat Tag.Monat.Jahr; Woche beginnt am Montag; Wochenende Samstag und Sonntag |
| N-06 | Nutzbar im Browser am Arbeitsplatz ohne lokale Installation; Listen und Zeitstrahl lesbar auf einem Laptop-Bildschirm |
| N-07 | Wertelisten, OE, Beteiligte und Feiertage sind durch die Verwaltung ohne Entwicklung änderbar |
| N-08 | Regelmässige Datensicherung; vollständiger Export aller Daten in einem offenen Format jederzeit möglich |
| N-09 | Die Inhalte sind intern; sie enthalten Namen von Mitarbeitenden und Partnern, aber keine besonders schützenswerten Personendaten |
| N-10 | Auslegung auf einige hundert Vorhaben und rund 20 gleichzeitige Benutzer; Listen und Zeitstrahl erscheinen in höchstens 2 Sekunden |

## 9. Datenübernahme

Alle 31 Vorhaben werden einmalig übernommen; rund die Hälfte der Zeilen braucht vorher eine Bereinigung von Hand.

| Feld | Befund in der Excel-Datei | Vorgehen |
| --- | --- | --- |
| Projektnummer | 18 verschiedene Werte, davon Platzhalter («nicht nötig», «xxx», «tbd», «kein Projekt.»); «P009.1» doppelt; ältere, abweichende Schreibweise | Platzhalter leer übernehmen, Doppel klären, Schreibweise vereinheitlichen |
| Start / Ende | 23 von 31 gefüllt, davon mehrere «???» | «???» als «noch offen» |
| Soll-Termin | 16 gefüllt, darunter «tbd», «??», «MUSS 7./8.2.26» | Datum übernehmen, Text als Vermerk |
| Nutzen / Aufwand | «??? HR muss einschätzen», «Tief?» | als «offen» mit zuständiger OE |
| Für OE | «HR, FC», «HR?» | in einzelne OE aufteilen |
| Referenz | Kennungen, daneben «kommt nicht auf die Incident-Liste», «Diverse siehe Kommentar», zwei Kennungen in einer Zelle | Kennungen einzeln übernehmen, Rest als Notiz |
| Beteiligte | 7 Spalten mit Namen, Einheit oder «nein» | je gefüllte Zelle eine Beteiligung; «nein» entfällt |
| Stand aktual. am | Datum, teils mit Kürzeln | als Anfangsstand der Historie |
| Notizen / nächster Schritt | Sammeltext mit Datum und Kürzel | als je ein Eintrag übernehmen; Aufteilen in Einzeleinträge nur für aktive Vorhaben |
| Markierungsfarbe | von Hand, teils abweichend vom Status | nicht übernehmen, aus Status ableiten |
| Typ | 3 mal «? tbd», 1 mal leer | als «noch offen» |

Wertelisten (Status, Typen) und die 65 Feiertage werden unverändert übernommen. Nach der Übernahme müssen die fünf Listen dieselben Vorhaben zeigen wie die heutigen Pivot-Blätter; ab dann wird die Excel-Datei nicht mehr nachgeführt.

## 10. Offene Fragen und Entscheidungen

| Nr. | Frage / Entscheidung | Betrifft |
| --- | --- | --- |
| O-01 | Priorität: weiterhin von Hand gesetzt, oder aus Must-do, Nutzen und Aufwand vorgeschlagen? Heute sind Nutzen und Aufwand ohne Wirkung auf die Priorität. | R-02, R-03 |
| O-02 | Projektnummer: Wer erhält eine (nur «echte» Projekte)? Braucht jedes Vorhaben daneben eine eigene, eindeutige Kennung? Was bedeuten Unternummern (.1, .3) – Teilprojekte eines übergeordneten Projekts? | Datenmodell, F-02 |
| O-03 | Markierungsfarbe: Welche aktiven Status zählen als «Läuft», welche als «Bestätigt – wird kommen»? Oder bleibt sie ein eigenes, frei gesetztes Merkmal? | R-07, Abschnitt 6 |
| O-04 | Status: Sind alle 14 Werte nötig (verwendet werden 8)? Gibt es erlaubte Reihenfolgen oder Pflichtangaben bei einem Wechsel, z. B. Begründung bei «Abgelehnt»? | Wertelisten |
| O-05 | Rollen: Erfassen Fachbereiche selbst (z. B. Nutzen einschätzen, Ideen einreichen) oder nur das Applikationsteam? Sehen alle alles? | Abschnitt 3 |
| O-06 | Referenzen auf Neuanforderungen und Incidents: nur Kennung als Text, oder Anbindung an die dortige Liste? Soll diese Liste später in dieselbe Applikation? | Abgrenzung |
| O-07 | Übergeordnete Themenliste: Export zum Kopieren, oder automatischer Abgleich? Was genau bedeutet «Nicht nötig»? | A-01, Ausgaben |
| O-08 | Projektordner: nur verlinken, oder beim Erfassen automatisch anlegen (heute Schritt 5 der Anleitung)? | F-08 |
| O-09 | Dauer: Kalendertage wie heute, oder Arbeitstage? Wofür werden die Feiertage sonst gebraucht? | R-05 |
| O-10 | Start/Ende und Soll-Termin: Ist der Soll-Termin die zugesagte Frist und Start/Ende die Planung? Soll eine Überschreitung gemeldet werden? | Zeitstrahl |
| O-11 | Ungenutzte Teile der Gantt-Vorlage (Fortschritt in %, Vorgänger, Teilaufgaben, Zuständige): bewusst weggelassen oder erwünscht? | Abgrenzung |
| O-12 | «Herkunft» (Kategorie): beibehalten und für alle Vorhaben pflegen, oder streichen? | Datenmodell |
| O-13 | Abhängigkeiten: Freitext wie heute, oder Verknüpfung zwischen Vorhaben? | Datenmodell |
| O-14 | Benachrichtigungen (z. B. bei Statuswechsel, vor Soll-Termin): erwünscht? In der Excel-Datei nicht vorhanden. | Umfang |
| O-15 | Geltungsbereich: nur Vorhaben zur heutigen Geschäftsapplikation, oder Liste für alle Geschäftsapplikationen des Teams? | Umfang |
