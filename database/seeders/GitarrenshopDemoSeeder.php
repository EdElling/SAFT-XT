<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\DynamicData\DataPermissions;
use App\DynamicData\Files\RecordFiles;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\Role;
use Database\Seeders\Concerns\SeedsDemoTables;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Gitarrenshop (Demo, B-121): einfache Ein- und Ausgabenrechnung mit MWST für einen kleinen Handel mit Einzelstücken –
 * Kunden und Lieferanten, Artikel mit Bildern, Belege mit Dateien, Buchungen (Warenwert, Zoll, Einfuhr-MWST, Dienstleistung, Sonstiges,
 * Verkauf) und Zahlungen (auch Raten). Referenz und Testfall für den KI-App-Designer (B-107 Etappe 7).
 *
 *   php artisan db:seed --class=GitarrenshopDemoSeeder
 *
 * Bilder und PDF-Belege sind Platzhalter, im Seeder erzeugt. Wiederholbar: vorhandene Tabellen und Daten bleiben.
 */
class GitarrenshopDemoSeeder extends Seeder
{
    use SeedsDemoTables;

    /** Schlüssel der Demo-Vorlage „Rechnung“ (B-124). */
    public const string INVOICE_TEMPLATE = '3d0f3c1e-7a52-4b8e-9c61-2f8a5b0d4e17';

    public const array GROUP = ['de' => 'Gitarrenshop (Demo)', 'en' => 'Guitar shop (demo)', 'fr' => 'Magasin de guitares (démo)', 'it' => 'Negozio di chitarre (demo)'];

    public const string ROLE = 'Gitarrenshop';

    public const string REPORT_YEARS = 'Einnahmen und Ausgaben je Jahr';

    public const string REPORT_BALANCE = 'Gesamtsaldo';

    public const string REPORT_BOOKINGS = 'Alle Buchungen';

    public const string REPORT_ITEMS = 'Ein- und Ausgaben je Artikel';

    /** MWST-Sätze der Schweiz als Auswahl: gespeicherter Wert => Satz für die Formel. */
    public const array VAT = ['8.1' => '0.081', '2.6' => '0.026', '3.8' => '0.038', '0' => '0'];

    /** App der Demo (B-107); der Schlüssel ist fest, damit wiederholtes Einspielen dieselbe App trifft. */
    public function demoAppDefinition(): array
    {
        return ['uuid' => '8f0c6a52-6d0e-4c8e-9a43-5b1c2f6e0a05', 'name' => self::GROUP, 'prefix' => 'gitarren', 'icon' => 'heroicon-o-musical-note', 'sort_order' => 550, 'manual' => 'gitarrenshop'];
    }

    public function run(): void
    {
        $group = $this->demoGroup(self::GROUP, 550);
        $created = $this->demoTables($group, $this->definitions());

        if (in_array('gitarren_artikel', $created, true)) {
            $this->seedRecords();
        }

        $this->demoRules('gitarren_buchungen', [['left' => 'netto', 'operator' => '>=', 'right_kind' => 'value', 'right_value' => '0', 'when_filled' => true]]);
        $this->demoRules('gitarren_zahlungen', [['left' => 'betrag', 'operator' => '>', 'right_kind' => 'value', 'right_value' => '0', 'when_filled' => true]]);
        $this->demoDetails([
            'gitarren_kunden' => ['gitarren_belege.kunde_id'],
            'gitarren_lieferanten' => ['gitarren_belege.lieferant_id'],
            'gitarren_artikel' => ['gitarren_buchungen.artikel_id'],
            'gitarren_belege' => ['gitarren_buchungen.beleg_id', 'gitarren_zahlungen.beleg_id'],
        ]);
        // Kopiert man einen Beleg, kommen die Buchungen mit, die Zahlungen nicht (B-123).
        $this->demoCopyWithParent(['gitarren_buchungen.beleg_id']);

        $t = self::demoText(...);
        $money = ['result' => 'decimal', 'precision' => 12, 'scale' => 2];
        $rate = 'if(mwst_satz = \'8.1\', 0.081, if(mwst_satz = \'2.6\', 0.026, if(mwst_satz = \'3.8\', 0.038, 0)))';
        $this->demoComputed([
            ['gitarren_buchungen', 'mwst', $t('MWST (CHF)', 'VAT (CHF)', 'TVA (CHF)', 'IVA (CHF)'), 'round(netto * '.$rate.', 2)', $money],
            ['gitarren_buchungen', 'brutto', $t('Brutto (CHF)', 'Gross (CHF)', 'Brut (CHF)', 'Lordo (CHF)'), 'netto + mwst', $money],
            // Formeln lesen keine Felder des Belegs: Einnahme ist, was als „Verkauf“ gebucht ist.
            ['gitarren_buchungen', 'einnahme', $t('Einnahme (CHF)', 'Income (CHF)', 'Recette (CHF)', 'Entrata (CHF)'), "if(art = 'Verkauf', brutto, 0)", $money],
            ['gitarren_buchungen', 'ausgabe', $t('Ausgabe (CHF)', 'Expense (CHF)', 'Dépense (CHF)', 'Uscita (CHF)'), "if(art <> 'Verkauf', brutto, 0)", $money],
            ['gitarren_buchungen', 'saldo', $t('Saldo (CHF)', 'Balance (CHF)', 'Solde (CHF)', 'Saldo (CHF)'), 'einnahme - ausgabe', $money],
            ['gitarren_artikel', 'kosten', $t('Kosten (CHF)', 'Costs (CHF)', 'Coûts (CHF)', 'Costi (CHF)'), 'sum(gitarren_buchungen.ausgabe)', $money],
            ['gitarren_artikel', 'erloes', $t('Erlös (CHF)', 'Revenue (CHF)', 'Produit (CHF)', 'Ricavo (CHF)'), 'sum(gitarren_buchungen.einnahme)', $money],
            ['gitarren_artikel', 'ergebnis', $t('Ergebnis (CHF)', 'Result (CHF)', 'Résultat (CHF)', 'Risultato (CHF)'), 'erloes - kosten', $money],
            ['gitarren_belege', 'summe', $t('Summe (CHF)', 'Total (CHF)', 'Total (CHF)', 'Totale (CHF)'), 'sum(gitarren_buchungen.brutto)', $money],
            ['gitarren_belege', 'bezahlt', $t('Bezahlt (CHF)', 'Paid (CHF)', 'Payé (CHF)', 'Pagato (CHF)'), 'sum(gitarren_zahlungen.betrag)', $money],
            ['gitarren_belege', 'offen', $t('Offen (CHF)', 'Open (CHF)', 'Ouvert (CHF)', 'Aperto (CHF)'), 'summe - bezahlt', $money],
        ]);
        $this->reports($group);
        $this->submenus($group);
        $this->role();
        $this->seedDemoManual();
        $this->demoDocumentTemplate(
            self::INVOICE_TEMPLATE, 'gitarren_belege', 'Rechnung', 'gitarrenshop-rechnung',
            "{{kunde_id.vorname}} {{kunde_id.name}}\n{{kunde_id.strasse}}\n{{kunde_id.plz}} {{kunde_id.ort}}",
            'Rechnung-{{belegnummer}}',
            'dateien',
            [
                'email_to' => '{{kunde_id.email}}',
                'email_subject' => 'Rechnung {{belegnummer}}',
                'email_body' => "Guten Tag {{kunde_id.vorname}} {{kunde_id.name}}\n\nIm Anhang erhalten Sie die Rechnung {{belegnummer}} vom {{datum | DatumLang}}.\n\nBesten Dank für Ihren Einkauf und freundliche Grüsse\n{{absender.firma}}",
                // QR-Rechnung (D4a): offener Betrag, RF-Referenz aus der Belegnummer, Kunde als Zahlungspflichtiger.
                'qr_bill' => [
                    'enabled' => true, 'position' => 'bottom', 'amount' => '{{offen | Roh}}', 'currency' => 'CHF',
                    'reference' => '{{belegnummer}}', 'message' => 'Rechnung {{belegnummer}}',
                    'debtor_name' => '{{kunde_id.vorname}} {{kunde_id.name}}', 'debtor_street' => '{{kunde_id.strasse}}',
                    'debtor_postal_code' => '{{kunde_id.plz}}', 'debtor_city' => '{{kunde_id.ort}}', 'debtor_country' => '{{kunde_id.land | Roh}}',
                ],
            ],
        );
        // Beispiel-IBAN aus den Unterlagen von SIX – für eigene Rechnungen im Briefpapier ersetzen.
        $this->demoQrCreditor('gitarren_belege', [
            'firma' => 'Gitarrenshop Muster AG', 'strasse' => 'Saitenweg 7', 'plz' => '8004', 'ort' => 'Zürich', 'land' => 'CH',
            'iban' => 'CH93 0076 2011 6238 5295 7',
        ]);
    }

