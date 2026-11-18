<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Formula\Recalculator;
use App\DynamicData\Rules\CopySource;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Reports\ReportExpression;
use App\Services\DynamicSchemaService;
use Database\Seeders\Concerns\SeedsDemoTables;
use Illuminate\Database\Seeder;

/**
 * Lagerverwaltung (Demo) als Tabellen des Table-Designers – dieselben Daten wie die externe
 * MariaDB-/SQL-Server-Demo, aber in SAFT selbst (ohne Datenbankverbindung). Menügruppe „Lager (Demo)“.
 *
 *   php artisan db:seed --class=LagerDemoSeeder
 *
 * Abweichungen zur externen Fassung: eigene IDs statt UUID bzw. zusammengesetztem Schlüssel, Obergruppe
 * als Verknüpfung statt Code, Merkmale (JSON) als Langtext; die Sicht „bestandsuebersicht“ wird zu den
 * berechneten Feldern Bestand und Reserve am Artikel sowie zur Auswertung „Artikel unter Mindestbestand“.
 * Wiederholbar: vorhandene Tabellen und Daten werden nicht dupliziert.
 */
class LagerDemoSeeder extends Seeder
{
    use SeedsDemoTables;

    public const array GROUP = ['de' => 'Lager (Demo)', 'en' => 'Warehouse (demo)', 'fr' => 'Entrepôt (démo)', 'it' => 'Magazzino (demo)'];

    /** Untermenü für das Tagesgeschäft; dieselbe Bezeichnung nutzen die Menüvorschläge der Lager-Plugins. */
    public const array OPERATIONS = ['de' => 'Lager führen', 'en' => 'Warehouse operations', 'fr' => 'Gestion de l’entrepôt', 'it' => 'Gestione del magazzino'];

    public const string REPORT_BELOW_MINIMUM = 'Artikel unter Mindestbestand';

    public const string REPORT_TEMPERATURE = 'Temperaturverlauf Kühlräume';

    public const string REPORT_PURCHASES = 'Einkaufsvolumen je Lieferant';

    public const string REPORT_CALCULATION = 'Artikel-Kalkulation';

    public const string REPORT_MOVEMENT_FIGURES = 'Bewegungskennzahlen je Artikelgruppe';

    public const string REPORT_STOCK_VALUE = 'Lagerwert gesamt';

    public const string REPORT_BELOW_MINIMUM_COUNT = 'Anzahl Artikel unter Mindestbestand';

    /** App der Demo (B-107); der Schlüssel ist fest, damit wiederholtes Einspielen dieselbe App trifft. */
    public function demoAppDefinition(): array
    {
        return ['uuid' => '8f0c6a52-6d0e-4c8e-9a43-5b1c2f6e0a02', 'name' => ['de' => 'Lager (Demo)', 'en' => 'Warehouse (demo)', 'fr' => 'Entrepôt (démo)', 'it' => 'Magazzino (demo)'], 'prefix' => 'lager', 'icon' => 'heroicon-o-cube', 'sort_order' => 520];
    }

    public function run(): void
    {
        $group = $this->demoGroup(self::GROUP, 520);
        $created = $this->demoTables($group, $this->definitions());

        if (in_array('lager_lieferanten', $created, true)) {
            $this->seedRecords();
        }

        $this->demoRules('lager_artikel', [[
            'left' => 'verkaufspreis', 'operator' => '>=', 'right_kind' => 'field', 'right_field' => 'einkaufspreis', 'when_filled' => true,
        ]]);
        $this->demoRules('lager_bewegungen', [[
            'left' => 'menge', 'operator' => '<>', 'right_kind' => 'value', 'right_value' => '0', 'when_filled' => true,
        ]]);
        $this->demoDetails([
            'lager_lieferanten' => ['lager_artikel.lieferant_id'],
            'lager_artikelgruppen' => ['lager_artikel.gruppe_id', 'lager_artikelgruppen.obergruppe_id'],
            'lager_artikel' => ['lager_bestaende.artikel_id', 'lager_bewegungen.artikel_id'],
            'lager_orte' => ['lager_bestaende.lagerort_id', 'lager_bewegungen.lagerort_id', 'lager_messprotokoll.lagerort_id'],
            'lager_mitarbeitende' => ['lager_bewegungen.mitarbeiter_id'],
        ]);
        $this->purchaseValues();
        $this->describeMovements();
        $this->demoComputed([
            ['lager_bewegungen', 'einkaufswert', self::demoText('Einkaufswert (EUR)', 'Purchase value (EUR)', 'Valeur d’achat (EUR)', 'Valore d’acquisto (EUR)'), 'round(menge * einkaufspreis, 2)', ['result' => 'decimal', 'precision' => 14, 'scale' => 2]],
            ['lager_artikel', 'bestand', self::demoText('Bestand', 'Stock', 'Stock', 'Giacenza'), 'sum(lager_bestaende.menge)', ['result' => 'integer']],
            ['lager_artikel', 'reserve', self::demoText('Reserve über Mindestbestand', 'Reserve above minimum', 'Réserve au-dessus du minimum', 'Riserva sopra il minimo'), 'bestand - mindestbestand', ['result' => 'integer']],
        ]);
        // Für vorhandene Bewegungen nachgetragene Preise in den Einkaufswert übernehmen.
        app(Recalculator::class)->recomputeTable(CustomTable::query()->where('name', 'lager_bewegungen')->sole());
        $this->reports($group);
        $this->submenus($group);
    }

    /** B-066: Lager führen, Stammdaten, Auswertungen; das Datenmodell bleibt direkt in der Gruppe. */
    private function submenus(Menu $group): void
    {
        $this->demoSubmenus($group, [
            [
                'label' => self::OPERATIONS, 'icon' => 'heroicon-o-arrows-right-left', 'sort_order' => 10,
                'entries' => [
                    ['table', 'lager_bewegungen'], ['table', 'lager_bestaende'],
                    // Seiten der Beispiel-Plugins, falls ihre Menüvorschläge schon übernommen sind.
                    ['route', 'filament.admin.pages.lager-umbuchung'], ['route', 'filament.admin.pages.lager-tafel.lagertafel'], ['route', 'filament.admin.pages.lager-tafel.lagerspiegel'],
                    ['table', 'lager_messprotokoll'],
                ],
            ],
            [
                'label' => self::demoText('Stammdaten', 'Master data', 'Données de base', 'Dati di base'), 'icon' => 'heroicon-o-archive-box', 'sort_order' => 20,
                'entries' => [['table', 'lager_artikel'], ['table', 'lager_artikelgruppen'], ['table', 'lager_lieferanten'], ['table', 'lager_orte'], ['table', 'lager_mitarbeitende']],
            ],
            [
                'label' => self::demoText('Auswertungen', 'Reports', 'Rapports', 'Report'), 'icon' => 'heroicon-o-presentation-chart-bar', 'sort_order' => 30,
                'entries' => array_map(static fn (string $name): array => ['report', $name], [
                    self::REPORT_BELOW_MINIMUM, self::REPORT_BELOW_MINIMUM_COUNT, self::REPORT_STOCK_VALUE, self::REPORT_CALCULATION,
                    self::REPORT_MOVEMENT_FIGURES, self::REPORT_PURCHASES, self::REPORT_TEMPERATURE,
                ]),
            ],
        ]);
    }

