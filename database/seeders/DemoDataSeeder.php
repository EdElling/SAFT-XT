<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\DynamicData\DataMenu;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Services\DynamicSchemaService;
use App\Support\Translations;
use Database\Seeders\Concerns\SeedsDemoTables;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Database\Seeder;

/**
 * Demo-Daten für den Table-Designer: Artikel, Kunden, Bestellungen, Bestellpositionen in der Menügruppe
 * „Shop (Demo)“, dazu eine geteilte Beispiel-Auswertung mit Diagramm.
 *
 * Nicht Teil von DatabaseSeeder – bewusst manuell:
 *   php artisan db:seed --class=DemoDataSeeder
 * Wiederholbar: vorhandene Tabellen/Daten werden nicht dupliziert.
 */
class DemoDataSeeder extends Seeder
{
    use SeedsDemoTables;

    public const int RECORDS = 10;

    /**
     * Bestellungen je Kunde (K-20001 … K-20010): unterschiedlich viele, damit Summe, Durchschnitt und Anzahl
     * über Kind-Datensätze in der Demo nachvollziehbar sind. Der zuletzt angelegte Kunde steht in der Liste
     * oben und hat Bestellungen (Handbuch-Screenshots).
     */
    public const array ORDERS_PER_CUSTOMER = [2, 0, 1, 3, 1, 0, 2, 1, 4, 3];

    /** Menügruppe der Demo-Tabellen (früher „Daten“, die Vorgabe-Gruppe für neue Tabellen). */
    public const array SHOP_GROUP = ['de' => 'Shop (Demo)', 'en' => 'Shop (demo)', 'fr' => 'Boutique (démo)', 'it' => 'Negozio (demo)'];

    public const string REPORT_NAME = 'Umsatz je Monat und Kategorie';

    private Generator $faker;

    /** App der Demo (B-107); der Schlüssel ist fest, damit wiederholtes Einspielen dieselbe App trifft. */
    public function demoAppDefinition(): array
    {
        return ['uuid' => '8f0c6a52-6d0e-4c8e-9a43-5b1c2f6e0a01', 'name' => ['de' => 'Shop (Demo)', 'en' => 'Shop (demo)', 'fr' => 'Boutique (démo)', 'it' => 'Negozio (demo)'], 'prefix' => null, 'icon' => 'heroicon-o-shopping-cart', 'sort_order' => 500];
    }

    public function run(DynamicSchemaService $schema, DataMenu $menu): void
    {
        $this->faker = FakerFactory::create('de_DE');
        $this->faker->seed(2026);

        $this->demoAssertNamesFree($this->definitions());
        $shop = $this->shopGroup();

        foreach ($this->definitions() as $definition) {
            $table = CustomTable::query()->where('name', $definition['name'])->first();

            if ($table === null) {
                $table = $schema->createTable(['app_id' => $this->demoApp()->id, ...$definition], $definition['fields']);
            } else {
                $this->addMissingTranslations($schema, $table, $definition);
            }

            if (! $menu->exists($table)) {
                $menu->create($table, $shop->id);
            }
        }

        // Datenmodell der Demo-Tabellen am Ende ihrer Gruppe.
        $group = Menu::query()->where('route', DataMenu::ROUTE)->where('route_parameters->table', 'kunden')->first()?->parent;

        if ($group instanceof Menu) {
            $menu->ensureDataModel($group);
        }

        $this->seedRecords();
        $this->configureDetails($schema);
        $this->configureRules($schema);
        $this->configureComputed($schema);

        if ($group instanceof Menu) {
            $this->seedReport($group);
        }
    }

    /**
     * Gruppe „Shop (Demo)“. Ältere Installationen hatten die Demo-Tabellen in „Daten“: diese Gruppe wird
     * umbenannt (neue Tabellen landen dann wieder in einer eigenen Gruppe „Daten“).
     */
    private function shopGroup(): Menu
    {
        $root = fn () => Menu::query()->whereNull('parent_id')->whereNull('route')->whereNull('url');
        $existing = $root()->where('label->de', self::SHOP_GROUP['de'])->first();

        if ($existing instanceof Menu) {
            return $existing;
        }

        $legacy = Menu::query()->where('route', DataMenu::ROUTE)->where('route_parameters->table', 'kunden')->first()?->parent;

        if ($legacy instanceof Menu && $legacy->parent_id === null && $legacy->getTranslation('label', 'de') === DataMenu::GROUP_LABEL) {
            $legacy->update(['label' => self::SHOP_GROUP]);

            return $legacy;
        }

        return Menu::create(['app_id' => $this->demoApp()->id, 'label' => self::SHOP_GROUP, 'sort_order' => 500, 'is_active' => true]);
    }

