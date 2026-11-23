# Konzept: Datensätze auswählen und Sammeländerung in SAFT XT

Stand: 23.11.2026 · Status: **erster Vorschlag zur Diskussion** · Backlog: B-133 (ersetzt B-024)

## 1. Ziel

In jeder Datentabelle mehrere Datensätze auswählen und **gemeinsam ändern**, ohne jeden einzeln zu öffnen. Beispiele
aus der Musikschule:

- Nach der Semester-Fortschreibung alle Anmeldungen von *Warteliste* auf *aktiv* setzen.
- Eine Lehrperson in allen Kursen des laufenden Semesters durch eine andere ersetzen (Filter Semester + Lehrperson).
- Bei allen Kursen eines Fachs den Tarif um 5 % erhöhen.

Sammeländerungen sind mächtig und deshalb gefährlich. Es gelten folgende Grundsätze:

- **Dieselben Prüfungen wie beim Einzelbearbeiten:** Pflicht, Feld- und Tabellenregeln, Plugin-Listener (z. B. die
  Konfliktprüfung der Musikschule), Regeln (B-142), Zeilenrechte (B-020), Änderungsprotokoll. Eine Sammeländerung ist
  kein Weg an den Regeln vorbei.
- **Vorher sehen, was passiert:** Anzahl, Beispiele vorher → nachher, Probelauf mit allen Prüfungen.
- **Alles oder nichts:** Scheitert ein Datensatz, wird nichts geändert.
- **Nachvollziehbar und rücknehmbar:** Jede Sammeländerung erhält eine Kennung im Protokoll und lässt sich zurücknehmen
  (Etappe S3).

## 2. Heute

| Baustein | Stand |
|---|---|
| Auswahl per Häkchen | **umgesetzt** mit B-124 D3a. Die Häkchen erscheinen, sobald eine Sammelaktion sichtbar ist; heute ist das nur „Dokumente erstellen“ (`DynamicRecordActions::documentBulk`, höchstens 50 je Lauf). Gelöschte Datensätze sind nicht auswählbar. |
| Sammelaktion Löschen | `DynamicRecordActions::deleteBulk()` ist vorhanden, steht aber in keiner Datentabelle in der Werkzeugleiste. |
| Detailtabellen (`DynamicDetailTable`) | keine Auswahl, keine Sammelaktionen |
| Filament | „Alle auswählen“ über alle Seiten (`trackDeselectedRecords`: Die Auswahl wird als Abfrage mit Ausnahmen geführt, nicht als lange Liste von Schlüsseln); Auswahl wird beim Ändern eines Filters aufgehoben (`deselectAllRecordsWhenFiltered`, Vorgabe an) |
| Änderungsprotokoll | spatie/laravel-activitylog, Spalte `properties` (JSON); keine Kennung für zusammengehörige Änderungen |
| Tabellen-Stammdaten | Schalter `export_enabled`, `import_enabled` zusätzlich zum Recht der Rolle |

## 3. Entscheidungen (Vorschlag)

| Frage | Vorschlag |
|---|---|
| Wirkt auf | **Ausgewählte Datensätze, geschnitten mit dem aktuellen Filter**, nur nicht gelöschte. Ausgewählte Zeilen, die ein geänderter Filter nicht mehr zeigt, bleiben unberührt. Filament hebt die Auswahl beim Filtern ohnehin auf; die Schnittmenge wird bei der Ausführung trotzdem noch einmal gebildet, als Sicherung. |
| Weg des Speicherns | **Je Datensatz derselbe Weg wie beim Bearbeiten:** `RecordChecker` mit dem bestehenden Datensatz, dann `save()` mit allen Ereignissen (Formeln, Summen im Master, Plugin-Listener, Regeln, Protokoll). Es gibt kein `UPDATE … WHERE` an den Regeln vorbei. |
| Transaktion | **Eine** für die ganze Sammeländerung, alles oder nichts, wie beim Import. Nutzt den gemeinsamen Baustein „Transaktion beim Speichern“ (Konzepte Regel-Engine 7.1, Nummernkreise 6.2, Zeilenrechte). |
| Obergrenze | einstellbar (`data.bulk_max`), Vorgabe **500** Datensätze je Sammeländerung, synchron. Grössere Mengen: in Teilen per Filter, oder später als Lauf im Hintergrund (Konzept Regel-Engine, Abschnitt 8). |
| Recht | Recht **ändern** der Tabelle, dazu der Schalter **„Sammeländerung erlauben“** in den Tabellen-Stammdaten (wie Export und Import, Vorgabe an). Mit Zeilenrechten (B-020) nur Datensätze, die man ändern darf. |
| Protokoll | Jede Änderung mit **Kennung der Sammeländerung** in `properties` (`bulk: <uuid>`) und eine Zeile in `bulk_changes` (Tabelle, Person, Felder, Anzahl, Zeitpunkt). |