    /**
     * Preis zum Zeitpunkt der Bewegung: „Einkaufspreis“ übernimmt beim Erfassen den Einkaufspreis des Artikels
     * (Wert übernehmen), spätere Preisänderungen am Artikel verfälschen alte Bewegungen nicht. Vorhandene Bewegungen
     * ohne Preis bekommen den aktuellen Einkaufspreis ihres Artikels.
     */
    private function purchaseValues(): void
    {
        $movements = CustomTable::query()->where('name', 'lager_bewegungen')->sole();

        if (! $movements->fields()->where('name', 'einkaufspreis')->exists()) {
            app(DynamicSchemaService::class)->addField($movements, [
                'name' => 'einkaufspreis',
                'label' => self::demoText('Einkaufspreis (EUR)', 'Purchase price (EUR)', 'Prix d’achat (EUR)', 'Prezzo d’acquisto (EUR)'),
                'type' => 'decimal',
                'options' => ['precision' => 10, 'scale' => 2, CopySource::OPTION => 'artikel_id.einkaufspreis'],
            ]);
        }

        $table = app(DynamicDataNamespace::class)->connection()->table(app(DynamicDataNamespace::class)->qualify('lager_bewegungen'));

        foreach (DynamicRecord::forTableName('lager_artikel')->get(['id', 'einkaufspreis']) as $item) {
            (clone $table)->where('artikel_id', $item->id)->whereNull('einkaufspreis')->update(['einkaufspreis' => $item->einkaufspreis]);
        }
    }

    /** @return array<string, string> */
    private static function movementsDescription(): array
    {
        return self::demoText(
            'Menge mit Vorzeichen: positiv = Zugang, negativ = Abgang (Ausgang, Inventurdifferenz); Umbuchungen erscheinen als Paar −/+.',
            'Quantity with sign: positive = receipt, negative = issue (issue, stocktaking difference); transfers appear as a −/+ pair.',
            'Quantité avec signe : positive = entrée, négative = sortie (sortie, écart d’inventaire) ; les transferts apparaissent en paire −/+.',
            'Quantità con segno: positiva = entrata, negativa = uscita (uscita, differenza d’inventario); i trasferimenti compaiono come coppia −/+.',
        );
    }

    /**
     * Beschreibung der Lagerbewegungen nachtragen (bestehende Installationen); sie geht auch an den KI-Assistenten,
     * der so das Vorzeichen der Menge kennt. Eine vorhandene Beschreibung bleibt unverändert.
     */
    private function describeMovements(): void
    {
        $table = CustomTable::query()->where('name', 'lager_bewegungen')->sole();

        if (array_filter($table->getTranslations('description')) === []) {
            $table->setTranslations('description', self::movementsDescription())->save();
        }
    }

    private function reports(Menu $group): void
    {
        $this->demoReport($group, self::REPORT_BELOW_MINIMUM, 'Artikel, deren Bestand über alle Lagerorte unter dem Mindestbestand liegt – mit Lieferant zum Nachbestellen.', [
            'base' => $this->demoTableId('lager_artikel'),
            'fields' => [
                ['key' => 'nr', 'path' => [], 'field' => $this->demoFieldId('lager_artikel.artikelnummer')],
                ['key' => 'bez', 'path' => [], 'field' => $this->demoFieldId('lager_artikel.bezeichnung')],
                ['key' => 'gruppe', 'path' => [$this->demoFieldId('lager_artikel.gruppe_id')], 'field' => $this->demoFieldId('lager_artikelgruppen.bezeichnung'), 'label' => 'Gruppe'],
                ['key' => 'bestand', 'path' => [], 'field' => $this->demoFieldId('lager_artikel.bestand')],
                ['key' => 'min', 'path' => [], 'field' => $this->demoFieldId('lager_artikel.mindestbestand')],
                ['key' => 'reserve', 'path' => [], 'field' => $this->demoFieldId('lager_artikel.reserve')],
                ['key' => 'lieferant', 'path' => [$this->demoFieldId('lager_artikel.lieferant_id')], 'field' => $this->demoFieldId('lager_lieferanten.name'), 'label' => 'Lieferant'],
            ],
            'filters' => [['field' => 'reserve', 'op' => 'lt', 'value' => '0']],
            'sort' => [['key' => 'reserve', 'dir' => 'asc']],
            'visual' => ['type' => 'table'],
        ], self::demoText('Unter Mindestbestand', 'Below minimum stock', 'Sous le stock minimum', 'Sotto la scorta minima'), 'heroicon-o-exclamation-triangle');

        $this->demoReport($group, self::REPORT_TEMPERATURE, 'Durchschnittliche Temperatur je Tag und Kühlraum aus dem Messprotokoll.', [
            'base' => $this->demoTableId('lager_messprotokoll'),
            'fields' => [
                ['key' => 'tag', 'path' => [], 'field' => $this->demoFieldId('lager_messprotokoll.zeitpunkt'), 'bucket' => 'day', 'label' => 'Tag'],
                ['key' => 'ort', 'path' => [$this->demoFieldId('lager_messprotokoll.lagerort_id')], 'field' => $this->demoFieldId('lager_orte.bezeichnung'), 'label' => 'Lagerort'],
                ['key' => 'temp', 'path' => [], 'field' => $this->demoFieldId('lager_messprotokoll.temperatur_c')],
            ],
            'groups' => ['tag'],
            'columns' => ['ort'],
            'measures' => [['key' => 'avg', 'field' => 'temp', 'agg' => 'avg', 'label' => 'Ø Temperatur (°C)']],
            'visual' => ['type' => 'line'],
        ], self::demoText('Temperaturverlauf', 'Temperature history', 'Évolution de la température', 'Andamento della temperatura'), 'heroicon-o-chart-bar');

        // Lieferant über den Artikel der Bewegung; das Verknüpfungsfeld zeigt den Namen und trennt Namensvettern.
        $this->demoReport($group, self::REPORT_PURCHASES, 'Wareneingänge des laufenden Jahres, bewertet mit dem Einkaufspreis zum Zeitpunkt der Bewegung, je Lieferant.', [
            'base' => $this->demoTableId('lager_bewegungen'),
            'fields' => [
                ['key' => 'lieferant', 'path' => [$this->demoFieldId('lager_bewegungen.artikel_id')], 'field' => $this->demoFieldId('lager_artikel.lieferant_id'), 'label' => 'Lieferant'],
                ['key' => 'wert', 'path' => [], 'field' => $this->demoFieldId('lager_bewegungen.einkaufswert')],
                ['key' => 'art', 'path' => [], 'field' => $this->demoFieldId('lager_bewegungen.art')],
                ['key' => 'zeit', 'path' => [], 'field' => $this->demoFieldId('lager_bewegungen.zeitpunkt')],
            ],
            'groups' => ['lieferant'],
            'measures' => [['key' => 'summe', 'field' => 'wert', 'agg' => 'sum', 'label' => 'Einkaufsvolumen (EUR)']],
            'filters' => [['field' => 'art', 'op' => 'eq', 'value' => 'Eingang'], ['field' => 'zeit', 'op' => 'this_year', 'value' => null]],
            'sort' => [['key' => 'summe', 'dir' => 'desc']],
            'visual' => ['type' => 'bar'],
        ], self::demoText('Einkaufsvolumen je Lieferant', 'Purchase volume by supplier', 'Volume d’achat par fournisseur', 'Volume d’acquisto per fornitore'), 'heroicon-o-chart-bar');

        $this->formulaReports($group);
    }