    /**
     * Beispiel-Auswertung: Umsatz der Bestellpositionen je Monat (Bestelldatum) und Artikelkategorie,
     * ohne stornierte Bestellungen, als Balkendiagramm – über zwei Verknüpfungen, ein berechnetes Feld,
     * einen Filter und eine Kreuztabelle. Geteilt und im Menü der Shop-Gruppe.
     */
    private function seedReport(Menu $group): void
    {
        $field = fn (string $table, string $name): int => (int) CustomTable::query()->where('name', $table)->sole()->fields()->where('name', $name)->value('id');
        $order = $field('bestellpositionen', 'bestellung_id');
        $definition = [
            'base' => (int) CustomTable::query()->where('name', 'bestellpositionen')->value('id'),
            'fields' => [
                ['key' => 'monat', 'path' => [$order], 'field' => $field('bestellungen', 'bestelldatum'), 'bucket' => 'month', 'label' => 'Monat'],
                ['key' => 'kategorie', 'path' => [$field('bestellpositionen', 'artikel_id')], 'field' => $field('artikel', 'kategorie')],
                ['key' => 'betrag', 'path' => [], 'field' => $field('bestellpositionen', 'betrag')],
                ['key' => 'status', 'path' => [$order], 'field' => $field('bestellungen', 'status')],
            ],
            'groups' => ['monat'],
            'columns' => ['kategorie'],
            'measures' => [['key' => 'umsatz', 'field' => 'betrag', 'agg' => 'sum', 'label' => 'Umsatz (EUR)']],
            'filters' => [['field' => 'status', 'op' => 'neq', 'value' => 'storniert']],
            'visual' => ['type' => 'bar'],
        ];

        $this->demoReport(
            $group,
            self::REPORT_NAME,
            'Umsatz der Bestellpositionen je Monat und Artikelkategorie, ohne stornierte Bestellungen.',
            $definition,
            self::t('Umsatz je Monat', 'Revenue per month', 'Chiffre d’affaires par mois', 'Fatturato per mese'),
            'heroicon-o-chart-bar',
        );
    }

    /**
     * @return array<string, string> Text je Sprache
     */
    private static function t(string $de, string $en, string $fr, string $it): array
    {
        return ['de' => $de, 'en' => $en, 'fr' => $fr, 'it' => $it];
    }

    /**
     * Ältere Demo-Tabellen haben nur deutsche Texte. Fehlende Sprachen nur dort ergänzen, wo noch der
     * ursprüngliche deutsche Text steht; eigene Umbenennungen und Übersetzungen bleiben erhalten.
     *
     * @param  array<string, mixed>  $definition
     */
    private function addMissingTranslations(DynamicSchemaService $schema, CustomTable $table, array $definition): void
    {
        $changes = [];

        foreach (['label', 'description'] as $key) {
            $merged = self::merge($table->getTranslations($key), $definition[$key]);

            if ($merged !== null) {
                $changes[$key] = $merged;
            }
        }

        if ($changes !== []) {
            $schema->updateTable($table, $changes);
        }

        foreach ($definition['fields'] as $fieldDefinition) {
            $field = $table->fields()->where('name', $fieldDefinition['name'])->first();

            if ($field === null) {
                continue;
            }

            $changes = [];
            $label = self::merge($field->getTranslations('label'), $fieldDefinition['label']);

            if ($label !== null) {
                $changes['label'] = $label;
            }

            $options = $field->optionsArray();
            $translated = false;

            foreach ((array) ($options['options'] ?? []) as $i => $option) {
                $wanted = collect((array) ($fieldDefinition['options']['options'] ?? []))->firstWhere('value', $option['value']);
                $merged = $wanted === null ? null : self::merge(Translations::perLocale($option['label'] ?? null), $wanted['label']);

                if ($merged !== null) {
                    $options['options'][$i]['label'] = $merged;
                    $translated = true;
                }
            }

            if ($translated) {
                $changes['options'] = $options;
            }

            if ($changes !== []) {
                $schema->updateField($field, $changes);
            }
        }
    }

