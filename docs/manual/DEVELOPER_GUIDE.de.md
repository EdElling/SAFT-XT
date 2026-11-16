# SAFT XT – Entwicklerhandbuch: Plugins

[English](DEVELOPER_GUIDE.md) · **Deutsch**

**Stand:** Version 0.1 · **Zielgruppe:** Programmiererinnen und Programmierer, die SAFT um eigene Seiten und Funktionen erweitern

**Weitere Handbücher:** [Administratorhandbuch](ADMIN_GUIDE.de.md) · [Systemadministration](SYSADMIN_GUIDE.de.md) · [Installation auf einem Entwickler-Rechner](INSTALL_DEVELOPMENT.de.md)

---

## Inhalt

- [1. Überblick](#1-überblick)
- [2. Erste Schritte](#2-erste-schritte)
- [3. Aufbau eines Plugins](#3-aufbau-eines-plugins)
- [4. Die Plugin-Klasse](#4-die-plugin-klasse)
- [5. Seiten, Ressourcen und Widgets](#5-seiten-ressourcen-und-widgets)
- [6. Rechte und Menü](#6-rechte-und-menü)
- [7. Übersetzungen, Views und eigene Tabellen](#7-übersetzungen-views-und-eigene-tabellen)
- [8. Auf Daten zugreifen](#8-auf-daten-zugreifen)
- [9. Auf Änderungen reagieren (Ereignisse)](#9-auf-änderungen-reagieren-ereignisse)
- [10. Aktionen in Datenansichten](#10-aktionen-in-datenansichten)
- [11. Formelfunktionen und Prüfregeln](#11-formelfunktionen-und-prüfregeln)
- [12. Installieren, aktualisieren, entfernen](#12-installieren-aktualisieren-entfernen)
- [13. Testen](#13-testen)
- [14. Kontext für externe Entwicklung und KI-Agenten](#14-kontext-für-externe-entwicklung-und-ki-agenten)
- [15. Sicherheit](#15-sicherheit)
- [16. Beispiele: Lager-Umbuchung, Lager-Tafel und CH-Prüfungen](#16-beispiele-lager-umbuchung-lager-tafel-und-ch-prüfungen)
- [17. API-Versionen und Änderungsprotokoll](#17-api-versionen-und-änderungsprotokoll)
- [Anhang A – Schritt-für-Schritt-Anleitungen](#anhang-a--schritt-für-schritt-anleitungen)

---

## 1. Überblick

SAFT deckt die häufigen Aufgaben einer Fachanwendung über Konfiguration ab: Tabellen im Table-Designer, Rechte,
Menüs, Auswertungen, Import und Export. Was darüber hinausgeht – eine spezialisierte Erfassungsmaske, ein Ablauf über
mehrere Tabellen, Geschäftsregeln beim Speichern – kommt als **Plugin** dazu, ohne den Kern von SAFT zu verändern.
Updates von SAFT bleiben dadurch konfliktfrei.

Ein Plugin ist ein gewöhnliches **Composer-Paket** mit einer Plugin-Klasse und einem ServiceProvider. Es kann
mitbringen:

| Was | Wie |
|---|---|
| Seiten, Ressourcen, Widgets | Filament-Klassen unter `src/Pages`, `src/Resources`, `src/Widgets` – werden automatisch gefunden |
| Rechte | in der Plugin-Klasse; SAFT legt sie nach jedem `migrate` an, sie erscheinen in der Rollenverwaltung |
| Menüeinträge | als Vorschläge; die Administration übernimmt sie auf der Seite **Plugins** mit einem Klick |
| Zugriff auf Tabellen des Table-Designers | `Saft::table(…)` – mit Rechten, Prüfregeln und Änderungsprotokoll |
| Geschäftsregeln beim Speichern und Löschen | Listener für Ereignisse; sie können Änderungen ablehnen |
| Knöpfe in Datenansichten | über der Tabelle oder je Zeile |
| Kacheln fürs Dashboard | Widgets unter `src/Widgets`, mit Bezeichnung über `DashboardWidget` |
| Formelfunktionen | zusätzliche Funktionen für berechnete Felder, z. B. `rappen(betrag)` |
| Prüfregeln für Felder | im Table-Designer unter „Weitere Prüfungen“ wählbar, z. B. IBAN |
| Übersetzungen, Views | unter `lang/` und `resources/views/`, im Namensraum der Plugin-id |
| Eigene Tabellen | Migrationen unter `database/migrations/` |
| Arten geplanter Aufgaben | Klassen, die `App\Tasks\TaskType` umsetzen |

**Was zugesichert ist:** Die Programmierschnittstelle für Plugins (Plugin-API) hat eine **eigene Versionsnummer**,
unabhängig von SAFT – derzeit **1.3**. Zugesichert sind die Klassen `App\Plugins\SaftPlugin`,
`App\Plugins\PluginServiceProvider`, `App\Plugins\MenuSuggestion`, alle Klassen unter `App\Plugins\Api` (ausser
`ApiManifest`), die Test-Basis `App\Plugins\Testing\PluginTestCase` und das Trait
`App\Filament\Concerns\LimitsFormWidth`; von `App\Models\DynamicRecord` (Datensätze) und
`App\Models\User` nur die Klasse als Typ mit ihrem Eloquent-Verhalten. Innerhalb einer API-Major-Version bleibt alles
kompatibel. Alle übrigen Klassen von SAFT sind intern – Plugins verwenden sie nicht (ein Test prüft das). Einzelheiten:
[Kapitel 17](#17-api-versionen-und-änderungsprotokoll).

**Schritt für Schritt:** [Anhang A](#anhang-a--schritt-für-schritt-anleitungen) führt für die häufigsten Aufgaben –
eigene Tabellensicht, eigenes Formular, Widget, Knopf in einer Datenansicht, Listener, Prüfregel, Menüvorschlag – von
der leeren Datei bis zum Test, am Beispiel-Plugin *Lager-Tafel*.

## 2. Erste Schritte

**Voraussetzungen:** eine Entwicklungsinstallation von SAFT (Git-Klon mit `composer install` inklusive Dev-Paketen,
siehe [Installation auf einem Entwickler-Rechner](INSTALL_DEVELOPMENT.de.md)) mit Testdatenbank, dazu Kenntnisse in
Laravel, Filament und Livewire. Dieses Handbuch beschreibt nur, was SAFT dazu beiträgt.

**1. Gerüst erzeugen** – im Hauptverzeichnis von SAFT:

```bash
php artisan saft:make-plugin kunden-portal --label="Kunden-Portal" --vendor=acme --namespace="Acme\KundenPortal"
```

Das legt `plugins/kunden-portal/` an: `composer.json` mit der aktuellen Plugin-API, Plugin-Klasse, ServiceProvider, eine
Startseite mit Recht und Menüvorschlag, Übersetzungen (Deutsch, Englisch), einen Test und eine `README.md`. Ohne
Optionen sind Bezeichnung, Hersteller (`saft`) und Namensraum aus der Kennung abgeleitet.

**2. Installieren und ausprobieren:**

```bash
composer require acme/kunden-portal               # bindet plugins/kunden-portal ein (symbolischer Link)
php artisan saft:plugins                          # legt die Rechte an
```

Danach unter **Administration › Plugins** den Menüvorschlag übernehmen und das Recht *Kunden-Portal verwenden* einer
Rolle geben (Super-Admins haben es ohnehin). Änderungen am Code wirken sofort, ein erneutes `composer require` ist nur
nach Änderungen an der `composer.json` des Plugins nötig.

**3. Testen und prüfen:**

```bash
vendor/bin/phpunit --testsuite Plugins --filter KundenPortalTest
php artisan saft:plugins --check kunden-portal
```

Die Prüfung meldet ungültige Angaben, fehlende Routen und Rechte, nicht erfüllte Abhängigkeiten und Klassen von SAFT, die
nicht zur Plugin-API gehören. Bei Fehlern endet sie mit einem Exit-Code ungleich 0 und eignet sich so auch für die CI
des Plugins.

**4. Eigenes Repository:** Der Ordner `plugins/` ist im Repository von SAFT ignoriert (ausser den Beispiel-Plugins).
Führen Sie das Plugin in einem eigenen Git-Repository:

```bash
cd plugins/kunden-portal && git init && git add . && git commit -m "Erste Fassung"
```

`composer require` trägt das Plugin in die `composer.json` und `composer.lock` von SAFT ein. In Ihrer
Entwicklungsinstallation ist das gewollt – committen Sie diese beiden Änderungen aber nicht ins Repository von SAFT.
Zum Ausliefern siehe [Kapitel 12](#12-installieren-aktualisieren-entfernen).

## 3. Aufbau eines Plugins

```
plugins/lager-umbuchung/
  composer.json
  src/
    LagerUmbuchungPlugin.php            Plugin-Klasse
    LagerUmbuchungServiceProvider.php   meldet das Plugin an
    Pages/Umbuchung.php                 Filament-Seite
  lang/{de,en,fr,it}/umbuchung.php      Übersetzungen
  tests/LagerUmbuchungTest.php          Tests (Testsuite „Plugins“)
  resources/views/                      (optional)
  database/migrations/                  (optional)
```

Die `composer.json` nennt den ServiceProvider (Laravel findet ihn automatisch) und die benötigte Version der Plugin-API:

```json
{
    "name": "saft/lager-umbuchung",
    "description": "Beispiel-Plugin für SAFT: Umbuchung zwischen Lagerorten",
    "type": "library",
    "version": "1.2.0",
    "require": { "php": "^8.4", "filament/filament": "^5.0" },
    "autoload": { "psr-4": { "Saft\\LagerUmbuchung\\": "src/" } },
    "autoload-dev": { "psr-4": { "Saft\\LagerUmbuchung\\Tests\\": "tests/" } },
    "extra": {
        "laravel": { "providers": ["Saft\\LagerUmbuchung\\LagerUmbuchungServiceProvider"] },
        "saft": { "api": "^1.0" }
    }
}
```

- `extra.saft.api` ist eine Composer-Versionsbedingung für die Plugin-API (siehe [Kapitel 17](#17-api-versionen-und-änderungsprotokoll)).
  Die Seite **Plugins** zeigt danach, ob das Plugin zu dieser Installation passt. Nur wenn ein Plugin auf ein Verhalten
  einer bestimmten SAFT-Version angewiesen ist, geben Sie zusätzlich `"requires"` mit der SAFT-Version an.
- `filament/filament` in `require`: Seiten, Ressourcen und Widgets sind Filament-Klassen. Mit der Hauptversion von
  Filament verhindert Composer, dass das Plugin auf einem SAFT mit einer anderen Filament-Hauptversion installiert wird –
  das deckt die Plugin-API nicht ab.
- `version` erscheint auf der Seite **Plugins**; bei Paketen aus einem Git-Repository kommt sie sonst aus dem Tag.
- `autoload-dev` nennt den Namensraum der Tests; SAFT lädt ihn beim Testen (siehe [Kapitel 13](#13-testen)).
- Pakete von SAFT selbst müssen Sie nicht in `require` aufnehmen – das Plugin läuft innerhalb von SAFT. **Andere
  Plugins**, deren Klassen Sie verwenden, gehören dagegen hinein, z. B. `"saft/lager-umbuchung": "^1.3"` im Plugin
  Lager-Tafel.

Der ServiceProvider besteht aus einer Zeile:

```php
final class LagerUmbuchungServiceProvider extends PluginServiceProvider
{
    protected function plugin(): string
    {
        return LagerUmbuchungPlugin::class;
    }
}
```

## 4. Die Plugin-Klasse

```php
final class LagerUmbuchungPlugin extends SaftPlugin
{
    public const string ID = 'lager-umbuchung';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('lager-umbuchung::umbuchung.plugin');
    }

    public function permissions(): array
    {
        return ['execute' => __('lager-umbuchung::umbuchung.permission')];
    }

    public function menuSuggestions(): array
    {
        return [new MenuSuggestion(
            route: Umbuchung::ROUTE,
            label: ['de' => 'Umbuchung', 'en' => 'Transfer', 'fr' => 'Transfert', 'it' => 'Trasferimento'],
            icon: 'heroicon-o-arrows-right-left',
            permission: $this->permissionName('execute'),
            group: ['de' => 'Lager (Demo)', 'en' => 'Warehouse (demo)', 'fr' => 'Entrepôt (démo)', 'it' => 'Magazzino (demo)'],
        )];
    }
}
```

| Methode | Pflicht | Bedeutung |
|---|---|---|
| `id()` | ja | Eindeutige Kennung aus Kleinbuchstaben, Ziffern und Bindestrichen, z. B. `lager-umbuchung`. Präfix für Rechte, Übersetzungen und Views; nach der ersten Installation nicht mehr ändern |
| `label()` | ja | Name in der aktuellen Sprache |
| `description()` | nein | Kurzbeschreibung; ohne Angabe aus der `composer.json` |
| `permissions()` | nein | Aktion => Bezeichnung; der Name des Rechts ist `<id>.<aktion>` (`permissionName('execute')`) |
| `menuSuggestions()` | nein | Liste von `MenuSuggestion`, siehe [Kapitel 6](#6-rechte-und-menü) |
| `taskTypes()` | nein | Klassen zusätzlicher Arten geplanter Aufgaben |
| `requiredTables()` | nein | Tabellen und Felder des Table-Designers, die das Plugin braucht, siehe [Kapitel 8](#8-auf-daten-zugreifen) |
| `recordListeners()` | nein | Listener für Änderungen an Tabellen, siehe [Kapitel 9](#9-auf-änderungen-reagieren-ereignisse) |
| `dataActions()` | nein | Knöpfe in Datenansichten, siehe [Kapitel 10](#10-aktionen-in-datenansichten) |
| `formulaFunctions()` | nein | Formelfunktionen für berechnete Felder, siehe [Kapitel 11](#11-formelfunktionen-und-prüfregeln) |
| `fieldRules()` | nein | Prüfregeln für Felder, siehe [Kapitel 11](#11-formelfunktionen-und-prüfregeln) |
| `register(Panel)` | nein | Standard: Seiten, Ressourcen und Widgets aus den Unterordnern; überschreiben Sie die Methode nur für Sonderfälle und rufen Sie dann `parent::register($panel)` auf |

## 5. Seiten, Ressourcen und Widgets

Seiten, Ressourcen und Widgets sind gewöhnliche Filament-Klassen (Filament 5). Drei Regeln gelten für Plugins:

1. **Adresse mit der id beginnen**, damit sie mit SAFT und anderen Plugins nicht kollidiert:
   `protected static ?string $slug = 'lager-umbuchung';` (Route `filament.admin.pages.lager-umbuchung`) bzw.
   `lager-umbuchung/umbuchungen` für weitere Seiten.
2. **Nicht selbst in die Navigation eintragen:** `protected static bool $shouldRegisterNavigation = false;`. Das Menü von
   SAFT kommt aus der Datenbank; Ihre Seite gelangt über einen Menüvorschlag dorthin.
3. **Zugriff prüfen:** `canAccess()` fragt das Recht des Plugins ab, z. B.
   `auth()->user()?->can('lager-umbuchung.execute') ?? false`. Prüfen Sie Rechte zusätzlich in jeder Aktion, die etwas
   ändert – Livewire-Aufrufe lassen sich auch ohne die Oberfläche auslösen.

Für Formularseiten gibt es das Trait `App\Filament\Concerns\LimitsFormWidth` (1280 px, linksbündig, wie die
Formulare von SAFT). Meldungen zeigen Sie mit `Filament\Notifications\Notification` an.

Eigene Views gestalten Sie mit Filament-Komponenten und einem `<style>`-Block: Tailwind-Klassen aus Views eines Plugins
sind im CSS von SAFT nicht enthalten. Schritt für Schritt: eigene Tabellensicht in
[Anhang A.1](#a1-eigene-tabellensicht), eigenes Formular mit Drag and Drop in [Anhang A.2](#a2-eigenes-bearbeiten-formular).

### Widgets

Ein Widget unter `src/Widgets` erscheint als Kachel auf dem **Dashboard** – neben den Kacheln von SAFT, verschieb- und
ausblendbar. Damit die Kachel beim Anpassen des Dashboards eine Bezeichnung hat, setzt das Widget
`App\Plugins\Api\DashboardWidget` um:

```php
final class MinimumStockWidget extends StatsOverviewWidget implements DashboardWidget
{
    protected int|string|array $columnSpan = 1;   // halbe Breite; 'full' = breiter Bereich unten

    public static function dashboardLabel(): string
    {
        return __('lager-umbuchung::umbuchung.widget.label');
    }

    public static function canView(): bool
    {
        return auth()->user()?->can('lager-umbuchung.execute') ?? false;
    }

    protected function getStats(): array { /* Daten über Saft::table(…) */ }
}
```

- **Wer die Kachel sieht**, entscheidet `canView()`: das Recht des Plugins, bei Bedarf die Datenrechte
  (`Saft::table('…')->can('view')`) und ob die nötigen Tabellen da sind. Lesen Sie Daten mit Rechteprüfung.
- **Breite:** `$columnSpan = 1` für eine Spalte, `'full'` für den breiten Bereich.
- **Nur auf einer eigenen Seite statt auf dem Dashboard:** `protected static bool $isDiscovered = false;` im Widget
  und auf der Seite `getHeaderWidgets()` bzw. `getFooterWidgets()`.
- Schritt für Schritt mit einem Diagramm: [Anhang A.3](#a3-widget).


## 6. Rechte und Menü

**Rechte** heissen `<id>.<aktion>`. SAFT legt sie nach jedem `php artisan migrate` und mit `php artisan saft:plugins`
an und löscht sie nie automatisch. In der Rollenverwaltung erscheinen sie als „<Name des Plugins>: <Bezeichnung>“.
Rollen erhalten sie dort wie jedes andere Recht; der Super-Admin hat sie automatisch.

Brauchen Sie zusätzlich **Datenrechte** auf Tabellen des Table-Designers (z. B. „Bestände: bearbeiten“), prüfen Sie diese
in Ihrem Code (siehe [Kapitel 8](#8-auf-daten-zugreifen)) und nennen Sie sie in der Beschreibung des Plugins,
damit die Administration sie mit vergibt.

**Menüvorschläge** (`App\Plugins\MenuSuggestion`):

| Parameter | Bedeutung |
|---|---|
| `route` | Name der Route der Seite |
| `label` | Bezeichnung je Sprache (`de`, `en`, `fr`, `it`) |
| `icon` | Heroicon, z. B. `heroicon-o-arrows-right-left` |
| `permission` | Recht, das den Eintrag sichtbar macht (vollständiger Name) |
| `group` | Menügruppe je Sprache; gefunden über die deutsche Bezeichnung, sonst neu angelegt; `null` = oberste Ebene |
| `sortOrder` | Reihenfolge innerhalb der Gruppe |

Übernommen werden die Vorschläge erst, wenn die Administration auf der Seite **Plugins** „Menüeinträge anlegen“
wählt. Danach sind es gewöhnliche Menüeinträge, die sich verschieben und umbenennen lassen. Schritt für Schritt:
[Anhang A.7](#a7-menüvorschläge).

## 7. Übersetzungen, Views und eigene Tabellen

- **Übersetzungen** liegen unter `lang/<sprache>/<datei>.php` und heissen `__('<id>::<datei>.<schlüssel>')`. Liefern
  Sie Deutsch und Englisch; Französisch und Italienisch fallen sonst auf Englisch zurück. Deutsche Texte in Schweizer
  Rechtschreibung (Doppel-s statt Eszett), wie im Rest von SAFT.
- **Views** unter `resources/views/` heissen `view('<id>::<name>')`.
- **Eigene Tabellen** legen Sie mit Migrationen unter `database/migrations/` an; sie laufen mit `php artisan migrate`.
  Es gelten die Regeln von SAFT:
  - Kein dialektspezifisches SQL – nur Schema-Builder, Eloquent und Query Builder (SAFT läuft auf PostgreSQL, MySQL
    und MariaDB).
  - Models nach dem Base-Model-Muster: Schema-Definition in `src/Models/Base/`, Logik in `src/Models/`.
  - Jedes geschäftsrelevante Model protokolliert Änderungen mit `Spatie\Activitylog\Traits\LogsActivity` und
    `logOnlyDirty()`; die Person, die etwas ändert, wird automatisch als Verursacher eingetragen.
  - Tabellennamen mit einem Präfix aus der id, z. B. `lager_umbuchung_…`.

## 8. Auf Daten zugreifen

Tabellen aus dem Table-Designer haben kein festes Model. Plugins greifen über `App\Plugins\Api\Saft` darauf zu:

```php
use App\Plugins\Api\Saft;

$bestaende = Saft::table('lager_bestaende');                    // InvalidArgumentException, wenn es sie nicht gibt
$bestaende->query()->where('artikel_id', $id)->get();           // Eloquent-Abfrage (ohne Papierkorb)
$bestaende->find($id);
$bewegung = Saft::table('lager_bewegungen')->create(['artikel_id' => $id, 'art' => 'Eingang', 'menge' => 5]);
Saft::table('lager_bewegungen')->update($bewegung, ['menge' => 4]);
Saft::table('lager_bewegungen')->delete($bewegung);

Saft::transaction(function (): void { /* mehrere Änderungen: alles oder nichts */ });
```

| Methode | Bedeutung |
|---|---|
| `Saft::hasTable($name)` | Gibt es die Tabelle? Prüfen Sie das, bevor Sie eine Seite anzeigen, und zeigen Sie sonst einen Hinweis |
| `Saft::table($name)` | Tabelle des Table-Designers (lesend auch angebundene Tabellen) |
| `->label()`, `->name()`, `->fieldNames()` | Bezeichnung, interner Name, technische Namen der aktiven Felder |
| `->query()`, `->find($id)` | Lesen; Datensätze im Papierkorb sind ausgenommen |
| `->create($werte)`, `->update($datensatz, $werte)`, `->delete($datensatz)` | Schreiben; `$datensatz` als Model oder Schlüssel |
| `->validate($werte, $vorhanden)` | Prüfen ohne zu speichern |
| `->can('update')`, `->authorize('update')` | Datenrechte der angemeldeten Person (`view`, `create`, `update`, `delete`, `export`, `import`) |
| `->formFields(only: [...], except: [...])` | Formularfelder aus dem Table-Designer für eigene Masken |
| `->withoutAuthorization()` | Kopie ohne Rechteprüfung, für Hintergrundarbeit |
| `Saft::transaction(fn …)` | Mehrere Änderungen als Einheit |
| `Saft::notify($person, $meldung)` | Benachrichtigung in die Glocke (siehe unten) |

**Rechte:** Jede Methode prüft die Datenrechte der angemeldeten Person und wirft sonst eine
`Illuminate\Auth\Access\AuthorizationException`, die SAFT als Meldung anzeigt. Ohne angemeldete Person (Konsole,
geplante Aufgabe) oder für bewusst systemseitige Buchungen schalten Sie die Prüfung mit `withoutAuthorization()` ab –
nur dort, wo es fachlich gewollt ist.

**Prüfregeln:** `create` und `update` prüfen dieselben Regeln wie die Erfassung in SAFT: Pflichtfelder, Länge,
Minimum und Maximum, E-Mail und URL, eindeutige Werte, gültige Werte von Auswahllisten, vorhandene verknüpfte
Datensätze und die Regeln der Tabelle. Verstösse werfen eine `Illuminate\Validation\ValidationException` mit Meldungen
je Feldname („Menge: Wert ist kleiner als 0.“). Eine Möglichkeit, die Regeln zu umgehen, gibt es bewusst nicht: Muss ein
Plugin Werte schreiben, die die Regeln verbieten, sind die Regeln im Table-Designer anzupassen. Unbekannte und
berechnete Felder werden abgewiesen.

**Werte:** Verknüpfungen erwarten den Schlüssel des verknüpften Datensatzes, Auswahllisten den gespeicherten Wert
(nicht die Bezeichnung), Datum und Zeit einen Text wie `2026-11-15` bzw. `2026-11-15 10:30:00` oder ein Datumsobjekt.
Berechnete Felder, fortlaufende Nummern und „Übernehmen aus“ setzt SAFT selbst; alle Änderungen landen im
Änderungsprotokoll.

**Angebundene Tabellen** (Table-Connector) sind über die Schnittstelle nur lesbar.

**Benötigte Tabellen und Felder angeben:** Spalten heissen wie die technischen Namen im Table-Designer – benennt die
Administration ein Feld um oder löscht es, funktioniert das Plugin nicht mehr. Geben Sie deshalb in der Plugin-Klasse
an, was es braucht:

```php
public function requiredTables(): array
{
    return [
        'lager_bestaende' => ['artikel_id', 'lagerort_id', 'menge'],
        'lager_orte' => ['bezeichnung'],
    ];
}
```

- Solange das Plugin installiert ist, lassen sich diese Tabellen und Felder im Table-Designer nicht löschen,
  deaktivieren oder umbenennen, und der Typ der Felder ist fest. Die Feldliste zeigt sie mit einem Puzzle-Symbol.
- Fehlt etwas oder ist es deaktiviert (z. B. weil das Plugin vor der Lager-Demo installiert wurde), **ruht** das Plugin:
  Seine Listener und Datenaktionen wirken nicht, und die Seite **Plugins** warnt mit einer Liste dessen, was fehlt.
- Ihre Seiten prüfen das mit `$plugin->missingDependencies()` (Liste lesbarer Meldungen, leer = alles da) und zeigen
  sonst einen Hinweis statt eines Fehlers.
- Geben Sie nur an, ohne was das Plugin nicht arbeiten kann. Optionale Felder (im Beispiel *Mindestbestand*) prüfen Sie
  beim Zugriff selbst – fehlen sie, entfällt nur die jeweilige Funktion.

**Formularfelder für eigene Masken:** `formFields()` liefert Filament-Felder mit Bezeichnung, Prüfregeln,
Auswahllisten und Verknüpfungsauswahl, wie sie der Table-Designer festlegt. Der Name jedes Felds ist der technische
Name, die Felder lassen sich wie andere Filament-Felder anpassen:

```php
...array_map(fn ($feld) => $feld->columnSpanFull(), Saft::table('lager_bewegungen')->formFields(only: ['bemerkung'])),
```

Fehler der Schnittstelle zeigen Sie in Masken als Meldung an, denn die Feldnamen müssen nicht zu Ihrem Formular passen:
`catch (ValidationException $e) { Notification::make()->danger()->body(implode("\n", Arr::flatten($e->errors())))->send(); }`.

**Benachrichtigungen in die Glocke** verschicken Sie mit `Saft::notify($person, Notification::make()->…)`. Filaments
eigenes `sendToDatabase()` stellt sie in eine Warteschlange, die ohne Queue-Worker nie abgearbeitet wird.

## 9. Auf Änderungen reagieren (Ereignisse)

Bei jeder Änderung an einem Datensatz einer Tabelle des Table-Designers – über die Datenansicht, den Import, die
Schnittstelle oder eine geplante Aufgabe – löst SAFT Ereignisse aus (`App\Plugins\Api\Events`):

| Ereignis | Wann | Typische Verwendung |
|---|---|---|
| `RecordSaving` | vor dem Speichern, nach den Vorgaben (fortlaufend, Übernehmen aus) und **vor** den berechneten Feldern | Werte ergänzen oder korrigieren, Änderung ablehnen |
| `RecordSaved` | nach dem Speichern | Folgebuchungen in anderen Tabellen, Benachrichtigungen |
| `RecordDeleting` | vor dem Löschen (auch in den Papierkorb) | Löschen ablehnen |
| `RecordDeleted` | nach dem Löschen | Folgebuchungen zurücknehmen |

Ein Plugin meldet seine Listener in der Plugin-Klasse an; sie laufen nur für die genannte Tabelle:

```php
public function recordListeners(): array
{
    return [
        new RecordListener('lager_bewegungen', RecordSaving::class, PruefeBestand::class),   // Klasse mit __invoke
        new RecordListener('lager_bestaende', RecordSaved::class, fn (RecordSaved $e) => …),  // oder Closure
    ];
}
```

Im Ereignis stehen `$event->table` (interner Name), `$event->record` (der Datensatz), `$event->value('feld')`,
`$event->previous('feld')` (Wert vor der Änderung) und bei `RecordSaving`/`RecordSaved` `$event->creating()` (neu
angelegt oder geändert).

- **Werte setzen** in `RecordSaving`: `$event->record->setAttribute('menge', -abs($menge))`. Berechnete Felder sehen den
  neuen Wert.
- **Ablehnen** in `RecordSaving` oder `RecordDeleting`: `throw new RecordRejected('Meldung für die Anzeige');`. SAFT
  speichert nichts und zeigt „Aktion nicht möglich“ mit Ihrer Meldung an; beim Import erscheint sie als Fehler der
  Zeile, und der ganze Import wird nicht ausgeführt.
- **Folgeänderungen** in `RecordSaved` und `RecordDeleted` schreiben Sie über `Saft::table(…)`. Sie laufen in derselben
  Transaktion, wenn die auslösende Änderung in einer Transaktion steht; lösen Sie selbst Ereignisse aus, achten Sie auf
  Endlosschleifen.
- **Demo-Daten:** Beim Einspielen der Demo-Daten (`db:seed --class=…DemoSeeder`) sind die Ereignisse für Plugins
  ausgeschaltet – Ihre Regeln wirken dort nicht.
- **Löschen verknüpfter Datensätze** lehnt SAFT selbst ab, solange Datensätze anderer Tabellen darauf verweisen –
  dafür braucht es keinen Listener.
- Auf der Seite **Plugins** sieht die Administration, an welchen Tabellen ein Plugin mithört.
- Schritt für Schritt, mit Werte setzen und Ablehnen: [Anhang A.5](#a5-listener).

## 10. Aktionen in Datenansichten

Knöpfe in der Datenansicht einer Tabelle (auch in Detailtabellen) meldet die Plugin-Klasse an:

```php
public function dataActions(): array
{
    return [
        new DataAction('lager_bestaende', DataAction::RECORD, fn (DataTable $table): Action => Action::make('lagerUmbuchung')
            ->label('Umbuchen')
            ->icon('heroicon-o-arrows-right-left')
            ->iconButton()
            ->visible(fn (): bool => Umbuchung::canAccess())
            ->disabled(fn (DynamicRecord $record): bool => (int) $record->getAttribute('menge') <= 0)
            ->url(fn (DynamicRecord $record): string => Umbuchung::getUrl(['artikel' => $record->getAttribute('artikel_id')]))),
    ];
}
```

- `DataAction::TABLE` setzt den Knopf über die Tabelle (neben „Neu“ und „Export“), `DataAction::RECORD` in jede Zeile
  (neben „Details“); dort erhalten Closures den Datensatz als `$record`.
- Die Aktion ist eine gewöhnliche Filament-Aktion: mit `url()` öffnet sie eine Seite, mit `action()` und `schema()` ein
  Dialogfenster. Prüfen Sie Sichtbarkeit und Rechte in der Aktion selbst.
- Zeilen ohne eigenen Schlüssel (z. B. Views) erhalten keine Zeilenaktionen.
- Schritt für Schritt: [Anhang A.4](#a4-aktion-in-einer-bestehenden-tabellensicht).

## 11. Formelfunktionen und Prüfregeln

Zwei Bausteine erweitern den Table-Designer, ohne dass Ihr Plugin eine eigene Seite braucht.

**Formelfunktionen** für berechnete Felder liefert `formulaFunctions()`:

```php
public function formulaFunctions(): array
{
    return [new FormulaFunction(
        name: 'rappen',
        minArguments: 1,
        maxArguments: 1,
        evaluate: static fn (array $args): string => SwissChecks::roundToFiveRappen($args[0]),
        description: __('ch-pruefungen::pruefungen.functions.rappen'),   // Tooltip in der Eingabehilfe
    )];
}
```

- Formeln rechnen mit Dezimalzahlen: Argumente und Ergebnis sind **Zahlen als Text** („12.35“); rechnen Sie exakt
  (`bcmath`), nicht mit `float`. `null` als Ergebnis bedeutet leer. Ist ein Argument leer, wird die Funktion nicht
  aufgerufen – das Ergebnis ist leer.
- **Name:** Kleinbuchstaben, Ziffern und `_`; eingebaute Funktionen (`round`, `sum` …) lassen sich nicht ersetzen. Gibt
  es den Namen in zwei Plugins, gilt das erste nach Kennung – `saft:plugins --check` meldet den Konflikt.
- Die Funktion erscheint in der Eingabehilfe des Formelfelds unter **Funktionen aus Plugins**. In den Ausdrücken des
  Auswertungs-Designers gibt es sie nicht (diese werden zu SQL); berechnete Felder, die sie verwenden, lassen sich aber
  normal auswerten, ihr Wert ist gespeichert.
- Wirft die Funktion eine Ausnahme, bleibt das Ergebnis leer und der Fehler steht im Log – das Speichern scheitert nicht.

**Prüfregeln** für Felder liefert `fieldRules()`:

```php
public function fieldRules(): array
{
    return [new FieldRule(
        name: 'iban',
        label: __('ch-pruefungen::pruefungen.rules.iban'),
        types: ['string'],                                    // string, text, integer, decimal
        check: static fn (mixed $value): ?string => SwissChecks::iban((string) $value)
            ? null : __('ch-pruefungen::pruefungen.invalid.iban'),
    )];
}
```

- Die Administration wählt die Regel im Table-Designer beim Feld unter **Weitere Prüfungen**; gespeichert wird sie als
  `<plugin-id>.<name>`, z. B. `ch-pruefungen.iban`. Name: Kleinbuchstaben, Ziffern und Bindestriche.
- `check` gibt die Fehlermeldung zurück oder `null`. Leere Werte werden nicht geprüft (dafür gibt es „Pflichtfeld“).
- Die Regel gilt bei der Erfassung, beim Import und über `Saft::table(…)`.
- Schritt für Schritt: [Anhang A.6](#a6-prüfregeln).

**Wenn das Plugin fehlt oder ruht:** Prüfregeln werden übersprungen, Formeln mit seinen Funktionen ergeben leer. Der
Table-Designer zeigt beim Feld „nicht verfügbar“, und `php artisan saft:plugins --check` listet die betroffenen Felder
unter **Table-Designer**.

## 12. Installieren, aktualisieren, entfernen

SAFT bindet alle Pakete unter `plugins/` als Composer-Repository ein. Installiert, aktualisiert und entfernt werden
Plugins von der Systemadministration auf der Konsole (siehe [Systemadministration, Kapitel 7](SYSADMIN_GUIDE.de.md#7-plugins)):

```bash
composer require saft/lager-umbuchung       # installieren (Ordner plugins/lager-umbuchung)
php artisan migrate --force                 # eigene Tabellen und Rechte
php artisan optimize && php artisan filament:optimize
```

Ein Plugin aus einem eigenen Git-Repository tragen Sie zusätzlich unter `repositories` in der `composer.json` von SAFT
ein (`{"type": "vcs", "url": "…"}`). Vor dem Ausliefern: `php artisan saft:plugins --check <id>` ohne Fehler, Version in
der `composer.json` bzw. als Git-Tag erhöht.

Nach der Installation zeigt **Administration › Plugins** das Plugin mit Version, Kompatibilität und Erweiterungen; dort
übernimmt die Administration die Menüeinträge und vergibt anschliessend die Rechte in der Rollenverwaltung.

**Entfernen:** Eigene Tabellen zuerst zurückrollen (`php artisan migrate:rollback --path=…` auf die Migrationen des
Plugins), dann `composer remove <paket>` und `php artisan optimize`. Menüeinträge des Plugins werden danach automatisch
ausgeblendet; zusammen mit den Rechten lassen sie sich auf der Seite **Plugins** unter **Aufräumen** löschen. Daten, die
das Plugin in Tabellen des Table-Designers geschrieben hat, bleiben erhalten; seine Listener und Aktionen wirken nicht
mehr.

## 13. Testen

Die Tests eines Plugins liegen in seinem Ordner `tests/` und laufen mit der Testsuite **Plugins** von SAFT – auf
PostgreSQL, MySQL und MariaDB wie die Tests von SAFT selbst. SAFT lädt dafür den Namensraum aus `autoload-dev` der
`composer.json` des Plugins; Tests von Plugins, die nicht installiert sind, werden übersprungen.

```bash
vendor/bin/phpunit --testsuite Plugins                         # alle Plugins
vendor/bin/phpunit --testsuite Plugins --filter KundenPortal   # nur eines
vendor/bin/phpunit -c phpunit.mysql.xml --testsuite Plugins    # auf MySQL (phpunit.mariadb.xml: MariaDB)
```

Testklassen erben von `App\Plugins\Testing\PluginTestCase` (Teil der Plugin-API). Jeder Test beginnt mit einer leeren
Testdatenbank samt Rollen, Rechten und Menü.

| Hilfe | Zweck |
|---|---|
| `$this->actingAsSuperAdmin()` | meldet eine Person mit allen Rechten an |
| `$this->actingWithPermissions(['kunden-portal.use', 'data.kunden.view'])` | meldet eine Person mit genau diesen Rechten an |
| `$this->createTable('kunden', 'Kunden', [...])` | legt eine Tabelle des Table-Designers an (Felder wie im Table-Designer) und gibt die `DataTable` zurück |
| `$this->withoutPluginEvents(fn () => …)` | Testdaten anlegen, ohne dass Listener reagieren – z. B. um eigene Regeln zu umgehen |
| `$this->createTablesFromSchema($pfad, ['kunden'])` | legt Tabellen aus der `schema.json` des Entwickler-Kontexts an, siehe [Kapitel 14](#14-kontext-für-externe-entwicklung-und-ki-agenten) |
| `$this->dataView('kunden')` | Datenansicht als Livewire-Test, z. B. für Knöpfe des Plugins (`TestAction::make('…')->table($record)`) |

```php
final class KundenPortalTest extends PluginTestCase
{
    public function test_start_page_needs_the_permission(): void
    {
        $this->actingWithPermissions([]);
        $this->get(StartPage::getUrl())->assertForbidden();

        $this->actingWithPermissions(['kunden-portal.use']);
        $this->get(StartPage::getUrl())->assertOk();
    }
}
```

Für Masken: `Livewire::test(MeineSeite::class)->fillForm([...])->call('aktion')->assertNotified()`. Listener eines
installierten Plugins sind in Tests aktiv.

Beispiel: `plugins/lager-umbuchung/tests/LagerUmbuchungTest.php`.

## 14. Kontext für externe Entwicklung und KI-Agenten

Wer ein Plugin für eine bestimmte Installation schreibt – eine externe Firma oder ein KI-Agent –, braucht die Regeln, die
Plugin-API und das Schema der Fachtabellen dieser Installation. SAFT erzeugt das als **Entwickler-Kontext** (Englisch,
weil Agenten und viele Entwickler damit am besten arbeiten):

| Datei | Inhalt |
|---|---|
| `AGENTS.md` | Einstieg: verbindliche Regeln, Umgebung (Versionen, Sprachen, Datenbanken), Befehle, wann eine Aufgabe fertig ist, installierte Plugins, verfügbare Formelfunktionen und Prüfregeln |
| `api-reference.md` | alle zugesicherten Klassen und Methoden mit Signaturen und Doc-Kommentaren – aus dem Code erzeugt, also immer passend zur Installation |
| `schema.md` | die Fachtabellen kompakt: Felder, Typen, Auswahlwerte, Verknüpfungen, Formeln, Prüfungen, Tabellenregeln |
| `schema.json` | dasselbe exakt (Format `saft-schema/1`), z. B. für `createTablesFromSchema()` |

Datensätze, Benutzer und Zugangsdaten externer Verbindungen sind nie enthalten; angebundene Tabellen erscheinen nur mit
ihren Feldern. Tabellen- und Feldnamen können trotzdem vertraulich sein.

**Erzeugen:**

```bash
php artisan saft:plugin-context --output=plugins/kunden-portal/context   # Ordner (Standard: storage/app/plugin-context)
php artisan saft:plugin-context --tables=kunden --tables=bestellungen    # nur diese Tabellen
php artisan saft:plugin-context --zip --output=kontext.zip               # als ZIP; --without-external ohne angebundene
```

In der Oberfläche: **Administration › Plugins › „Entwickler-Kontext herunterladen“** (Recht *Plugins: verwalten*) mit
Auswahl der Tabellen – so gibt die Administration einer Kundeninstallation den Kontext weiter, ohne Zugang zur
Datenbank zu gewähren. Der Download steht im Änderungsprotokoll.

**Einem KI-Agenten geben:** Das Gerüst (`saft:make-plugin`) legt eine `AGENTS.md` ins Plugin, die auf `context/` verweist;
Agenten wie Claude Code, Codex oder Cursor lesen sie selbst. Legen Sie den Kontext nach `plugins/<id>/context/` (in der
`.gitignore` des Plugins) und beginnen Sie die Aufgabe mit „Lies AGENTS.md“. Arbeitet der Agent in der
Entwicklungsinstallation, kann er den Kontext mit dem Befehl oben selbst auffrischen.

**Beispiel-Prompt** für einen Agenten, der im Ordner des Plugins (`plugins/kunden-portal`) arbeitet und den Kontext
unter `context/` vorfindet:

```text
Du entwickelst das SAFT-Plugin in diesem Ordner. Lies zuerst AGENTS.md und context/AGENTS.md
und halte dich an die dort genannten Regeln. Die Plugin-API steht in context/api-reference.md,
die Fachtabellen in context/schema.md.

Aufgabe: Erstelle eine Seite „Offene Bestellungen“, die für einen gewählten Kunden alle
Bestellungen mit Status „offen“ auflistet (Tabellen kunden und bestellungen) und mit einem
Knopf „Erledigt“ den Status auf „erledigt“ setzt.
- Zugriff nur mit dem Plugin-Recht kunden-portal.use; Daten nur über Saft::table(…).
- Biete einen Menüvorschlag an und gib die benötigten Tabellen und Felder in requiredTables() an.
- Texte auf Deutsch und Englisch.
- Schreibe Tests mit PluginTestCase; lege die Tabellen mit
  createTablesFromSchema(__DIR__.'/../context/schema.json', ['kunden', 'bestellungen']) an.

Fertig ist die Aufgabe, wenn
  vendor/bin/phpunit --testsuite Plugins --filter KundenPortal
grün ist und
  php artisan saft:plugins --check kunden-portal
keine Fehler meldet (beide Befehle im Hauptverzeichnis von SAFT). Fasse am Ende zusammen,
welche Dateien du geändert hast und was die Administration einrichten muss
(Menüvorschlag übernehmen, Rechte vergeben).
```

Bewährt hat sich: die Aufgabe fachlich beschreiben (welche Tabellen, was soll passieren), die Rechte nennen und die
Abnahmebefehle als Ende vorgeben. Ein Agent ohne Zugang zur Kundeninstallation arbeitet nur mit `context/`; die Tabellen
für seine Tests legt er mit `createTablesFromSchema()` an.

**Gegen das Schema einer anderen Installation testen:** `createTablesFromSchema()` legt die Tabellen aus der
`schema.json` im eigenen Entwicklungs-SAFT an (in Durchgängen, auch mit gegenseitigen Verknüpfungen und Formeln):

```php
public function test_orders_page(): void
{
    $this->createTablesFromSchema(__DIR__.'/../context/schema.json', ['kunden', 'bestellungen']);
    $this->actingWithPermissions(['kunden-portal.use', 'data.kunden.view', 'data.bestellungen.view']);
    // …
}
```

## 15. Sicherheit

Ein Plugin ist Programmcode, der mit allen Rechten von SAFT läuft. Die Administration sollte nur Plugins aus
vertrauenswürdiger Quelle installieren; prüfen Sie bei eigenen Plugins mindestens:

- Jede Seite hat `canAccess()`, jede ändernde Aktion prüft das Recht erneut.
- Zugriff auf Tabellen nur über `Saft::table(…)`; `withoutAuthorization()` nur, wo es fachlich gewollt ist, mit
  Kommentar.
- Eingaben werden validiert; keine Werte ungeprüft in Abfragen, Dateipfade oder Shell-Aufrufe.
- Kein Raw-SQL, keine Shell-Aufrufe, kein `eval`; Dateien nur unter `storage/`.
- Listener lehnen nur ab, was fachlich falsch ist – sie wirken in allen Datenansichten und beim Import.
- Zugangsdaten und Schlüssel nie im Code, sondern in der `.env` bzw. in verschlüsselten Einstellungen.

## 16. Beispiele: Lager-Umbuchung, Lager-Tafel und CH-Prüfungen

Drei Beispiel-Plugins liegen bei SAFT: *Lager-Umbuchung* zeigt eine Fachanwendung mit Maske, Geschäftsregeln und
Kachel, *Lager-Tafel* ist das Beispiel zu den Anleitungen in Anhang A, *CH-Prüfungen* zeigt Formelfunktionen und
Prüfregeln ohne eigene Seiten.

### 16.1 Lager-Umbuchung

Das Plugin `plugins/lager-umbuchung` ist mit SAFT ausgeliefert und in Entwicklungs- und Demo-Installationen aktiv (in
der Produktion mit `--no-dev` nicht). Es zeigt alle Bausteine an der Lager-Demo:

| Baustein | Datei | Was es tut |
|---|---|---|
| Fachmaske | `src/Pages/Umbuchung.php` | Artikel wählen; „Von Lagerort“ nur mit Bestand; Menge höchstens der Bestand; Bemerkung als Feld aus dem Table-Designer; Vorbelegung über `?artikel=…&von=…` |
| Ablauf | `src/StockTransfer.php` | schreibt zwei Lagerbewegungen der Art „Umbuchung“ (−/+) in einer Transaktion |
| Listener `StockLedger` | `src/StockLedger.php` | jede neue, geänderte, gelöschte oder wiederhergestellte Lagerbewegung bucht den Bestand am Lagerort; Ausgang immer negativ, Eingang immer positiv; würde ein Bestand negativ, wird abgelehnt; eine Inventur setzt die letzte Zählung |
| Listener `StockGuard` | `src/StockGuard.php` | Bestände sind nur über Lagerbewegungen änderbar – direktes Anlegen, Ändern, Löschen wird abgelehnt |
| Listener `MinimumStockAlert` | `src/MinimumStockAlert.php` | sinkt der Gesamtbestand eines Artikels unter seinen Mindestbestand, kommt eine Meldung in die Glocke (nicht bei Umbuchungen) |
| Zeilenaktion | `LagerUmbuchungPlugin::dataActions()` | Knopf „Umbuchen“ in jeder Zeile von *Bestände*; ohne Bestand ausgegraut |
| Abhängigkeiten | `LagerUmbuchungPlugin::requiredTables()` | vier Tabellen der Lager-Demo mit zehn Feldern; ohne sie zeigt die Maske, was fehlt |

**Rechte:** Die Seite braucht das Plugin-Recht *Lager-Umbuchung: Umbuchungen ausführen* und die Datenrechte
*anzeigen* auf Artikel, Lagerorte und Bestände sowie *anlegen* auf Lagerbewegungen. Die Bestände bucht der Listener
ohne Rechteprüfung nach – wer Bewegungen erfassen darf, braucht kein Recht zum Bearbeiten der Bestände.

So probieren Sie es aus: Lager-Demo einspielen (`php artisan db:seed --class=LagerDemoSeeder`), unter
**Administration › Plugins** „Menüeinträge anlegen“, dann **Lager (Demo) › Umbuchung** oder der Knopf „Umbuchen“ in
**Lager (Demo) › Bestände**. Eine Lagerbewegung „Ausgang“ zeigt die Buchung des Bestands und – unter dem
Mindestbestand – die Meldung in der Glocke. Die Kachel **Lager: Mindestbestand** (`src/Widgets/MinimumStockWidget.php`)
auf dem Dashboard zeigt die Artikel unter dem Mindestbestand.

### 16.2 Lager-Tafel

Das Plugin `plugins/lager-tafel` (nur in Entwicklungs- und Demo-Installationen) setzt jeden Baustein aus
[Anhang A](#anhang-a--schritt-für-schritt-anleitungen) einmal an der Lager-Demo um. Es braucht das Plugin
*Lager-Umbuchung*, über dessen Ablauf es bucht.

| Baustein | Datei | Was es tut |
|---|---|---|
| Tabellensicht | `src/Pages/Lagerspiegel.php` | je Artikel eine Zeile, je Lagerort eine Spalte; Suche, Filter „Nur unter Mindestbestand“ |
| Formular mit Drag and Drop | `src/Pages/Lagertafel.php`, `resources/views/lagertafel.blade.php` | Bestand auf einen anderen Lagerort ziehen, Menge im Dialog, Buchung über `StockTransfer` |
| Kachel | `src/Widgets/StockByLocationChart.php` | Balkendiagramm: Bestand je Lagerort |
| Knöpfe | `LagerTafelPlugin::dataActions()` | in der Datenansicht Artikel: „Lagerspiegel“ über der Tabelle, „Auf Lagertafel verteilen“ je Zeile |
| Listener | `src/Listeners/` | Artikelnummer in einer Schreibweise speichern; gesperrt, sobald es Lagerbewegungen gibt |
| Prüfregel | `src/ArticleNumber.php`, `LagerTafelPlugin::fieldRules()` | „Artikelnummer (Lager-Demo)“: ein Buchstabe, Bindestrich, vier Ziffern |
| Menüvorschläge | `LagerTafelPlugin::menuSuggestions()` | Lagerspiegel und Lagertafel in der Gruppe *Lager (Demo)* |

**Rechte:** *Lager-Tafel: Lagerspiegel anzeigen* für Lagerspiegel und Kachel, *Lager-Tafel: Auf der Lagertafel umbuchen*
für die Lagertafel; dazu die Datenrechte wie bei Lager-Umbuchung.

### 16.3 CH-Prüfungen

Das Plugin `plugins/ch-pruefungen` (ebenfalls nur in Entwicklungs- und Demo-Installationen) zeigt Formelfunktionen und
Prüfregeln ohne eigene Seiten – es braucht daher weder Rechte noch Menüvorschläge, und es verlangt keine Tabellen:

| Baustein | Datei | Was es tut |
|---|---|---|
| Prüfregeln | `ChPruefungenPlugin::fieldRules()` | AHV-Nummer (756.xxxx.xxxx.xx mit Prüfziffer), IBAN (Prüfsumme, CH/LI 21 Zeichen), UID (CHE-xxx.xxx.xxx mit Prüfziffer), Postleitzahl Schweiz |
| Formelfunktion | `ChPruefungenPlugin::formulaFunctions()` | `rappen(betrag)`: kaufmännisch auf 5 Rappen runden |
| Prüfziffern | `src/SwissChecks.php` | die Algorithmen, ohne Bezug zu SAFT – einfach zu testen |
| Übersetzungen | `lang/{de,en,fr,it}/pruefungen.php` | Bezeichnungen der Regeln, Fehlermeldungen, Beschreibung von `rappen()` |
| Tests | `tests/ChPruefungenTest.php` | Prüfziffern, Regeln über die Schnittstelle und in der Datenansicht, `rappen` im berechneten Feld |

So probieren Sie es aus: im Table-Designer bei einem Textfeld unter **Weitere Prüfungen** „IBAN“ wählen, oder ein
berechnetes Feld mit der Formel `rappen(betrag * 1.081)` anlegen.

## 17. API-Versionen und Änderungsprotokoll

Die Plugin-API hat eine eigene Versionsnummer **Major.Minor** (derzeit **1.3**). Sie steht in `config/saft.php`
(`api_versions`) und erscheint unter **Dokumentation › Über SAFT XT** sowie auf der Seite **Plugins**.

| Änderung | Version | Wirkung für Plugins |
|---|---|---|
| Neue Klassen, Methoden, Ereignisse, optionale Parameter | **Minor** (1.0 → 1.1) | keine – bestehende Plugins laufen weiter |
| Entfernen oder Ändern einer zugesicherten Methode, eines Ereignisses oder eines Verhaltens | **Major** (1 → 2) | Plugin anpassen |
| Interne Klassen von SAFT, neue SAFT-Versionen | keine | keine, solange die API-Version gleich bleibt |

**Regeln, an die sich SAFT hält:**

- Breaking Changes nur mit einer neuen API-Major-Version. Was wegfallen soll, wird vorher in einer Minor-Version als
  *veraltet* markiert (`@deprecated` im Code, Hinweis in diesem Kapitel).
- Erscheint API 2, liefert SAFT API 1 noch mindestens zwölf Monate bzw. bis zur nächsten SAFT-Hauptversion mit (beide
  Versionen stehen dann in `api_versions`). Plugins mit `"api": "^1.0"` gelten so lange als kompatibel.
- Die zugesicherten Klassen und ihre öffentlichen Methoden, Eigenschaften und Konstanten sowie die geschützten
  Methoden (für abgeleitete Klassen) sind in
  `tests/Feature/Plugins/plugin-api.json` festgehalten. Der Test `PluginApiContractTest` schlägt fehl, wenn sich daran
  etwas ändert, ohne dass die API-Version passend erhöht wurde, und wenn ein Plugin unter `plugins/` andere Klassen von
  SAFT verwendet.

**Für Plugin-Entwickler:** Geben Sie die niedrigste Minor-Version an, deren Funktionen Sie nutzen – z. B. `"api": "^1.1"`,
wenn Ihre Tests `PluginTestCase` verwenden – und testen Sie Ihr Plugin, wenn eine neue API-Major-Version erscheint,
nicht bei jedem SAFT-Release. Verwenden Sie nur die zugesicherten Klassen; `php artisan saft:plugins --check` prüft das.

### Änderungsprotokoll

| API | SAFT | Änderungen |
|---|---|---|
| **1.3** | 0.1 | `PluginTestCase::createTablesFromSchema()`. Werkzeuge (nicht Teil der API): Entwickler-Kontext (`saft:plugin-context`, Download in der Plugin-Verwaltung), `AGENTS.md` im Gerüst |
| **1.2** | 0.1 | `DashboardWidget` (Bezeichnung der Kachel), `FormulaFunction` und `SaftPlugin::formulaFunctions()`, `FieldRule` und `SaftPlugin::fieldRules()` |
| **1.1** | 0.1 | Test-Basis `PluginTestCase` (`actingAsSuperAdmin`, `actingWithPermissions`, `createTable`, `withoutPluginEvents`, `dataView`); geschützte Methoden gehören zum Vertrag (`PluginServiceProvider::plugin()`). Werkzeuge (nicht Teil der API): `saft:make-plugin`, `saft:plugins --check`, Testsuite „Plugins“ |
| **1.0** | 0.1 | Erste Fassung: `SaftPlugin` (Rechte, Menüvorschläge, Aufgaben-Arten, `requiredTables()`, `recordListeners()`, `dataActions()`), `PluginServiceProvider`, `MenuSuggestion`; `Saft` (`table`, `hasTable`, `plugin`, `transaction`, `notify`), `DataTable` (Lesen, Schreiben mit Rechten und Prüfregeln, `formFields`, `withoutAuthorization`), Ereignisse `RecordSaving`, `RecordSaved`, `RecordDeleting`, `RecordDeleted`, `RecordRejected`, `RecordListener`, `DataAction`; Trait `LimitsFormWidth` |

## Anhang A – Schritt-für-Schritt-Anleitungen

Die Kapitel 1–17 beschreiben jeden Baustein für sich. Dieser Anhang führt für die sieben häufigsten Aufgaben von der
leeren Datei bis zum getesteten Ergebnis. Alle Beispiele stammen aus dem Beispiel-Plugin **Lager-Tafel**
(`plugins/lager-tafel`). Sein Code ist mit Tests auf PostgreSQL, MySQL und MariaDB abgesichert, die Ausschnitte hier
laufen also so, wie sie dastehen.

| Anleitung | Baustein im Plugin | Datei |
|---|---|---|
| [A.1 Eigene Tabellensicht](#a1-eigene-tabellensicht) | Lagerspiegel: je Artikel eine Zeile, je Lagerort eine Spalte | `src/Pages/Lagerspiegel.php` |
| [A.2 Eigenes Bearbeiten-Formular](#a2-eigenes-bearbeiten-formular) | Lagertafel: Bestand per Drag and Drop umbuchen | `src/Pages/Lagertafel.php`, `resources/views/lagertafel.blade.php` |
| [A.3 Widget](#a3-widget) | Kachel „Lager: Bestand je Lagerort“ | `src/Widgets/StockByLocationChart.php` |
| [A.4 Aktion in einer bestehenden Tabellensicht](#a4-aktion-in-einer-bestehenden-tabellensicht) | Knöpfe in der Datenansicht Artikel | `LagerTafelPlugin::dataActions()` |
| [A.5 Listener](#a5-listener) | Artikelnummer vereinheitlichen und sperren | `src/Listeners/` |
| [A.6 Prüfregeln](#a6-prüfregeln) | „Artikelnummer (Lager-Demo)“ | `src/ArticleNumber.php`, `LagerTafelPlugin::fieldRules()` |
| [A.7 Menüvorschläge](#a7-menüvorschläge) | Lagerspiegel und Lagertafel im Menü | `LagerTafelPlugin::menuSuggestions()` |

**Ausprobieren:** Lager-Demo einspielen (`php artisan db:seed --class=LagerDemoSeeder`), unter
**Administration › Plugins** bei *Lager-Tafel* „Menüeinträge anlegen“, dann **Lager (Demo) › Lagerspiegel** bzw.
**Lagertafel**. Die Tests: `vendor/bin/phpunit --testsuite Plugins --filter LagerTafelTest`.

### Typsicher programmieren

Plugins laufen innerhalb von SAFT – ein Tippfehler in einem Tabellen- oder Rechtnamen fällt sonst erst in der
Produktion auf. Alle Beispiele halten sich deshalb an dieselben Regeln:

- **`declare(strict_types=1);`** in jeder Datei, Klassen **`final`**, Eigenschaften, Parameter und Rückgaben **typisiert**.
- **Namen als Konstanten:** die id des Plugins (`LagerTafelPlugin::ID`), Routen (`Lagerspiegel::ROUTE`), Tabellen
  (`Warehouse::ARTICLES`) und der Präfix der Übersetzungen (`private const string T = 'lager-tafel::tafel.';`). Ein
  falscher Name ist dann ein Fehler der IDE, kein leeres Ergebnis zur Laufzeit.
- **Array-Formen im PHPDoc**, wo PHP selbst keinen genaueren Typ kennt:
  `@return array<int, array<int, int>>` oder `@return list<array{id: int, label: string, quantity: int}>`.
- **Werte aus Datensätzen umwandeln:** `getAttribute()` liefert `mixed` – je nach Datenbank auch Zahlen als Text.
  Wandeln Sie sofort um: `(int) $record->getAttribute('menge')`.
- **Fachlogik ohne Bezug zu SAFT** in eigene Klassen (hier `ArticleNumber`): einfach zu testen, ohne Datenbank.
- **Lesezugriffe an einer Stelle bündeln:** `Warehouse` kapselt alle Abfragen auf die Lager-Tabellen. Seiten, Kachel und
  Listener rufen nur seine Methoden auf; ändert sich ein Feldname, ist es eine Stelle.

**Abhängigkeit von einem anderen Plugin:** Die Lagertafel bucht über `StockTransfer` des Plugins *Lager-Umbuchung*,
statt den Ablauf zu kopieren. Ein anderes Plugin gehört – anders als SAFT selbst – in `require` der `composer.json`:

```json
"require": { "php": "^8.4", "filament/filament": "^5.0", "saft/lager-umbuchung": "^1.3" }
```

### A.1 Eigene Tabellensicht

**Wozu:** Die Datenansicht von SAFT zeigt eine Tabelle so, wie sie im Table-Designer steht. Eine eigene Tabellensicht
lohnt sich, wenn Zeilen oder Spalten anders entstehen sollen – hier der *Lagerspiegel*: je Artikel eine Zeile, je
Lagerort eine Spalte mit dem Bestand, dazu Gesamtbestand und Mindestbestand. Die Spalten der Lagerorte entstehen zur
Laufzeit. **Nicht nötig** ist eine eigene Sicht für andere Spalten, Filter oder Sortierung derselben Tabelle – das
richtet die Administration im Table-Designer bzw. mit einer Auswertung ein.

**Schritt 1 – Seite mit Tabelle anlegen** (`src/Pages/Lagerspiegel.php`). Eine Filament-Seite mit `HasTable` und
`InteractsWithTable`; Adresse mit der id beginnen, nicht selbst in die Navigation eintragen, Zugriff über das Recht
des Plugins:

```php
final class Lagerspiegel extends Page implements HasTable
{
    use InteractsWithTable;

    public const string ROUTE = 'filament.admin.pages.lager-tafel.lagerspiegel';

    private const string T = 'lager-tafel::tafel.matrix.';

    protected static ?string $slug = 'lager-tafel/lagerspiegel';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<int, array<int, int>>|null Bestände je Artikel und Lagerort, einmal je Anfrage geladen */
    private ?array $matrix = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can(LagerTafelPlugin::ID.'.view') ?? false;
    }
```

**Schritt 2 – Hinweis statt Fehler.** Fehlen Tabellen der Lager-Demo oder Datenrechte, zeigt die Seite einen Hinweis
und keine Tabelle. `content()` legt fest, was die Seite zeigt; `EmbeddedTable` ist die Tabelle:

```php
public function content(Schema $schema): Schema
{
    if (! $this->warehouse()->available()) {
        return $schema->components([Callout::make(__('lager-tafel::tafel.unavailable_title'))
            ->description(__('lager-tafel::tafel.unavailable').' '.implode('; ', $this->warehouse()->missing()))
            ->warning()]);
    }

    if (! $this->warehouse()->canRead()) {
        return $schema->components([Callout::make(__('lager-tafel::tafel.no_rights'))->danger()]);
    }

    return $schema->components([EmbeddedTable::make()]);
}
```

**Schritt 3 – Abfrage und Spalten.** Die Zeilen kommen aus `Saft::table(…)->query()` – damit gelten die Datenrechte,
und Datensätze im Papierkorb sind ausgenommen. Spalten, die es in der Tabelle gibt, sind gewöhnliche `TextColumn`s
mit `searchable()` und `sortable()`. Berechnete Spalten erhalten ihren Wert über `state()`; die Bestände lädt die
Seite **einmal** für alle Zeilen (`$this->matrix`), nicht je Zeile eine Abfrage:

```php
public function table(Table $table): Table
{
    $warehouse = $this->warehouse();

    if (! $warehouse->available() || ! $warehouse->canRead()) {
        return $table->records(static fn (): array => []);     // content() zeigt den Hinweis
    }

    $locationColumns = [];

    foreach ($warehouse->locations() as $id => $label) {
        $locationColumns[] = TextColumn::make('lagerort_'.$id)
            ->label($label)
            ->state(fn (DynamicRecord $record): int => $this->stocks($record)[$id] ?? 0)
            ->numeric()
            ->alignEnd()
            ->color(static fn (int $state): ?string => $state === 0 ? 'gray' : null);
    }

    $hasMinimum = $warehouse->hasMinimum();

    return $table
        ->query(static fn (): Builder => Saft::table(Warehouse::ARTICLES)->query())
        ->defaultSort('artikelnummer')
        ->columns([
            TextColumn::make('artikelnummer')->label(__(self::T.'number'))->searchable()->sortable(),
            TextColumn::make('bezeichnung')->label(__(self::T.'article'))->searchable()->sortable()->wrap(),
            ...$locationColumns,
            TextColumn::make('gesamt')
                ->label(__(self::T.'total'))
                ->state(fn (DynamicRecord $record): int => array_sum($this->stocks($record)))
                ->numeric()->alignEnd()->weight('bold'),
            TextColumn::make('mindestbestand')->label(__(self::T.'minimum'))->numeric()->alignEnd()->sortable()
                ->visible($hasMinimum),
        ])
        // …
}
```

**Schritt 4 – Filter und Zeilenaktionen.** Ein Filter verändert die Abfrage; hier liefert `Warehouse` die Schlüssel der
Artikel unter dem Mindestbestand. Die Zeilenaktion öffnet die Lagertafel (A.2) für den Artikel:

```php
        ->filters([
            Filter::make('unter_mindestbestand')
                ->label(__(self::T.'below_minimum'))
                ->toggle()
                ->visible($hasMinimum)
                ->query(fn (Builder $query): Builder => $query->whereKey($this->warehouse()->belowMinimum())),
        ])
        ->recordActions([
            Action::make('verteilen')
                ->label(__('lager-tafel::tafel.actions.board'))
                ->icon('heroicon-o-view-columns')
                ->iconButton()
                ->visible(static fn (): bool => Lagertafel::canAccess())
                ->url(static fn (DynamicRecord $record): string => Lagertafel::getUrl(['artikel' => $record->getKey()])),
        ]);
```

**Schritt 5 – ins Menü bringen und Rechte vergeben:** Menüvorschlag (A.7), danach in der Rollenverwaltung das Recht
*Lager-Tafel: Lagerspiegel anzeigen* und die Datenrechte *anzeigen* auf Artikel, Lagerorte und Bestände.

**Testen:**

```php
Livewire::test(Lagerspiegel::class)
    ->assertCanSeeTableRecords([$schraube, $nagel])
    ->assertTableColumnStateSet('lagerort_'.$nord, 10, $schraube->getKey())
    ->searchTable('Nagel')
    ->assertCanNotSeeTableRecords([$schraube])
    ->searchTable('')
    ->filterTable('unter_mindestbestand')
    ->assertCanSeeTableRecords([$nagel]);
```

**Stolpersteine:**

- **Suchen und Sortieren** gehen nur bei Spalten, die es in der Tabelle gibt. Berechnete Spalten (`state()`) lassen sich
  weder durchsuchen noch sortieren – dafür bräuchte es die Werte in der Datenbank.
- **Keine Abfrage je Zeile:** `state()` läuft für jede Zelle. Laden Sie Zusatzdaten einmal in eine Eigenschaft.
- **Verknüpfungen** zeigen in der Tabelle den gespeicherten Schlüssel. Brauchen Sie die Bezeichnung, laden Sie die
  Zuordnung Schlüssel → Bezeichnung einmal (wie `Warehouse::locations()`) und geben sie in `state()` aus.
- `Saft::table()` wirft ohne Leserecht eine `AuthorizationException`. Prüfen Sie `can('view')` vorher und zeigen Sie
  einen Hinweis, statt die Seite scheitern zu lassen.

### A.2 Eigenes Bearbeiten-Formular

**Wozu:** Das Formular von SAFT bearbeitet einen Datensatz einer Tabelle. Ein eigenes Formular braucht es, wenn ein
Vorgang mehrere Datensätze oder Tabellen auf einmal ändert oder anders bedient werden soll. Die *Lagertafel* zeigt
für einen Artikel je Lagerort eine Karte mit dem Bestand. Wer den Bestand auf eine andere Karte zieht, gibt im
Dialog die Menge ein; gebucht werden zwei Lagerbewegungen in einer Transaktion.

> Ein Plugin ersetzt das Standard-Formular einer Tabelle nicht. Ein eigenes Formular ist immer eine eigene Seite, die
> ein Menüeintrag (A.7) oder ein Knopf in der Datenansicht (A.4) öffnet.

**Schritt 1 – Seite mit eigener View** (`src/Pages/Lagertafel.php`). Für Drag and Drop braucht die Seite eigenes HTML,
deshalb eine View aus dem Plugin (`view('lager-tafel::lagertafel')` liegt unter `resources/views/lagertafel.blade.php`):

```php
final class Lagertafel extends Page
{
    public const string ROUTE = 'filament.admin.pages.lager-tafel.lagertafel';

    private const string T = 'lager-tafel::tafel.board.';

    protected static ?string $slug = 'lager-tafel/lagertafel';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'lager-tafel::lagertafel';

    /** @var array{artikel?: int|string|null} Zustand des Formulars (Auswahl des Artikels) */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(LagerTafelPlugin::ID.'.transfer') ?? false;
    }

    /** Vorbelegung aus dem Knopf „Auf Lagertafel verteilen“ (?artikel=…). */
    public function mount(): void
    {
        $this->form->fill(['artikel' => request()->integer('artikel') ?: null]);
    }
```

**Schritt 2 – Formular für die Auswahl.** Ein gewöhnliches Filament-Formular; `live()` zeichnet die Tafel neu, sobald
ein Artikel gewählt ist:

```php
public function form(Schema $schema): Schema
{
    return $schema
        ->statePath('data')
        ->components($this->ready() ? [
            Select::make('artikel')
                ->label(__(self::T.'article'))
                ->options(fn (): array => $this->warehouse()->articles())
                ->searchable()
                ->live(),
        ] : []);
}
```

**Schritt 3 – Daten für die View.** Öffentliche Methoden der Seite sind in der View als `$this->…()` aufrufbar.
`board()` liefert je Lagerort eine Karte – auch ohne Bestand, denn dorthin soll man ziehen können:

```php
/** @return list<array{id: int, label: string, quantity: int}> */
public function board(): array
{
    if (($article = $this->article()) === null) {
        return [];
    }

    $stocks = $this->warehouse()->stocksOf($article);
    $cards = [];

    foreach ($this->warehouse()->locations() as $id => $label) {
        $cards[] = ['id' => $id, 'label' => $label, 'quantity' => $stocks[$id] ?? 0];
    }

    return $cards;
}
```

**Schritt 4 – Drag and Drop in der View.** Livewire 4 bringt `wire:sort` mit; zusätzliches JavaScript ist nicht nötig.
Alle Karten bilden eine Gruppe (`wire:sort:group`), damit sich zwischen ihnen ziehen lässt. Jede Karte nennt ihre
Kennung (`wire:sort:group-id`), das ziehbare Element seinen Schlüssel (`wire:sort:item`, hier der Quellort):

```blade
<x-filament-panels::page>
    {{ $this->content }}

    @if ($this->article() !== null)
        <div class="lager-tafel-board">
            @foreach ($this->board() as $card)
                <x-filament::section :heading="$card['label']" compact wire:key="lager-tafel-card-{{ $card['id'] }}">
                    <div class="lager-tafel-drop"
                         wire:sort="drop"
                         wire:sort:group="lager-tafel"
                         wire:sort:group-id="{{ $card['id'] }}">
                        @if ($card['quantity'] > 0)
                            <div class="lager-tafel-item"
                                 wire:key="lager-tafel-item-{{ $card['id'] }}-{{ $card['quantity'] }}"
                                 wire:sort:item="{{ $card['id'] }}">
                                <x-filament::badge size="lg" icon="heroicon-m-cube">{{ $card['quantity'] }}</x-filament::badge>
                            </div>
                        @else
                            <span class="lager-tafel-empty" wire:sort:ignore>{{ __('lager-tafel::tafel.board.empty') }}</span>
                        @endif
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
```

**Gestaltung:** SAFT liefert die Oberfläche mit dem fertig übersetzten CSS von Filament aus. Tailwind-Klassen aus
Views eines Plugins sind darin **nicht** enthalten und wirken nicht. Verwenden Sie Filament-Komponenten
(`<x-filament::section>`, `<x-filament::badge>`, `<x-filament::button>` …) und für den Rest ein `<style>`-Block in der
View, mit dem Präfix der Plugin-id und den Farbvariablen von Filament (`var(--gray-300)`, `var(--primary-500)`), damit
der dunkle Modus passt:

```css
.lager-tafel-drop { min-height: 4.5rem; border: 2px dashed var(--gray-300); border-radius: .75rem; /* … */ }
.dark .lager-tafel-drop { border-color: var(--gray-600); }
```

**Schritt 5 – Ablegen entgegennehmen.** Beim Ablegen ruft Livewire die Methode aus `wire:sort` mit dem Schlüssel des
Elements, der Position und der Kennung der Zielkarte auf. Die Seite bucht noch nicht, sondern öffnet einen Dialog:

```php
public function drop(int|string $item, int $position, int|string $target): void
{
    abort_unless(self::canAccess(), 403);                 // Livewire-Aufrufe lassen sich auch ohne Oberfläche auslösen
    [$from, $to] = [(int) $item, (int) $target];

    if ($from === $to || $this->article() === null) {
        return;
    }

    $this->mountAction('transfer', ['from' => $from, 'to' => $to]);
}
```

**Schritt 6 – Dialog und Buchung.** Eine Aktion `transferAction()` gehört automatisch zur Seite. Die Argumente aus
`mountAction()` stehen in `$arguments`; `maxValue()` verhindert schon im Dialog zu grosse Mengen:

```php
public function transferAction(): Action
{
    return Action::make('transfer')
        ->modalHeading(fn (array $arguments): string => __(self::T.'modal', [
            'from' => $this->warehouse()->locations()[(int) $arguments['from']] ?? '?',
            'to' => $this->warehouse()->locations()[(int) $arguments['to']] ?? '?',
        ]))
        ->modalSubmitActionLabel(__(self::T.'submit'))
        ->fillForm(fn (array $arguments): array => ['menge' => $this->available((int) $arguments['from'])])
        ->schema(fn (array $arguments): array => [
            TextInput::make('menge')
                ->label(__(self::T.'quantity'))
                ->integer()
                ->required()
                ->minValue(1)
                ->maxValue($this->available((int) $arguments['from'])),
        ])
        ->action(function (array $data, array $arguments): void {
            abort_unless(self::canAccess(), 403);
            $this->transfer((int) $arguments['from'], (int) $arguments['to'], (int) $data['menge']);
        });
}
```

Die Buchung selbst übernimmt `StockTransfer` des Plugins *Lager-Umbuchung* (zwei Lagerbewegungen in
`Saft::transaction()`, siehe [Kapitel 16.1](#161-lager-umbuchung)). Fehler zeigt die Seite als Meldung an – auch die
Ablehnung eines Listeners (`RecordRejected`) und Verstösse gegen Prüfregeln (`ValidationException`):

```php
try {
    $transfers->transfer((int) $this->article(), $from, $to, $quantity);
} catch (TransferRejected|RecordRejected $e) {
    Notification::make()->danger()->title($e->getMessage())->send();

    return;
} catch (ValidationException $e) {
    Notification::make()->danger()->title(__(self::T.'invalid'))->body(implode("\n", Arr::flatten($e->errors())))->send();

    return;
}
```

Nach der Aktion zeichnet Livewire die Seite neu; die Tafel zeigt die neuen Bestände.

**Testen:** Ziehen lässt sich im Feature-Test nicht, wohl aber der Aufruf, den Livewire beim Ablegen macht:

```php
Livewire::withQueryParams(['artikel' => $schraube])->test(Lagertafel::class)
    ->assertSeeHtml('wire:sort:group-id="'.$sued.'"')
    ->call('drop', (string) $nord, 0, (string) $sued)
    ->assertActionMounted(TestAction::make('transfer')->arguments(['from' => $nord, 'to' => $sued]))
    ->fillForm(['menge' => 11])
    ->callMountedAction()
    ->assertHasFormErrors(['menge' => 'max'])
    ->fillForm(['menge' => 4])
    ->callMountedAction()
    ->assertNotified('4 Stück von Nord nach Süd umgebucht.');
```

**Stolpersteine:**

- **`wire:key` mit dem Inhalt:** Das gezogene Element hängt der Browser in die Zielkarte. Ein Schlüssel, der die Menge
  enthält, sorgt dafür, dass Livewire es nach der Buchung sauber neu zeichnet – auch wenn der Dialog abgebrochen wird.
- **Rechte zweimal prüfen:** in `canAccess()` und in jeder Methode, die etwas ändert (`drop()`, `action()`).
- **Bedienung ohne Maus:** Drag and Drop geht nicht mit der Tastatur. Bieten Sie denselben Vorgang zusätzlich als
  Formular an – hier die Seite *Umbuchung* des Plugins Lager-Umbuchung.
- **Eingaben mehrerer Tabellen** schreiben Sie in `Saft::transaction()`, damit nichts halb gebucht wird.

### A.3 Widget

**Wozu:** Eine Kachel auf dem **Dashboard** zeigt eine Kennzahl oder ein Diagramm, ohne dass man eine Seite öffnen
muss. Die Kachel *Lager: Bestand je Lagerort* summiert die Bestände aller Artikel je Lagerort als Balkendiagramm.

**Schritt 1 – Klasse unter `src/Widgets` anlegen.** SAFT findet sie dort selbst. Filament bietet als Grundlage
`StatsOverviewWidget` (Kennzahlen), `ChartWidget` (Diagramme mit Chart.js) und `TableWidget` (Tabelle). Das Interface
`DashboardWidget` liefert die Bezeichnung für „Dashboard anpassen“:

```php
final class StockByLocationChart extends ChartWidget implements DashboardWidget
{
    protected static ?int $sort = 51;                     // Reihenfolge beim ersten Anzeigen

    protected int|string|array $columnSpan = 1;           // halbe Breite; 'full' = breiter Bereich unten

    protected ?string $maxHeight = '16rem';

    public static function dashboardLabel(): string
    {
        return __('lager-tafel::tafel.widget.label');
    }
```

**Schritt 2 – wer die Kachel sieht.** `canView()` prüft das Recht des Plugins, ob die Lager-Demo da ist, und die
Datenrechte. Ohne diese Prüfung scheitert das Dashboard für alle, denen ein Recht fehlt:

```php
public static function canView(): bool
{
    $warehouse = app(Warehouse::class);

    return Lagerspiegel::canAccess() && $warehouse->available() && $warehouse->canRead();
}
```

**Schritt 3 – Daten liefern.** Ein `ChartWidget` gibt Datenreihen und Beschriftungen im Format von Chart.js zurück:

```php
protected function getType(): string
{
    return 'bar';
}

/** @return array{datasets: list<array{label: string, data: list<int>}>, labels: list<string>} */
protected function getData(): array
{
    $warehouse = app(Warehouse::class);
    $totals = [];

    foreach ($warehouse->matrix() as $stocks) {
        foreach ($stocks as $location => $quantity) {
            $totals[$location] = ($totals[$location] ?? 0) + $quantity;
        }
    }

    $locations = $warehouse->locations();

    return [
        'datasets' => [[
            'label' => __('lager-tafel::tafel.widget.quantity'),
            'data' => array_values(array_map(static fn (int $id): int => $totals[$id] ?? 0, array_keys($locations))),
        ]],
        'labels' => array_values($locations),
    ];
}
```

**Verwenden:** Nach der Installation erscheint die Kachel auf dem Dashboard aller Personen, für die `canView()` wahr
ist. Jede Person kann sie unter **Dashboard anpassen** verschieben oder ausblenden. Ein eigenes Recht der
Administration für die Kachel braucht es nicht – sie folgt dem Recht des Plugins.

**Testen:**

```php
$this->assertFalse(StockByLocationChart::canView(), 'Ohne Lager-Demo.');
$data = (fn (): array => $this->getData())->call(new StockByLocationChart);
$this->assertSame(['Nord', 'Süd'], $data['labels']);
$key = 'plugin:Saft.LagerTafel.Widgets.StockByLocationChart';                 // Schlüssel auf dem Dashboard
$this->assertSame('Lager: Bestand je Lagerort', Livewire::test(Dashboard::class)->instance()->cards()[$key]['label']);
```

**Stolpersteine:**

- **Nur auf einer eigenen Seite:** `protected static bool $isDiscovered = false;` im Widget, auf der Seite
  `getHeaderWidgets()` bzw. `getFooterWidgets()`.
- **Teure Abfragen** laufen bei jedem Aufruf des Dashboards. Lesen Sie mit einer Abfrage für alle Werte, nicht je
  Balken eine.

### A.4 Aktion in einer bestehenden Tabellensicht

**Wozu:** Ein Knopf in der Datenansicht einer Tabelle von SAFT führt von dort in Ihr Plugin – ohne dass die
Administration etwas einrichten muss. Lager-Tafel setzt zwei Knöpfe in die Datenansicht **Artikel**: *Lagerspiegel*
über der Tabelle und *Auf Lagertafel verteilen* in jeder Zeile.

**Schritt 1 – Knöpfe in der Plugin-Klasse anmelden.** `DataAction` nennt die Tabelle, den Platz und eine Closure, die
eine Filament-Aktion baut:

```php
public function dataActions(): array
{
    return [
        new DataAction(Warehouse::ARTICLES, DataAction::TABLE, static fn (): Action => Action::make('lagerTafelSpiegel')
            ->label(__(self::T.'actions.matrix'))
            ->icon('heroicon-o-table-cells')
            ->color('gray')
            ->visible(static fn (): bool => Lagerspiegel::canAccess())
            ->url(static fn (): string => Lagerspiegel::getUrl())),
        new DataAction(Warehouse::ARTICLES, DataAction::RECORD, static fn (): Action => Action::make('lagerTafelVerteilen')
            ->label(__(self::T.'actions.board'))
            ->tooltip(__(self::T.'actions.board'))
            ->icon('heroicon-o-view-columns')
            ->iconButton()
            ->visible(static fn (): bool => Lagertafel::canAccess())
            ->url(static fn (DynamicRecord $record): string => Lagertafel::getUrl(['artikel' => $record->getKey()]))),
    ];
}
```

- `DataAction::TABLE` – über der Tabelle, neben „Neu“ und „Export“.
- `DataAction::RECORD` – in jeder Zeile, neben „Details“; Closures erhalten den Datensatz als `DynamicRecord $record`.
- **Namen der Aktionen** mit einem Präfix aus der id (`lagerTafel…`), damit sie nicht mit Aktionen von SAFT oder anderen
  Plugins kollidieren.

**Schritt 2 – Ziel entgegennehmen.** Die Seite liest die Vorbelegung aus der Adresse, siehe `mount()` in A.2.

**Statt einer Seite einen Dialog öffnen:** Mit `schema()` und `action()` statt `url()` öffnet der Knopf ein
Dialogfenster in der Datenansicht – z. B. für eine kurze Eingabe, die über `Saft::table(…)` gespeichert wird. Prüfen Sie
das Recht dann auch in `action()`.

**Testen** mit `dataView()` aus `PluginTestCase`:

```php
$this->dataView('lager_artikel')
    ->assertActionVisible(TestAction::make('lagerTafelSpiegel')->table())
    ->assertActionHasUrl(TestAction::make('lagerTafelVerteilen')->table($schraube), Lagertafel::getUrl(['artikel' => $schraube->getKey()]));

$this->actingWithPermissions(['lager-tafel.view', 'data.lager_artikel.view']);
$this->dataView('lager_artikel')->assertActionHidden(TestAction::make('lagerTafelVerteilen')->table($schraube));
```

**Stolpersteine:**

- **Ausblenden oder ausgrauen:** `visible()` für Knöpfe, die eine Person nie benutzen darf; `disabled()` für Knöpfe, die
  nur bei diesem Datensatz nicht gehen (wie „Umbuchen“ ohne Bestand im Plugin Lager-Umbuchung) – so bleiben die
  Knöpfe der Zeilen untereinander.
- **Zeilen ohne Schlüssel** (z. B. aus Views) erhalten keine Zeilenaktionen.
- **Ruht das Plugin** (fehlende Tabellen oder Felder), erscheinen seine Knöpfe nicht.

### A.5 Listener

**Wozu:** Ein Listener setzt eine Geschäftsregel durch, die **überall** gilt, wo Datensätze einer Tabelle geändert
werden: in der Datenansicht, beim Import, über die Schnittstelle und in geplanten Aufgaben. Eine Seite des Plugins
kann das nicht – sie sieht nur ihre eigenen Eingaben. Typische Aufgaben:

| Aufgabe | Ereignis | Beispiel |
|---|---|---|
| Werte vereinheitlichen oder ergänzen | `RecordSaving` | Artikelnummer in Grossbuchstaben |
| Änderung ablehnen, die über mehrere Tabellen falsch wäre | `RecordSaving`, `RecordDeleting` | Nummer gesperrt, sobald es Lagerbewegungen gibt |
| Folgebuchungen in anderen Tabellen | `RecordSaved`, `RecordDeleted` | Lagerbewegung bucht den Bestand (Lager-Umbuchung) |
| Benachrichtigen | `RecordSaved` | Mindestbestand unterschritten (Lager-Umbuchung) |

**Nicht nötig** ist ein Listener für Prüfungen eines einzelnen Felds (dafür eine Prüfregel, A.6, oder die Regeln des
Table-Designers) und für das Löschen verknüpfter Datensätze – das lehnt SAFT selbst ab, solange Datensätze anderer
Tabellen darauf verweisen.

**Schritt 1 – Werte setzen** (`src/Listeners/NormalizeArticleNumber.php`). Ein Listener ist eine Klasse mit
`__invoke()`, die das Ereignis erhält. In `RecordSaving` ändern Sie den Datensatz direkt; berechnete Felder sehen
danach den neuen Wert:

```php
final class NormalizeArticleNumber
{
    public function __invoke(RecordSaving $event): void
    {
        $number = $event->value('artikelnummer');

        if (is_string($number) && $number !== '') {
            $event->record->setAttribute('artikelnummer', ArticleNumber::normalize($number));
        }
    }
}
```

**Schritt 2 – Ablehnen** (`src/Listeners/LockArticleNumber.php`). `previous()` liefert den Wert vor der Änderung,
`creating()` sagt, ob der Datensatz neu ist. Eine `RecordRejected` bricht das Speichern ab; SAFT zeigt die Meldung
an, beim Import als Fehler der Zeile:

```php
final class LockArticleNumber
{
    private const string MOVEMENTS = 'lager_bewegungen';

    public function __invoke(RecordSaving $event): void
    {
        if ($event->creating() || ! Saft::hasTable(self::MOVEMENTS)) {
            return;
        }

        $before = ArticleNumber::normalize((string) $event->previous('artikelnummer'));
        $after = ArticleNumber::normalize((string) $event->value('artikelnummer'));

        if ($before === $after) {
            return;
        }

        // Ohne Rechteprüfung lesen: Die Regel gilt auch für Personen, die Lagerbewegungen nicht sehen dürfen.
        $used = Saft::table(self::MOVEMENTS)->withoutAuthorization()->query()
            ->where('artikel_id', $event->record->getKey())
            ->exists();

        if ($used) {
            throw new RecordRejected(__('lager-tafel::tafel.errors.number_locked', ['number' => $before]));
        }
    }
}
```

**Schritt 3 – in der Plugin-Klasse anmelden.** Je Tabelle und Ereignis ein `RecordListener`; mehrere Listener für
dasselbe Ereignis laufen in der angegebenen Reihenfolge:

```php
public function recordListeners(): array
{
    return [
        new RecordListener(Warehouse::ARTICLES, RecordSaving::class, NormalizeArticleNumber::class),
        new RecordListener(Warehouse::ARTICLES, RecordSaving::class, LockArticleNumber::class),
    ];
}
```

**Verwenden:** Nichts einzurichten – nach der Installation wirkt der Listener sofort, für alle Personen. Auf der Seite
**Plugins** sieht die Administration, an welchen Tabellen ein Plugin mithört.

**Testen:** Listener eines installierten Plugins sind in Tests aktiv. Testdaten, die die eigene Regel verletzen müssten,
legen Sie mit `$this->withoutPluginEvents(fn () => …)` an.

```php
$artikel = Saft::table('lager_artikel')->create(['artikelnummer' => ' b-2001 ', 'bezeichnung' => 'Dübel']);
$this->assertSame('B-2001', $artikel->fresh()->getAttribute('artikelnummer'));

$this->expectException(RecordRejected::class);
Saft::table('lager_artikel')->update($schraube, ['artikelnummer' => 'A-9999']);   // hat Lagerbewegungen
```

**Stolpersteine:**

- **Rechte:** Ein Listener läuft mit den Rechten der Person, die speichert. Lesen oder schreiben Sie dort, wo die Regel
  für alle gelten muss, mit `withoutAuthorization()` – und begründen Sie es im Kommentar.
- **Endlosschleifen:** Schreibt ein Listener in `RecordSaved` in dieselbe Tabelle, löst er sich selbst wieder aus.
  Merken Sie sich mit einer statischen Eigenschaft, dass Sie gerade schreiben (wie `StockLedger::$writing` im Plugin
  Lager-Umbuchung).
- **Nur ablehnen, was fachlich falsch ist:** Die Ablehnung trifft jede Datenansicht und jeden Import, auch einen ganzen
  Import wegen einer Zeile.
- **Demo-Daten:** Beim Einspielen der Demo-Daten sind die Ereignisse für Plugins ausgeschaltet.

### A.6 Prüfregeln

**Wozu:** Eine Prüfregel prüft den Wert **eines Felds**, z. B. eine IBAN oder das Format einer Artikelnummer. Anders als
ein Listener wirkt sie nur dort, wo die Administration sie im Table-Designer auswählt – das Plugin liefert die Regel,
die Administration entscheidet, wo sie gilt.

**Schritt 1 – die Prüfung ohne Bezug zu SAFT** (`src/ArticleNumber.php`). So lässt sie sich ohne Datenbank testen und
vom Listener (A.5) mitbenutzen:

```php
final class ArticleNumber
{
    private const string PATTERN = '/^[A-Z]-\d{4}$/';

    /** Schreibweise vereinheitlichen: Leerzeichen weg, Grossbuchstaben („ a-1001 “ → „A-1001“). */
    public static function normalize(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', $value) ?? $value);
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, self::normalize($value)) === 1;
    }
}
```

**Schritt 2 – Regel in der Plugin-Klasse anmelden.** `check` gibt die Fehlermeldung zurück oder `null`, wenn der Wert
in Ordnung ist. `types` bestimmt, bei welchen Feldtypen die Regel zur Auswahl steht (`string`, `text`, `integer`,
`decimal`):

```php
public function fieldRules(): array
{
    return [new FieldRule(
        name: 'artikelnummer',
        label: __(self::T.'rules.article_number'),
        types: ['string'],
        check: static fn (mixed $value): ?string => ArticleNumber::isValid((string) $value)
            ? null
            : __(self::T.'rules.article_number_invalid'),
    )];
}
```

**Schritt 3 – Texte in allen Sprachen.** Bezeichnung und Meldung kommen aus `lang/{de,en,fr,it}/tafel.php`. Die
Bezeichnung erscheint im Table-Designer, die Meldung beim Feld.

**Verwenden:** Im **Table-Designer** beim Feld *Artikelnummer* unter **Weitere Prüfungen** „Artikelnummer (Lager-Demo)“
wählen. Gespeichert wird die Regel als `lager-tafel.artikelnummer`. Sie gilt danach bei der Erfassung, beim Import und
über `Saft::table(…)`; leere Werte prüft sie nicht (dafür gibt es „Pflichtfeld“).

**Testen:** In `createTable()` wählen Sie die Regel wie der Table-Designer:

```php
$this->createTable('lager_artikel', 'Artikel', [
    ['name' => 'artikelnummer', 'label' => 'Nummer', 'type' => 'string', 'options' => ['length' => 20],
     'validation_rules' => ['checks' => ['lager-tafel.artikelnummer']]],
    // …
]);

$this->expectException(ValidationException::class);
Saft::table('lager_artikel')->create(['artikelnummer' => '1001', 'bezeichnung' => 'Falsch']);
```

**Stolpersteine:**

- **Name:** Kleinbuchstaben, Ziffern und Bindestriche; nach der ersten Installation nicht mehr ändern, sonst verlieren
  die Felder ihre Regel.
- **Prüfen und Speichern trennen:** Die Regel ändert keinen Wert. Soll eine andere Schreibweise gespeichert werden,
  übernimmt das ein Listener (A.5). Darum prüft `isValid()` den vereinheitlichten Wert – „a-1001“ besteht, gespeichert
  wird „A-1001“.
- **Fehlt das Plugin oder ruht es,** wird die Regel übersprungen; der Table-Designer zeigt beim Feld „nicht verfügbar“.

### A.7 Menüvorschläge

**Wozu:** Das Menü von SAFT kommt aus der Datenbank und gehört der Administration. Ein Plugin trägt sich deshalb nicht
selbst ein, sondern **schlägt** Einträge vor; die Administration übernimmt sie mit einem Klick und kann sie danach
verschieben, umbenennen oder löschen wie jeden anderen Eintrag.

**Schritt 1 – Seiten aus der Navigation von Filament heraushalten:** in jeder Seite
`protected static bool $shouldRegisterNavigation = false;` (siehe A.1 und A.2).

**Schritt 2 – Vorschläge in der Plugin-Klasse:**

```php
public function menuSuggestions(): array
{
    $group = ['de' => 'Lager (Demo)', 'en' => 'Warehouse (demo)', 'fr' => 'Entrepôt (démo)', 'it' => 'Magazzino (demo)'];

    return [
        new MenuSuggestion(
            route: Lagerspiegel::ROUTE,
            label: ['de' => 'Lagerspiegel', 'en' => 'Stock matrix', 'fr' => 'Matrice des stocks', 'it' => 'Matrice delle giacenze'],
            icon: 'heroicon-o-table-cells',
            permission: $this->permissionName('view'),
            group: $group,
            sortOrder: 6,
        ),
        new MenuSuggestion(
            route: Lagertafel::ROUTE,
            label: ['de' => 'Lagertafel', 'en' => 'Stock board', 'fr' => 'Tableau des stocks', 'it' => 'Tabellone delle giacenze'],
            icon: 'heroicon-o-view-columns',
            permission: $this->permissionName('transfer'),
            group: $group,
            sortOrder: 7,
        ),
    ];
}
```

- **`route`** als Konstante der Seite – ein Tippfehler fällt so beim Prüfen auf (`saft:plugins --check` meldet fehlende
  Routen).
- **`permission`** über `permissionName()` – der Eintrag erscheint nur für Personen mit diesem Recht.
- **`group`** wird über die deutsche Bezeichnung gefunden; gibt es die Gruppe schon (hier aus der Lager-Demo und dem
  Plugin Lager-Umbuchung), landen die Einträge darin, sonst legt SAFT sie an. `null` = oberste Ebene.
- **`sortOrder`** ordnet innerhalb der Gruppe; hier hinter „Umbuchung“ (5).
- **`label`** in allen vier Sprachen angeben (`de`, `en`, `fr`, `it`).

**Verwenden:** Unter **Administration › Plugins** beim Plugin **Menüeinträge anlegen** wählen, danach in der
**Rollenverwaltung** die Rechte vergeben. Ein zweites Übernehmen legt nichts doppelt an. Wird das Plugin entfernt,
blendet SAFT seine Einträge aus; unter **Aufräumen** lassen sie sich zusammen mit den Rechten löschen.

**Testen:**

```php
$vorschlaege = Saft::plugin(LagerTafelPlugin::ID)->menuSuggestions();
$this->assertSame([Lagerspiegel::ROUTE, Lagertafel::ROUTE], array_map(static fn ($s): string => $s->route, $vorschlaege));
$this->assertSame(['lager-tafel.view', 'lager-tafel.transfer'], array_map(static fn ($s): ?string => $s->permission, $vorschlaege));
```

**Stolpersteine:**

- **Ein Recht je Zweck:** Lager-Tafel trennt *anzeigen* (Lagerspiegel, Kachel) und *umbuchen* (Lagertafel) – so kann
  die Administration lesen erlauben, ohne Buchungen zu erlauben.
- **Vorschläge ändern** wirkt nicht auf schon übernommene Einträge; die Administration übernimmt neue Vorschläge mit
  einem erneuten Klick.