    /**
     * Beispiele für Formeln in Auswertungen: berechnete Spalten einer Liste und berechnete Werte einer
     * gruppierten Auswertung – zusammen alle Rechenzeichen, Funktionen und Zusammenfassungen.
     */
    private function formulaReports(Menu $group): void
    {
        $formula = fn (string $text, array $keys): array => ReportExpression::parse($text, array_combine($keys, $keys));
        $item = ['ek', 'vk', 'bestand', 'min', 'gewicht', 'reserve'];

        // Liste: + − × ÷, Vorzeichen, Klammern, round, abs, min, max, coalesce.
        $this->demoReport($group, self::REPORT_CALCULATION, 'Beispiel für berechnete Spalten: Marge, Aufschlag, Lagerwert, Nachbestellmenge und Gesamtgewicht je Artikel.', [
            'base' => $this->demoTableId('lager_artikel'),
            'fields' => [
                ['key' => 'nr', 'path' => [], 'field' => $this->demoFieldId('lager_artikel.artikelnummer')],
                ['key' => 'bez', 'path' => [], 'field' => $this->demoFieldId('lager_artikel.bezeichnung')],
                ['key' => 'ek', 'path' => [], 'field' => $this->demoFieldId('lager_artikel.einkaufspreis')],
                ['key' => 'vk', 'path' => [], 'field' => $this->demoFieldId('lager_artikel.verkaufspreis')],
                ['key' => 'bestand', 'path' => [], 'field' => $this->demoFieldId('lager_artikel.bestand')],
                ['key' => 'min', 'path' => [], 'field' => $this->demoFieldId('lager_artikel.mindestbestand')],
                ['key' => 'gewicht', 'path' => [], 'field' => $this->demoFieldId('lager_artikel.gewicht_kg')],
                ['key' => 'reserve', 'path' => [], 'field' => $this->demoFieldId('lager_artikel.reserve')],
            ],
            'show' => ['nr', 'bez', 'marge', 'aufschlag', 'lagerwert', 'nachbestellen', 'gedeckt', 'abstand', 'gesamtgewicht'],
            'expressions' => [
                ['key' => 'marge', 'expr' => $formula('[vk] - [ek]', $item), 'label' => 'Marge je Einheit (EUR)', 'scale' => 2],
                ['key' => 'aufschlag', 'expr' => $formula('round(([vk] - [ek]) / [ek] * 100; 1)', $item), 'label' => 'Aufschlag (%)', 'scale' => 1],
                ['key' => 'lagerwert', 'expr' => $formula('[bestand] * [ek]', $item), 'label' => 'Lagerwert (EUR)', 'scale' => 2],
                ['key' => 'nachbestellen', 'expr' => $formula('max(-[reserve]; 0)', $item), 'label' => 'Nachbestellmenge', 'scale' => 0],
                ['key' => 'gedeckt', 'expr' => $formula('min([bestand]; [min])', $item), 'label' => 'Gedeckter Mindestbestand', 'scale' => 0],
                ['key' => 'abstand', 'expr' => $formula('abs([reserve])', $item), 'label' => 'Abstand zum Mindestbestand', 'scale' => 0],
                ['key' => 'gesamtgewicht', 'expr' => $formula('coalesce([gewicht]; 0) * [bestand]', $item), 'label' => 'Gesamtgewicht (kg)', 'scale' => 1],
            ],
            'sort' => [['key' => 'lagerwert', 'dir' => 'desc']],
            'visual' => ['type' => 'table'],
        ], self::demoText('Artikel-Kalkulation', 'Item calculation', 'Calcul des articles', 'Calcolo articoli'), 'heroicon-o-calculator');

        // Gruppiert: Summe, Durchschnitt, Minimum und Maximum je einer Formel, auch mit Feldern des Artikels.
        $movement = ['menge', 'preis', 'vk'];
        $this->demoReport($group, self::REPORT_MOVEMENT_FIGURES, 'Beispiel für berechnete Werte: Summe, Durchschnitt, Minimum und Maximum von Formeln je Artikelgruppe.', [
            'base' => $this->demoTableId('lager_bewegungen'),
            'fields' => [
                ['key' => 'gruppe', 'path' => [$this->demoFieldId('lager_bewegungen.artikel_id')], 'field' => $this->demoFieldId('lager_artikel.gruppe_id'), 'label' => 'Artikelgruppe'],
                ['key' => 'menge', 'path' => [], 'field' => $this->demoFieldId('lager_bewegungen.menge')],
                ['key' => 'preis', 'path' => [], 'field' => $this->demoFieldId('lager_bewegungen.einkaufspreis')],
                ['key' => 'vk', 'path' => [$this->demoFieldId('lager_bewegungen.artikel_id')], 'field' => $this->demoFieldId('lager_artikel.verkaufspreis')],
            ],
            'groups' => ['gruppe'],
            'measures' => [
                ['key' => 'wert', 'agg' => 'sum', 'expr' => $formula('[menge] * [preis]', $movement), 'label' => 'Bewegter Einkaufswert (EUR)', 'scale' => 2],
                ['key' => 'spanne', 'agg' => 'avg', 'expr' => $formula('[menge] * ([vk] - [preis])', $movement), 'label' => 'Ø Spanne je Bewegung (EUR)', 'scale' => 2],
                ['key' => 'faktor', 'agg' => 'min', 'expr' => $formula('round([vk] / [preis]; 2)', $movement), 'label' => 'Kleinster Preisfaktor VK/EK', 'scale' => 2],
                ['key' => 'groesste', 'agg' => 'max', 'expr' => $formula('[menge] * [vk]', $movement), 'label' => 'Grösste Bewegung zu VK (EUR)', 'scale' => 0],
            ],
            'sort' => [['key' => 'wert', 'dir' => 'desc']],
            'visual' => ['type' => 'table'],
        ], self::demoText('Bewegungskennzahlen', 'Movement figures', 'Indicateurs des mouvements', 'Indicatori dei movimenti'), 'heroicon-o-calculator');

        // Kennzahlen (eine große Zahl) – gedacht als Kacheln auf dem Dashboard („Auf mein Dashboard“).
        $this->demoReport($group, self::REPORT_STOCK_VALUE, 'Kennzahl: Wert des gesamten Lagerbestands zum Einkaufspreis.', [
            'base' => $this->demoTableId('lager_artikel'),
            'fields' => [
                ['key' => 'ek', 'path' => [], 'field' => $this->demoFieldId('lager_artikel.einkaufspreis')],
                ['key' => 'bestand', 'path' => [], 'field' => $this->demoFieldId('lager_artikel.bestand')],
            ],
            'measures' => [['key' => 'wert', 'agg' => 'sum', 'expr' => $formula('[bestand] * [ek]', ['bestand', 'ek']), 'label' => 'Lagerwert (EUR)', 'scale' => 2]],
            'visual' => ['type' => 'kpi'],
        ], self::demoText('Lagerwert', 'Stock value', 'Valeur du stock', 'Valore di magazzino'), 'heroicon-o-banknotes');

        $this->demoReport($group, self::REPORT_BELOW_MINIMUM_COUNT, 'Kennzahl: wie viele Artikel unter dem Mindestbestand liegen (0 = alles in Ordnung).', [
            'base' => $this->demoTableId('lager_artikel'),
            'fields' => [['key' => 'reserve', 'path' => [], 'field' => $this->demoFieldId('lager_artikel.reserve')]],
            'measures' => [['key' => 'anzahl', 'field' => null, 'agg' => 'count', 'label' => 'Artikel unter Mindestbestand']],
            'filters' => [['field' => 'reserve', 'op' => 'lt', 'value' => '0']],
            'visual' => ['type' => 'kpi'],
        ], self::demoText('Unter Minimum (Anzahl)', 'Below minimum (count)', 'Sous le minimum (nombre)', 'Sotto il minimo (numero)'), 'heroicon-o-exclamation-circle');
    }