## 4. Dialog „Ändern …“

Knopf **„Ändern …“** in der Werkzeugleiste, aktiv sobald Zeilen ausgewählt sind. Der Dialog zeigt oben „**N Datensätze
ausgewählt**“ und listet dann die änderbaren Felder. Je Feld:

- ein Häkchen **„ändern“**. Nur angehakte Felder werden angefasst.
- die **Art der Änderung**:

| Art | Für | Beispiel |
|---|---|---|
| **setzen** | alle änderbaren Typen; das Eingabefeld des Feldtyps (Auswahl, Datum, Verknüpfung mit Suche, Ja/Nein) | Status ← *aktiv*; Lehrperson ← *M. Keller* |
| **leeren** | Felder ohne Pflicht | Bemerkung ← leer |
| **rechnen** (S2) | Zahl: `+ − × ÷` und „um %“, mit Runden auf Stellen; Datum: ± Tage, Monate | Tarif × 1,05, gerundet auf 0,05; Fälligkeit + 30 Tage |
| **Formel** (S2) | alle; Formel in der vorhandenen Sprache, ausgewertet je Datensatz, mit dem bisherigen Wert und verknüpften Feldern | `round(tarif * 1.05 / 0.05, 0) * 0.05`, `kurs.lehrperson` |

**Nicht änderbar** und deshalb nicht angeboten:

- berechnete Felder, Dateien, Nummernkreise (B-135), Schlüssel
- **eindeutige** Felder: Ein Wert für viele Datensätze verletzt die Eindeutigkeit (leeren nur ohne Pflicht)
- Felder, die ein Plugin sperrt
- angebundene (externe) Tabellen: Sie sind nur lesbar, der Knopf fehlt dort ganz

„**Wert übernehmen aus**“ wirkt heute nur beim Anlegen. Wird eine Verknüpfung geändert, bleiben übernommene Werte
deshalb wie beim Einzelbearbeiten unverändert. Der Dialog weist darauf hin, wenn er ein Verknüpfungsfeld mit
abhängigen Übernahmen ändert („Einzelpreis wird nicht neu übernommen“).

## 5. Vorschau und Probelauf

Nach „Weiter“ zeigt eine zweite Seite des Dialogs:

1. **Wirkt auf:** „47 Datensätze werden geändert, 3 sind schon so (unverändert)“. Datensätze, bei denen sich nichts
   ändert, werden übersprungen. Das gibt kein Protokoll und keine Ereignisse.
2. **Beispiele:** die ersten 10 als Tabelle *Datensatz · Feld · vorher → nachher*. Bei „rechnen“ und „Formel“ ist das
   der einzige Weg, das Ergebnis zu sehen, bevor es gilt.
3. **Probelauf:** SAFT führt die ganze Änderung in einer Transaktion aus und rollt sie zurück: alle Prüfungen, Plugins
   und Regeln, aber ohne Benachrichtigungen, E-Mails und Webhooks (die gehen erst nach dem Commit hinaus,
   vgl. Regel-Engine 7.1). Ergebnis: „Alle 47 bestehen die Prüfungen“ oder eine Liste „Kurs *Klavier Mo 16:00*:
   Lehrperson hat zu dieser Zeit schon einen Kurs (Konfliktprüfung)“. Mit Fehlern bleibt „Ändern“ gesperrt.
