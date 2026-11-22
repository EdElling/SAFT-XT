# Konzept: Dokumente aus Vorlagen (PDF) in SAFT XT

Stand: 21.11.2026 · Status: abgestimmt, offene Punkte entschieden, Etappe D1 in Planung · Backlog: B-124

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
| Weg zum PDF | **HTML-Vorlage mit Platzhaltern, gerendert mit tc-lib-pdf** (reines PHP, LGPL-3.0, Nachfolger von TCPDF). Kopf- und Fusszeilen, Seitenzahlen, wiederholte Tabellenköpfe, eingebettete Schriften, PDF-Seiten als Hintergrund, PDF/A, Signatur. Verworfen: mPDF – GPL-2.0, mit der Lizenz von SAFT XT nicht vereinbar (Abschnitt 10); Headless Chrome (Browsershot, spatie/laravel-pdf) und Gotenberg – brauchen Chromium bzw. einen eigenen Dienst; wkhtmltopdf – nicht mehr gepflegt; dompdf – schwächer bei Seitenumbrüchen, kein PDF/A. |
| Platzhaltersprache | **Mustache** (logikfrei, sicher): Vorlagen von Admins oder der KI können keinen Code ausführen. Blade scheidet aus (führt PHP aus). Ergänzt um wenige Formatierungen (Abschnitt 5). |
| Gestaltung und Inhalt | **Getrennt.** Das **Briefpapier** (CI) wird einmal zentral festgelegt; **Inhaltsvorlagen** setzen nur Daten darauf und können das CI nicht verletzen (Abschnitt 3). |
| Bearbeiten | In SAFT selbst: **Code-Editor** für Inhaltsvorlagen mit Feldauswahl und **Live-Vorschau** als PDF. Später Bausteine im Formular für Fachanwender. Kein WYSIWYG-Designer. |
| KI | Erzeugt **nur Inhaltsvorlagen** innerhalb des Briefpapiers, nie das CI (Etappe D2). |
| Zuordnung | Inhaltsvorlagen gehören zu einer **App** und einer **Grundtabelle** (z. B. „Rechnung“ zu *Belege*). Das Briefpapier gilt für die ganze Installation; **je App ein eigenes Briefpapier ab D1**. |
| Archivierung | **PDF/A-3b** (tc-lib-pdf), einstellbar, Vorgabe an. Abgelegte Dokumente sind schreibgeschützt; erneutes Erzeugen legt eine neue Fassung an. Prüfwert (SHA-256) im Änderungsprotokoll. **Digitale Signatur später** (eigene Etappe D5 nach einem Versuch). |
| Nummernkreise | **Nicht Teil der Dokumente**, eigenes Backlog-Thema B-135 (Feldeigenschaft). Vorlagen zeigen das vorhandene Nummernfeld an. |
| Recht | Je Vorlage, wie bei Auswertungen: Wer die Grundtabelle lesen darf und das Recht der Vorlage hat, darf das Dokument erzeugen. Vorlagen bearbeiten: eigenes Recht *Dokumentvorlagen: verwalten*. |

## 3. Briefpapier (CI) – einmal festlegen

Das Briefpapier entsteht dort, wo CI üblicherweise entsteht: in einem Gestaltungsprogramm (InDesign, Illustrator,
Affinity, auch Word) bei der Agentur oder der Kommunikationsabteilung. In SAFT wird es nur hinterlegt
(*Administration › Anwendung › Briefpapier*, Recht *Globale Einstellungen: bearbeiten*):

- **Briefpapier als PDF**, getrennt für die **erste Seite** und die **Folgeseiten** (Logo, Linien, Fusszeile mit
  Adresse, Bank, MWST-Nummer). SAFT legt es als Hintergrund unter jede Seite – pixelgenau wie geliefert, niemand muss
  es nachbauen. Ohne PDF: einfacher Kopf mit Logo und Absender aus den Einstellungen.
- **Hausschriften** (TTF hochladen; ohne eigene eine mitgelieferte, gut lesbare Schrift) und **Hausfarben** (Primär-,
  Sekundär-, Textfarbe) als feste Werte, die alle Vorlagen über vorgegebene CSS-Klassen verwenden.