    /** Fachrolle der App mit allen Rechten auf ihre Tabellen und dem Ansehen der Auswertungen. */
    private function role(): void
    {
        $app = $this->demoApp();
        $role = Role::query()->where('name', self::ROLE)->where('guard_name', 'web')->first()
            ?? Role::query()->create(['name' => self::ROLE, 'guard_name' => 'web', 'app_id' => $app->id]);

        $permissions = ['reports.view'];

        foreach ($this->demoTableNames() as $table) {
            $permissions = [...$permissions, ...DataPermissions::names($table)];
        }

        $role->givePermissionTo($permissions);
    }

    private function submenus(Menu $group): void
    {
        $this->demoSubmenus($group, [
            [
                'label' => self::demoText('Buchhaltung', 'Accounting', 'Comptabilité', 'Contabilità'), 'icon' => 'heroicon-o-banknotes', 'sort_order' => 10,
                'entries' => [['table', 'gitarren_belege'], ['table', 'gitarren_buchungen'], ['table', 'gitarren_zahlungen']],
            ],
            [
                'label' => self::demoText('Stammdaten', 'Master data', 'Données de base', 'Dati di base'), 'icon' => 'heroicon-o-archive-box', 'sort_order' => 20,
                'entries' => [['table', 'gitarren_artikel'], ['table', 'gitarren_kunden'], ['table', 'gitarren_lieferanten']],
            ],
            [
                'label' => self::demoText('Auswertungen', 'Reports', 'Rapports', 'Report'), 'icon' => 'heroicon-o-presentation-chart-bar', 'sort_order' => 30,
                'entries' => [['report', self::REPORT_YEARS], ['report', self::REPORT_BALANCE], ['report', self::REPORT_BOOKINGS], ['report', self::REPORT_ITEMS]],
            ],
        ]);
    }

