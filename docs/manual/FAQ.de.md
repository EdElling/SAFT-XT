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

---
## Häufige Fragen

### Ich sehe eine Tabelle nicht im Menü.

Sie haben kein Leserecht dafür, es gibt keinen Menüeintrag, oder Ihre Rolle blendet den Eintrag aus. Wenden Sie sich
an die Administration (Rollen → Datenrechte bzw. Menüeinträge, oder Menüs).

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