- **Satzspiegel:** Seitenränder je Seitentyp, Position des **Adressfelds** (Fensterkuvert links oder rechts, Schweiz
  bzw. DIN 5008), Absenderzeile.
- **Absenderdaten** als Platzhalter für Vorlagen: Firmenname, Adresse, MWST-Nummer, IBAN, Kontakt.

Optional je App ein eigenes Briefpapier (z. B. eine Abteilung mit eigenem Auftritt), schon in D1; sonst gilt das der
Installation. Je App lassen sich alle Teile oder nur einzelne (z. B. nur Briefpapier-PDF und Absender) abweichend festlegen.

## 4. Inhaltsvorlagen – Daten auf das Briefpapier setzen

Eine Inhaltsvorlage ist **HTML mit Platzhaltern** und gehört zu einer App und einer Grundtabelle. Sie legt fest, was
wo steht: Adresse, Datum, Betreff, Einleitung, Positionstabelle, Summen, Schlusstext – nicht Schrift, Farben oder
Briefpapier.

**Bearbeiten** auf einer Admin-Seite *Dokumentvorlagen* der App:

- **Code-Editor** (HTML) mit Syntaxhervorhebung; daneben die **Feldauswahl** wie im Formeleditor: Felder der
  Grundtabelle, über Verknüpfungen erreichbare Felder (`kunde_id.name`), Kind-Tabellen als Liste
  (`{{#positionen}} … {{/positionen}}`), berechnete Felder, Absenderdaten. Ein Klick fügt den Platzhalter ein.
- **Live-Vorschau** als PDF mit einem wählbaren Beispieldatensatz, aktualisiert beim Tippen (verzögert).
- **Prüfung** beim Speichern: unbekannte Felder, nicht geschlossene Abschnitte, HTML, das tc-lib-pdf nicht darstellen kann
  (Hinweis statt Fehler).
- **Mitgelieferte Grundlagen**: „Rechnung“, „Brief“, „Liste“ – als Ausgangspunkt zum Kopieren.

Wer lieber ausserhalb arbeitet, schreibt die Vorlage in einem Texteditor (z. B. VS Code) und fügt sie ein.
Gestaltungsprogramme sind dafür ungeeignet; sie liefern kein HTML in der nötigen einfachen Form.

**Grenzen von tc-lib-pdf**, die Vorlagen kennen müssen: kein Flexbox, kein Grid, kein `float` und kein `position`.
Layouts entstehen mit Tabellen – für Rechnungen und Briefe ausreichend. Kopf, Fuss, Seitenzahlen und das Adressfeld
setzt SAFT selbst an feste Stellen. Die mitgelieferten Vorlagen zeigen, wie.

## 5. Platzhalter

Mustache, ergänzt um Formatierungen mit `|`:

| Schreibweise | Bedeutung |
|---|---|
| `{{belegnummer}}` | Feld der Grundtabelle, angezeigt wie in SAFT (Zahl, Datum, Bezeichnung des Auswahlwerts, Anzeige der Verknüpfung); Text wird maskiert |
| `{{kunde_id.name}}`, `{{kunde_id.ort}}` | Feld über eine Verknüpfung (bis 3 Schritte) |
| `{{#gitarren_buchungen}} … {{/gitarren_buchungen}}` | je Kind-Datensatz wiederholen (Positionen), in der Reihenfolge der Kind-Tabelle |
| `{{^gitarren_zahlungen}} … {{/gitarren_zahlungen}}` | nur wenn es keine gibt |
| `{{betrag \| CHF}}`, `{{datum \| DatumLang}}`, `{{menge \| Zahl:0}}`, `\| Zeilen`, `\| Roh` | Formatierung: Betrag, Datum, Zahl mit Stellen, Zeilenumbrüche, gespeicherter Wert – in der Sprache des Dokuments. Gross geschrieben, damit sie nie mit einem (immer klein geschriebenen) Feld kollidieren – Mustache sucht Formatierungen wie Platzhalter |
| `{{absender.firma}}`, `{{absender.iban}}` | Absenderdaten aus dem Briefpapier |
| `{{heute}}` | Datum der Erzeugung; Seitenzahlen setzt SAFT selbst in die Fusszeile |

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
- Im Änderungsprotokoll: wer welches Dokument zu welchem Datensatz erzeugt hat, mit Prüfwert (SHA-256) des PDFs.
- **PDF/A-3b** (Einstellung, Vorgabe an): Schriften eingebettet, keine Transparenz. Ein Briefpapier-PDF mit Transparenzen
  kann damit kollidieren – Prüfung und Hinweis beim Hochladen.