4. **Bestätigen:** „47 Datensätze ändern“. Erst jetzt wird die Änderung wirklich ausgeführt, mit derselben Prüfung noch
   einmal, weil sich die Daten inzwischen geändert haben könnten.

Bei mehr als 500 Datensätzen gibt es eine Meldung mit der Anzahl und dem Hinweis, den Filter einzugrenzen.

## 6. Ausführung

- **Reihenfolge:** nach Schlüssel. Je Datensatz Werte berechnen, prüfen, speichern.
- **Summen im Master** (`Recalculator::propagate`) laufen je Datensatz. Bei vielen Kind-Datensätzen desselben Masters
  ist das mehrfache Rechnen unnötig. Eine Bündelung („Master am Ende einmal nachrechnen“) ist eine Optimierung für
  später, falls es sich als langsam erweist.
- **Sperren:** Die Datensätze werden beim Lesen gesperrt (`lockForUpdate`), damit niemand sie zwischen Probelauf und
  Speichern ändert. Die Sperre gilt nur während der Ausführung.
- **Ergebnis:** Meldung „47 Datensätze geändert“ mit Link „Rückgängig“ (S3). Die Auswahl wird danach aufgehoben, und
  die Liste lädt neu.
- **Fehler trotz Probelauf** (gleichzeitige Änderung): alles zurückrollen und die Meldung mit dem Datensatz zeigen.

## 7. Protokoll und Rückgängig

**Protokoll (S1):** Jede Änderung erscheint im Änderungsprotokoll des Datensatzes wie eine Einzeländerung, mit dem
Zusatz „**Sammeländerung**“ und der Kennung in `properties.bulk`. Eine Tabelle `bulk_changes` hält je Sammeländerung
fest: Tabelle, Person, geänderte Felder und Art, Anzahl, Zeitpunkt, Filter als Text, `undone_at`.

**Rückgängig (S3):** In der Datenansicht gibt es unter „Letzte Sammeländerungen“ die eigenen, Admins sehen alle; dazu
der Link in der Erfolgsmeldung. „Rückgängig“ setzt die Werte aus dem Protokoll zurück:

- Es gelten dieselben Prüfungen, und alles läuft in einer Transaktion mit eigener Kennung. Die Rücknahme ist selbst
  wieder eine Sammeländerung.
- Datensätze, die **seit der Sammeländerung wieder geändert** wurden, werden **übersprungen** und gemeldet. Sonst
  würde eine spätere Änderung überschrieben.
- Folgen über Regeln (B-142), etwa ein angelegter Datensatz in einer anderen Tabelle, nimmt das Rückgängig **nicht**
  zurück. Der Dialog sagt das, wenn Regeln beteiligt waren.
- Möglich nur, solange das Protokoll die Einträge noch hat, und für die Person mit dem Recht *ändern*.

## 8. Weitere Sammelaktionen

Wenn die Auswahl einmal da ist, kommen weitere Aktionen in dieselbe Werkzeugleiste. Der Knopf „Ändern …“ steht dort
zuerst, die übrigen folgen in einem Menü:

| Aktion | Stand bzw. Vorschlag |
|---|---|
| Dokumente erstellen | vorhanden (B-124 D3a) |
| **Löschen** | `deleteBulk()` vorhanden, in die Werkzeugleiste aufnehmen: Löschsperre je Datensatz (`DeleteGuard`), in einer Transaktion, mit Anzahl in der Bestätigung (S1) |
| **Wiederherstellen** | im Papierkorb-Filter für ausgewählte Datensätze (S1) |
| **Auswahl exportieren** | Export heute: alles, was der Filter zeigt; neu wahlweise „nur ausgewählte“ (S4) |
| **Kopieren** | bewusst nicht: Bei Kopien mit Detail-Datensätzen wäre das schwer zu überblicken |
| **Sammelaktionen aus Plugins** | Plugin-API: `DataAction::BULK` neben `TABLE` und `RECORD` (Minor) (S4) |
| **Regeln mit Auslöser „manuell“** | erscheinen als Sammelaktion (Konzept Regel-Engine 4.1) |

