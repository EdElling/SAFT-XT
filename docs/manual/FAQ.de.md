# SAFT XT – Häufige Fragen

**Deutsch** · [English](FAQ.md) · [Français](FAQ.fr.md) · [Italiano](FAQ.it.md)

**Stand:** Version 0.1 · **Zielgruppe:** alle

**Weitere Handbücher:** [Anwenderhandbuch](USER_GUIDE.de.md) · [Administratorhandbuch](ADMIN_GUIDE.de.md) · [Systemadministration](SYSADMIN_GUIDE.de.md)

---

## Inhalt

- [Häufige Fragen](#häufige-fragen)
   - [Ich sehe eine Tabelle nicht im Menü.](#ich-sehe-eine-tabelle-nicht-im-menü)
   - [Unter einer Bestellung erscheinen keine Positionen.](#unter-einer-bestellung-erscheinen-keine-positionen)
   - [Ich kann einen Datensatz nicht löschen.](#ich-kann-einen-datensatz-nicht-löschen)
   - [Ich kann eine Spalte nicht auf einen anderen Typ umstellen.](#ich-kann-eine-spalte-nicht-auf-einen-anderen-typ-umstellen)
   - [Ich habe „Passwort vergessen“ benutzt, aber keine E-Mail erhalten.](#ich-habe-passwort-vergessen-benutzt-aber-keine-e-mail-erhalten)
   - [Ich habe mein Handy mit der Authenticator-App verloren.](#ich-habe-mein-handy-mit-der-authenticator-app-verloren)
   - [In welcher Sprache erscheint die Anwendung?](#in-welcher-sprache-erscheint-die-anwendung)
   - [Ich habe Englisch gewählt, aber ein Menüeintrag oder eine Tabelle heisst weiterhin deutsch.](#ich-habe-englisch-gewählt-aber-ein-menüeintrag-oder-eine-tabelle-heisst-weiterhin-deutsch)
   - [Ich sehe keine Schaltfläche „Exportieren“.](#ich-sehe-keine-schaltfläche-exportieren)
   - [Bei einer Tabelle fehlen „Neuer Datensatz“ und „Bearbeiten“.](#bei-einer-tabelle-fehlen-neuer-datensatz-und-bearbeiten)
   - [Wie gebe ich jemandem eine bestimmte Ansicht weiter?](#wie-gebe-ich-jemandem-eine-bestimmte-ansicht-weiter)
   - [Eine Datei lässt sich nicht hochladen.](#eine-datei-lässt-sich-nicht-hochladen)
   - [Bei einer Verknüpfung fehlt eine Tabelle in der Auswahl.](#bei-einer-verknüpfung-fehlt-eine-tabelle-in-der-auswahl)
   - [Eine App ist aus dem Umschalter verschwunden.](#eine-app-ist-aus-dem-umschalter-verschwunden)
   - [Ich kann ein Dokument nicht aus einem Dateifeld entfernen.](#ich-kann-ein-dokument-nicht-aus-einem-dateifeld-entfernen)
   - [In einer Liste fehlen die Häkchen zum Auswählen.](#in-einer-liste-fehlen-die-häkchen-zum-auswählen)
   - [Ein Dokument liess sich nicht per E-Mail senden.](#ein-dokument-liess-sich-nicht-per-e-mail-senden)
   - [Die QR-Rechnung fehlt oder die Banking-App liest sie nicht.](#die-qr-rechnung-fehlt-oder-die-banking-app-liest-sie-nicht)
   - [Die Word-Datei sieht anders aus als das PDF.](#die-word-datei-sieht-anders-aus-als-das-pdf)
   - [Statt der Datei erscheint eine Seite „Arbeit“ mit Fortschrittsbalken.](#statt-der-datei-erscheint-eine-seite-arbeit-mit-fortschrittsbalken)
   - [Der Import einer grossen Datei dauert lange.](#der-import-einer-grossen-datei-dauert-lange)
   - [Wie bekommen Rechnungen eine fortlaufende Nummer?](#wie-bekommen-rechnungen-eine-fortlaufende-nummer)
   - [Wie übernehme ich vorhandene Rechnungsnummern in einen Nummernkreis?](#wie-übernehme-ich-vorhandene-rechnungsnummern-in-einen-nummernkreis)
   - [Wie bekommt eine Rechnung erst beim Versand eine Nummer?](#wie-bekommt-eine-rechnung-erst-beim-versand-eine-nummer)
   - [Wie bekommen Rechnungen und Gutschriften eigene Nummern?](#wie-bekommen-rechnungen-und-gutschriften-eigene-nummern)
   - [Wie ändere ich viele Datensätze auf einmal?](#wie-ändere-ich-viele-datensätze-auf-einmal)
   - [Die Sammeländerung meldet „Nichts geändert“.](#die-sammeländerung-meldet-nichts-geändert)
   - [Bei der Sammeländerung blieben Datensätze unverändert.](#bei-der-sammeländerung-blieben-datensätze-unverändert)
   - [Wie mache ich eine Sammeländerung rückgängig?](#wie-mache-ich-eine-sammeländerung-rückgängig)
   - [Wie exportiere ich nur einige Datensätze?](#wie-exportiere-ich-nur-einige-datensätze)
   - [Welche Datensätze hat eine Sammeländerung geändert?](#welche-datensätze-hat-eine-sammeländerung-geändert)
   - [Wann und von wem wurde ein Datensatz angelegt?](#wann-und-von-wem-wurde-ein-datensatz-angelegt)
   - [Wie mache ich eine einzelne Änderung rückgängig?](#wie-mache-ich-eine-einzelne-änderung-rückgängig)

---
## Häufige Fragen

### Ich sehe eine Tabelle nicht im Menü.

Sie haben kein Leserecht dafür, es gibt keinen Menüeintrag, oder Ihre Rolle blendet den Eintrag aus. Wenden Sie sich
an die Administration (Rollen → Datenrechte bzw. Menüeinträge, oder Menüs). Gehört die Tabelle zu einer anderen **App**, wechseln Sie
zuerst mit dem Umschalter oben in diese App.

### Unter einer Bestellung erscheinen keine Positionen.

Entweder sind für die Tabelle keine Details eingestellt (Table-Designer → Details), oder Ihnen fehlt das
Leserecht auf die Positionen.

### Ich kann einen Datensatz nicht löschen.

Es verweisen noch andere Datensätze darauf (siehe Meldung). Löschen Sie zuerst diese.

### Ich kann eine Spalte nicht auf einen anderen Typ umstellen.

Die Umstellung würde vorhandene Daten verändern. Sie ist nur bei leerer Spalte oder für verlustfreie Typwechsel
möglich (siehe [Administratorhandbuch, Kapitel 4](ADMIN_GUIDE.de.md#4-table-designer)).

### Ich habe „Passwort vergessen“ benutzt, aber keine E-Mail erhalten.

Die Bestätigung erscheint immer, auch wenn es zu der Adresse kein Konto gibt. Prüfen Sie die Schreibweise der
Adresse und Ihren Spam-Ordner, und warten Sie vor einem neuen Versuch einen Moment, weil wiederholte Anfragen
gesperrt werden. Hilft das nicht, wenden Sie sich an die Administration.

### Ich habe mein Handy mit der Authenticator-App verloren.

Melden Sie sich mit einem Ihrer **Wiederherstellungscodes** an: nach dem Passwort auf **„Stattdessen einen
Wiederherstellungscode verwenden“** klicken. Jeder Code gilt nur einmal. Richten Sie danach in *Meine Einstellungen*
die App auf dem neuen Handy ein. Haben Sie auch die Codes nicht mehr, setzt die Administration den zweiten Faktor
zurück; danach genügt das Passwort, bis Sie neu eingerichtet haben.

### In welcher Sprache erscheint die Anwendung?

In dieser Reihenfolge gilt: die im Konto gespeicherte Wahl, die Wahl in dieser Sitzung oder auf diesem Gerät, die
Sprache des Browsers, zuletzt die Vorgabe der Administration.

### Ich habe Englisch gewählt, aber ein Menüeintrag oder eine Tabelle heisst weiterhin deutsch.

Für diesen Eintrag ist keine englische Bezeichnung gepflegt; dann erscheint die deutsche. Die Administration kann
sie unter Menüs bzw. im Table-Designer ergänzen. Daten wie Firmennamen werden nie übersetzt.

### Ich sehe keine Schaltfläche „Exportieren“.

Entweder ist der Export für diese Tabelle nicht freigegeben (Table-Designer → Export erlauben), oder Ihrer Rolle
fehlt das Recht (Rollen → Datenrechte → Exportieren).

### Bei einer Tabelle fehlen „Neuer Datensatz“ und „Bearbeiten“.

Es ist eine angebundene Tabelle einer externen Datenbank (Table-Connector), für die Schreiben nicht freigeschaltet
ist (Table-Connector → Schreiben), oder Ihrer Rolle fehlt das Recht (Rollen → Datenrechte).

### Wie gebe ich jemandem eine bestimmte Ansicht weiter?

Kopieren Sie die Adresse aus dem Browser – sie enthält Tabelle, Spaltenauswahl und die ausgewählten Datensätze.
Der Empfänger sieht nur, wofür er Rechte hat.

### Eine Datei lässt sich nicht hochladen.

Das Feld erlaubt nur bestimmte Dateiarten und eine Höchstgrösse und -anzahl (Table-Designer → Feld → Erlaubte
Dateien, Höchstgrösse je Datei). Geprüft wird der Inhalt: Eine umbenannte Datei mit falscher Endung wird abgewiesen.
Scheitern auch kleine erlaubte Dateien, ist die Grenze des Servers zu klein (Systemadministration:
`upload_max_filesize` und `post_max_size`).

### Bei einer Verknüpfung fehlt eine Tabelle in der Auswahl.

Verknüpfungen gibt es zwischen Tabellen derselben App; Tabellen einer anderen App erscheinen nur, wenn diese App sie
als **Schnittstelle freigegeben** hat – dann mit dem App-Namen in Klammern, und als Anzeigefelder stehen nur die
freigegebenen Felder zur Wahl (Administratorhandbuch, Kapitel 15). Fehlt die Tabelle, bitten Sie die Administration, sie
freizugeben – oder legen Sie die neue Tabelle in deren App an.

### Eine App ist aus dem Umschalter verschwunden.

Die Administration hat sie deaktiviert – etwa für Umbauten oder bevor sie entfernt wird. Ihre Daten bleiben erhalten; sobald
sie wieder aktiviert ist, erscheint sie wie zuvor. Fragen Sie bei der Administration nach.

### Ich kann ein Dokument nicht aus einem Dateifeld entfernen.

Dokumente, die SAFT aus einer Vorlage erstellt und beim Datensatz abgelegt hat (z. B. eine Rechnung), sind
schreibgeschützt: Entfernen darf sie nur, wer Datensätze der Tabelle löschen darf. So bleibt nachvollziehbar, welches
Dokument verschickt wurde. Ein korrigiertes Dokument erstellen Sie einfach neu – es erscheint als neue Fassung daneben
(Anwenderhandbuch, Abschnitt 4.10). Muss ein Dokument wirklich weg, bitten Sie jemanden mit Lösch-Recht.

### In einer Liste fehlen die Häkchen zum Auswählen.

Häkchen zum Auswählen mehrerer Datensätze erscheinen nur, wenn es dafür eine Sammelaktion gibt, die Sie nutzen dürfen:
**„Ändern …“** (Recht *Bearbeiten*, und die Administration erlaubt die Sammeländerung für die Tabelle; Anwenderhandbuch,
Abschnitt 4.11), **„Ausgewählte löschen“** (Recht *Löschen*), **„Auswahl exportieren“** (Recht *Exportieren* und Export für die Tabelle freigegeben; Abschnitt 4.7) oder **„Dokumente erstellen“** (aktive Dokumentvorlage und
Recht *Dokumente: erzeugen*; Abschnitt 4.10). Angebundene Tabellen haben keine Sammeländerung, wohl aber „Auswahl exportieren“.

### Ein Dokument liess sich nicht per E-Mail senden.

- **Kein Schalter „Per E-Mail senden“:** Die Vorlage sieht keine E-Mail vor, oder Ihrer Rolle fehlt das Recht
  *Dokumente: versenden*.
- **„An“ ist leer oder „keine gültige E-Mail-Adresse“:** Beim Datensatz bzw. beim verknüpften Kunden fehlt die
  Adresse – oder Sie dürfen die verknüpfte Tabelle nicht lesen. Tragen Sie die Adresse von Hand ein.
- **„Der Mailserver lehnt die Absenderadresse … ab“** oder eine andere Meldung des Servers: Das ist eine Einstellung
  der Administration (E-Mail-Versand in den globalen Einstellungen). Das Dokument wurde trotzdem erstellt und – wenn
  vorgesehen – abgelegt.

### Die QR-Rechnung fehlt oder die Banking-App liest sie nicht.

- **„Die QR-Rechnung ist ungültig“ oder „fehlen im Briefpapier“:** Im Briefpapier fehlen Angaben des
  Zahlungsempfängers (PLZ, Ort, IBAN) oder die IBAN ist falsch – die Administration korrigiert das Briefpapier
  (Administratorhandbuch, Kapitel 16, „QR-Rechnung“).
- **„Der Betrag der QR-Rechnung ist keine Zahl“:** Beim Datensatz fehlt der Betrag oder er ist kein Zahlenwert.
- **Die Banking-App erkennt den Code nicht:** Das PDF in Originalgrösse am Bildschirm zeigen oder ausdrucken (nicht
  verkleinert, „An Seite anpassen“ aus). Der Code muss 46 × 46 mm gross sein.

### Die Word-Datei sieht anders aus als das PDF.

Die Word-Fassung ist zum Nachbearbeiten gedacht, nicht als Ersatz für das PDF: Ein Briefpapier als PDF-Hintergrund, ein
Logo als SVG und die QR-Rechnung gibt es nur im PDF; Word zeigt stattdessen Logo (PNG/JPEG) und Absender als Text im
Kopf und verwendet die Schrift Arial. Für den Versand und die Ablage nimmt SAFT immer das PDF.

### Statt der Datei erscheint eine Seite „Arbeit“ mit Fortschrittsbalken.

Export, Import, „Dokumente erstellen“ für viele Datensätze und Datenstände laufen als **Arbeit** in Schritten. Kleine
Mengen sind fertig, bevor Sie es merken, und die Datei kommt wie gewohnt. Dauert es länger, zeigt SAFT die Seite der
Arbeit: Lassen Sie sie offen, geht es am schnellsten; Sie dürfen sie aber auch schliessen – die Arbeit läuft im
Hintergrund weiter, die Glocke meldet das Ende, und die Datei steht 24 Stunden unter **Meine Arbeiten** (Benutzermenü)
zum Herunterladen bereit.

### Der Import einer grossen Datei dauert lange.

Jeder Datensatz wird wie von Hand erfasst – mit Vorgaben, berechneten Feldern, Summen in übergeordneten Datensätzen und
den Regeln der Plugins. Das kostet je Datensatz einige Millisekunden; bei zehntausenden Zeilen sind das Minuten. Der
Fortschritt zeigt erst **Prüfen**, dann **Einspielen**. Sie können die Seite verlassen; bei Fehlern oder Abbruch wird
nichts angelegt bzw. alles Angelegte wieder entfernt.

### Wie bekommen Rechnungen eine fortlaufende Nummer?

Mit einem Feld vom Typ **Nummernkreis**, z. B. mit dem Muster `R-{YYYY}-{0000}` und jährlichem Neubeginn. SAFT vergibt
die Nummer beim Anlegen, nie doppelt und nach dem Löschen nicht wieder; danach lässt sie sich nicht ändern. Das Feld
zeigt deshalb im Formular nur „wird beim Speichern vergeben“. Einrichten im Table-Designer, siehe
[Administratorhandbuch, Nummernkreise](ADMIN_GUIDE.de.md#nummernkreise).

### Wie übernehme ich vorhandene Rechnungsnummern in einen Nummernkreis?

Stellen Sie das bisherige Feld (Text, Ganzzahl oder Auswahl) im Table-Designer auf den Typ **Nummernkreis** um. Vorher
zeigt SAFT, wie viele Werte zum Muster passen und wo die Nummern weiterzählen; Werte, die nicht passen, bleiben
unverändert. Leere Werte lassen sich gleich oder später mit **Jetzt nummerieren** füllen, und mit **Zählerstand
setzen** beginnt z. B. das nächste Jahr bei 1000. Siehe
[Administratorhandbuch, Bestehende Felder umstellen](ADMIN_GUIDE.de.md#bestehende-felder-umstellen).

### Wie bekommt eine Rechnung erst beim Versand eine Nummer?

Tragen Sie beim Nummernkreis unter **Vergeben, wenn** eine Bedingung ein, z. B. `status <> 'entwurf'`. Dann bleibt die
Nummer bei Entwürfen leer und wird beim ersten Speichern vergeben, bei dem die Bedingung zutrifft; danach ist sie fest.
Verworfene Entwürfe hinterlassen so keine Lücken. Siehe
[Administratorhandbuch, Vergabe erst bei einer Bedingung](ADMIN_GUIDE.de.md#vergabe-erst-bei-einer-bedingung).

### Wie bekommen Rechnungen und Gutschriften eigene Nummern?

Wählen Sie beim Nummernkreis unter **Gruppe** das Auswahlfeld mit der Belegart und setzen Sie `{GROUP}` ins Muster,
z. B. `{GROUP}-{YYYY}-{000}`. Je Wahl tragen Sie ein Kurzzeichen ein, z. B. `R` für Rechnung und `G` für Gutschrift;
dann zählt jede Belegart für sich (`R-2026-001`, `G-2026-001`). Siehe
[Administratorhandbuch, Eigener Zähler je Gruppe](ADMIN_GUIDE.de.md#eigener-zähler-je-gruppe).

### Wie ändere ich viele Datensätze auf einmal?

Filtern Sie die Liste, setzen Sie die Häkchen (oder „Alle N Datensätze auswählen“) und klicken Sie **„Ändern …“**. Haken Sie die
Felder an, wählen Sie die Art (*setzen*, *leeren*, *rechnen*, *ersetzen*, *Formel*) und prüfen Sie die Vorschau; **„Nur prüfen“** ändert noch nichts. Beispiel
Musikschule: Filter Semester und Lehrperson, alle Kurse auswählen, *Lehrperson* auf die neue setzen. Ebenso: Tarif der Kurse eines Fachs *rechnen* „um % ändern“ 5,
gerundet auf ein Vielfaches 0,05; in Adressen „str.“ durch „strasse“ *ersetzen*. Siehe
[Anwenderhandbuch 4.11](USER_GUIDE.de.md#411-mehrere-datensätze-ändern-sammeländerung).

### Die Sammeländerung meldet „Nichts geändert“.

Mindestens ein ausgewählter Datensatz besteht die Prüfungen nicht – dieselben wie beim Bearbeiten von Hand (Pflichtfeld,
Prüfregel, Regel der Tabelle, Plugin). Die Meldung nennt die Datensätze mit Grund. Es gilt alles oder nichts: Wählen Sie
diese Datensätze ab oder korrigieren Sie sie einzeln und starten Sie die Sammeländerung erneut. Steht „inzwischen von
jemand anderem geändert“ da, hat jemand während der Sammeländerung gespeichert; sie wurde vollständig zurückgenommen und
lässt sich einfach wiederholen. Beim Rechnen und bei Formeln kommen zwei Gründe dazu: **„Division durch 0“** (die Formel
teilt bei diesem Datensatz durch einen Wert 0; mit `if(feld = 0, …, …)` lässt sich das abfangen) und **„Die Zahl ist zu
gross für das Feld“** (das Ergebnis hat mehr Stellen vor dem Komma, als das Feld erlaubt). Beides zeigt schon die
Vorschau rot an. Nennt die Meldung einen Konflikt mit einem Datensatz, den Sie nicht finden (z. B. „unterrichtet am Mittwoch 23:00 bereits …“), liegt er zwischen zwei ausgewählten Datensätzen: Die Prüfung sieht die anderen ausgewählten schon mit den neuen Werten.

### Bei der Sammeländerung blieben Datensätze unverändert.

Das ist gewollt, wenn sich bei ihnen nichts ändern würde: Sie hatten schon den neuen Wert („schon so“), oder beim
Rechnen war der bisherige Wert leer, beim Ersetzen kam der Suchtext nicht vor bzw. die Formel ergab nichts („ohne Wert
bzw. ohne Treffer“). Vorschau und Meldung zählen beides. Sollen leere Werte gefüllt werden, filtern Sie nach „leer“ und
wählen *setzen*. Findet *ersetzen* weniger als erwartet, prüfen Sie die Häkchen „Gross-/Kleinschreibung beachten“ und
„nur ganzes Wort“: „Str.“ als ganzes Wort passt nicht in „Bahnhofstr.“, weil davor ein Buchstabe steht.

### Wie mache ich eine Sammeländerung rückgängig?

Über den Link **„Rückgängig“** in der Erfolgsmeldung oder später über **„Letzte Sammeländerungen“** über der Tabelle.
Die Bestätigung zeigt vorher, wie viele Datensätze zurückkommen, und Beispiele mit *jetzt → danach*. Datensätze, bei
denen ein geändertes Feld seither einen anderen Wert hat, bleiben stehen und die Meldung nennt sie. Ist der Knopf
gesperrt („Kein Datensatz würde zurückgenommen“), wurden alle Datensätze seither wieder geändert, oft von einer späteren
Sammeländerung: Nehmen Sie zuerst diese zurück. Vergebene Nummern und versendete E-Mails bleiben. Fremde
Sammeländerungen nimmt nur zurück, wer das Änderungsprotokoll sehen darf. Sammelaktionen aus Plugins (z. B.
„Lektionen erzeugen“) stehen nicht in der Liste und lassen sich dort nicht zurücknehmen. Siehe
[Anwenderhandbuch 4.11](USER_GUIDE.de.md#411-mehrere-datensätze-ändern-sammeländerung).

### Wie exportiere ich nur einige Datensätze?

Haken Sie die Datensätze am Zeilenanfang an und wählen Sie in der Leiste über der Tabelle **„Auswahl exportieren“**
(Excel oder CSV). Die Datei enthält nur die ausgewählten Datensätze, in der Sortierung der Liste und mit den sichtbaren
Spalten. Über alle Seiten wählen Sie mit dem Häkchen im Tabellenkopf und **„Alle N Datensätze auswählen“**; einzelne
lassen sich danach wieder abwählen. „Exportieren“ oben exportiert dagegen alle Treffer des Filters. Siehe
[Anwenderhandbuch 4.7](USER_GUIDE.de.md#47-daten-exportieren).

### Welche Datensätze hat eine Sammeländerung geändert?

Öffnen Sie **„Letzte Sammeländerungen“** über der Tabelle und klicken Sie bei der Sammeländerung auf **„Datensätze
anzeigen“** (den Link gibt es auch in der Bestätigung „Rückgängig“). Die Liste zeigt dann genau diese Datensätze, mit
einem Kärtchen „Sammeländerung vom …“ über der Tabelle. Ihre gespeicherte Ansicht bleibt erhalten: Das ✕ am Kärtchen
oder „Ansicht zurücksetzen“ bringt Ihre bisherigen Filter zurück. Siehe
[Anwenderhandbuch 4.11](USER_GUIDE.de.md#411-mehrere-datensätze-ändern-sammeländerung).

### Wann und von wem wurde ein Datensatz angelegt?

Blenden Sie im Spaltenmenü (Spalten-Symbol rechts neben dem Filter) die Spalten **Angelegt am**, **Angelegt von**,
**Geändert am** und **Geändert von** ein; sie stehen am Ende der Liste. Nach den Daten können Sie sortieren und im
Filter einen Zeitraum wählen, nach Personen filtern. Wer was genau geändert hat, zeigt der **Verlauf** eines
Datensatzes. Siehe [Anwenderhandbuch 4.1](USER_GUIDE.de.md#41-die-tabellenansicht).

### Wie mache ich eine einzelne Änderung rückgängig?

Öffnen Sie den **Verlauf** des Datensatzes (Uhr-Symbol in der Zeile, auf dem Smartphone unter „Details“) und klicken
Sie beim Eintrag „geändert“ auf den Pfeil zurück. Die Bestätigung zeigt je Feld *jetzt → zurück auf*; entfernen Sie
die Häkchen der Felder, die bleiben sollen, und wählen Sie **Zurücknehmen**. Felder, die seither nochmals geändert
wurden, sind nicht angehakt – setzen Sie das Häkchen, wenn Sie sie trotzdem zurücknehmen wollen. Fehlt der Pfeil, ist
der Eintrag kein Ändern (z. B. „angelegt“), alle Felder stehen schon auf dem alten Wert oder Ihnen fehlt das Recht
zum Bearbeiten. Ganze Sammeländerungen nehmen Sie über „Letzte Sammeländerungen“ zurück. Siehe
[Anwenderhandbuch 4.1](USER_GUIDE.de.md#41-die-tabellenansicht).