- **Schreibgeschützt:** Ein im Dateifeld abgelegtes Dokument wird nicht überschrieben; erneutes Erzeugen legt eine neue
  Fassung daneben (`Rechnung-R-2026-001 (2).pdf`).
- Später (D3): mehrere ausgewählte Datensätze auf einmal – ein zusammengefügtes PDF oder eine ZIP-Datei; Versand per
  E-Mail.

## 7. App-Definition

Inhaltsvorlagen gehören zur App und wandern mit Export, Installieren und Aktualisieren (Teil `documents` der
App-Definition `saft-app/1`, mit stabilen Schlüsseln). Das Briefpapier der Installation gehört nicht dazu – eine
installierte App nutzt das Briefpapier der Zielinstallation.

## 8. Etappen

| Etappe | Inhalt | Grösse |
|---|---|---|
| **D1 Grundlage** | tc-lib-pdf; Briefpapier der Installation und je App (PDF-Hintergrund für erste und Folgeseiten, Hausschriften, Hausfarben, Satzspiegel, Absenderdaten); PDF/A-3b, Prüfwert, schreibgeschützte Ablage; Inhaltsvorlagen je App mit Code-Editor, Feldauswahl, Live-Vorschau und Prüfung; mitgelieferte Vorlagen „Rechnung“ und „Brief“; Symbol „Dokument“ in der Zeile, Herunterladen und Ablage im Dateifeld; Rechte; Handbücher. Demo: Rechnung im Gitarrenshop. | gross |
| **D2 KI und Bausteine** | Inhaltsvorlage aus einer Beschreibung (innerhalb des Briefpapiers); Bausteine im Formular für Fachanwender (Layout wählen, Felder im Adressblock, Spalten der Positionstabelle, Summen, Texte) | mittel |
| **D3 Mehrere und Versand** | Auswahl mehrerer Datensätze → ein PDF bzw. ZIP; Versand per E-Mail mit dem Dokument als Anhang | mittel |
| **D4 Schweiz und Word** | **QR-Rechnung** (Zahlteil mit QR-Code nach Schweizer Standard, reine PHP-Bibliothek); DOCX für Briefe, die man nachbearbeiten will. Sinnvoll mit Nummernkreisen (B-135) | mittel |
| **D5 Signatur** | erzeugte PDFs digital signieren (Zertifikat von SAFT erzeugt oder hochgeladen), damit jede Änderung erkennbar ist; vorher Versuch, ob eine reine PHP-Lösung PDF/A erhält | klein bis mittel |

Jede Etappe ist für sich auslieferbar. D1 ist ohne KI vollständig nutzbar.

## 9. Risiken

- **CSS-Grenzen von tc-lib-pdf:** Vorlagen aus dem Web (Flexbox, Grid) sehen im PDF anders aus. Gegenmittel: mitgelieferte
  Vorlagen als Muster, Prüfung mit Hinweisen, Live-Vorschau.
- **Speicher und Zeit** bei langen Dokumenten (viele Positionen, grosse Briefpapier-PDFs): die PDF-Erzeugung braucht
  dafür mehr Speicher. Gegenmittel: Grenze für Positionen je Dokument, Briefpapier-PDF in der Grösse begrenzt.
- **Schriften und Lizenzen:** Hausschriften müssen für die Einbettung in PDFs lizenziert sein – Hinweis beim Hochladen.
- **Sicherheit:** Vorlagen dürfen keine externen Ressourcen laden (Bilder, Schriften, CSS aus dem Internet) und keinen
  Code ausführen; tc-lib-pdf lädt nichts aus dem Internet und nur Dateien aus den eigenen Verzeichnissen, Mustache führt keinen Code aus.

## 10. Entschiedene Punkte (21.11.2026)

- **Briefpapier je App:** bereits in D1.
- **Nummernkreise:** eigenes Backlog-Thema B-135 – formatierte Nummern (`R-2026-0027`), Neubeginn je Jahr, einmal
  vergeben nie wieder, ohne Doppel bei gleichzeitigem Anlegen. Heute gibt es nur „fortlaufend vorschlagen“ (höchster
  Wert + 1, auch je Verknüpfung mit „Zählen je“).
