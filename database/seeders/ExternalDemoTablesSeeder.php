<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\DynamicData\DataMenu;
use App\Exceptions\BusinessRuleException;
use App\ExternalData\TableConnectorService;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\Menu;

/**
 * Angebundene Tabellen der Demo-Forschungsdatenbank (Vorgabeschema der Verbindung) mit Bezeichnungen in vier
 * Sprachen, Verknüpfungen, Details und Menüeinträgen unter „Forschungsdatenbank“.
 *
 * Entspricht der im Projekt erprobten Konfiguration. Wiederholbar: bereits angebundene Tabellen
 * bleiben unverändert. Die externe Datenbank wird nicht verändert.
 */
final class ExternalDemoTablesSeeder
{
    public const string GROUP = 'Forschungsdatenbank';

    private const array GROUP_LABELS = ['de' => self::GROUP, 'en' => 'Research database', 'fr' => 'Base de recherche', 'it' => 'Database di ricerca'];

    public function __construct(
        private readonly TableConnectorService $connector,
        private readonly DataMenu $menu,
    ) {}

    /** @return int Zahl der neu angebundenen Tabellen */
    public function install(DatabaseConnection $connection): int
    {
        $this->assertNamesFree($connection);
        $research = $this->researchGroup();
        $created = 0;

        foreach ($this->tables() as $definition) {
            if (CustomTable::query()->where('name', $definition['name'])->exists()) {
                continue;
            }

            $table = $this->connector->connect($connection, $definition['schema'] ?? $connection->schemaName(), $definition['table'], [
                'name' => $definition['name'],
                'label' => $definition['label'],
                'description' => $definition['description'] ?? null,
                'menu_group' => null,
            ]);

            foreach ($definition['fields'] as $name => $settings) {
                $field = $table->fields->firstWhere('name', $name);

                if ($field instanceof CustomField) {
                    $this->connector->updateField($field, array_intersect_key($settings, array_flip(['label', 'is_read_only', 'show_in_table'])));
                }
            }

            $this->menu->create($table, ($definition['group'] ?? 'research') === 'data' ? $this->menu->defaultGroup() : (string) $research->id);
            $created++;
        }

        // Verknüpfungen und Details erst, wenn alle Tabellen da sind.
        foreach ($this->tables() as $definition) {
            $table = CustomTable::query()->with('fields')->where('name', $definition['name'])->first();

            if ($table === null) {
                continue;
            }

            foreach ($definition['fields'] as $name => $settings) {
                $field = $table->fields->firstWhere('name', $name);

                if ($field instanceof CustomField && isset($settings['relation'])) {
                    [$target, $display, $separator] = [...$settings['relation'], null];
                    $this->connector->setRelation($field, $target, $display, separator: $separator);
                }
            }

            if (isset($definition['details']) && empty($table->detail_field_ids)) {
                $ids = array_map(fn (string $reference): int => $this->fieldId($reference), $definition['details']);
                $this->connector->update($table->fresh(), ['detail_field_ids' => array_filter($ids)]);
            }
        }

        $this->menu->ensureDataModel($research);

        return $created;
    }

    /**
     * Vor dem ersten Schritt: Ein Name der Demo gilt als frei, wenn es ihn nicht gibt oder er schon diese
     * Tabelle derselben Verbindung bezeichnet (erneutes Laden). Sonst Abbruch, ohne etwas anzulegen.
     */
    private function assertNamesFree(DatabaseConnection $connection): void
    {
        $definitions = collect($this->tables())->keyBy('name');
        $taken = CustomTable::query()->whereIn('name', $definitions->keys())->orderBy('name')->get()
            ->reject(fn (CustomTable $table): bool => $table->isExternal()
                && $table->database_connection_id === $connection->id
                && $table->external_table === $definitions[$table->name]['table'])
            ->map(fn (CustomTable $table): string => $table->name.' ('.($table->isExternal() ? 'angebunden' : 'SAFT-Tabelle').')')
            ->all();

        if ($taken !== []) {
            throw new BusinessRuleException(
                'Demo nicht angebunden: Die Namen '.implode(', ', $taken).' sind bereits vergeben. Diese Tabellen entfernen oder die Demo weglassen.',
                'demo.name_taken',
            );
        }
    }

