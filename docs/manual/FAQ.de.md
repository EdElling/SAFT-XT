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

Häkchen zum Auswählen mehrerer Datensätze erscheinen nur, wenn es dafür eine Sammelaktion gibt, die Sie nutzen dürfen –
heute **„Dokumente erstellen“**: Die Tabelle braucht eine aktive Dokumentvorlage, und Ihre Rolle das Recht *Dokumente:
erzeugen* (Anwenderhandbuch, Abschnitt 4.10). Gelöschte Datensätze im Papierkorb lassen sich nicht auswählen.