    private function reports(Menu $group): void
    {
        $f = fn (string $qualified): int => $this->demoFieldId($qualified);
        $receipt = [$f('gitarren_buchungen.beleg_id')];

        $this->demoReport($group, self::REPORT_YEARS, 'Einnahmen, Ausgaben und Saldo (Gewinn oder Verlust) je Jahr des Belegdatums.', [
            'base' => $this->demoTableId('gitarren_buchungen'),
            'fields' => [
                ['key' => 'jahr', 'path' => $receipt, 'field' => $f('gitarren_belege.datum'), 'bucket' => 'year', 'label' => 'Jahr'],
                ['key' => 'einnahme', 'path' => [], 'field' => $f('gitarren_buchungen.einnahme')],
                ['key' => 'ausgabe', 'path' => [], 'field' => $f('gitarren_buchungen.ausgabe')],
                ['key' => 'saldo', 'path' => [], 'field' => $f('gitarren_buchungen.saldo')],
            ],
            'groups' => ['jahr'],
            'measures' => [
                ['key' => 'einnahmen', 'field' => 'einnahme', 'agg' => 'sum', 'label' => 'Einnahmen (CHF)'],
                ['key' => 'ausgaben', 'field' => 'ausgabe', 'agg' => 'sum', 'label' => 'Ausgaben (CHF)'],
                ['key' => 'saldo_summe', 'field' => 'saldo', 'agg' => 'sum', 'label' => 'Saldo (CHF)'],
            ],
            'sort' => [['key' => 'jahr', 'dir' => 'asc']],
            'visual' => ['type' => 'table'],
        ], self::demoText('Einnahmen und Ausgaben je Jahr', 'Income and expenses by year', 'Recettes et dépenses par année', 'Entrate e uscite per anno'), 'heroicon-o-table-cells');

        $this->demoReport($group, self::REPORT_BALANCE, 'Kennzahl: Saldo über alle Einnahmen und Ausgaben – positiv = Gewinn, negativ = Verlust.', [
            'base' => $this->demoTableId('gitarren_buchungen'),
            'fields' => [['key' => 'saldo', 'path' => [], 'field' => $f('gitarren_buchungen.saldo')]],
            'measures' => [['key' => 'summe', 'field' => 'saldo', 'agg' => 'sum', 'label' => 'Saldo (CHF)']],
            'visual' => ['type' => 'kpi'],
        ], self::demoText('Gesamtsaldo', 'Overall balance', 'Solde total', 'Saldo complessivo'), 'heroicon-o-scale');

        $this->demoReport($group, self::REPORT_BOOKINGS, 'Alle Buchungen mit Beleg, Artikel, Art und Beträgen, die neusten zuerst.', [
            'base' => $this->demoTableId('gitarren_buchungen'),
            'fields' => [
                ['key' => 'datum', 'path' => $receipt, 'field' => $f('gitarren_belege.datum'), 'label' => 'Datum'],
                ['key' => 'beleg', 'path' => [], 'field' => $f('gitarren_buchungen.beleg_id')],
                ['key' => 'artikel', 'path' => [], 'field' => $f('gitarren_buchungen.artikel_id')],
                ['key' => 'art', 'path' => [], 'field' => $f('gitarren_buchungen.art')],
                ['key' => 'text', 'path' => [], 'field' => $f('gitarren_buchungen.text')],
                ['key' => 'netto', 'path' => [], 'field' => $f('gitarren_buchungen.netto')],
                ['key' => 'mwst', 'path' => [], 'field' => $f('gitarren_buchungen.mwst')],
                ['key' => 'brutto', 'path' => [], 'field' => $f('gitarren_buchungen.brutto')],
                ['key' => 'saldo', 'path' => [], 'field' => $f('gitarren_buchungen.saldo')],
            ],
            'sort' => [['key' => 'datum', 'dir' => 'desc']],
            'visual' => ['type' => 'table'],
        ], self::demoText('Alle Buchungen', 'All bookings', 'Toutes les écritures', 'Tutte le registrazioni'), 'heroicon-o-queue-list');

        $this->demoReport($group, self::REPORT_ITEMS, 'Ausgaben, Einnahmen und Ergebnis je Artikel (Buchungen ohne Artikel, z. B. Fracht, in einer eigenen Zeile).', [
            'base' => $this->demoTableId('gitarren_buchungen'),
            'fields' => [
                ['key' => 'artikel', 'path' => [], 'field' => $f('gitarren_buchungen.artikel_id')],
                ['key' => 'ausgabe', 'path' => [], 'field' => $f('gitarren_buchungen.ausgabe')],
                ['key' => 'einnahme', 'path' => [], 'field' => $f('gitarren_buchungen.einnahme')],
                ['key' => 'saldo', 'path' => [], 'field' => $f('gitarren_buchungen.saldo')],
            ],
            'groups' => ['artikel'],
            'measures' => [
                ['key' => 'ausgaben', 'field' => 'ausgabe', 'agg' => 'sum', 'label' => 'Ausgaben (CHF)'],
                ['key' => 'einnahmen', 'field' => 'einnahme', 'agg' => 'sum', 'label' => 'Einnahmen (CHF)'],
                ['key' => 'ergebnis', 'field' => 'saldo', 'agg' => 'sum', 'label' => 'Ergebnis (CHF)'],
            ],
            'sort' => [['key' => 'ergebnis', 'dir' => 'desc']],
            'visual' => ['type' => 'table'],
        ], self::demoText('Ein- und Ausgaben je Artikel', 'Income and expenses by item', 'Recettes et dépenses par article', 'Entrate e uscite per articolo'), 'heroicon-o-musical-note');
    }