    /** @return list<array<string, mixed>> Verknüpfungsziele vor den Tabellen, die auf sie verweisen */
    private function definitions(): array
    {
        $t = self::demoText(...);
        $countries = ['options' => [
            ['value' => 'DE', 'label' => $t('Deutschland', 'Germany', 'Allemagne', 'Germania')],
            ['value' => 'CH', 'label' => $t('Schweiz', 'Switzerland', 'Suisse', 'Svizzera')],
            ['value' => 'AT', 'label' => $t('Österreich', 'Austria', 'Autriche', 'Austria')],
            ['value' => 'SE', 'label' => $t('Schweden', 'Sweden', 'Suède', 'Svezia')],
        ]];

        return [
            [
                'name' => 'lager_lieferanten', 'label' => $t('Lieferanten', 'Suppliers', 'Fournisseurs', 'Fornitori'), 'icon' => 'heroicon-o-truck',
                'description' => $t('Lieferanten mit Bewertung (1 = schlecht, 5 = sehr gut)', 'Suppliers with rating (1 = poor, 5 = very good)', 'Fournisseurs avec évaluation (1 = mauvais, 5 = très bon)', 'Fornitori con valutazione (1 = scarso, 5 = ottimo)'),
                'fields' => [
                    ['name' => 'name', 'label' => $t('Name', 'Name', 'Nom', 'Nome'), 'type' => 'string', 'options' => ['length' => 150], 'is_required' => true, 'is_unique' => true, 'is_searchable' => true],
                    ['name' => 'land', 'label' => $t('Land', 'Country', 'Pays', 'Paese'), 'type' => 'select', 'options' => $countries, 'is_required' => true],
                    ['name' => 'email', 'label' => $t('E-Mail', 'Email', 'E-mail', 'E-mail'), 'type' => 'string', 'options' => ['length' => 200], 'is_unique' => true, 'validation_rules' => ['email' => true]],
                    ['name' => 'bewertung', 'label' => $t('Bewertung', 'Rating', 'Évaluation', 'Valutazione'), 'type' => 'integer', 'validation_rules' => ['min' => 1, 'max' => 5]],
                    ['name' => 'aktiv', 'label' => $t('Aktiv', 'Active', 'Actif', 'Attivo'), 'type' => 'boolean'],
                ],
            ],
            [
                'name' => 'lager_artikelgruppen', 'label' => $t('Artikelgruppen', 'Item groups', 'Groupes d’articles', 'Gruppi di articoli'), 'icon' => 'heroicon-o-folder',
                'fields' => [
                    ['name' => 'code', 'label' => $t('Code', 'Code', 'Code', 'Codice'), 'type' => 'string', 'options' => ['length' => 10], 'is_required' => true, 'is_unique' => true],
                    ['name' => 'bezeichnung', 'label' => $t('Bezeichnung', 'Name', 'Désignation', 'Denominazione'), 'type' => 'string', 'options' => ['length' => 100], 'is_required' => true, 'is_searchable' => true],
                    // Verweis auf die eigene Tabelle (Hierarchie); Anzeigefeld muss vorher angelegt sein.
                    ['name' => 'obergruppe_id', 'label' => $t('Obergruppe', 'Parent group', 'Groupe parent', 'Gruppo superiore'), 'type' => 'relation', 'options' => ['table' => 'lager_artikelgruppen', 'display_field' => 'bezeichnung']],
                ],
            ],
            [
                'name' => 'lager_artikel', 'label' => $t('Artikel', 'Items', 'Articles', 'Articoli'), 'icon' => 'heroicon-o-cube',
                'fields' => [
                    ['name' => 'artikelnummer', 'label' => $t('Artikelnummer', 'Item number', 'Numéro d’article', 'Codice articolo'), 'type' => 'string', 'options' => ['length' => 20], 'is_required' => true, 'is_unique' => true, 'is_searchable' => true],
                    ['name' => 'bezeichnung', 'label' => $t('Bezeichnung', 'Name', 'Désignation', 'Denominazione'), 'type' => 'string', 'options' => ['length' => 200], 'is_required' => true, 'is_searchable' => true],
                    ['name' => 'gruppe_id', 'label' => $t('Artikelgruppe', 'Item group', 'Groupe d’articles', 'Gruppo di articoli'), 'type' => 'relation', 'options' => ['table' => 'lager_artikelgruppen', 'display_field' => 'bezeichnung'], 'is_required' => true],
                    ['name' => 'lieferant_id', 'label' => $t('Lieferant', 'Supplier', 'Fournisseur', 'Fornitore'), 'type' => 'relation', 'options' => ['table' => 'lager_lieferanten', 'display_field' => 'name']],
                    ['name' => 'einheit', 'label' => $t('Einheit', 'Unit', 'Unité', 'Unità'), 'type' => 'select', 'is_required' => true, 'options' => ['options' => array_map(fn (string $u): array => ['value' => $u], ['Stk', 'kg', 'm', 'l', 'Pack'])]],
                    ['name' => 'einkaufspreis', 'label' => $t('Einkaufspreis (EUR)', 'Purchase price (EUR)', 'Prix d’achat (EUR)', 'Prezzo d’acquisto (EUR)'), 'type' => 'decimal', 'options' => ['precision' => 19, 'scale' => 4], 'is_required' => true, 'validation_rules' => ['min' => 0]],
                    ['name' => 'verkaufspreis', 'label' => $t('Verkaufspreis (EUR)', 'Sales price (EUR)', 'Prix de vente (EUR)', 'Prezzo di vendita (EUR)'), 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2], 'is_required' => true, 'validation_rules' => ['min' => 0]],
                    ['name' => 'mindestbestand', 'label' => $t('Mindestbestand', 'Minimum stock', 'Stock minimum', 'Scorta minima'), 'type' => 'integer', 'is_required' => true, 'validation_rules' => ['min' => 0]],
                    ['name' => 'gewicht_kg', 'label' => $t('Gewicht (kg)', 'Weight (kg)', 'Poids (kg)', 'Peso (kg)'), 'type' => 'decimal', 'options' => ['precision' => 8, 'scale' => 3]],
                    ['name' => 'merkmale', 'label' => $t('Merkmale (JSON)', 'Features (JSON)', 'Caractéristiques (JSON)', 'Caratteristiche (JSON)'), 'type' => 'text', 'show_in_table' => false],
                ],
            ],
            [
                'name' => 'lager_orte', 'label' => $t('Lagerorte', 'Storage locations', 'Emplacements', 'Ubicazioni'), 'icon' => 'heroicon-o-map-pin',
                'fields' => [
                    ['name' => 'kuerzel', 'label' => $t('Kürzel', 'Code', 'Code', 'Sigla'), 'type' => 'string', 'options' => ['length' => 10], 'is_required' => true, 'is_unique' => true],
                    ['name' => 'bezeichnung', 'label' => $t('Bezeichnung', 'Name', 'Désignation', 'Denominazione'), 'type' => 'string', 'options' => ['length' => 100], 'is_required' => true],
                    ['name' => 'typ', 'label' => $t('Typ', 'Type', 'Type', 'Tipo'), 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                        ['value' => 'Hochregal', 'label' => $t('Hochregal', 'High-bay rack', 'Rayonnage haut', 'Scaffalatura alta')],
                        ['value' => 'Kühlraum', 'label' => $t('Kühlraum', 'Cold room', 'Chambre froide', 'Cella frigorifera')],
                        ['value' => 'Freifläche', 'label' => $t('Freifläche', 'Open area', 'Aire extérieure', 'Area aperta')],
                        ['value' => 'Kleinteile', 'label' => $t('Kleinteile', 'Small parts', 'Petites pièces', 'Minuteria')],
                    ]]],
                    ['name' => 'temperatur_c', 'label' => $t('Temperatur (°C)', 'Temperature (°C)', 'Température (°C)', 'Temperatura (°C)'), 'type' => 'decimal', 'options' => ['precision' => 4, 'scale' => 1]],
                    ['name' => 'scanner_ip', 'label' => $t('Scanner-Adresse', 'Scanner address', 'Adresse du scanner', 'Indirizzo dello scanner'), 'type' => 'string', 'options' => ['length' => 45]],
                ],
            ],
            [
                'name' => 'lager_bestaende', 'label' => $t('Bestände', 'Stock levels', 'Stocks', 'Giacenze'), 'icon' => 'heroicon-o-archive-box',
                'fields' => [
                    ['name' => 'artikel_id', 'label' => $t('Artikel', 'Item', 'Article', 'Articolo'), 'type' => 'relation', 'options' => ['table' => 'lager_artikel', 'display_field' => 'bezeichnung'], 'is_required' => true],
                    ['name' => 'lagerort_id', 'label' => $t('Lagerort', 'Location', 'Emplacement', 'Ubicazione'), 'type' => 'relation', 'options' => ['table' => 'lager_orte', 'display_field' => 'bezeichnung'], 'is_required' => true],
                    ['name' => 'menge', 'label' => $t('Menge', 'Quantity', 'Quantité', 'Quantità'), 'type' => 'integer', 'is_required' => true, 'validation_rules' => ['min' => 0]],
                    ['name' => 'letzte_zaehlung', 'label' => $t('Letzte Zählung', 'Last count', 'Dernier comptage', 'Ultimo conteggio'), 'type' => 'date'],
                ],
            ],
            [
                'name' => 'lager_mitarbeitende', 'label' => $t('Mitarbeitende', 'Staff', 'Collaborateurs', 'Collaboratori'), 'icon' => 'heroicon-o-user-group',
                'fields' => [
                    ['name' => 'vorname', 'label' => $t('Vorname', 'First name', 'Prénom', 'Nome'), 'type' => 'string', 'options' => ['length' => 80], 'is_required' => true],
                    ['name' => 'nachname', 'label' => $t('Nachname', 'Last name', 'Nom', 'Cognome'), 'type' => 'string', 'options' => ['length' => 80], 'is_required' => true, 'is_searchable' => true],
                    ['name' => 'kuerzel', 'label' => $t('Kürzel', 'Initials', 'Initiales', 'Sigla'), 'type' => 'string', 'options' => ['length' => 3], 'is_required' => true, 'is_unique' => true],
                    ['name' => 'eintritt', 'label' => $t('Eintritt', 'Joined', 'Entrée', 'Assunzione'), 'type' => 'date', 'is_required' => true],
                ],
            ],
            [
                'name' => 'lager_bewegungen', 'label' => $t('Lagerbewegungen', 'Stock movements', 'Mouvements de stock', 'Movimenti di magazzino'), 'icon' => 'heroicon-o-arrows-right-left',
                'description' => self::movementsDescription(),
                'fields' => [
                    ['name' => 'artikel_id', 'label' => $t('Artikel', 'Item', 'Article', 'Articolo'), 'type' => 'relation', 'options' => ['table' => 'lager_artikel', 'display_field' => 'bezeichnung'], 'is_required' => true],
                    ['name' => 'lagerort_id', 'label' => $t('Lagerort', 'Location', 'Emplacement', 'Ubicazione'), 'type' => 'relation', 'options' => ['table' => 'lager_orte', 'display_field' => 'bezeichnung'], 'is_required' => true],
                    ['name' => 'art', 'label' => $t('Art', 'Kind', 'Type', 'Tipo'), 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                        ['value' => 'Eingang', 'label' => $t('Eingang', 'Receipt', 'Entrée', 'Entrata')],
                        ['value' => 'Ausgang', 'label' => $t('Ausgang', 'Issue', 'Sortie', 'Uscita')],
                        ['value' => 'Umbuchung', 'label' => $t('Umbuchung', 'Transfer', 'Transfert', 'Trasferimento')],
                        ['value' => 'Inventur', 'label' => $t('Inventur', 'Stocktaking', 'Inventaire', 'Inventario')],
                    ]]],
                    ['name' => 'menge', 'label' => $t('Menge', 'Quantity', 'Quantité', 'Quantità'), 'type' => 'integer', 'is_required' => true],
                    ['name' => 'zeitpunkt', 'label' => $t('Zeitpunkt', 'Time', 'Date et heure', 'Data e ora'), 'type' => 'datetime', 'is_required' => true, 'options' => ['prefill_now' => true]],
                    ['name' => 'mitarbeiter_id', 'label' => $t('Mitarbeiter/in', 'Staff member', 'Collaborateur', 'Collaboratore'), 'type' => 'relation', 'options' => ['table' => 'lager_mitarbeitende', 'display_field' => 'kuerzel']],
                    ['name' => 'bemerkung', 'label' => $t('Bemerkung', 'Note', 'Remarque', 'Nota'), 'type' => 'text', 'show_in_table' => false],
                ],
            ],
            [
                'name' => 'lager_messprotokoll', 'label' => $t('Messprotokoll', 'Measurement log', 'Journal des mesures', 'Registro delle misure'), 'icon' => 'heroicon-o-beaker',
                'fields' => [
                    ['name' => 'zeitpunkt', 'label' => $t('Zeitpunkt', 'Time', 'Date et heure', 'Data e ora'), 'type' => 'datetime', 'is_required' => true],
                    ['name' => 'lagerort_id', 'label' => $t('Lagerort', 'Location', 'Emplacement', 'Ubicazione'), 'type' => 'relation', 'options' => ['table' => 'lager_orte', 'display_field' => 'bezeichnung'], 'is_required' => true],
                    ['name' => 'temperatur_c', 'label' => $t('Temperatur (°C)', 'Temperature (°C)', 'Température (°C)', 'Temperatura (°C)'), 'type' => 'decimal', 'options' => ['precision' => 4, 'scale' => 1], 'is_required' => true],
                ],
            ],
        ];
    }

    /** Dieselben Daten wie die externe Lager-Demo (MariaDB/SQL Server). */
    protected function reseedDemoData(): void
    {
        $this->seedRecords();
        $this->purchaseValues();
    }

    private function seedRecords(): void
    {
        $insert = fn (string $table, array $data): int => (int) DynamicRecord::forTableName($table)->create($data)->getKey();

        $suppliers = [];

        foreach ([
            ['Müller Werkzeuge GmbH', 'DE', 'bestellung@mueller-werkzeuge.example', 5, true],
            ['Schweizer Präzision AG', 'CH', 'info@praezision.example', 4, true],
            ['Österreichische Kältetechnik', 'AT', null, 3, true],
            ['Nordic Packaging AB', 'SE', 'sales@nordicpack.example', 4, true],
            ['Bürobedarf Weiß & Söhne', 'DE', 'service@weiss-soehne.example', 2, true],
            ['Alte Handelsgesellschaft', 'DE', null, null, false],
        ] as $i => [$name, $land, $email, $bewertung, $aktiv]) {
            $suppliers[$i + 1] = $insert('lager_lieferanten', compact('name', 'land', 'email', 'bewertung', 'aktiv'));
        }

        $groups = [];

        foreach ([
            ['WZ', 'Werkzeuge', null], ['WZ-HAND', 'Handwerkzeuge', 'WZ'], ['WZ-EL', 'Elektrowerkzeuge', 'WZ'],
            ['BEF', 'Befestigungstechnik', null], ['VERP', 'Verpackung', null], ['KUEHL', 'Kühlware', null],
            ['BUERO', 'Büromaterial', null], ['BUERO-PAP', 'Papier', 'BUERO'],
        ] as [$code, $bezeichnung, $parent]) {
            $groups[$code] = $insert('lager_artikelgruppen', ['code' => $code, 'bezeichnung' => $bezeichnung, 'obergruppe_id' => $parent === null ? null : $groups[$parent]]);
        }

        $items = [];

        foreach ([
            ['A-1001', 'Hammer 500 g, Holzstiel', 'WZ-HAND', 1, 'Stk', '8.40', '14.90', 10, '0.650', '{"material": "Stahl/Esche", "laenge_mm": 330}'],
            ['A-1002', 'Schraubendreher-Satz 6-teilig', 'WZ-HAND', 1, 'Stk', '11.20', '19.50', 8, '0.420', '{"teile": 6, "isoliert": true}'],
            ['A-1003', 'Wasserwaage 60 cm', 'WZ-HAND', 2, 'Stk', '17.80', '29.00', 5, '0.510', '{"libellen": 2, "genauigkeit": "0,5 mm/m"}'],
            ['A-1004', 'Akku-Bohrschrauber 18 V', 'WZ-EL', 2, 'Stk', '89.00', '149.00', 3, '1.450', '{"spannung_v": 18, "akkus": 2, "drehmoment_nm": 60}'],
            ['A-1005', 'Winkelschleifer 125 mm', 'WZ-EL', 1, 'Stk', '54.50', '89.90', 3, '2.100', '{"leistung_w": 1200, "scheibe_mm": 125}'],
            ['A-1006', 'Stichsäge mit Pendelhub', 'WZ-EL', 2, 'Stk', '72.00', '119.00', 2, '2.300', '{"leistung_w": 750, "pendelstufen": 4}'],
            ['B-2001', 'Holzschrauben 4×40, 200 Stück', 'BEF', 1, 'Pack', '4.10', '7.95', 30, '0.800', '{"antrieb": "TX20", "verzinkt": true}'],
            ['B-2002', 'Dübel 8 mm, 100 Stück', 'BEF', 1, 'Pack', '2.60', '4.95', 40, '0.250', null],
            ['B-2003', 'Gewindestange M10, 1 m', 'BEF', 2, 'm', '1.90', '3.40', 50, '0.480', '{"festigkeit": "4.8"}'],
            ['B-2004', 'Kabelbinder schwarz, 500 Stück', 'BEF', 4, 'Pack', '5.30', '9.90', 20, '0.900', '{"laenge_mm": 200, "uv_bestaendig": true}'],
            ['V-3001', 'Faltkarton 40×30×30 cm', 'VERP', 4, 'Stk', '0.85', '1.60', 200, '0.420', '{"welle": "einwellig"}'],
            ['V-3002', 'Luftpolsterfolie 1 m breit', 'VERP', 4, 'm', '0.35', '0.80', 300, '0.030', null],
            ['V-3003', 'Packband braun, 66 m', 'VERP', 4, 'Stk', '1.10', '2.20', 60, '0.190', null],
            ['K-4001', 'Kühlakku 600 g', 'KUEHL', 3, 'Stk', '1.95', '3.90', 40, '0.600', '{"temperatur_c": -18}'],
            ['K-4002', 'Trockeneis 10 kg', 'KUEHL', 3, 'kg', '2.40', '4.50', 50, '1.000', '{"gefahrgut": "UN 1845"}'],
            ['K-4003', 'Kühlbox 25 l', 'KUEHL', 3, 'Stk', '22.00', '39.00', 5, '3.200', '{"volumen_l": 25}'],
            ['P-5001', 'Kopierpapier A4, 500 Blatt', 'BUERO-PAP', 5, 'Pack', '3.20', '5.90', 100, '2.500', '{"gramm": 80, "recycling": false}'],
            ['P-5002', 'Etiketten 70×37 mm, 2400 Stück', 'BUERO', 5, 'Pack', '9.80', '16.50', 15, '1.100', null],
            ['P-5003', 'Kugelschreiber blau, 50 Stück', 'BUERO', 5, 'Pack', '6.50', '12.00', 10, '0.400', null],
            ['P-5004', 'Ordner A4 breit', 'BUERO', null, 'Stk', '1.60', '2.95', 50, '0.450', '{"rueckenbreite_mm": 80}'],
        ] as $i => [$nummer, $bezeichnung, $gruppe, $lieferant, $einheit, $ek, $vk, $min, $gewicht, $merkmale]) {
            $items[$i + 1] = $insert('lager_artikel', [
                'artikelnummer' => $nummer, 'bezeichnung' => $bezeichnung, 'gruppe_id' => $groups[$gruppe],
                'lieferant_id' => $lieferant === null ? null : $suppliers[$lieferant], 'einheit' => $einheit,
                'einkaufspreis' => $ek, 'verkaufspreis' => $vk, 'mindestbestand' => $min, 'gewicht_kg' => $gewicht, 'merkmale' => $merkmale,
            ]);
        }

        $locations = [];

        foreach ([
            ['HR-A', 'Hochregal Halle A', 'Hochregal', null, '2001:db8::a1'],
            ['HR-B', 'Hochregal Halle B', 'Hochregal', null, '2001:db8::b1'],
            ['KR-1', 'Kühlraum 1', 'Kühlraum', '4.0', '::ffff:192.0.2.31'],
            ['KR-2', 'Tiefkühlraum', 'Kühlraum', '-18.0', '::ffff:192.0.2.32'],
            ['FF', 'Freifläche Hof', 'Freifläche', null, null],
            ['KT', 'Kleinteilelager', 'Kleinteile', null, '2001:db8::c1'],
        ] as $i => [$kuerzel, $bezeichnung, $typ, $temperatur, $ip]) {
            $locations[$i + 1] = $insert('lager_orte', ['kuerzel' => $kuerzel, 'bezeichnung' => $bezeichnung, 'typ' => $typ, 'temperatur_c' => $temperatur, 'scanner_ip' => $ip]);
        }

        foreach ([
            [1, 1, 24, '2026-10-01'], [1, 6, 6, '2026-10-01'], [2, 6, 5, '2026-10-01'], [3, 1, 12, '2026-09-15'],
            [4, 1, 4, '2026-10-01'], [4, 2, 1, null], [5, 2, 2, '2026-10-01'], [6, 2, 7, '2026-09-15'],
            [7, 6, 120, '2026-10-20'], [7, 1, 40, '2026-10-20'], [8, 6, 35, '2026-10-20'], [9, 5, 180, '2026-08-30'],
            [10, 6, 22, '2026-10-20'], [11, 2, 650, '2026-10-05'], [11, 5, 300, null], [12, 2, 240, '2026-10-05'],
            [13, 2, 90, '2026-10-05'], [14, 3, 75, '2026-11-02'], [14, 4, 120, '2026-11-02'], [15, 4, 30, '2026-11-02'],
            [16, 3, 9, '2026-11-02'], [17, 1, 140, '2026-10-12'], [18, 6, 11, '2026-10-12'], [19, 6, 25, '2026-10-12'],
            [20, 1, 0, '2026-10-12'],
        ] as [$item, $location, $menge, $zaehlung]) {
            $insert('lager_bestaende', ['artikel_id' => $items[$item], 'lagerort_id' => $locations[$location], 'menge' => $menge, 'letzte_zaehlung' => $zaehlung]);
        }

        $staff = [];

        foreach ([
            ['Jürgen', 'Bäcker', 'JBA', '2015-04-01'], ['Sophie', 'Lefèvre', 'SLE', '2019-09-01'], ['Murat', 'Öztürk', 'MOE', '2021-02-15'],
            ['Ana', 'Kovačević', 'AKO', '2023-06-01'], ['Lukas', 'Strauß', 'LST', '2024-11-01'], ['Greta', 'Nyström', 'GNY', '2026-03-01'],
        ] as $i => [$vorname, $nachname, $kuerzel, $eintritt]) {
            $staff[$i + 1] = $insert('lager_mitarbeitende', compact('vorname', 'nachname', 'kuerzel', 'eintritt'));
        }

        foreach ([
            [1, 1, 'Eingang', 30, '2026-09-02 08:15', 1, 'Lieferung Müller, LS 4711'], [1, 1, 'Ausgang', -6, '2026-09-10 14:20', 3, null],
            [1, 6, 'Umbuchung', 6, '2026-09-11 09:00', 3, 'Für Werkstattausgabe'], [2, 6, 'Eingang', 10, '2026-09-03 10:30', 2, null],
            [2, 6, 'Ausgang', -5, '2026-09-25 16:45', 4, null], [3, 1, 'Eingang', 12, '2026-09-04 07:50', 1, null],
            [4, 1, 'Eingang', 6, '2026-09-05 11:10', 2, 'Schweizer Präzision, Teillieferung'], [4, 1, 'Ausgang', -2, '2026-10-02 13:00', 5, null],
            [4, 2, 'Eingang', 1, '2026-10-15 08:05', 2, 'Rücksendung Kunde, geprüft'], [5, 2, 'Eingang', 4, '2026-09-06 09:40', 1, null],
            [5, 2, 'Ausgang', -2, '2026-10-08 15:30', 3, null], [6, 2, 'Eingang', 7, '2026-09-08 10:00', 2, null],
            [7, 6, 'Eingang', 200, '2026-09-01 07:30', 1, null], [7, 6, 'Umbuchung', -40, '2026-09-20 12:00', 3, 'Nach Halle A'],
            [7, 1, 'Umbuchung', 40, '2026-09-20 12:05', 3, 'Aus Kleinteilelager'], [7, 6, 'Ausgang', -40, '2026-10-18 14:10', 4, null],
            [8, 6, 'Eingang', 50, '2026-09-01 07:45', 1, null], [8, 6, 'Ausgang', -15, '2026-10-01 10:20', 5, null],
            [9, 5, 'Eingang', 200, '2026-08-25 06:55', 1, 'Sperrgut, Hof'], [9, 5, 'Inventur', -20, '2026-08-30 17:00', 2, 'Inventurdifferenz'],
            [10, 6, 'Eingang', 25, '2026-09-12 08:30', 4, null], [10, 6, 'Ausgang', -3, '2026-10-19 11:15', 5, null],
            [11, 2, 'Eingang', 1000, '2026-09-15 09:00', 2, 'Nordic Packaging, 4 Paletten'], [11, 2, 'Ausgang', -350, '2026-10-03 13:45', 3, null],
            [11, 5, 'Eingang', 300, '2026-10-28 07:20', 6, 'Zwischenlager Hof'], [12, 2, 'Eingang', 300, '2026-09-15 09:30', 2, null],
            [12, 2, 'Ausgang', -60, '2026-10-04 10:00', 4, null], [13, 2, 'Eingang', 120, '2026-09-15 09:45', 2, null],
            [13, 2, 'Ausgang', -30, '2026-10-05 15:00', 5, null], [14, 3, 'Eingang', 100, '2026-10-20 06:30', 6, 'Kühlkette eingehalten'],
            [14, 3, 'Ausgang', -25, '2026-10-30 08:00', 3, null], [14, 4, 'Eingang', 120, '2026-10-20 06:45', 6, null],
            [15, 4, 'Eingang', 40, '2026-10-25 07:00', 6, null], [15, 4, 'Ausgang', -10, '2026-11-01 09:30', 4, null],
            [16, 3, 'Eingang', 10, '2026-10-22 10:15', 1, null], [16, 3, 'Ausgang', -1, '2026-11-02 14:00', 5, 'Musterstück Vertrieb'],
            [17, 1, 'Eingang', 200, '2026-09-30 08:00', 2, null], [17, 1, 'Ausgang', -60, '2026-10-10 11:30', 3, null],
            [18, 6, 'Eingang', 12, '2026-10-01 09:10', 4, null], [20, 1, 'Inventur', -3, '2026-10-12 16:30', 2, 'Bestand 0 – nachbestellen'],
        ] as [$item, $location, $art, $menge, $zeitpunkt, $person, $bemerkung]) {
            $insert('lager_bewegungen', [
                'artikel_id' => $items[$item], 'lagerort_id' => $locations[$location], 'art' => $art, 'menge' => $menge,
                'zeitpunkt' => $zeitpunkt.':00', 'mitarbeiter_id' => $staff[$person], 'bemerkung' => $bemerkung,
            ]);
        }

        foreach ([
            ['2026-11-01 06:00', 3.8, -18.2], ['2026-11-01 12:00', 4.1, -17.9], ['2026-11-01 18:00', 4.4, -18.0],
            ['2026-11-02 06:00', 3.9, -18.4], ['2026-11-02 12:00', 5.2, -17.6], ['2026-11-02 18:00', 4.0, -18.1],
            ['2026-11-03 06:00', 3.7, -18.3], ['2026-11-03 12:00', 4.2, -18.0], ['2026-11-03 18:00', 4.3, -17.8],
            ['2026-11-04 06:00', 3.9, -18.2],
        ] as [$zeitpunkt, $kuehlraum, $tiefkuehl]) {
            $insert('lager_messprotokoll', ['zeitpunkt' => $zeitpunkt.':00', 'lagerort_id' => $locations[3], 'temperatur_c' => (string) $kuehlraum]);
            $insert('lager_messprotokoll', ['zeitpunkt' => $zeitpunkt.':00', 'lagerort_id' => $locations[4], 'temperatur_c' => (string) $tiefkuehl]);
        }
    }
}
