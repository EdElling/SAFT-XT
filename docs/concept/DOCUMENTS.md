# Konzept: Dokumente aus Vorlagen (PDF) in SAFT XT

Stand: 21.11.2026 · Status: abgestimmt, Etappe D1 in Planung · Backlog: B-124

## 1. Ziel

Aus den Daten einer App entstehen **professionell aussehende Dokumente als PDF**: Rechnungen aus Beleg, Positionen und
Kunde, Lieferscheine, Briefe, Bestätigungen, einfache Listen. Ein Klick in der Datenzeile erzeugt das Dokument zum
Herunterladen und legt es auf Wunsch in einem Dateifeld des Datensatzes ab.

Zwei Bedingungen bestimmen den Aufbau:

- **Läuft überall, wo SAFT läuft** – auch auf einem Webspace wie Strato ohne Zusatzprogramme (kein Chromium, kein
  Node, kein LibreOffice, kein Docker).
- **Strenge CD/CI-Vorgaben einhaltbar**, ohne dass Fachanwender Gestaltung nachbauen müssen.

Nicht Ziel der ersten Etappen: frei gestaltbare Layouts per Ziehen und Ablegen (WYSIWYG), Serienbriefe an Tausende
Empfänger, elektronische Rechnungsformate (ZUGFeRD, XRechnung).

## 2. Entscheidungen

| Frage | Entscheidung |
|---|---|
| Weg zum PDF | **HTML-Vorlage mit Platzhaltern, gerendert mit mPDF** (reines PHP). Kopf- und Fusszeilen, Seitenzahlen, wiederholte Tabellenköpfe, eingebettete Schriften, PDF als Seitenhintergrund. Verworfen: Headless Chrome (Browsershot, spatie/laravel-pdf) und Gotenberg – brauchen Chromium bzw. einen eigenen Dienst; wkhtmltopdf – nicht mehr gepflegt; dompdf – schwächer bei Seitenumbrüchen. |
| Platzhaltersprache | **Mustache** (logikfrei, sicher): Vorlagen von Admins oder der KI können keinen Code ausführen. Blade scheidet aus (führt PHP aus). Ergänzt um wenige Formatierungen (Abschnitt 5). |
| Gestaltung und Inhalt | **Getrennt.** Das **Briefpapier** (CI) wird einmal zentral festgelegt; **Inhaltsvorlagen** setzen nur Daten darauf und können das CI nicht verletzen (Abschnitt 3). |
| Bearbeiten | In SAFT selbst: **Code-Editor** für Inhaltsvorlagen mit Feldauswahl und **Live-Vorschau** als PDF. Später Bausteine im Formular für Fachanwender. Kein WYSIWYG-Designer. |
| KI | Erzeugt **nur Inhaltsvorlagen** innerhalb des Briefpapiers, nie das CI (Etappe D2). |
| Zuordnung | Inhaltsvorlagen gehören zu einer **App** und einer **Grundtabelle** (z. B. „Rechnung“ zu *Belege*). Das Briefpapier gilt für die ganze Installation, mit möglicher Abweichung je App. |
| Recht | Je Vorlage, wie bei Auswertungen: Wer die Grundtabelle lesen darf und das Recht der Vorlage hat, darf das Dokument erzeugen. Vorlagen bearbeiten: eigenes Recht *Dokumentvorlagen: verwalten*. |

## 3. Briefpapier (CI) – einmal festlegen

Das Briefpapier entsteht dort, wo CI üblicherweise entsteht: in einem Gestaltungsprogramm (InDesign, Illustrator,
Affinity, auch Word) bei der Agentur oder der Kommunikationsabteilung. In SAFT wird es nur hinterlegt
(*Einstellungen › Dokumente*, Recht *Globale Einstellungen: bearbeiten*):

- **Briefpapier als PDF**, getrennt für die **erste Seite** und die **Folgeseiten** (Logo, Linien, Fusszeile mit
  Adresse, Bank, MWST-Nummer). mPDF legt es als Hintergrund unter jede Seite – pixelgenau wie geliefert, niemand muss
  es nachbauen. Ohne PDF: einfacher Kopf mit Logo und Absender aus den Einstellungen.