**Detailtabellen** (Kurs → Anmeldungen) bekommen Auswahl und dieselben Sammelaktionen. Gerade dort liegen die typischen
Fälle, z. B. alle Anmeldungen eines Kurses auf *aktiv* setzen.

## 9. Zusammenspiel

| Bereich | Verhalten |
|---|---|
| Zeilenrechte (B-020) | Auswahl nur aus sichtbaren Datensätzen; geändert werden nur solche, die die Einschränkung *ändern* erfüllen, und danach weiter erfüllen (Konzept Zeilenrechte, Abschnitt 6) |
| Regeln (B-142) | laufen je Datensatz wie bei jeder Änderung, in derselben Transaktion. Die Höchstzahl von Ketten gilt je Datensatz. |
| Nummernkreise (B-135) | nicht änderbar. Bei „Vergabe bei Bedingung“ (N3) kann eine Sammeländerung des Status Nummern auslösen, in Schlüsselreihenfolge. |
| Plugin-API | `DataTable::updateMany(iterable $records, array $values)` als Baustein derselben Logik (Prüfungen, Transaktion, Protokoll-Kennung), z. B. für die Semester-Fortschreibung der Musikschule (S4) |
| KI (B-051 Stufe 3) | später: „Setze alle Anmeldungen der Warteliste im HS 2026 auf aktiv“ ergibt Filter und Dialog vorausgefüllt; die Person bestätigt |
| Handbücher | Anwender-, Admin- und FAQ-Handbuch in vier Sprachen |

## 10. Etappen

| Etappe | Inhalt | Umfang |
|---|---|---|
| **S1 Sammeländerung** | Dialog „Ändern …“ mit *setzen* und *leeren*, Schnitt mit dem Filter, Vorschau mit Beispielen, Probelauf, Ausführung in einer Transaktion mit Sperre, Obergrenze, Schalter „Sammeländerung erlauben“, Protokoll-Kennung und `bulk_changes`; Löschen und Wiederherstellen in die Werkzeugleiste; Auswahl in Detailtabellen | mittel |
| **S2 Rechnen und Formel** | Arten *rechnen* (Zahl, Datum) und *Formel* mit Vorschau | klein |
| **S3 Rückgängig** | Liste „Letzte Sammeländerungen“, Rücknahme mit Überspringen später geänderter Datensätze | mittel |
| **S4 Erweiterungen** | Auswahl exportieren, Sammelaktionen aus Plugins (`DataAction::BULK`), `DataTable::updateMany()` | klein |

S1 lässt sich vor dem gemeinsamen Baustein „Transaktion beim Speichern“ umsetzen, weil die Sammeländerung ihre
Transaktion selbst öffnet. Der Baustein sollte aber spätestens dann entstehen, damit nicht zwei Varianten wachsen.

## 11. Offene Fragen zur Diskussion

1. **Obergrenze:** Reichen 500 synchron? Oder grössere Mengen gleich im Hintergrund (braucht Queue-Worker, vgl. B-117)?
2. **Eigenes Recht** „Sammeländerung“ je Rolle zusätzlich zum Schalter in den Tabellen-Stammdaten? Vorschlag: nein,
   Schalter + Recht *ändern* genügen.
3. **Teilweise ausführen:** Sollen Datensätze, die die Prüfung nicht bestehen, übersprungen werden können („44 ändern,
   3 überspringen“) statt alles oder nichts? Vorschlag: erst einmal nicht; der Probelauf zeigt die drei, man wählt sie
   ab.
4. **„Wert übernehmen aus“** bei geänderter Verknüpfung neu übernehmen (Häkchen im Dialog)? Das gälte dann auch fürs
   Einzelbearbeiten und ist eher ein eigenes Thema.
5. **Rückgängig für alle oder nur die eigenen** Sammeländerungen? Vorschlag: eigene für alle mit *ändern*, fremde nur
   mit dem Recht auf das ganze Protokoll.