- **Archivierung:** PDF/A-3b in D1 (erlaubt später eingebettete Rechnungsdaten, ZUGFeRD); PDF/A allein schützt nicht vor
  Änderungen, deshalb schreibgeschützte Ablage und Prüfwert; digitale Signatur (auch mit selbst erzeugtem Zertifikat)
  in D5.
- **PDF-Bibliothek (22.11.2026):** tc-lib-pdf statt mPDF. mPDF steht unter GPL-2.0; SAFT XT steht unter der
  PolyForm-Noncommercial-Lizenz mit separaten kommerziellen Lizenzen – das verträgt sich nicht. tc-lib-pdf (LGPL-3.0)
  darf als Bibliothek eingebunden werden. Ein Versuch mit derselben Probeseite ergab gleichwertige Ergebnisse
  (Briefpapier auf Seite 1 und Folgeseiten, Adressfeld, wiederholter Tabellenkopf, Seitenzahlen, Hausschrift,
  PDF/A-3b); dazu kommen eingebaute Signatur (PAdES, für D5) und Factur-X/ZUGFeRD. Weniger CSS als mPDF (siehe
  Abschnitt 4). Die Bibliothek ist sehr neu (TCPDF 7 ist nur noch eine Hülle darum) – Version eng festgelegt.
- **D1b (22.11.2026):** `{{feld}}` zeigt den Wert wie in SAFT (nicht den gespeicherten; dafür `| Roh`), Formatierungen
  gross geschrieben. Demo-Vorlagen (Rechnung Gitarrenshop, Semesterrechnung Musikschule) schon in D1b. Die Seite des
  Briefpapiers heisst „Briefpapier“, die der Vorlagen „Dokumentvorlagen“ (beide unter Administration › Anwendung). Für
  den KI-Assistenten (D2) sind Platzhalter-Katalog (`TemplateFields`) und Prüfung (`TemplateChecker`) die Grundlage –
  Vorschlag prüfen und Fehler einmal zur Korrektur zurückgeben wie bei den Auswertungen.
- **D1c (22.11.2026):** Symbol „Dokument erstellen“ nur in Tabellen mit aktiver Vorlage, für Personen mit *Dokumente:
  erzeugen* und Leserecht; Ablegen im Dateifeld der Vorlage braucht das Bearbeiten-Recht und ist vorgewählt. Erzeugte
  Dateien tragen Vorlage und SHA-256 (`record_files`), entfernen nur mit Lösch-Recht – das Upload-Feld lehnt es sofort
  ab; beim Kopieren eines Datensatzes kommen sie nicht mit. Ereignis „Dokument erzeugt“ im Änderungsprotokoll. Ein
  Dateifeld für die Rechnungen der Musikschule folgt mit der App-Definition (D1d).
- **D1d (22.11.2026):** Dokumentvorlagen sind ein Teil der App-Definition (`documents`, beim Export vorgewählt), das
  Briefpapier nicht – es gehört zur Installation. Installieren als Kopie benennt Grundtabelle und Abschnitte `{{#…}}`
  um; eine Vorlage, die nicht mehr passt, wird trotzdem angelegt und als Hinweis gemeldet. Das Aktualisieren behandelt
  Vorlagen wie die anderen Objekte (Dreiwegvergleich, Konflikte); beim Entfernen bleiben erzeugte Dokumente. Die
  Musikschule (1.2) bringt die Semesterrechnung in der Definition mit, dazu das Dateifeld „Dokumente“ der Rechnungen
  und das Recht *Dokumente: erzeugen* für ihre Rolle.
- **D2a (22.11.2026):** KI-Assistent im Formular der Vorlage mit eigenem App-Recht *Dokumente: Assistent nutzen*
  (Migration: alle Rollen mit *Dokumente: verwalten*). Ein Vorschlag ersetzt Empfänger, Inhalt und Dateiname (Name nur,
  solange leer oder vom Assistenten), „Rückgängig“ einen Schritt. An die KI: Beschreibung, Vorlage, Felder der
  Grundtabelle, ihrer Verknüpfungen (ein Schritt) und Kind-Tabellen, das Grundgerüst als Stilmuster – nie Datensätze.
  Auch PDF-Hinweise gehen zur Korrektur zurück. Bausteine (D2b) erst nach Bedarf, eigener Plan.