    /** @return list<array<string, mixed>> Verknüpfungsziele vor den Tabellen, die auf sie verweisen */
    private function definitions(): array
    {
        $t = self::demoText(...);
        $o = fn (string $value, string $de, string $en, string $fr, string $it): array => ['value' => $value, 'label' => $t($de, $en, $fr, $it)];

        $countries = ['options' => [
            $o('CH', 'Schweiz', 'Switzerland', 'Suisse', 'Svizzera'),
            $o('DE', 'Deutschland', 'Germany', 'Allemagne', 'Germania'),
            $o('AT', 'Österreich', 'Austria', 'Autriche', 'Austria'),
            $o('IT', 'Italien', 'Italy', 'Italie', 'Italia'),
            $o('FR', 'Frankreich', 'France', 'France', 'Francia'),
            $o('CN', 'China', 'China', 'Chine', 'Cina'),
        ]];

        return [
            [
                'name' => 'gitarren_kunden', 'label' => $t('Kunden', 'Customers', 'Clients', 'Clienti'), 'icon' => 'heroicon-o-users',
                'description' => $t('Käuferinnen und Käufer mit Adresse für Rechnungen und E-Mail für den Newsletter.', 'Buyers with address for invoices and email for the newsletter.', 'Acheteurs avec adresse pour les factures et e-mail pour la newsletter.', 'Acquirenti con indirizzo per le fatture ed e-mail per la newsletter.'),
                'fields' => [
                    ['name' => 'kundennummer', 'label' => $t('Kundennummer', 'Customer number', 'Numéro de client', 'Numero cliente'), 'type' => 'string', 'options' => ['length' => 20], 'is_required' => true, 'is_unique' => true, 'is_searchable' => true],
                    ['name' => 'vorname', 'label' => $t('Vorname', 'First name', 'Prénom', 'Nome'), 'type' => 'string', 'options' => ['length' => 80], 'is_searchable' => true],
                    ['name' => 'name', 'label' => $t('Nachname oder Firma', 'Last name or company', 'Nom ou entreprise', 'Cognome o ditta'), 'type' => 'string', 'options' => ['length' => 150], 'is_required' => true, 'is_searchable' => true],
                    ['name' => 'strasse', 'label' => $t('Strasse', 'Street', 'Rue', 'Via'), 'type' => 'string', 'options' => ['length' => 150]],
                    ['name' => 'plz', 'label' => $t('PLZ', 'Postcode', 'NPA', 'NAP'), 'type' => 'string', 'options' => ['length' => 10]],
                    ['name' => 'ort', 'label' => $t('Ort', 'City', 'Localité', 'Località'), 'type' => 'string', 'options' => ['length' => 100], 'is_searchable' => true],
                    ['name' => 'land', 'label' => $t('Land', 'Country', 'Pays', 'Paese'), 'type' => 'select', 'options' => $countries],
                    ['name' => 'email', 'label' => $t('E-Mail', 'Email', 'E-mail', 'E-mail'), 'type' => 'string', 'options' => ['length' => 200], 'validation_rules' => ['email' => true]],
                    ['name' => 'newsletter', 'label' => $t('Newsletter', 'Newsletter', 'Newsletter', 'Newsletter'), 'type' => 'boolean'],
                    ['name' => 'telefon', 'label' => $t('Telefon', 'Phone', 'Téléphone', 'Telefono'), 'type' => 'string', 'options' => ['length' => 40]],
                    ['name' => 'bemerkung', 'label' => $t('Bemerkung', 'Note', 'Remarque', 'Nota'), 'type' => 'text', 'show_in_table' => false],
                ],
            ],
            [
                'name' => 'gitarren_lieferanten', 'label' => $t('Lieferanten', 'Suppliers', 'Fournisseurs', 'Fornitori'), 'icon' => 'heroicon-o-truck',
                'description' => $t('Hersteller, Händler, Zoll, Spedition und Werkstätten, bei denen eingekauft wird.', 'Manufacturers, dealers, customs, carriers and workshops you buy from.', 'Fabricants, marchands, douane, transporteurs et ateliers chez qui on achète.', 'Produttori, rivenditori, dogana, spedizionieri e laboratori presso cui si acquista.'),
                'fields' => [
                    ['name' => 'name', 'label' => $t('Name', 'Name', 'Nom', 'Nome'), 'type' => 'string', 'options' => ['length' => 150], 'is_required' => true, 'is_unique' => true, 'is_searchable' => true],
                    ['name' => 'land', 'label' => $t('Land', 'Country', 'Pays', 'Paese'), 'type' => 'select', 'options' => $countries],
                    ['name' => 'ort', 'label' => $t('Ort', 'City', 'Localité', 'Località'), 'type' => 'string', 'options' => ['length' => 100]],
                    ['name' => 'email', 'label' => $t('E-Mail', 'Email', 'E-mail', 'E-mail'), 'type' => 'string', 'options' => ['length' => 200], 'validation_rules' => ['email' => true]],
                    ['name' => 'telefon', 'label' => $t('Telefon', 'Phone', 'Téléphone', 'Telefono'), 'type' => 'string', 'options' => ['length' => 40]],
                    ['name' => 'webseite', 'label' => $t('Webseite', 'Website', 'Site web', 'Sito web'), 'type' => 'string', 'options' => ['length' => 200], 'validation_rules' => ['url' => true], 'show_in_table' => false],
                    ['name' => 'unsere_kundennummer', 'label' => $t('Unsere Kundennummer', 'Our customer number', 'Notre numéro de client', 'Nostro numero cliente'), 'type' => 'string', 'options' => ['length' => 40], 'show_in_table' => false],
                ],
            ],
            [
                'name' => 'gitarren_artikel', 'label' => $t('Artikel', 'Items', 'Articles', 'Articoli'), 'icon' => 'heroicon-o-musical-note',
                'description' => $t('Einzelstücke: Instrumente, Zubehör, Dienstleistungen und Sonstiges, mit Kosten und Erlös aus den Buchungen.', 'Single items: instruments, accessories, services and other, with costs and revenue from the bookings.', 'Pièces uniques : instruments, accessoires, services et divers, avec coûts et produit tirés des écritures.', 'Pezzi unici: strumenti, accessori, servizi e altro, con costi e ricavo dalle registrazioni.'),
                'fields' => [
                    ['name' => 'inventarnummer', 'label' => $t('Inventarnummer', 'Inventory number', 'Numéro d’inventaire', 'Numero d’inventario'), 'type' => 'string', 'options' => ['length' => 20], 'is_required' => true, 'is_unique' => true, 'is_searchable' => true],
                    ['name' => 'bezeichnung', 'label' => $t('Bezeichnung', 'Name', 'Désignation', 'Denominazione'), 'type' => 'string', 'options' => ['length' => 200], 'is_required' => true, 'is_searchable' => true],
                    ['name' => 'kategorie', 'label' => $t('Kategorie', 'Category', 'Catégorie', 'Categoria'), 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                        $o('Instrument', 'Instrument', 'Instrument', 'Instrument', 'Strumento'),
                        $o('Zubehör', 'Zubehör', 'Accessory', 'Accessoire', 'Accessorio'),
                        $o('Dienstleistung', 'Dienstleistung', 'Service', 'Service', 'Servizio'),
                        $o('Sonstiges', 'Sonstiges', 'Other', 'Divers', 'Altro'),
                    ]]],
                    ['name' => 'status', 'label' => $t('Status', 'Status', 'Statut', 'Stato'), 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                        $o('bestellt', 'Bestellt', 'Ordered', 'Commandé', 'Ordinato'),
                        $o('an_lager', 'An Lager', 'In stock', 'En stock', 'In magazzino'),
                        $o('reserviert', 'Reserviert', 'Reserved', 'Réservé', 'Riservato'),
                        $o('verkauft', 'Verkauft', 'Sold', 'Vendu', 'Venduto'),
                    ]]],
                    ['name' => 'beschreibung', 'label' => $t('Beschreibung', 'Description', 'Description', 'Descrizione'), 'type' => 'text', 'show_in_table' => false],
                    ['name' => 'bilder', 'label' => $t('Bilder', 'Images', 'Images', 'Immagini'), 'type' => 'file', 'options' => ['kinds' => ['images'], 'max_mb' => 10, 'max_files' => 10]],
                ],
            ],
            [
                'name' => 'gitarren_belege', 'label' => $t('Belege', 'Receipts', 'Justificatifs', 'Giustificativi'), 'icon' => 'heroicon-o-document-text',
                'description' => $t('Kaufbelege, Quittungen und Rechnungen; die Buchungen ergeben die Summe, die Zahlungen den bezahlten und offenen Betrag.', 'Purchase receipts and invoices; the bookings give the total, the payments the paid and open amount.', 'Justificatifs d’achat et factures ; les écritures donnent le total, les paiements le montant payé et ouvert.', 'Ricevute d’acquisto e fatture; le registrazioni danno il totale, i pagamenti l’importo pagato e aperto.'),
                'fields' => [
                    ['name' => 'belegnummer', 'label' => $t('Belegnummer', 'Receipt number', 'Numéro de justificatif', 'Numero del giustificativo'), 'type' => 'string', 'options' => ['length' => 30], 'is_required' => true, 'is_unique' => true, 'is_searchable' => true],
                    ['name' => 'datum', 'label' => $t('Datum', 'Date', 'Date', 'Data'), 'type' => 'date', 'is_required' => true, 'is_sortable' => true],
                    ['name' => 'typ', 'label' => $t('Typ', 'Type', 'Type', 'Tipo'), 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                        $o('Einnahme', 'Einnahme', 'Income', 'Recette', 'Entrata'),
                        $o('Ausgabe', 'Ausgabe', 'Expense', 'Dépense', 'Uscita'),
                    ]]],
                    // Einnahme: Kunde, Ausgabe: Lieferant.
                    ['name' => 'kunde_id', 'label' => $t('Kunde', 'Customer', 'Client', 'Cliente'), 'type' => 'relation', 'options' => ['table' => 'gitarren_kunden', 'display_fields' => ['vorname', 'name'], 'separator' => ' ']],
                    ['name' => 'lieferant_id', 'label' => $t('Lieferant', 'Supplier', 'Fournisseur', 'Fornitore'), 'type' => 'relation', 'options' => ['table' => 'gitarren_lieferanten', 'display_fields' => ['name']]],
                    ['name' => 'beschreibung', 'label' => $t('Beschreibung', 'Description', 'Description', 'Descrizione'), 'type' => 'text', 'show_in_table' => false],
                    ['name' => 'dateien', 'label' => $t('Belegdateien', 'Receipt files', 'Fichiers', 'File'), 'type' => 'file', 'options' => ['kinds' => ['pdf', 'images'], 'max_mb' => 10, 'max_files' => 5]],
                ],
            ],
            [
                'name' => 'gitarren_buchungen', 'label' => $t('Buchungen', 'Bookings', 'Écritures', 'Registrazioni'), 'icon' => 'heroicon-o-queue-list',
                'description' => $t('Positionen der Belege. Netto und MWST-Satz ergeben MWST und Brutto; „Verkauf“ ist eine Einnahme, alle anderen Arten sind Ausgaben.', 'Lines of the receipts. Net and VAT rate give VAT and gross; "Sale" is income, all other kinds are expenses.', 'Lignes des justificatifs. Net et taux de TVA donnent TVA et brut ; « Vente » est une recette, les autres genres sont des dépenses.', 'Righe dei giustificativi. Netto e aliquota IVA danno IVA e lordo; «Vendita» è un’entrata, gli altri generi sono uscite.'),
                'fields' => [
                    ['name' => 'beleg_id', 'label' => $t('Beleg', 'Receipt', 'Justificatif', 'Giustificativo'), 'type' => 'relation', 'options' => ['table' => 'gitarren_belege', 'display_field' => 'belegnummer', 'copy_with_parent' => true], 'is_required' => true],
                    ['name' => 'artikel_id', 'label' => $t('Artikel', 'Item', 'Article', 'Articolo'), 'type' => 'relation', 'options' => ['table' => 'gitarren_artikel', 'display_fields' => ['inventarnummer', 'bezeichnung'], 'separator' => ' ']],
                    ['name' => 'art', 'label' => $t('Art', 'Kind', 'Genre', 'Genere'), 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                        $o('Warenwert', 'Warenwert', 'Goods value', 'Valeur marchandise', 'Valore merce'),
                        $o('Zoll', 'Zoll', 'Customs', 'Douane', 'Dogana'),
                        $o('Einfuhr-MWST', 'Einfuhr-MWST', 'Import VAT', 'TVA à l’importation', 'IVA all’importazione'),
                        $o('Dienstleistung', 'Dienstleistung', 'Service', 'Service', 'Servizio'),
                        $o('Sonstiges', 'Sonstiges', 'Other', 'Divers', 'Altro'),
                        $o('Verkauf', 'Verkauf', 'Sale', 'Vente', 'Vendita'),
                    ]]],
                    ['name' => 'text', 'label' => $t('Text', 'Text', 'Texte', 'Testo'), 'type' => 'string', 'options' => ['length' => 200]],
                    ['name' => 'netto', 'label' => $t('Netto (CHF)', 'Net (CHF)', 'Net (CHF)', 'Netto (CHF)'), 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2], 'is_required' => true],
                    ['name' => 'mwst_satz', 'label' => $t('MWST-Satz', 'VAT rate', 'Taux de TVA', 'Aliquota IVA'), 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                        $o('8.1', '8,1 % (Normalsatz)', '8.1 % (standard)', '8,1 % (taux normal)', '8,1 % (aliquota normale)'),
                        $o('2.6', '2,6 % (reduziert)', '2.6 % (reduced)', '2,6 % (réduit)', '2,6 % (ridotta)'),
                        $o('3.8', '3,8 % (Beherbergung)', '3.8 % (accommodation)', '3,8 % (hébergement)', '3,8 % (alloggio)'),
                        $o('0', '0 % (ohne MWST)', '0 % (no VAT)', '0 % (sans TVA)', '0 % (senza IVA)'),
                    ]]],
                ],
            ],
            [
                'name' => 'gitarren_zahlungen', 'label' => $t('Zahlungen', 'Payments', 'Paiements', 'Pagamenti'), 'icon' => 'heroicon-o-credit-card',
                'description' => $t('Zahlungen zu einem Beleg; bei Teil- oder Ratenzahlung mehrere, je mit eigenem Kassenbeleg.', 'Payments for a receipt; several for part or instalment payments, each with its own cash receipt.', 'Paiements d’un justificatif ; plusieurs en cas de paiement partiel ou échelonné, chacun avec son ticket.', 'Pagamenti di un giustificativo; più d’uno per pagamenti parziali o rateali, ognuno con il proprio scontrino.'),
                'fields' => [
                    ['name' => 'beleg_id', 'label' => $t('Beleg', 'Receipt', 'Justificatif', 'Giustificativo'), 'type' => 'relation', 'options' => ['table' => 'gitarren_belege', 'display_field' => 'belegnummer'], 'is_required' => true],
                    ['name' => 'datum', 'label' => $t('Datum', 'Date', 'Date', 'Data'), 'type' => 'date', 'is_required' => true],
                    ['name' => 'betrag', 'label' => $t('Betrag (CHF)', 'Amount (CHF)', 'Montant (CHF)', 'Importo (CHF)'), 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2], 'is_required' => true],
                    ['name' => 'zahlungsart', 'label' => $t('Zahlungsart', 'Payment method', 'Mode de paiement', 'Modalità di pagamento'), 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                        $o('bar', 'Bar', 'Cash', 'Espèces', 'Contanti'),
                        $o('bank', 'Bank', 'Bank', 'Banque', 'Banca'),
                        $o('twint', 'TWINT', 'TWINT', 'TWINT', 'TWINT'),
                        $o('karte', 'Karte', 'Card', 'Carte', 'Carta'),
                    ]]],
                    ['name' => 'kassenbeleg', 'label' => $t('Kassenbeleg', 'Cash receipt', 'Ticket de caisse', 'Scontrino'), 'type' => 'file', 'options' => ['kinds' => ['pdf', 'images'], 'max_mb' => 10, 'max_files' => 1]],
                    ['name' => 'bemerkung', 'label' => $t('Bemerkung', 'Note', 'Remarque', 'Nota'), 'type' => 'string', 'options' => ['length' => 200]],
                ],
            ],
        ];
    }

    protected function reseedDemoData(): void
    {
        $this->seedRecords();
    }

    private function seedRecords(): void
    {
        $insert = fn (string $table, array $data): int => (int) DynamicRecord::forTableName($table)->create($data)->getKey();
        $items = [];
        $partners = [];

        foreach ([
            ['K-1001', 'Lena', 'Meier', 'Bahnhofstrasse 12', '8001', 'Zürich', 'CH', 'lena.meier@example.ch', true, '+41 44 555 12 34'],
            ['K-1002', 'Marco', 'Rossi', 'Via Nassa 5', '6900', 'Lugano', 'CH', 'marco.rossi@example.ch', false, '+41 91 555 22 11'],
            ['K-1003', null, 'Studio Klangraum GmbH', 'Industriestrasse 40', '3008', 'Bern', 'CH', 'info@klangraum.example', true, '+41 31 555 40 40'],
            ['K-1004', 'Sophie', 'Keller', 'Rheinweg 3', '4058', 'Basel', 'CH', 'sophie.keller@example.ch', true, null],
            ['K-1005', 'Jonas', 'Weber', 'Hauptstrasse 7', '79539', 'Lörrach', 'DE', null, false, '+49 7621 555 77'],
        ] as [$nummer, $vorname, $name, $strasse, $plz, $ort, $land, $email, $newsletter, $telefon]) {
            $partners[$name] = ['kunde_id' => $insert('gitarren_kunden', compact('vorname', 'name', 'strasse', 'plz', 'ort', 'land', 'email', 'newsletter', 'telefon') + ['kundennummer' => $nummer]), 'address' => trim(($vorname ?? '').' '.$name).', '.$strasse.', '.$plz.' '.$ort];
        }

        foreach ([
            ['Shenzhen Guitar Co.', 'CN', 'Shenzhen', 'sales@szguitar.example', '+86 755 5555 0101', 'https://szguitar.example', 'SAFT-0042'],
            ['BAZG (Zoll)', 'CH', 'Bern', null, null, 'https://www.bazg.admin.ch', null],
            ['Spedition Alpentrans', 'CH', 'Basel', 'dispo@alpentrans.example', '+41 61 555 90 90', null, '77810'],
            ['Gitarrenbau Huber', 'CH', 'Winterthur', 'werkstatt@huber-gitarren.example', '+41 52 555 31 31', null, null],
        ] as [$name, $land, $ort, $email, $telefon, $webseite, $nummer]) {
            $partners[$name] = ['lieferant_id' => $insert('gitarren_lieferanten', compact('name', 'land', 'ort', 'email', 'telefon', 'webseite') + ['unsere_kundennummer' => $nummer]), 'address' => $name.', '.$ort];
        }

        // Inventarnummer, Bezeichnung, Kategorie, Status, Beschreibung, Bilder (Farben der Platzhalter).
        foreach ([
            ['G-001', 'E-Gitarre Stratocaster-Typ, Sunburst', 'Instrument', 'verkauft', 'Erle, 3 Single-Coils, Vintage-Tremolo.', [[200, 120, 40], [120, 70, 30]]],
            ['G-002', 'E-Gitarre Telecaster-Typ, Butterscotch', 'Instrument', 'verkauft', 'Esche, Ahornhals; nach Reparatur der Bundierung verkauft.', [[230, 180, 90]]],
            ['G-003', 'E-Gitarre Les-Paul-Typ, Cherry', 'Instrument', 'an_lager', 'Mahagoni mit Ahorndecke, 2 Humbucker.', [[150, 20, 30], [90, 10, 20], [60, 40, 30]]],
            ['G-004', 'Akustikgitarre Dreadnought, Natur', 'Instrument', 'verkauft', 'Fichtendecke massiv; Verkauf mit Ratenzahlung.', [[220, 190, 140]]],
            ['G-005', 'E-Bass 4-Saiter, Schwarz', 'Instrument', 'an_lager', 'Jazz-Bass-Form, aktive Elektronik.', [[30, 30, 35]]],
            ['G-006', 'Halbresonanz-Gitarre, Walnuss', 'Instrument', 'bestellt', 'Bestellt in Shenzhen, Anzahlung geleistet.', []],
            ['Z-001', 'Röhrenverstärker 15 W', 'Zubehör', 'verkauft', 'Combo 1×12", Federhall.', [[60, 60, 60]]],
            ['Z-002', 'Hartschalenkoffer', 'Zubehör', 'reserviert', 'Für E-Gitarren, Plüschfutter.', [[20, 20, 20]]],
            ['D-001', 'Setup und Einstellung', 'Dienstleistung', 'verkauft', 'Saitenlage, Halskrümmung, Oktavreinheit.', []],
            ['S-001', 'Gitarrenständer, Holz', 'Sonstiges', 'an_lager', 'Handgefertigt, Eiche.', [[160, 110, 60]]],
        ] as [$nummer, $bezeichnung, $kategorie, $status, $beschreibung, $colors]) {
            $items[$nummer] = $insert('gitarren_artikel', [
                'inventarnummer' => $nummer, 'bezeichnung' => $bezeichnung, 'kategorie' => $kategorie, 'status' => $status, 'beschreibung' => $beschreibung,
                'bilder' => array_map(fn (array $rgb, int $i): array => $this->file('gitarren_artikel', 'bilder', strtolower($nummer).'-'.($i + 1).'.png', self::png(...$rgb)), $colors, array_keys($colors)) ?: null,
            ]);
        }

        // Beleg: Nummer, Datum, Typ, Kunde (Nachname oder Firma) bzw. Lieferant, Buchungen (Artikel, Art, Text, Netto, MWST-Satz), Zahlungen (Datum, Betrag, Art, Kassenbeleg?, Bemerkung).
        foreach ([
            ['E-2025-001', '2025-03-10', 'Ausgabe', 'Shenzhen Guitar Co.', [
                ['G-001', 'Warenwert', 'Stratocaster-Typ', '180.00', '0'], ['G-002', 'Warenwert', 'Telecaster-Typ', '190.00', '0'], ['G-003', 'Warenwert', 'Les-Paul-Typ', '220.00', '0'],
            ], [['2025-03-10', '590.00', 'bank', false, 'Vorauszahlung']]],
            ['E-2025-002', '2025-03-24', 'Ausgabe', 'BAZG (Zoll)', [
                ['G-001', 'Zoll', 'Zoll Stratocaster-Typ', '12.00', '0'], ['G-002', 'Zoll', 'Zoll Telecaster-Typ', '12.00', '0'], ['G-003', 'Zoll', 'Zoll Les-Paul-Typ', '15.00', '0'],
                ['G-001', 'Einfuhr-MWST', 'Einfuhr-MWST', '15.55', '0'], ['G-002', 'Einfuhr-MWST', 'Einfuhr-MWST', '16.36', '0'], ['G-003', 'Einfuhr-MWST', 'Einfuhr-MWST', '19.04', '0'],
            ], [['2025-03-24', '89.95', 'bank', false, null]]],
            ['E-2025-003', '2025-03-24', 'Ausgabe', 'Spedition Alpentrans', [
                [null, 'Sonstiges', 'Fracht Shenzhen–Zürich', '85.00', '8.1'],
            ], [['2025-03-28', '91.89', 'twint', true, null]]],
            ['E-2025-004', '2025-05-15', 'Ausgabe', 'Gitarrenbau Huber', [
                ['G-002', 'Dienstleistung', 'Neue Bundierung', '120.00', '8.1'],
            ], [['2025-05-15', '129.72', 'bar', true, null]]],
            ['V-2025-001', '2025-06-01', 'Einnahme', 'Meier', [
                ['G-001', 'Verkauf', 'Stratocaster-Typ', '450.00', '8.1'],
            ], [['2025-06-01', '486.45', 'twint', true, null]]],
            ['V-2025-002', '2025-09-12', 'Einnahme', 'Rossi', [
                ['G-002', 'Verkauf', 'Telecaster-Typ', '620.00', '8.1'], ['D-001', 'Verkauf', 'Setup und Einstellung', '80.00', '8.1'],
            ], [['2025-09-12', '756.70', 'karte', true, null]]],
            ['E-2026-001', '2026-01-20', 'Ausgabe', 'Shenzhen Guitar Co.', [
                ['G-004', 'Warenwert', 'Akustikgitarre', '160.00', '0'], ['G-005', 'Warenwert', 'E-Bass', '210.00', '0'], ['Z-001', 'Warenwert', 'Röhrenverstärker', '140.00', '0'], ['Z-002', 'Warenwert', 'Hartschalenkoffer', '45.00', '0'],
            ], [['2026-01-20', '555.00', 'bank', false, null]]],
            ['E-2026-002', '2026-02-05', 'Ausgabe', 'BAZG (Zoll)', [
                ['G-004', 'Zoll', 'Zoll Akustikgitarre', '10.00', '0'], ['G-005', 'Zoll', 'Zoll E-Bass', '14.00', '0'], ['Z-001', 'Zoll', 'Zoll Verstärker', '9.00', '0'],
                ['G-004', 'Einfuhr-MWST', 'Einfuhr-MWST', '13.77', '0'], ['G-005', 'Einfuhr-MWST', 'Einfuhr-MWST', '18.15', '0'], ['Z-001', 'Einfuhr-MWST', 'Einfuhr-MWST', '12.07', '0'], ['Z-002', 'Einfuhr-MWST', 'Einfuhr-MWST', '3.65', '0'],
            ], [['2026-02-05', '80.64', 'bank', false, null]]],
            ['V-2026-001', '2026-03-14', 'Einnahme', 'Studio Klangraum GmbH', [
                ['Z-001', 'Verkauf', 'Röhrenverstärker', '290.00', '8.1'],
            ], [['2026-03-20', '313.49', 'bank', false, null]]],
            ['V-2026-002', '2026-04-02', 'Einnahme', 'Keller', [
                ['G-004', 'Verkauf', 'Akustikgitarre, Ratenzahlung in 3 Raten', '540.00', '8.1'],
            ], [['2026-04-02', '200.00', 'bar', true, 'Rate 1/3'], ['2026-05-02', '200.00', 'twint', true, 'Rate 2/3'], ['2026-06-02', '183.74', 'twint', true, 'Rate 3/3']]],
            ['E-2026-003', '2026-05-18', 'Ausgabe', 'Shenzhen Guitar Co.', [
                ['G-006', 'Warenwert', 'Halbresonanz-Gitarre', '260.00', '0'],
            ], [['2026-05-18', '130.00', 'bank', false, 'Anzahlung 50 %']]],
        ] as [$nummer, $datum, $typ, $partner, $lines, $payments]) {
            $receipt = $insert('gitarren_belege', [
                'belegnummer' => $nummer, 'datum' => $datum, 'typ' => $typ,
                'kunde_id' => $partners[$partner]['kunde_id'] ?? null, 'lieferant_id' => $partners[$partner]['lieferant_id'] ?? null,
                'dateien' => [$this->file('gitarren_belege', 'dateien', strtolower($nummer).'.pdf', self::pdf(($typ === 'Einnahme' ? 'Rechnung ' : 'Beleg ').$nummer, [
                    $partners[$partner]['address'], 'Datum: '.$datum,
                    ...array_map(static fn (array $l): string => sprintf('%s  %s  CHF %s  (MWST %s %%)', $l[1], $l[2], $l[3], $l[4]), $lines),
                ]))],
            ]);

            foreach ($lines as [$item, $art, $text, $netto, $satz]) {
                $insert('gitarren_buchungen', ['beleg_id' => $receipt, 'artikel_id' => $item === null ? null : $items[$item], 'art' => $art, 'text' => $text, 'netto' => $netto, 'mwst_satz' => $satz]);
            }

            foreach ($payments as $i => [$date, $amount, $method, $withReceipt, $note]) {
                $insert('gitarren_zahlungen', [
                    'beleg_id' => $receipt, 'datum' => $date, 'betrag' => $amount, 'zahlungsart' => $method, 'bemerkung' => $note,
                    'kassenbeleg' => $withReceipt ? [$this->file('gitarren_zahlungen', 'kassenbeleg', strtolower($nummer).'-zahlung-'.($i + 1).'.pdf', self::pdf('Quittung '.$nummer, ['Datum: '.$date, 'Betrag: CHF '.$amount, (string) $note]))] : null,
                ]);
            }
        }
    }

    /**
     * Datei wie beim Hochladen ablegen; der Datensatz übernimmt sie beim Speichern.
     *
     * @return array{id: string, name: string}
     */
    private function file(string $table, string $field, string $name, string $content): array
    {
        $owner = CustomTable::query()->where('name', $table)->sole();
        $path = tempnam(sys_get_temp_dir(), 'saft-demo-');
        file_put_contents($path, $content);
        $mime = str_ends_with($name, '.png') ? 'image/png' : 'application/pdf';

        try {
            return app(RecordFiles::class)->store($owner, $owner->fields()->where('name', $field)->sole(), new UploadedFile($path, $name, $mime, null, true))->toReference();
        } finally {
            @unlink($path);
        }
    }

    /** Einfarbiges Platzhalterbild 320×200 mit hellem Streifen (PNG ohne GD). */
    private static function png(int $r, int $g, int $b): string
    {
        [$width, $height] = [320, 200];
        $pixel = chr($r).chr($g).chr($b);
        $light = chr(min(255, $r + 60)).chr(min(255, $g + 60)).chr(min(255, $b + 60));
        $rows = '';

        for ($y = 0; $y < $height; $y++) {
            $rows .= "\x00".str_repeat($y >= 80 && $y < 120 ? $light : $pixel, $width);
        }

        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IDAT', (string) gzcompress($rows)).$chunk('IEND', '');
    }

    /**
     * Einseitiges PDF mit Titel und Zeilen (Helvetica, ohne Bibliothek); Umlaute als WinAnsi.
     *
     * @param  list<string>  $lines
     */
    private static function pdf(string $title, array $lines): string
    {
        $text = static fn (string $s): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) mb_convert_encoding($s, 'Windows-1252', 'UTF-8'));
        $stream = 'BT /F1 18 Tf 50 780 Td ('.$text($title).") Tj ET\n";

        foreach (array_values(array_filter($lines, 'strlen')) as $i => $line) {
            $stream .= 'BT /F1 11 Tf 50 '.(740 - 18 * $i).' Td ('.$text($line).") Tj ET\n";
        }

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
