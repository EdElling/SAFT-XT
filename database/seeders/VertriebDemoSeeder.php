<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\DynamicData\Rules\CopySource;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Services\DynamicSchemaService;
use Database\Seeders\Concerns\SeedsDemoTables;
use Illuminate\Database\Seeder;

/**
 * Vertrieb (Demo) als eigene App, die auf der Lager-Demo aufsetzt: Kunden, Events, Anfragen mit Positionen und
 * Bestellungen. Die Positionen verknüpfen über die Schnittstelle der Lager-App auf deren Artikel (B-107, Etappe 5) und
 * übernehmen den Verkaufspreis. Fehlt die Lager-Demo, wird sie zuerst eingespielt.
 *
 *   php artisan db:seed --class=VertriebDemoSeeder
 *
 * Wiederholbar: vorhandene Tabellen und Daten werden nicht dupliziert, eigene Anpassungen bleiben.
 */
class VertriebDemoSeeder extends Seeder
{
    use SeedsDemoTables;

    public const array GROUP = ['de' => 'Vertrieb (Demo)', 'en' => 'Sales (demo)', 'fr' => 'Ventes (démo)', 'it' => 'Vendite (demo)'];

    /** Felder der Lager-Artikel, die die Vertriebs-App über die Schnittstelle liest. */
    public const array SHARED_ITEM_FIELDS = ['artikelnummer', 'bezeichnung', 'einheit', 'verkaufspreis'];

    public const string REPORT_STATUS = 'Anfragen je Status';

    public const string REPORT_ITEMS = 'Angefragte Artikel';

    public const string REPORT_WON = 'Gewonnene Anfragen je Kunde';

    /** App der Demo (B-107); der Schlüssel ist fest, damit wiederholtes Einspielen dieselbe App trifft. */
    public function demoAppDefinition(): array
    {
        return ['uuid' => '8f0c6a52-6d0e-4c8e-9a43-5b1c2f6e0a04', 'name' => ['de' => 'Vertrieb (Demo)', 'en' => 'Sales (demo)', 'fr' => 'Ventes (démo)', 'it' => 'Vendite (demo)'], 'prefix' => 'vertrieb', 'icon' => 'heroicon-o-briefcase', 'sort_order' => 530, 'manual' => 'vertrieb'];
    }

    public function run(): void
    {
        $lager = app(LagerDemoSeeder::class);

        if (! $lager->demoInstalled()) {
            $this->call(LagerDemoSeeder::class);
        }

        $this->shareItems();
        $group = $this->demoGroup(self::GROUP, 530);
        $created = $this->demoTables($group, $this->definitions());

        if (in_array('vertrieb_kunden', $created, true)) {
            $this->seedRecords();
        }

        $this->demoRules('vertrieb_events', [[
            'left' => 'ende', 'operator' => '>=', 'right_kind' => 'field', 'right_field' => 'beginn', 'when_filled' => true,
        ]]);
        $this->demoDetails([
            'vertrieb_kunden' => ['vertrieb_anfragen.kunde_id', 'vertrieb_bestellungen.kunde_id'],
            'vertrieb_events' => ['vertrieb_anfragen.event_id'],
            'vertrieb_bestellungen' => ['vertrieb_anfragen.bestellung_id'],
            'vertrieb_anfragen' => ['vertrieb_anfragepositionen.anfrage_id'],
        ]);
        $this->demoComputed([
            ['vertrieb_anfragepositionen', 'betrag', self::demoText('Betrag (EUR)', 'Amount (EUR)', 'Montant (EUR)', 'Importo (EUR)'), 'round(menge * preis, 2)', ['result' => 'decimal', 'precision' => 12, 'scale' => 2]],
            ['vertrieb_anfragen', 'summe', self::demoText('Summe (EUR)', 'Total (EUR)', 'Total (EUR)', 'Totale (EUR)'), 'sum(vertrieb_anfragepositionen.betrag)', ['result' => 'decimal', 'precision' => 14, 'scale' => 2]],
        ]);
        $this->reports($group);
        $this->submenus($group);
        $this->seedDemoManual();
    }