    /**
     * Ergänzte Übersetzungen oder null, wenn nichts zu tun ist oder der deutsche Text geändert wurde.
     *
     * @param  array<string, string>  $current
     * @param  array<string, string>  $wanted
     * @return array<string, string>|null
     */
    private static function merge(array $current, array $wanted): ?array
    {
        if (($current['de'] ?? null) !== $wanted['de']) {
            return null;
        }

        $merged = [...$wanted, ...array_filter($current, filled(...))];

        return $merged == $current ? null : $merged;
    }

    /**
     * Demo berechneter Felder: Betrag je Position, Gesamtpreis (Summe der Beträge) und MWST je Bestellung.
     * Nur fehlende Felder werden ergänzt; die Werte berechnet SAFT dabei für alle Datensätze.
     */
    private function configureComputed(DynamicSchemaService $schema): void
    {
        $money = ['result' => 'decimal', 'precision' => 12, 'scale' => 2];

        foreach ([
            ['bestellpositionen', 'betrag', self::t('Betrag (EUR)', 'Amount (EUR)', 'Montant (EUR)', 'Importo (EUR)'), 'round(menge * einzelpreis, 2)'],
            ['bestellungen', 'gesamtpreis', self::t('Gesamtpreis (EUR)', 'Total (EUR)', 'Total (EUR)', 'Totale (EUR)'), 'sum(bestellpositionen.betrag)'],
            ['bestellungen', 'mwst', self::t('MWST 8,1 % (EUR)', 'VAT 8.1 % (EUR)', 'TVA 8,1 % (EUR)', 'IVA 8,1 % (EUR)'), 'round(gesamtpreis * 0.081, 2)'],
        ] as [$tableName, $name, $label, $formula]) {
            $table = CustomTable::query()->where('name', $tableName)->firstOrFail();

            if (! $table->fields()->where('name', $name)->exists()) {
                $schema->addField($table, ['name' => $name, 'label' => $label, 'type' => 'computed', 'options' => [...$money, 'formula' => $formula]]);
            }
        }
    }

    /** Regel-Demo: „Lieferdatum ≥ Bestelldatum“, sofern die Bestellungen noch keine Regeln haben. */
    private function configureRules(DynamicSchemaService $schema): void
    {
        $orders = CustomTable::query()->with('fields')->where('name', 'bestellungen')->firstOrFail();

        if ($orders->tableRules() !== []) {
            return;
        }

        $schema->updateTable($orders, ['validation_rules' => [[
            'left' => 'lieferdatum',
            'operator' => '>=',
            'right_kind' => 'field',
            'right_field' => 'bestelldatum',
            'when_filled' => true,
        ]]]);
    }