- **Hausschriften** (TTF hochladen; ohne eigene eine mitgelieferte, gut lesbare Schrift) und **Hausfarben** (Primär-,
  Sekundär-, Textfarbe) als feste Werte, die alle Vorlagen über CSS-Variablen verwenden.
- **Satzspiegel:** Seitenränder je Seitentyp, Position des **Adressfelds** (Fensterkuvert links oder rechts, Schweiz
  bzw. DIN 5008), Absenderzeile.
- **Absenderdaten** als Platzhalter für Vorlagen: Firmenname, Adresse, MWST-Nummer, IBAN, Kontakt.

Optional je App ein eigenes Briefpapier (z. B. eine Abteilung mit eigenem Auftritt); sonst gilt das der Installation.

## 4. Inhaltsvorlagen – Daten auf das Briefpapier setzen

Eine Inhaltsvorlage ist **HTML mit Platzhaltern** und gehört zu einer App und einer Grundtabelle. Sie legt fest, was
wo steht: Adresse, Datum, Betreff, Einleitung, Positionstabelle, Summen, Schlusstext – nicht Schrift, Farben oder
Briefpapier.

**Bearbeiten** auf einer Admin-Seite *Dokumentvorlagen* der App:

- **Code-Editor** (HTML) mit Syntaxhervorhebung; daneben die **Feldauswahl** wie im Formeleditor: Felder der
  Grundtabelle, über Verknüpfungen erreichbare Felder (`kunde_id.name`), Kind-Tabellen als Liste
  (`{{#positionen}} … {{/positionen}}`), berechnete Felder, Absenderdaten. Ein Klick fügt den Platzhalter ein.
- **Live-Vorschau** als PDF mit einem wählbaren Beispieldatensatz, aktualisiert beim Tippen (verzögert).
- **Prüfung** beim Speichern: unbekannte Felder, nicht geschlossene Abschnitte, HTML, das mPDF nicht darstellen kann
  (Hinweis statt Fehler).
- **Mitgelieferte Grundlagen**: „Rechnung“, „Brief“, „Liste“ – als Ausgangspunkt zum Kopieren.

Wer lieber ausserhalb arbeitet, schreibt die Vorlage in einem Texteditor (z. B. VS Code) und fügt sie ein.
Gestaltungsprogramme sind dafür ungeeignet; sie liefern kein HTML in der nötigen einfachen Form.

**Grenzen von mPDF**, die Vorlagen kennen müssen: kein Flexbox und kein Grid. Layouts entstehen mit Tabellen und festen
Positionen – für Rechnungen und Briefe ausreichend. Die mitgelieferten Vorlagen zeigen, wie.

## 5. Platzhalter

Mustache, ergänzt um Formatierungen mit `|`:

| Schreibweise | Bedeutung |
|---|---|
| `{{belegnummer}}` | Feld der Grundtabelle (Text wird maskiert) |
| `{{kunde_id.name}}`, `{{kunde_id.ort}}` | Feld über eine Verknüpfung (bis 3 Schritte) |
| `{{#gitarren_buchungen}} … {{/gitarren_buchungen}}` | je Kind-Datensatz wiederholen (Positionen), in der Reihenfolge der Kind-Tabelle |
| `{{^gitarren_zahlungen}} … {{/gitarren_zahlungen}}` | nur wenn es keine gibt |
| `{{betrag \| chf}}`, `{{datum \| datum}}`, `{{menge \| zahl:0}}` | Formatierung: Währung, Datum, Zahl mit Stellen – in der Sprache des Dokuments |
| `{{art \| bezeichnung}}` | Auswahlfeld: Bezeichnung statt gespeichertem Wert |
| `{{absender.firma}}`, `{{absender.iban}}` | Absenderdaten aus dem Briefpapier |
| `{{heute \| datum}}`, `{{seite}}`, `{{seiten}}` | Datum der Erzeugung, Seitenzahlen (Fusszeile) |

Summen, MWST und Ähnliches rechnen die **berechneten Felder** der Tabellen; die Vorlage zeigt sie nur an. So bleibt die
Vorlage logikfrei, und Dokument und Bildschirm zeigen dieselben Beträge.

