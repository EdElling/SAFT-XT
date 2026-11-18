<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\DynamicData\DynamicDataNamespace;
use App\Models\CustomField;
use App\Models\Menu;
use App\Services\DynamicSchemaService;
use Database\Seeders\Concerns\SeedsDemoTables;
use Illuminate\Database\Connection;
use Illuminate\Database\Seeder;

/**
 * Forschungsdatenbank (Demo) als Tabellen des Table-Designers – dieselben Daten wie die externe Fassung
 * (ResearchDemoData), aber in SAFT selbst (ohne Datenbankverbindung). Menügruppe „Forschung (Demo)“.
 *
 *   php artisan db:seed --class=ForschungDemoSeeder
 *
 * Abweichungen zur externen Fassung: eigene IDs statt UUID bzw. zusammengesetztem Schlüssel bei
 * Publikationen und Autoren, Typ/Status/Land als Auswahlfelder, Metadaten (JSON) als Langtext.
 * Wiederholbar: vorhandene Tabellen und Daten werden nicht dupliziert.
 */
class ForschungDemoSeeder extends Seeder
{
    use ResearchDemoData;
    use SeedsDemoTables;

    public const array GROUP = ['de' => 'Forschung (Demo)', 'en' => 'Research (demo)', 'fr' => 'Recherche (démo)', 'it' => 'Ricerca (demo)'];

    public const string REPORT_PUBLICATIONS = 'Publikationen je Jahr und Typ';

    public const string REPORT_BUDGET = 'Projektbudget je Status';

    public const string REPORT_BUDGET_PER_PUBLICATION = 'Budget je Publikation';

    /** Verbindung des Table-Designers; ResearchDemoData schreibt direkt in die Tabellen. */
    private Connection $db;

    /** App der Demo (B-107); der Schlüssel ist fest, damit wiederholtes Einspielen dieselbe App trifft. */
    public function demoAppDefinition(): array
    {
        return ['uuid' => '8f0c6a52-6d0e-4c8e-9a43-5b1c2f6e0a03', 'name' => ['de' => 'Forschung (Demo)', 'en' => 'Research (demo)', 'fr' => 'Recherche (démo)', 'it' => 'Ricerca (demo)'], 'prefix' => 'forschung', 'icon' => 'heroicon-o-beaker', 'sort_order' => 540];
    }

    public function run(DynamicDataNamespace $namespace): void
    {
        $this->db = $namespace->connection();
        $group = $this->demoGroup(self::GROUP, 540);
        $created = $this->demoTables($group, $this->definitions());

        if (in_array('forschung_organisationen', $created, true)) {
            $this->seed();
        }

        // Jahr ohne Tausendertrennzeichen – auch in bereits geladenen Demos.
        $year = CustomField::query()->find($this->demoFieldId('forschung_publikationen.jahr'));

        if ($year instanceof CustomField && $year->groupsDigits()) {
            app(DynamicSchemaService::class)->updateField($year, ['options' => [...$year->optionsArray(), CustomField::GROUP_DIGITS => false]]);
        }

        $this->demoRules('forschung_projekte', [[
            'left' => 'ende', 'operator' => '>=', 'right_kind' => 'field', 'right_field' => 'beginn', 'when_filled' => true,
        ]]);
        $this->demoDetails([
            'forschung_organisationen' => ['forschung_personen.arbeitgeber_id', 'forschung_projekte.partner_id'],
            'forschung_personen' => ['forschung_projekte.projektleiter_id', 'forschung_publikation_autoren.person_id'],
            'forschung_projekte' => ['forschung_publikationen.projekt_id'],
            'forschung_publikationen' => ['forschung_publikation_autoren.publikation_id'],
        ]);
        $this->reports($group);
    }

    protected function reseedDemoData(): void
    {
        $this->db = app(DynamicDataNamespace::class)->connection();
        $this->seed();
    }

    protected function qualified(string $table): string
    {
        return app(DynamicDataNamespace::class)->qualify('forschung_'.$table);
    }

    /** Datum und Uhrzeit ohne Zeitzone (Feldtyp „Datum und Uhrzeit“). */
    protected function importTime(string $local): string
    {
        return $local;
    }