    /**
     * Schnittstelle der Lager-App: Artikel mit den Feldern, die der Vertrieb braucht, für andere Apps freigeben. Schon
     * freigegebene Felder bleiben freigegeben.
     */
    private function shareItems(): void
    {
        $items = CustomTable::query()->with('fields')->where('name', 'lager_artikel')->sole();
        $shared = $items->is_shared ? $items->fields->where('is_shared', true)->pluck('name')->all() : [];
        $wanted = array_values(array_unique([...$shared, ...self::SHARED_ITEM_FIELDS]));

        if (! $items->is_shared || array_diff($wanted, $shared) !== []) {
            app(DynamicSchemaService::class)->updateInterface($items, true, $wanted);
        }
    }

    /** Verkauf, Kunden und Events, Auswertungen; das Datenmodell bleibt direkt in der Gruppe. */
    private function submenus(Menu $group): void
    {
        $this->demoSubmenus($group, [
            [
                'label' => self::demoText('Verkauf', 'Sales', 'Vente', 'Vendita'), 'icon' => 'heroicon-o-inbox-arrow-down', 'sort_order' => 10,
                'entries' => [['table', 'vertrieb_anfragen'], ['table', 'vertrieb_anfragepositionen'], ['table', 'vertrieb_bestellungen']],
            ],
            [
                'label' => self::demoText('Kunden und Events', 'Customers and events', 'Clients et événements', 'Clienti ed eventi'), 'icon' => 'heroicon-o-user-group', 'sort_order' => 20,
                'entries' => [['table', 'vertrieb_kunden'], ['table', 'vertrieb_events']],
            ],
            [
                'label' => self::demoText('Auswertungen', 'Reports', 'Rapports', 'Report'), 'icon' => 'heroicon-o-presentation-chart-bar', 'sort_order' => 30,
                'entries' => [['report', self::REPORT_STATUS], ['report', self::REPORT_ITEMS], ['report', self::REPORT_WON]],
            ],
        ]);
    }

    private function reports(Menu $group): void
    {
        $this->demoReport($group, self::REPORT_STATUS, 'Anzahl und Volumen der Anfragen je Status – wie viel ist offen, angeboten, gewonnen oder verloren.', [
            'base' => $this->demoTableId('vertrieb_anfragen'),
            'fields' => [
                ['key' => 'status', 'path' => [], 'field' => $this->demoFieldId('vertrieb_anfragen.status')],
                ['key' => 'summe', 'path' => [], 'field' => $this->demoFieldId('vertrieb_anfragen.summe')],
            ],
            'groups' => ['status'],
            'measures' => [
                ['key' => 'anzahl', 'field' => null, 'agg' => 'count', 'label' => 'Anfragen'],
                ['key' => 'volumen', 'field' => 'summe', 'agg' => 'sum', 'label' => 'Volumen (EUR)'],
            ],
            'visual' => ['type' => 'bar'],
        ], self::demoText('Anfragen je Status', 'Enquiries by status', 'Demandes par statut', 'Richieste per stato'), 'heroicon-o-chart-bar');

        // Über die Schnittstelle: Artikel der Lager-App als Gruppe.
        $this->demoReport($group, self::REPORT_ITEMS, 'Welche Artikel des Lagers am häufigsten angefragt werden – Menge und Betrag aller Anfragepositionen.', [
            'base' => $this->demoTableId('vertrieb_anfragepositionen'),
            'fields' => [
                ['key' => 'artikel', 'path' => [], 'field' => $this->demoFieldId('vertrieb_anfragepositionen.artikel_id')],
                ['key' => 'menge', 'path' => [], 'field' => $this->demoFieldId('vertrieb_anfragepositionen.menge')],
                ['key' => 'betrag', 'path' => [], 'field' => $this->demoFieldId('vertrieb_anfragepositionen.betrag')],
            ],
            'groups' => ['artikel'],
            'measures' => [
                ['key' => 'menge_summe', 'field' => 'menge', 'agg' => 'sum', 'label' => 'Menge'],
                ['key' => 'betrag_summe', 'field' => 'betrag', 'agg' => 'sum', 'label' => 'Betrag (EUR)'],
            ],
            'sort' => [['key' => 'betrag_summe', 'dir' => 'desc']],
            'visual' => ['type' => 'table'],
        ], self::demoText('Angefragte Artikel', 'Requested items', 'Articles demandés', 'Articoli richiesti'), 'heroicon-o-cube');

        $this->demoReport($group, self::REPORT_WON, 'Volumen der gewonnenen Anfragen je Kunde.', [
            'base' => $this->demoTableId('vertrieb_anfragen'),
            'fields' => [
                ['key' => 'kunde', 'path' => [], 'field' => $this->demoFieldId('vertrieb_anfragen.kunde_id')],
                ['key' => 'status', 'path' => [], 'field' => $this->demoFieldId('vertrieb_anfragen.status')],
                ['key' => 'summe', 'path' => [], 'field' => $this->demoFieldId('vertrieb_anfragen.summe')],
            ],
            'groups' => ['kunde'],
            'measures' => [['key' => 'volumen', 'field' => 'summe', 'agg' => 'sum', 'label' => 'Gewonnenes Volumen (EUR)']],
            'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'gewonnen']],
            'sort' => [['key' => 'volumen', 'dir' => 'desc']],
            'visual' => ['type' => 'bar'],
        ], self::demoText('Gewonnen je Kunde', 'Won by customer', 'Gagnées par client', 'Vinte per cliente'), 'heroicon-o-trophy');
    }