**Sprache des Dokuments:** Vorgabe ist die Sprache der Person; optional ein Feld des Datensatzes (z. B. Korrespondenzsprache
des Kunden). Bezeichnungen von Auswahlwerten und Formatierungen folgen ihr.

## 6. Erzeugen

- **Symbol „Dokument“** in der Datenzeile (bei mehreren Vorlagen der Grundtabelle ein Auswahlmenü), auch im
  Details-Fenster am Smartphone.
- Ergebnis: **PDF herunterladen** und auf Wunsch **in einem Dateifeld ablegen** (je Vorlage einstellbar, z. B.
  „Belegdateien“) – dann bleibt das Dokument beim Datensatz und erscheint in der Vorschau der Dateien.
- Dateiname aus der Vorlage, z. B. `Rechnung-{{belegnummer}}.pdf`.
- Im Änderungsprotokoll: wer welches Dokument zu welchem Datensatz erzeugt hat.
- Später (D3): mehrere ausgewählte Datensätze auf einmal – ein zusammengefügtes PDF oder eine ZIP-Datei; Versand per
  E-Mail.

## 7. App-Definition

Inhaltsvorlagen gehören zur App und wandern mit Export, Installieren und Aktualisieren (Teil `documents` der
App-Definition `saft-app/1`, mit stabilen Schlüsseln). Das Briefpapier der Installation gehört nicht dazu – eine
installierte App nutzt das Briefpapier der Zielinstallation.

## 8. Etappen

| Etappe | Inhalt | Grösse |
|---|---|---|
| **D1 Grundlage** | mPDF; Briefpapier (PDF-Hintergrund für erste und Folgeseiten, Hausschriften, Hausfarben, Satzspiegel, Absenderdaten); Inhaltsvorlagen je App mit Code-Editor, Feldauswahl, Live-Vorschau und Prüfung; mitgelieferte Vorlagen „Rechnung“ und „Brief“; Symbol „Dokument“ in der Zeile, Herunterladen und Ablage im Dateifeld; Rechte; Handbücher. Demo: Rechnung im Gitarrenshop. | gross |
| **D2 KI und Bausteine** | Inhaltsvorlage aus einer Beschreibung (innerhalb des Briefpapiers); Bausteine im Formular für Fachanwender (Layout wählen, Felder im Adressblock, Spalten der Positionstabelle, Summen, Texte) | mittel |
| **D3 Mehrere und Versand** | Auswahl mehrerer Datensätze → ein PDF bzw. ZIP; Versand per E-Mail mit dem Dokument als Anhang | mittel |
| **D4 Schweiz und Word** | **QR-Rechnung** (Zahlteil mit QR-Code nach Schweizer Standard, reine PHP-Bibliothek); DOCX für Briefe, die man nachbearbeiten will | mittel |

Jede Etappe ist für sich auslieferbar. D1 ist ohne KI vollständig nutzbar.

## 9. Risiken

- **CSS-Grenzen von mPDF:** Vorlagen aus dem Web (Flexbox, Grid) sehen im PDF anders aus. Gegenmittel: mitgelieferte
  Vorlagen als Muster, Prüfung mit Hinweisen, Live-Vorschau.
- **Speicher und Zeit** bei langen Dokumenten (viele Positionen, grosse Briefpapier-PDFs): mPDF braucht dafür mehr
  Speicher. Gegenmittel: Grenze für Positionen je Dokument, Briefpapier-PDF in der Grösse begrenzt.
- **Schriften und Lizenzen:** Hausschriften müssen für die Einbettung in PDFs lizenziert sein – Hinweis beim Hochladen.
- **Sicherheit:** Vorlagen dürfen keine externen Ressourcen laden (Bilder, Schriften, CSS aus dem Internet) und keinen
  Code ausführen; mPDF wird entsprechend eingeschränkt, Mustache führt keinen Code aus.

## 10. Offene Punkte

- Briefpapier je App in D1 oder erst bei Bedarf?
- Nummernkreise (z. B. Rechnungsnummer) – heute über „Zählen je“ in den Feldern; reicht das, oder braucht es fortlaufende
  Nummern je Jahr?
- Archivierung: erzeugte Dokumente unveränderbar ablegen (z. B. PDF/A, Aufbewahrungspflicht)?