    private function reports(Menu $group): void
    {
        $this->demoReport($group, self::REPORT_PUBLICATIONS, 'Anzahl Publikationen je Erscheinungsjahr, aufgeteilt nach Publikationstyp.', [
            'base' => $this->demoTableId('forschung_publikationen'),
            'fields' => [
                ['key' => 'jahr', 'path' => [], 'field' => $this->demoFieldId('forschung_publikationen.jahr')],
                ['key' => 'typ', 'path' => [], 'field' => $this->demoFieldId('forschung_publikationen.typ')],
            ],
            'groups' => ['jahr'],
            'columns' => ['typ'],
            'measures' => [['key' => 'anzahl', 'field' => null, 'agg' => 'count', 'label' => 'Publikationen']],
            'visual' => ['type' => 'bar'],
        ], self::demoText('Publikationen je Jahr', 'Publications per year', 'Publications par année', 'Pubblicazioni per anno'), 'heroicon-o-chart-bar');

        $this->demoReport($group, self::REPORT_BUDGET, 'Summe der Projektbudgets je Projektstatus.', [
            'base' => $this->demoTableId('forschung_projekte'),
            'fields' => [
                ['key' => 'status', 'path' => [], 'field' => $this->demoFieldId('forschung_projekte.status')],
                ['key' => 'budget', 'path' => [], 'field' => $this->demoFieldId('forschung_projekte.budget')],
            ],
            'groups' => ['status'],
            'measures' => [['key' => 'summe', 'field' => 'budget', 'agg' => 'sum', 'label' => 'Budget (CHF)']],
            'sort' => [['key' => 'summe', 'dir' => 'desc']],
            'visual' => ['type' => 'pie'],
        ], self::demoText('Budget je Status', 'Budget per status', 'Budget par statut', 'Budget per stato'), 'heroicon-o-chart-pie');

        // Kennzahl aus Werten: Budget des Projekts (gruppiert, je Zeile ein Wert) ÷ Anzahl seiner Publikationen.
        $this->demoReport($group, self::REPORT_BUDGET_PER_PUBLICATION, 'Beispiel für eine Kennzahl aus Werten: Projektbudget geteilt durch die Anzahl Publikationen des Projekts.', [
            'base' => $this->demoTableId('forschung_publikationen'),
            'fields' => [
                ['key' => 'projekt', 'path' => [], 'field' => $this->demoFieldId('forschung_publikationen.projekt_id')],
                ['key' => 'budget', 'path' => [$this->demoFieldId('forschung_publikationen.projekt_id')], 'field' => $this->demoFieldId('forschung_projekte.budget'), 'label' => 'Budget (CHF)'],
            ],
            'groups' => ['projekt', 'budget'],
            'measures' => [
                ['key' => 'anzahl', 'field' => null, 'agg' => 'count', 'label' => 'Publikationen'],
                ['key' => 'je_publikation', 'field' => null, 'agg' => 'calc', 'label' => 'Budget je Publikation (CHF)', 'scale' => 0,
                    'calc' => ['op' => '/', 'left' => ['field' => 'budget'], 'right' => ['field' => 'anzahl']]],
            ],
            'filters' => [['field' => 'projekt', 'op' => 'not_empty', 'value' => null]],
            'sort' => [['key' => 'je_publikation', 'dir' => 'desc']],
            'visual' => ['type' => 'table'],
        ], self::demoText('Budget je Publikation', 'Budget per publication', 'Budget par publication', 'Budget per pubblicazione'), 'heroicon-o-calculator');
    }