    /** @return list<array<string, mixed>> Verknüpfungsziele vor den Tabellen, die auf sie verweisen */
    private function definitions(): array
    {
        $t = self::demoText(...);
        $option = fn (string $value, string $de, string $en, string $fr, string $it): array => ['value' => $value, 'label' => $t($de, $en, $fr, $it)];

        return [
            [
                'name' => 'vertrieb_kunden', 'label' => $t('Kunden', 'Customers', 'Clients', 'Clienti'), 'icon' => 'heroicon-o-building-office',
                'fields' => [
                    ['name' => 'kundennummer', 'label' => $t('Kundennummer', 'Customer number', 'Numéro de client', 'Numero cliente'), 'type' => 'string', 'options' => ['length' => 20], 'is_required' => true, 'is_unique' => true, 'is_searchable' => true],
                    ['name' => 'name', 'label' => $t('Name', 'Name', 'Nom', 'Nome'), 'type' => 'string', 'options' => ['length' => 150], 'is_required' => true, 'is_searchable' => true],
                    ['name' => 'land', 'label' => $t('Land', 'Country', 'Pays', 'Paese'), 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                        $option('DE', 'Deutschland', 'Germany', 'Allemagne', 'Germania'),
                        $option('CH', 'Schweiz', 'Switzerland', 'Suisse', 'Svizzera'),
                        $option('AT', 'Österreich', 'Austria', 'Autriche', 'Austria'),
                    ]]],
                    ['name' => 'ort', 'label' => $t('Ort', 'City', 'Localité', 'Località'), 'type' => 'string', 'options' => ['length' => 100]],
                    ['name' => 'email', 'label' => $t('E-Mail', 'Email', 'E-mail', 'E-mail'), 'type' => 'string', 'options' => ['length' => 200], 'validation_rules' => ['email' => true]],
                    ['name' => 'telefon', 'label' => $t('Telefon', 'Phone', 'Téléphone', 'Telefono'), 'type' => 'string', 'options' => ['length' => 50], 'show_in_table' => false],
                    ['name' => 'kunde_seit', 'label' => $t('Kunde seit', 'Customer since', 'Client depuis', 'Cliente dal'), 'type' => 'date'],
                ],
            ],
            [
                'name' => 'vertrieb_events', 'label' => $t('Events', 'Events', 'Événements', 'Eventi'), 'icon' => 'heroicon-o-calendar-days',
                'fields' => [
                    ['name' => 'bezeichnung', 'label' => $t('Bezeichnung', 'Name', 'Désignation', 'Denominazione'), 'type' => 'string', 'options' => ['length' => 150], 'is_required' => true, 'is_searchable' => true],
                    ['name' => 'art', 'label' => $t('Art', 'Kind', 'Type', 'Tipo'), 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                        $option('Messe', 'Messe', 'Trade fair', 'Salon', 'Fiera'),
                        $option('Hausmesse', 'Hausmesse', 'In-house show', 'Salon maison', 'Fiera aziendale'),
                        $option('Webinar', 'Webinar', 'Webinar', 'Webinaire', 'Webinar'),
                        $option('Kundentag', 'Kundentag', 'Customer day', 'Journée clients', 'Giornata clienti'),
                    ]]],
                    ['name' => 'beginn', 'label' => $t('Beginn', 'Start', 'Début', 'Inizio'), 'type' => 'datetime', 'is_required' => true],
                    ['name' => 'ende', 'label' => $t('Ende', 'End', 'Fin', 'Fine'), 'type' => 'datetime'],
                    ['name' => 'ort', 'label' => $t('Ort', 'Location', 'Lieu', 'Luogo'), 'type' => 'string', 'options' => ['length' => 150]],
                    ['name' => 'online', 'label' => $t('Online', 'Online', 'En ligne', 'Online'), 'type' => 'boolean'],
                    ['name' => 'beschreibung', 'label' => $t('Beschreibung', 'Description', 'Description', 'Descrizione'), 'type' => 'text', 'show_in_table' => false],
                ],
            ],
            [
                'name' => 'vertrieb_bestellungen', 'label' => $t('Bestellungen', 'Orders', 'Commandes', 'Ordini'), 'icon' => 'heroicon-o-shopping-bag',
                'fields' => [
                    ['name' => 'bestellnummer', 'label' => $t('Bestellnummer', 'Order number', 'Numéro de commande', 'Numero d’ordine'), 'type' => 'string', 'options' => ['length' => 20], 'is_required' => true, 'is_unique' => true, 'is_searchable' => true],
                    ['name' => 'kunde_id', 'label' => $t('Kunde', 'Customer', 'Client', 'Cliente'), 'type' => 'relation', 'options' => ['table' => 'vertrieb_kunden', 'display_field' => 'name'], 'is_required' => true],
                    ['name' => 'bestelldatum', 'label' => $t('Bestelldatum', 'Order date', 'Date de commande', 'Data dell’ordine'), 'type' => 'date', 'is_required' => true],
                    ['name' => 'status', 'label' => $t('Status', 'Status', 'Statut', 'Stato'), 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                        $option('bestaetigt', 'Bestätigt', 'Confirmed', 'Confirmée', 'Confermato'),
                        $option('geliefert', 'Geliefert', 'Delivered', 'Livrée', 'Consegnato'),
                        $option('storniert', 'Storniert', 'Cancelled', 'Annulée', 'Annullato'),
                    ]]],
                    ['name' => 'betrag', 'label' => $t('Betrag (EUR)', 'Amount (EUR)', 'Montant (EUR)', 'Importo (EUR)'), 'type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2], 'is_required' => true, 'validation_rules' => ['min' => 0]],
                ],
            ],
            [
                'name' => 'vertrieb_anfragen', 'label' => $t('Anfragen', 'Enquiries', 'Demandes', 'Richieste'), 'icon' => 'heroicon-o-inbox-arrow-down',
                'fields' => [
                    ['name' => 'anfragedatum', 'label' => $t('Anfragedatum', 'Enquiry date', 'Date de la demande', 'Data della richiesta'), 'type' => 'date', 'is_required' => true],
                    ['name' => 'kunde_id', 'label' => $t('Kunde', 'Customer', 'Client', 'Cliente'), 'type' => 'relation', 'options' => ['table' => 'vertrieb_kunden', 'display_field' => 'name'], 'is_required' => true],
                    ['name' => 'betreff', 'label' => $t('Betreff', 'Subject', 'Objet', 'Oggetto'), 'type' => 'string', 'options' => ['length' => 200], 'is_required' => true, 'is_searchable' => true],
                    ['name' => 'status', 'label' => $t('Status', 'Status', 'Statut', 'Stato'), 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                        $option('offen', 'Offen', 'Open', 'Ouverte', 'Aperta'),
                        $option('in_bearbeitung', 'In Bearbeitung', 'In progress', 'En cours', 'In corso'),
                        $option('angeboten', 'Angeboten', 'Quoted', 'Offre envoyée', 'Offerta inviata'),
                        $option('gewonnen', 'Gewonnen', 'Won', 'Gagnée', 'Vinta'),
                        $option('verloren', 'Verloren', 'Lost', 'Perdue', 'Persa'),
                    ]]],
                    ['name' => 'event_id', 'label' => $t('Event', 'Event', 'Événement', 'Evento'), 'type' => 'relation', 'options' => ['table' => 'vertrieb_events', 'display_field' => 'bezeichnung']],
                    ['name' => 'bestellung_id', 'label' => $t('Bestellung', 'Order', 'Commande', 'Ordine'), 'type' => 'relation', 'options' => ['table' => 'vertrieb_bestellungen', 'display_field' => 'bestellnummer']],
                    ['name' => 'beschreibung', 'label' => $t('Beschreibung', 'Description', 'Description', 'Descrizione'), 'type' => 'text', 'show_in_table' => false],
                ],
            ],
            [
                'name' => 'vertrieb_anfragepositionen', 'label' => $t('Anfragepositionen', 'Enquiry items', 'Lignes de demande', 'Righe della richiesta'), 'icon' => 'heroicon-o-list-bullet',
                'fields' => [
                    ['name' => 'anfrage_id', 'label' => $t('Anfrage', 'Enquiry', 'Demande', 'Richiesta'), 'type' => 'relation', 'options' => ['table' => 'vertrieb_anfragen', 'display_field' => 'betreff'], 'is_required' => true],
                    ['name' => 'position', 'label' => $t('Position', 'Line', 'Ligne', 'Riga'), 'type' => 'integer', 'options' => ['group_digits' => false]],
                    // Artikel der Lager-App über deren Schnittstelle.
                    ['name' => 'artikel_id', 'label' => $t('Artikel', 'Item', 'Article', 'Articolo'), 'type' => 'relation', 'options' => ['table' => 'lager_artikel', 'display_field' => 'artikelnummer', 'display_fields' => ['artikelnummer', 'bezeichnung'], 'separator' => ' '], 'is_required' => true],
                    ['name' => 'menge', 'label' => $t('Menge', 'Quantity', 'Quantité', 'Quantità'), 'type' => 'integer', 'is_required' => true, 'validation_rules' => ['min' => 1]],
                    ['name' => 'preis', 'label' => $t('Preis (EUR)', 'Price (EUR)', 'Prix (EUR)', 'Prezzo (EUR)'), 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2, CopySource::OPTION => 'artikel_id.verkaufspreis'], 'is_required' => true, 'validation_rules' => ['min' => 0]],
                ],
            ],
        ];
    }

    protected function reseedDemoData(): void
    {
        $this->seedRecords();
    }

    /** Feste Beispieldaten; Artikel der Lager-Demo über ihre Artikelnummer, damit auch nach deren Zurücksetzen alles passt. */
    private function seedRecords(): void
    {
        $insert = fn (string $table, array $data): int => (int) DynamicRecord::forTableName($table)->create($data)->getKey();
        $items = DynamicRecord::forTableName('lager_artikel')->newQuery()->get(['id', 'artikelnummer', 'verkaufspreis'])->keyBy('artikelnummer');

        $customers = [];

        foreach ([
            ['K-1001', 'Baumarkt Lindner GmbH', 'DE', 'Augsburg', 'einkauf@lindner-baumarkt.example', '+49 821 555 10 10', '2019-03-01'],
            ['K-1002', 'Schreinerei Hofer', 'AT', 'Innsbruck', 'office@hofer-holz.example', '+43 512 555 22 1', '2021-06-15'],
            ['K-1003', 'Haustechnik Meier AG', 'CH', 'Winterthur', 'bestellung@meier-haustechnik.example', '+41 52 555 30 30', '2018-11-20'],
            ['K-1004', 'Frischdienst Nord KG', 'DE', 'Hamburg', 'logistik@frischdienst-nord.example', '+49 40 555 44 00', '2022-02-01'],
            ['K-1005', 'Atelier Rossi', 'CH', 'Lugano', 'info@atelier-rossi.example', '+41 91 555 12 34', '2023-09-10'],
            ['K-1006', 'Versandhaus Weber', 'DE', 'Leipzig', 'einkauf@weber-versand.example', '+49 341 555 60 60', '2020-05-04'],
            ['K-1007', 'Gemeinde Seefeld, Bauhof', 'AT', 'Seefeld', 'bauhof@seefeld.example', '+43 5212 555 70', '2024-01-08'],
            ['K-1008', 'Elektro Schäfer', 'DE', 'Kassel', null, '+49 561 555 80 8', '2025-04-22'],
            ['K-1009', 'Büroservice Brunner', 'CH', 'Bern', 'service@brunner-buero.example', '+41 31 555 90 90', '2017-07-01'],
            ['K-1010', 'Kühlhaus Danube', 'AT', 'Linz', 'dispo@kuehlhaus-danube.example', '+43 732 555 10', '2026-02-16'],
            ['K-1011', 'Werkstatt Yılmaz', 'DE', 'Köln', 'kontakt@werkstatt-yilmaz.example', '+49 221 555 11 11', '2025-10-01'],
            ['K-1012', 'Möbelbau Novak', 'AT', 'Graz', 'novak@moebelbau-novak.example', null, '2026-06-01'],
        ] as $i => [$kundennummer, $name, $land, $ort, $email, $telefon, $seit]) {
            $customers[$i + 1] = $insert('vertrieb_kunden', ['kundennummer' => $kundennummer, 'name' => $name, 'land' => $land, 'ort' => $ort, 'email' => $email, 'telefon' => $telefon, 'kunde_seit' => $seit]);
        }

        $events = [];

        foreach ([
            ['Eisenwarenmesse Köln', 'Messe', '2026-09-08 09:00', '2026-09-11 18:00', 'Köln, Messe', false, 'Stand Halle 10, Neuheiten Elektrowerkzeuge.'],
            ['Webinar: Kühlkette im Versand', 'Webinar', '2026-09-24 10:00', '2026-09-24 11:00', null, true, 'Kühlakkus, Trockeneis und Kühlboxen richtig einsetzen.'],
            ['Hausmesse Herbst', 'Hausmesse', '2026-10-02 10:00', '2026-10-03 16:00', 'Halle A', false, null],
            ['Kundentag Schreinereien', 'Kundentag', '2026-10-15 13:00', '2026-10-15 18:00', 'Innsbruck', false, 'Werkzeugvorführung mit Partner Schweizer Präzision.'],
            ['Webinar: Verpackung sparen', 'Webinar', '2026-10-29 16:00', '2026-10-29 17:00', null, true, null],
            ['Swissbau Basel', 'Messe', '2026-11-17 09:00', '2026-11-20 18:00', 'Basel, Messe', false, null],
            ['Kundentag Büro', 'Kundentag', '2026-12-03 09:30', '2026-12-03 12:00', 'Bern', false, 'Neues Sortiment Büromaterial.'],
            ['Webinar: Neuheiten 2027', 'Webinar', '2027-01-14 10:00', null, null, true, 'Termin steht, Dauer noch offen.'],
        ] as $i => [$bezeichnung, $art, $beginn, $ende, $ort, $online, $beschreibung]) {
            $events[$i + 1] = $insert('vertrieb_events', ['bezeichnung' => $bezeichnung, 'art' => $art, 'beginn' => $beginn.':00', 'ende' => $ende === null ? null : $ende.':00', 'ort' => $ort, 'online' => $online, 'beschreibung' => $beschreibung]);
        }

        // Anfrage: Datum, Kunde, Betreff, Status, Event, Positionen (Artikelnummer, Menge, Rabatt in %), Bestellung (Datum, Status).
        $orderNumber = 0;

        foreach ([
            ['2026-08-18', 1, 'Werkzeug für neue Filiale', 'gewonnen', null, [['A-1004', 10, 5], ['A-1005', 6, 5], ['A-1001', 20, 0]], ['2026-08-27', 'geliefert']],
            ['2026-08-25', 3, 'Befestigungsmaterial Grossbaustelle', 'gewonnen', null, [['B-2001', 80, 8], ['B-2002', 120, 8], ['B-2003', 200, 10]], ['2026-09-03', 'geliefert']],
            ['2026-09-01', 4, 'Kühlversand Saison', 'angeboten', null, [['K-4001', 300, 10], ['K-4002', 150, 5]], null],
            ['2026-09-09', 1, 'Akku-Geräte nach Messebesuch', 'gewonnen', 1, [['A-1004', 15, 7], ['A-1006', 5, 5]], ['2026-09-18', 'geliefert']],
            ['2026-09-10', 6, 'Verpackungsmaterial Q4', 'gewonnen', 1, [['V-3001', 2000, 12], ['V-3002', 1500, 10], ['V-3003', 300, 10]], ['2026-09-22', 'geliefert']],
            ['2026-09-10', 8, 'Messeanfrage Winkelschleifer', 'verloren', 1, [['A-1005', 4, 0]], null],
            ['2026-09-24', 10, 'Kühlboxen und Akkus', 'gewonnen', 2, [['K-4003', 40, 6], ['K-4001', 400, 10]], ['2026-10-01', 'geliefert']],
            ['2026-09-25', 4, 'Trockeneis Testlieferung', 'in_bearbeitung', 2, [['K-4002', 50, 0]], null],
            ['2026-10-02', 9, 'Büromaterial Jahresbedarf', 'gewonnen', 3, [['P-5001', 400, 10], ['P-5002', 30, 5], ['P-5003', 20, 5], ['P-5004', 150, 8]], ['2026-10-09', 'geliefert']],
            ['2026-10-03', 7, 'Bauhof Grundausstattung', 'angeboten', 3, [['A-1001', 12, 0], ['A-1003', 6, 0], ['B-2004', 10, 0]], null],
            ['2026-10-03', 5, 'Kleinmengen Atelier', 'verloren', 3, [['A-1002', 2, 0], ['V-3003', 6, 0]], null],
            ['2026-10-15', 2, 'Werkstattausrüstung Schreinerei', 'gewonnen', 4, [['A-1006', 3, 5], ['A-1003', 4, 5], ['B-2001', 40, 5]], ['2026-10-22', 'geliefert']],
            ['2026-10-15', 12, 'Erstausstattung Möbelbau', 'angeboten', 4, [['A-1004', 4, 3], ['A-1002', 6, 3], ['B-2001', 60, 3]], null],
            ['2026-10-20', 11, 'Werkstattbedarf', 'offen', null, [['A-1002', 3, 0], ['B-2004', 5, 0]], null],
            ['2026-10-29', 6, 'Nachbestellung Kartons', 'gewonnen', 5, [['V-3001', 1500, 12]], ['2026-11-04', 'bestaetigt']],
            ['2026-10-30', 3, 'Gewindestangen Etappe 2', 'in_bearbeitung', null, [['B-2003', 300, 10]], null],
            ['2026-11-03', 1, 'Weihnachtsaktion Werkzeugsets', 'gewonnen', null, [['A-1002', 60, 15], ['A-1001', 40, 10]], ['2026-11-10', 'bestaetigt']],
            ['2026-11-05', 4, 'Kühlkette Winter', 'offen', null, [['K-4001', 200, 0], ['K-4003', 10, 0]], null],
            ['2026-11-09', 8, 'Elektrowerkzeuge Frühjahr', 'offen', null, [['A-1004', 3, 0], ['A-1005', 3, 0]], null],
            ['2026-11-12', 10, 'Tiefkühl-Erweiterung', 'gewonnen', null, [['K-4002', 200, 8]], ['2026-11-16', 'storniert']],
        ] as [$datum, $customer, $betreff, $status, $event, $lines, $order]) {
            $orderId = null;
            $sum = 0.0;
            $positions = [];

            foreach ($lines as $index => [$number, $menge, $rabatt]) {
                $preis = round((float) $items[$number]->verkaufspreis * (100 - $rabatt) / 100, 2);
                $sum += round($menge * $preis, 2);
                $positions[] = ['position' => $index + 1, 'artikel_id' => (int) $items[$number]->id, 'menge' => $menge, 'preis' => number_format($preis, 2, '.', '')];
            }

            if ($order !== null) {
                $orderNumber++;
                $orderId = $insert('vertrieb_bestellungen', [
                    'bestellnummer' => sprintf('B-2026-%03d', $orderNumber), 'kunde_id' => $customers[$customer],
                    'bestelldatum' => $order[0], 'status' => $order[1], 'betrag' => number_format($sum, 2, '.', ''),
                ]);
            }

            $enquiry = $insert('vertrieb_anfragen', [
                'anfragedatum' => $datum, 'kunde_id' => $customers[$customer], 'betreff' => $betreff, 'status' => $status,
                'event_id' => $event === null ? null : $events[$event], 'bestellung_id' => $orderId,
            ]);

            foreach ($positions as $position) {
                $insert('vertrieb_anfragepositionen', ['anfrage_id' => $enquiry, ...$position]);
            }
        }
    }
}