    /**
     * Master-Detail-Demo: Details der Tabellen (Table-Designer), sofern noch keine
     * konfiguriert sind – spätere Anpassungen bleiben erhalten.
     */
    private function configureDetails(DynamicSchemaService $schema): void
    {
        $this->demoDetails([
            'kunden' => ['bestellungen.kunde_id'],
            'bestellungen' => ['bestellpositionen.bestellung_id'],
            'artikel' => ['bestellpositionen.artikel_id'],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            [
                'name' => 'artikel',
                'label' => self::t('Artikel', 'Articles', 'Articles', 'Articoli'),
                'icon' => 'heroicon-o-cube',
                'description' => self::t('Artikelstamm mit Preisen und Lagerbestand.', 'Article master data with prices and stock.', 'Fichier des articles avec prix et stock.', 'Anagrafica articoli con prezzi e giacenze.'),
                'fields' => [
                    ['name' => 'artikelnummer', 'label' => self::t('Artikelnummer', 'Item number', 'Numéro d’article', 'Codice articolo'), 'type' => 'string', 'options' => ['length' => 20], 'is_required' => true, 'is_unique' => true, 'is_searchable' => true],
                    ['name' => 'bezeichnung', 'label' => self::t('Bezeichnung', 'Name', 'Désignation', 'Denominazione'), 'type' => 'string', 'options' => ['length' => 150], 'is_required' => true, 'is_searchable' => true],
                    ['name' => 'kategorie', 'label' => self::t('Kategorie', 'Category', 'Catégorie', 'Categoria'), 'type' => 'select', 'options' => ['options' => [
                        ['value' => 'hardware', 'label' => self::t('Hardware', 'Hardware', 'Matériel', 'Hardware')],
                        ['value' => 'software', 'label' => self::t('Software', 'Software', 'Logiciel', 'Software')],
                        ['value' => 'zubehoer', 'label' => self::t('Zubehör', 'Accessories', 'Accessoires', 'Accessori')],
                        ['value' => 'dienstleistung', 'label' => self::t('Dienstleistung', 'Service', 'Prestation de service', 'Servizio')],
                    ]]],
                    ['name' => 'preis', 'label' => self::t('Preis (EUR)', 'Price (EUR)', 'Prix (EUR)', 'Prezzo (EUR)'), 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2], 'is_required' => true, 'validation_rules' => ['min' => 0]],
                    ['name' => 'lagerbestand', 'label' => self::t('Lagerbestand', 'Stock', 'Stock', 'Giacenza'), 'type' => 'integer', 'validation_rules' => ['min' => 0]],
                    ['name' => 'aktiv', 'label' => self::t('Aktiv', 'Active', 'Actif', 'Attivo'), 'type' => 'boolean'],
                    ['name' => 'beschreibung', 'label' => self::t('Beschreibung', 'Description', 'Description', 'Descrizione'), 'type' => 'text', 'show_in_table' => false],
                ],
            ],
            [
                'name' => 'kunden',
                'label' => self::t('Kunden', 'Customers', 'Clients', 'Clienti'),
                'icon' => 'heroicon-o-building-office',
                'description' => self::t('Kundenstamm.', 'Customer master data.', 'Fichier des clients.', 'Anagrafica clienti.'),
                'fields' => [
                    ['name' => 'kundennummer', 'label' => self::t('Kundennummer', 'Customer number', 'Numéro de client', 'Numero cliente'), 'type' => 'string', 'options' => ['length' => 20], 'is_required' => true, 'is_unique' => true, 'is_searchable' => true],
                    ['name' => 'firma', 'label' => self::t('Firma', 'Company', 'Société', 'Azienda'), 'type' => 'string', 'options' => ['length' => 150], 'is_required' => true, 'is_searchable' => true],
                    ['name' => 'ansprechpartner', 'label' => self::t('Ansprechpartner', 'Contact person', 'Interlocuteur', 'Referente'), 'type' => 'string', 'options' => ['length' => 100], 'is_searchable' => true],
                    ['name' => 'email', 'label' => self::t('E-Mail', 'Email', 'E-mail', 'E-mail'), 'type' => 'string', 'validation_rules' => ['email' => true]],
                    ['name' => 'telefon', 'label' => self::t('Telefon', 'Phone', 'Téléphone', 'Telefono'), 'type' => 'string', 'options' => ['length' => 40], 'show_in_table' => false],
                    ['name' => 'plz', 'label' => self::t('PLZ', 'Postcode', 'Code postal', 'CAP'), 'type' => 'string', 'options' => ['length' => 10]],
                    ['name' => 'ort', 'label' => self::t('Ort', 'City', 'Localité', 'Località'), 'type' => 'string', 'options' => ['length' => 100], 'is_searchable' => true],
                    ['name' => 'land', 'label' => self::t('Land', 'Country', 'Pays', 'Paese'), 'type' => 'select', 'options' => ['options' => [
                        ['value' => 'DE', 'label' => self::t('Deutschland', 'Germany', 'Allemagne', 'Germania')],
                        ['value' => 'AT', 'label' => self::t('Österreich', 'Austria', 'Autriche', 'Austria')],
                        ['value' => 'CH', 'label' => self::t('Schweiz', 'Switzerland', 'Suisse', 'Svizzera')],
                    ]]],
                    ['name' => 'kunde_seit', 'label' => self::t('Kunde seit', 'Customer since', 'Client depuis', 'Cliente dal'), 'type' => 'date'],
                ],
            ],
            [
                'name' => 'bestellungen',
                'label' => self::t('Bestellungen', 'Orders', 'Commandes', 'Ordini'),
                'icon' => 'heroicon-o-shopping-cart',
                'description' => self::t('Bestellköpfe; Artikel stehen in den Bestellpositionen.', 'Order headers; items are listed in the order lines.', 'En-têtes de commande ; les articles figurent dans les lignes de commande.', 'Testate degli ordini; gli articoli sono nelle righe d’ordine.'),
                'fields' => [
                    ['name' => 'bestellnummer', 'label' => self::t('Bestellnummer', 'Order number', 'Numéro de commande', 'Numero d’ordine'), 'type' => 'string', 'options' => ['length' => 20], 'is_required' => true, 'is_unique' => true, 'is_searchable' => true],
                    ['name' => 'kunde_id', 'label' => self::t('Kunde', 'Customer', 'Client', 'Cliente'), 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma'], 'is_required' => true],
                    ['name' => 'bestelldatum', 'label' => self::t('Bestelldatum', 'Order date', 'Date de commande', 'Data dell’ordine'), 'type' => 'date', 'is_required' => true],
                    ['name' => 'status', 'label' => self::t('Status', 'Status', 'Statut', 'Stato'), 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                        ['value' => 'neu', 'label' => self::t('Neu', 'New', 'Nouvelle', 'Nuovo')],
                        ['value' => 'in_bearbeitung', 'label' => self::t('In Bearbeitung', 'In progress', 'En cours', 'In lavorazione')],
                        ['value' => 'versendet', 'label' => self::t('Versendet', 'Shipped', 'Expédiée', 'Spedito')],
                        ['value' => 'storniert', 'label' => self::t('Storniert', 'Cancelled', 'Annulée', 'Annullato')],
                    ]]],
                    ['name' => 'lieferdatum', 'label' => self::t('Lieferdatum', 'Delivery date', 'Date de livraison', 'Data di consegna'), 'type' => 'date'],
                    ['name' => 'bemerkung', 'label' => self::t('Bemerkung', 'Note', 'Remarque', 'Nota'), 'type' => 'text', 'show_in_table' => false],
                ],
            ],
            [
                'name' => 'bestellpositionen',
                'label' => self::t('Bestellpositionen', 'Order lines', 'Lignes de commande', 'Righe d’ordine'),
                'icon' => 'heroicon-o-list-bullet',
                'description' => self::t('Artikel je Bestellung.', 'Items per order.', 'Articles par commande.', 'Articoli per ordine.'),
                'fields' => [
                    ['name' => 'bestellung_id', 'label' => self::t('Bestellung', 'Order', 'Commande', 'Ordine'), 'type' => 'relation', 'options' => ['table' => 'bestellungen', 'display_field' => 'bestellnummer'], 'is_required' => true],
                    ['name' => 'position', 'label' => self::t('Pos.', 'Pos.', 'Pos.', 'Pos.'), 'type' => 'integer', 'is_required' => true, 'validation_rules' => ['min' => 1], 'options' => ['suggest_next' => true, 'suggest_scope' => 'bestellung_id']],
                    ['name' => 'artikel_id', 'label' => self::t('Artikel', 'Item', 'Article', 'Articolo'), 'type' => 'relation', 'options' => ['table' => 'artikel', 'display_field' => 'bezeichnung'], 'is_required' => true],
                    ['name' => 'menge', 'label' => self::t('Menge', 'Quantity', 'Quantité', 'Quantità'), 'type' => 'integer', 'is_required' => true, 'validation_rules' => ['min' => 1]],
                    ['name' => 'einzelpreis', 'label' => self::t('Einzelpreis (EUR)', 'Unit price (EUR)', 'Prix unitaire (EUR)', 'Prezzo unitario (EUR)'), 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2, 'copy_from' => 'artikel_id.preis'], 'is_required' => true, 'validation_rules' => ['min' => 0]],
                ],
            ],
        ];
    }

    protected function reseedDemoData(): void
    {
        $this->faker = FakerFactory::create('de_DE');
        $this->faker->seed(2026);
        $this->seedRecords();
    }

    private function seedRecords(): void
    {
        $artikel = DynamicRecord::forTableName('artikel');
        $kunden = DynamicRecord::forTableName('kunden');
        $bestellungen = DynamicRecord::forTableName('bestellungen');
        $positionen = DynamicRecord::forTableName('bestellpositionen');

        if ($artikel->newQuery()->withTrashed()->doesntExist()) {
            $produkte = ['Notebook 14"', 'Monitor 27"', 'Dockingstation', 'Tastatur', 'Maus', 'Headset', 'Office-Lizenz', 'Virenschutz', 'Installation vor Ort', 'Wartungsvertrag'];
            $kategorien = ['hardware', 'hardware', 'zubehoer', 'zubehoer', 'zubehoer', 'zubehoer', 'software', 'software', 'dienstleistung', 'dienstleistung'];

            foreach ($produkte as $i => $name) {
                $artikel->create([
                    'artikelnummer' => sprintf('A-%04d', 1001 + $i),
                    'bezeichnung' => $name,
                    'kategorie' => $kategorien[$i],
                    'preis' => $this->faker->randomFloat(2, 9, 1800),
                    'lagerbestand' => $kategorien[$i] === 'dienstleistung' ? null : $this->faker->numberBetween(0, 250),
                    'aktiv' => $i !== 7,
                    'beschreibung' => $this->faker->sentence(12),
                ]);
            }
        }

        if ($kunden->newQuery()->withTrashed()->doesntExist()) {
            for ($i = 0; $i < self::RECORDS; $i++) {
                $kunden->create([
                    'kundennummer' => sprintf('K-%05d', 20001 + $i),
                    'firma' => $this->faker->company(),
                    'ansprechpartner' => $this->faker->name(),
                    'email' => $this->faker->unique()->companyEmail(),
                    'telefon' => $this->faker->phoneNumber(),
                    'plz' => $this->faker->postcode(),
                    'ort' => $this->faker->city(),
                    'land' => $this->faker->randomElement(['DE', 'DE', 'DE', 'AT', 'CH']),
                    'kunde_seit' => $this->faker->dateTimeBetween('-8 years', '-1 month')->format('Y-m-d'),
                ]);
            }
        }

        if ($bestellungen->newQuery()->withTrashed()->doesntExist()) {
            $kundenIds = $kunden->newQuery()->orderBy('id')->pluck('id')->all();
            $artikelRows = $artikel->newQuery()->orderBy('id')->get(['id', 'preis'])->all();

            $kundeFuerBestellung = [];

            foreach (self::ORDERS_PER_CUSTOMER as $index => $count) {
                for ($n = 0; $n < $count; $n++) {
                    $kundeFuerBestellung[] = $kundenIds[$index % count($kundenIds)];
                }
            }

            foreach ($kundeFuerBestellung as $i => $kundeId) {
                $datum = $this->faker->dateTimeBetween('-6 months', '-1 day');
                $status = $this->faker->randomElement(['neu', 'in_bearbeitung', 'versendet', 'versendet', 'storniert']);

                $bestellung = $bestellungen->create([
                    'bestellnummer' => sprintf('B-2026-%04d', 1 + $i),
                    'kunde_id' => $kundeId,
                    'bestelldatum' => $datum->format('Y-m-d'),
                    'status' => $status,
                    'lieferdatum' => $status === 'versendet' ? (clone $datum)->modify('+3 days')->format('Y-m-d') : null,
                    'bemerkung' => $this->faker->boolean(30) ? $this->faker->sentence() : null,
                ]);

                // 1–3 Positionen je Bestellung.
                foreach ($this->faker->randomElements($artikelRows, $this->faker->numberBetween(1, 3)) as $pos => $art) {
                    $positionen->create([
                        'bestellung_id' => $bestellung->id,
                        'position' => $pos + 1,
                        'artikel_id' => $art->id,
                        'menge' => $this->faker->numberBetween(1, 10),
                        'einzelpreis' => $art->preis,
                    ]);
                }
            }
        }
    }
}