    /** @return list<array<string, mixed>> Verknüpfungsziele vor den Tabellen, die auf sie verweisen */
    private function definitions(): array
    {
        $t = self::demoText(...);
        $countries = ['options' => [
            ['value' => 'CH', 'label' => $t('Schweiz', 'Switzerland', 'Suisse', 'Svizzera')],
            ['value' => 'DE', 'label' => $t('Deutschland', 'Germany', 'Allemagne', 'Germania')],
            ['value' => 'AT', 'label' => $t('Österreich', 'Austria', 'Autriche', 'Austria')],
            ['value' => 'SE', 'label' => $t('Schweden', 'Sweden', 'Suède', 'Svezia')],
        ]];
        $name = $t('Name', 'Name', 'Nom', 'Nome');

        return [
            [
                'name' => 'forschung_organisationen', 'label' => $t('Organisationen', 'Organisations', 'Organisations', 'Organizzazioni'), 'icon' => 'heroicon-o-building-library',
                'fields' => [
                    ['name' => 'name', 'label' => $name, 'type' => 'string', 'options' => ['length' => 200], 'is_required' => true, 'is_unique' => true, 'is_searchable' => true],
                    ['name' => 'land', 'label' => $t('Land', 'Country', 'Pays', 'Paese'), 'type' => 'select', 'options' => $countries, 'is_required' => true],
                    ['name' => 'typ', 'label' => $t('Typ', 'Type', 'Type', 'Tipo'), 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                        ['value' => 'hochschule', 'label' => $t('Hochschule', 'University', 'Haute école', 'Università')],
                        ['value' => 'forschungsinstitut', 'label' => $t('Forschungsinstitut', 'Research institute', 'Institut de recherche', 'Istituto di ricerca')],
                        ['value' => 'unternehmen', 'label' => $t('Unternehmen', 'Company', 'Entreprise', 'Azienda')],
                        ['value' => 'behoerde', 'label' => $t('Behörde', 'Authority', 'Administration', 'Autorità')],
                    ]]],
                    ['name' => 'gegruendet', 'label' => $t('Gegründet', 'Founded', 'Fondée', 'Fondata'), 'type' => 'date'],
                    ['name' => 'webseite', 'label' => $t('Webseite', 'Website', 'Site web', 'Sito web'), 'type' => 'string', 'validation_rules' => ['url' => true]],
                    ['name' => 'aktiv', 'label' => $t('Aktiv', 'Active', 'Actif', 'Attivo'), 'type' => 'boolean'],
                ],
            ],
            [
                'name' => 'forschung_personen', 'label' => $t('Personen', 'People', 'Personnes', 'Persone'), 'icon' => 'heroicon-o-user',
                'fields' => [
                    ['name' => 'vorname', 'label' => $t('Vorname', 'First name', 'Prénom', 'Nome'), 'type' => 'string', 'options' => ['length' => 100], 'is_required' => true],
                    ['name' => 'nachname', 'label' => $t('Nachname', 'Last name', 'Nom', 'Cognome'), 'type' => 'string', 'options' => ['length' => 100], 'is_required' => true, 'is_searchable' => true],
                    ['name' => 'email', 'label' => $t('E-Mail', 'Email', 'E-mail', 'E-mail'), 'type' => 'string', 'options' => ['length' => 200], 'is_unique' => true, 'validation_rules' => ['email' => true]],
                    ['name' => 'orcid', 'label' => 'ORCID', 'type' => 'string', 'options' => ['length' => 19], 'is_unique' => true],
                    ['name' => 'geburtsdatum', 'label' => $t('Geburtsdatum', 'Date of birth', 'Date de naissance', 'Data di nascita'), 'type' => 'date'],
                    ['name' => 'arbeitgeber_id', 'label' => $t('Arbeitgeber', 'Employer', 'Employeur', 'Datore di lavoro'), 'type' => 'relation', 'options' => ['table' => 'forschung_organisationen', 'display_field' => 'name']],
                ],
            ],
            [
                'name' => 'forschung_projekte', 'label' => $t('Projekte', 'Projects', 'Projets', 'Progetti'), 'icon' => 'heroicon-o-light-bulb',
                'fields' => [
                    ['name' => 'kurzname', 'label' => $t('Kurzname', 'Short name', 'Nom court', 'Nome breve'), 'type' => 'string', 'options' => ['length' => 30], 'is_required' => true, 'is_unique' => true],
                    ['name' => 'titel', 'label' => $t('Titel', 'Title', 'Titre', 'Titolo'), 'type' => 'string', 'options' => ['length' => 255], 'is_required' => true, 'is_searchable' => true],
                    ['name' => 'beginn', 'label' => $t('Beginn', 'Start', 'Début', 'Inizio'), 'type' => 'date', 'is_required' => true],
                    ['name' => 'ende', 'label' => $t('Ende', 'End', 'Fin', 'Fine'), 'type' => 'date'],
                    ['name' => 'budget', 'label' => $t('Budget (CHF)', 'Budget (CHF)', 'Budget (CHF)', 'Budget (CHF)'), 'type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2], 'validation_rules' => ['min' => 0]],
                    ['name' => 'status', 'label' => $t('Status', 'Status', 'Statut', 'Stato'), 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                        ['value' => 'geplant', 'label' => $t('Geplant', 'Planned', 'Planifié', 'Pianificato')],
                        ['value' => 'laufend', 'label' => $t('Laufend', 'Running', 'En cours', 'In corso')],
                        ['value' => 'abgeschlossen', 'label' => $t('Abgeschlossen', 'Completed', 'Terminé', 'Concluso')],
                        ['value' => 'abgebrochen', 'label' => $t('Abgebrochen', 'Cancelled', 'Interrompu', 'Interrotto')],
                    ]]],
                    ['name' => 'projektleiter_id', 'label' => $t('Projektleitung', 'Project lead', 'Direction du projet', 'Responsabile'), 'type' => 'relation', 'options' => ['table' => 'forschung_personen', 'display_field' => 'nachname'], 'is_required' => true],
                    ['name' => 'partner_id', 'label' => $t('Partner', 'Partner', 'Partenaire', 'Partner'), 'type' => 'relation', 'options' => ['table' => 'forschung_organisationen', 'display_field' => 'name']],
                    ['name' => 'metadaten', 'label' => $t('Metadaten (JSON)', 'Metadata (JSON)', 'Métadonnées (JSON)', 'Metadati (JSON)'), 'type' => 'text', 'show_in_table' => false],
                ],
            ],
            [
                'name' => 'forschung_publikationen', 'label' => $t('Publikationen', 'Publications', 'Publications', 'Pubblicazioni'), 'icon' => 'heroicon-o-book-open',
                'fields' => [
                    ['name' => 'titel', 'label' => $t('Titel', 'Title', 'Titre', 'Titolo'), 'type' => 'string', 'options' => ['length' => 255], 'is_required' => true, 'is_searchable' => true],
                    ['name' => 'jahr', 'label' => $t('Jahr', 'Year', 'Année', 'Anno'), 'type' => 'integer', 'options' => [CustomField::GROUP_DIGITS => false], 'is_required' => true, 'validation_rules' => ['min' => 1900, 'max' => 2100]],
                    ['name' => 'doi', 'label' => 'DOI', 'type' => 'string', 'options' => ['length' => 100], 'is_unique' => true],
                    ['name' => 'typ', 'label' => $t('Typ', 'Type', 'Type', 'Tipo'), 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                        ['value' => 'artikel', 'label' => $t('Artikel', 'Article', 'Article', 'Articolo')],
                        ['value' => 'konferenz', 'label' => $t('Konferenzbeitrag', 'Conference paper', 'Contribution à une conférence', 'Contributo a conferenza')],
                        ['value' => 'buchkapitel', 'label' => $t('Buchkapitel', 'Book chapter', 'Chapitre de livre', 'Capitolo di libro')],
                        ['value' => 'bericht', 'label' => $t('Bericht', 'Report', 'Rapport', 'Rapporto')],
                    ]]],
                    ['name' => 'open_access', 'label' => 'Open Access', 'type' => 'boolean'],
                    ['name' => 'projekt_id', 'label' => $t('Projekt', 'Project', 'Projet', 'Progetto'), 'type' => 'relation', 'options' => ['table' => 'forschung_projekte', 'display_field' => 'kurzname']],
                ],
            ],
            [
                'name' => 'forschung_publikation_autoren', 'label' => $t('Autorschaften', 'Authorships', 'Auteurs', 'Autori'), 'icon' => 'heroicon-o-pencil-square',
                'fields' => [
                    ['name' => 'publikation_id', 'label' => $t('Publikation', 'Publication', 'Publication', 'Pubblicazione'), 'type' => 'relation', 'options' => ['table' => 'forschung_publikationen', 'display_field' => 'titel'], 'is_required' => true],
                    ['name' => 'person_id', 'label' => $t('Person', 'Person', 'Personne', 'Persona'), 'type' => 'relation', 'options' => ['table' => 'forschung_personen', 'display_field' => 'nachname'], 'is_required' => true],
                    ['name' => 'reihenfolge', 'label' => $t('Reihenfolge', 'Order', 'Ordre', 'Ordine'), 'type' => 'integer', 'is_required' => true, 'validation_rules' => ['min' => 1]],
                ],
            ],
            [
                'name' => 'forschung_laender', 'label' => $t('Länder', 'Countries', 'Pays', 'Paesi'), 'icon' => 'heroicon-o-globe-europe-africa',
                'fields' => [
                    ['name' => 'code', 'label' => $t('Code', 'Code', 'Code', 'Codice'), 'type' => 'string', 'options' => ['length' => 2], 'is_required' => true, 'is_unique' => true],
                    ['name' => 'name', 'label' => $name, 'type' => 'string', 'options' => ['length' => 100], 'is_required' => true],
                ],
            ],
            [
                'name' => 'forschung_importprotokoll', 'label' => $t('Importprotokoll', 'Import log', 'Journal d’import', 'Registro importazioni'), 'icon' => 'heroicon-o-clipboard-document-list',
                'fields' => [
                    ['name' => 'zeitpunkt', 'label' => $t('Zeitpunkt', 'Time', 'Date et heure', 'Data e ora'), 'type' => 'datetime', 'is_required' => true],
                    ['name' => 'quelle', 'label' => $t('Quelle', 'Source', 'Source', 'Fonte'), 'type' => 'string', 'options' => ['length' => 100], 'is_required' => true],
                    ['name' => 'zeilen', 'label' => $t('Zeilen', 'Rows', 'Lignes', 'Righe'), 'type' => 'integer', 'is_required' => true],
                    ['name' => 'meldung', 'label' => $t('Meldung', 'Message', 'Message', 'Messaggio'), 'type' => 'text'],
                ],
            ],
        ];
    }
}