- **D3a (22.11.2026):** Auswahl per Häkchen in den Datentabellen (Teil 1 von B-133), nur sichtbar mit einer
  Sammelaktion. Sammelaktion „Dokumente erstellen“: ein zusammengefügtes PDF (Seiten der einzelnen Dokumente unverändert,
  je eigene Seitenzählung) oder ZIP; Ablage je Datensatz wie bei einem Dokument, was nicht abgelegt werden kann, wird
  trotzdem erzeugt und gemeldet. Ohne Queue-Worker direkt in der Anfrage, höchstens `DOCUMENTS_BATCH_MAX` (Vorgabe 50)
  Datensätze. Versand per E-Mail folgt in D3b mit eigenem App-Recht *Dokumente: versenden*.
- **D3b (22.11.2026):** E-Mail je Vorlage (An, Betreff, Text, Antwort an – Platzhalter, geprüft und in der Vorschau),
  App-Recht *Dokumente: versenden* ohne automatische Vergabe. Einzeln mit änderbarem An/Betreff/Text und Kopie an sich
  selbst, als Sammelaktion je Datensatz mit dem Text der Vorlage. Sofort versendet (kein Queue-Worker), versendete
  Dokumente werden abgelegt, wenn möglich; Ereignis „Dokument versendet“. Mailserver neu in den globalen Einstellungen
  (Passwort verschlüsselt, leere Felder = .env, SMTP geht vor `MAIL_MAILER`) mit „Verbindung prüfen“ statt Test-E-Mail:
  verbinden, TLS, Anmeldung und Absender (MAIL FROM, danach RSET), Ursache in der Meldung – GMX lehnt fremde
  Absenderadressen ab. Musikschule 1.3 mit E-Mail an Kontaktperson bzw. Schüler.
- **D4a (22.11.2026):** QR-Rechnung mit `sprain/swiss-qr-bill` (MIT) für Daten und Prüfung; den Zahlteil zeichnet SAFT
  selbst mit tc-lib-pdf (Liberation Sans, OFL, eingebettet; QR-Code als Vektor, Schweizerkreuz gezeichnet). Je Vorlage
  `qr_bill` (JSON): Zahlteil auf eigener letzter Seite (Vorgabe) oder unten, wenn Platz ist; Betrag, Währung, Referenz,
  Mitteilung, Zahlungspflichtiger mit Platzhaltern. Zahlungsempfänger aus dem Briefpapier (neu Strasse, Hausnummer,
  PLZ, Ort, Land, QR-IBAN; leer aus der Adresse „PLZ Ort“). Referenz: QR-IBAN → QR-Referenz aus den Ziffern, sonst
  RF-Referenz. Nach IG 2.4 (gültig ab 14.11.2026): Hausnummer getrennt (ohne eigenes Feld vom Strassennamen abgetrennt),
  QR-IBAN nur für CHF, Schere auf beiden Trennlinien. Kundentabellen bleiben unverändert. IBAN-Prüfziffer schon beim
  Speichern des Briefpapiers. DOCX zurückgestellt (D4b).
- **D4b (22.11.2026):** Word-Fassung aus derselben HTML-Vorlage (Weg A) mit `phpoffice/phpword` (LGPL-3.0); keine
  eigene Word-Vorlage (Weg B bei Bedarf später). Je Vorlage `formats`: pdf (Vorgabe), docx oder both; Wahl im Dialog.
  Klassen der Vorlage als Word-Formate, Kopf mit Logo und Absender (Briefpapier-PDF und SVG-Logo nicht in Word),
  Adressfeld als Tabelle fester Höhe, Schrift Arial. Word wird nur heruntergeladen, nie abgelegt oder versendet (das PDF
  bleibt verbindlich); Sammelaktion als ZIP; keine QR-Rechnung in Word. Ältere App-Definitionen ohne `formats` gelten
  als PDF.
- **PDF/A-Prüfung (22.11.2026):** veraPDF bestätigt PDF/A-3b für die erzeugte Rechnung des Gitarrenshops und die
  Semesterrechnung der Musikschule.