    private function researchGroup(): Menu
    {
        return Menu::query()->whereNull('parent_id')->whereNull('route')->whereNull('url')->where('label->de', self::GROUP)->first()
            ?? Menu::create(['label' => self::GROUP_LABELS, 'sort_order' => 450, 'is_active' => true]);
    }

    /** „tabelle.spalte“ → ID des Felds. */
    private function fieldId(string $reference): int
    {
        [$table, $field] = explode('.', $reference, 2);

        return (int) CustomField::query()->whereHas('custom_table', fn ($q) => $q->where('name', $table))->where('name', $field)->value('id');
    }

    /** @return array<string, string> */
    private static function t(string $de, string $en, string $fr, string $it): array
    {
        return ['de' => $de, 'en' => $en, 'fr' => $fr, 'it' => $it];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tables(): array
    {
        return [
            [
                'name' => 'fdb_organisationen',
                'table' => 'organisationen',
                'label' => self::t('Organisationen', 'Organisations', 'Organisations', 'Organizzazioni'),
                'details' => ['fdb_personen.arbeitgeber_id'],
                'fields' => [
                    'id' => ['label' => self::t('ID', 'ID', 'ID', 'ID')],
                    'name' => ['label' => self::t('Name', 'Name', 'Nom', 'Nome')],
                    'land' => ['label' => self::t('Land', 'Country', 'Pays', 'Paese')],
                    'typ' => ['label' => self::t('Typ', 'Type', 'Type', 'Tipo')],
                    'gegruendet' => ['label' => self::t('Gegründet', 'Founded', 'Fondée', 'Fondata')],
                    'webseite' => ['label' => self::t('Webseite', 'Website', 'Site web', 'Sito web')],
                    'aktiv' => ['label' => self::t('Aktiv', 'Active', 'Active', 'Attiva')],
                ],
            ],
            [
                'name' => 'fdb_personen',
                'table' => 'personen',
                'label' => self::t('Personen', 'People', 'Personnes', 'Persone'),
                'description' => self::t('Personen in der Forschungsdatenbank', 'People in the research database', 'Personnes de la base de recherche', 'Persone nel database di ricerca'),
                'details' => ['fdb_projekte.projektleiter_id', 'fdb_publikation_autoren.person_id'],
                'fields' => [
                    'id' => ['label' => self::t('ID', 'ID', 'ID', 'ID')],
                    'vorname' => ['label' => self::t('Vorname', 'First name', 'Prénom', 'Nome')],
                    'nachname' => ['label' => self::t('Nachname', 'Last name', 'Nom', 'Cognome')],
                    'vollname' => ['label' => self::t('Vollname', 'Full name', 'Nom complet', 'Nome completo')],
                    'email' => ['label' => self::t('E-Mail', 'Email', 'E-mail', 'E-mail')],
                    'orcid' => ['label' => self::t('ORCID', 'ORCID', 'ORCID', 'ORCID')],
                    'geburtsdatum' => ['label' => self::t('Geburtsdatum', 'Date of birth', 'Date de naissance', 'Data di nascita')],
                    'arbeitgeber_id' => ['label' => self::t('Arbeitgeber', 'Employer', 'Employeur', 'Datore di lavoro'), 'relation' => ['fdb_organisationen', ['name']]],
                    'erstellt_am' => ['label' => self::t('Erstellt am', 'Created on', 'Créée le', 'Creata il')],
                ],
            ],
            [
                'name' => 'fdb_projekte',
                'table' => 'projekte',
                'label' => self::t('Projekte', 'Projects', 'Projets', 'Progetti'),
                'description' => self::t('Forschungsprojekte mit Leitung und Partner', 'Research projects with lead and partner', 'Projets de recherche avec direction et partenaire', 'Progetti di ricerca con responsabile e partner'),
                'fields' => [
                    'id' => ['label' => self::t('ID', 'ID', 'ID', 'ID')],
                    'kurzname' => ['label' => self::t('Kurzname', 'Short name', 'Nom court', 'Nome breve')],
                    'titel' => ['label' => self::t('Titel', 'Title', 'Titre', 'Titolo')],
                    'beginn' => ['label' => self::t('Beginn', 'Start', 'Début', 'Inizio')],
                    'ende' => ['label' => self::t('Ende', 'End', 'Fin', 'Fine')],
                    'budget' => ['label' => self::t('Budget', 'Budget', 'Budget', 'Budget')],
                    'status' => ['label' => self::t('Status', 'Status', 'Statut', 'Stato')],
                    'projektleiter_id' => ['label' => self::t('Projektleiter', 'Project lead', 'Chef de projet', 'Responsabile'), 'relation' => ['fdb_personen', ['vorname', 'nachname'], ' ']],
                    'partner_id' => ['label' => self::t('Partner', 'Partner', 'Partenaire', 'Partner'), 'relation' => ['fdb_organisationen', ['name']]],
                    'metadaten' => ['label' => self::t('Metadaten', 'Metadata', 'Métadonnées', 'Metadati')],
                ],
            ],
            [
                'name' => 'fdb_publikationen',
                'table' => 'publikationen',
                'label' => self::t('Publikationen', 'Publications', 'Publications', 'Pubblicazioni'),
                'details' => ['fdb_publikation_autoren.publikation_id'],
                'fields' => [
                    'id' => ['label' => self::t('ID', 'ID', 'ID', 'ID')],
                    'titel' => ['label' => self::t('Titel', 'Title', 'Titre', 'Titolo')],
                    'jahr' => ['label' => self::t('Jahr', 'Year', 'Année', 'Anno')],
                    'doi' => ['label' => self::t('DOI', 'DOI', 'DOI', 'DOI')],
                    'typ' => ['label' => self::t('Typ', 'Type', 'Type', 'Tipo')],
                    'open_access' => ['label' => self::t('Open Access', 'Open access', 'Libre accès', 'Accesso aperto')],
                    'projekt_id' => ['label' => self::t('Projekt', 'Project', 'Projet', 'Progetto'), 'relation' => ['fdb_projekte', ['kurzname']]],
                ],
            ],
            [
                'name' => 'fdb_publikation_autoren',
                'table' => 'publikation_autoren',
                'label' => self::t('Publikation autoren', 'Publication authors', 'Auteurs des publications', 'Autori delle pubblicazioni'),
                'fields' => [
                    'publikation_id' => ['label' => self::t('Publikation', 'Publication', 'Publication', 'Pubblicazione'), 'relation' => ['fdb_publikationen', ['titel']]],
                    'person_id' => ['label' => self::t('Person', 'Person', 'Personne', 'Persona'), 'relation' => ['fdb_personen', ['vorname', 'nachname'], ' ']],
                    'reihenfolge' => ['label' => self::t('Reihenfolge', 'Order', 'Ordre', 'Ordine')],
                ],
            ],
            [
                'name' => 'fdb_projekt_uebersicht',
                'table' => 'projekt_uebersicht',
                'label' => self::t('Projekt uebersicht', 'Project overview', 'Aperçu des projets', 'Panoramica dei progetti'),
                'group' => 'data',
                'fields' => [
                    'id' => ['label' => self::t('ID', 'ID', 'ID', 'ID')],
                    'kurzname' => ['label' => self::t('Kurzname', 'Short name', 'Nom court', 'Nome breve')],
                    'titel' => ['label' => self::t('Titel', 'Title', 'Titre', 'Titolo')],
                    'status' => ['label' => self::t('Status', 'Status', 'Statut', 'Stato')],
                    'projektleiter' => ['label' => self::t('Projektleiter', 'Project lead', 'Chef de projet', 'Responsabile')],
                    'partner' => ['label' => self::t('Partner', 'Partner', 'Partenaire', 'Partner')],
                    'publikationen' => ['label' => self::t('Publikationen', 'Publications', 'Publications', 'Pubblicazioni')],
                ],
            ],
        ];
    }
}
