<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Menu;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    public const string REPORTS_ROUTE = 'filament.admin.resources.reports.index';

    public const array REPORTS_LABEL = ['de' => 'Auswertungen', 'en' => 'Reports', 'fr' => 'Rapports', 'it' => 'Report'];

    public const string DOCUMENTATION_ROUTE = 'filament.admin.pages.documentation';

    public const array DOCUMENTATION_LABEL = ['de' => 'Dokumentation', 'en' => 'Documentation', 'fr' => 'Documentation', 'it' => 'Documentazione'];

    public function run(): void
    {
        // Einträge über ihre Seite erkennen, Gruppen über die deutsche Bezeichnung.
        Menu::updateOrCreate(
            ['parent_id' => null, 'route' => 'filament.admin.pages.dashboard'],
            ['label' => ['de' => 'Dashboard', 'en' => 'Dashboard', 'fr' => 'Tableau de bord', 'it' => 'Dashboard'], 'icon' => 'heroicon-o-home', 'sort_order' => 0, 'is_active' => true],
        );

        // Dokumentation (Online-Hilfe): für alle, direkt unter dem Dashboard.
        Menu::updateOrCreate(
            ['parent_id' => null, 'route' => self::DOCUMENTATION_ROUTE],
            ['label' => self::DOCUMENTATION_LABEL, 'icon' => 'heroicon-o-book-open', 'sort_order' => 10, 'is_active' => true],
        );

        // Auswertungen: eigener Hauptpunkt zwischen den Datengruppen und der Administration.
        Menu::updateOrCreate(
            ['parent_id' => null, 'route' => self::REPORTS_ROUTE],
            ['label' => self::REPORTS_LABEL, 'icon' => 'heroicon-o-presentation-chart-bar', 'permission_name' => 'reports.view', 'sort_order' => 800, 'is_active' => true],
        );

        // Gruppe ohne Icon: Filament erlaubt Icons nur an Gruppe ODER Einträgen.
        $admin = Menu::query()->whereNull('parent_id')->whereNull('route')->where('label->de', 'Administration')->first() ?? new Menu;
        $admin->fill(['label' => ['de' => 'Administration', 'en' => 'Administration', 'fr' => 'Administration', 'it' => 'Amministrazione'], 'icon' => null, 'route' => null, 'url' => null, 'sort_order' => 900, 'is_active' => true])->save();

        $children = [
            ['label' => ['de' => 'Benutzer', 'en' => 'Users', 'fr' => 'Utilisateurs', 'it' => 'Utenti'], 'route' => 'filament.admin.resources.users.index', 'icon' => 'heroicon-o-users', 'permission_name' => 'users.view', 'sort_order' => 10],
            ['label' => ['de' => 'Rollen', 'en' => 'Roles', 'fr' => 'Rôles', 'it' => 'Ruoli'], 'route' => 'filament.admin.resources.roles.index', 'icon' => 'heroicon-o-shield-check', 'permission_name' => 'roles.view', 'sort_order' => 20],
            ['label' => ['de' => 'Menüs', 'en' => 'Menus', 'fr' => 'Menus', 'it' => 'Menu'], 'route' => 'filament.admin.resources.menus.index', 'icon' => 'heroicon-o-bars-3', 'permission_name' => 'menus.view', 'sort_order' => 30],
            ['label' => ['de' => 'Änderungsprotokoll', 'en' => 'Activity log', 'fr' => 'Journal des modifications', 'it' => 'Registro modifiche'], 'route' => 'filament.admin.resources.activity-log.index', 'icon' => 'heroicon-o-clock', 'permission_name' => 'activity_log.view', 'sort_order' => 40],
            ['label' => ['de' => 'Authentifizierung', 'en' => 'Authentication', 'fr' => 'Authentification', 'it' => 'Autenticazione'], 'route' => 'filament.admin.pages.auth-settings', 'icon' => 'heroicon-o-lock-closed', 'permission_name' => 'settings.auth.view', 'sort_order' => 50],
            ['label' => ['de' => 'Identity Provider', 'en' => 'Identity providers', 'fr' => 'Fournisseurs d’identité', 'it' => 'Provider di identità'], 'route' => 'filament.admin.resources.identity-providers.index', 'icon' => 'heroicon-o-finger-print', 'permission_name' => 'identity_providers.view', 'sort_order' => 60],
            ['label' => ['de' => 'App-Einstellungen', 'en' => 'App settings', 'fr' => 'Paramètres de l’application', 'it' => 'Impostazioni dell’applicazione'], 'route' => 'filament.admin.pages.app-settings', 'icon' => 'heroicon-o-adjustments-horizontal', 'permission_name' => 'settings.app.view', 'sort_order' => 80],
            ['label' => ['de' => 'Table-Designer', 'en' => 'Table designer', 'fr' => 'Concepteur de tables', 'it' => 'Designer di tabelle'], 'route' => 'filament.admin.resources.table-designer.index', 'icon' => 'heroicon-o-table-cells', 'permission_name' => 'custom_tables.view', 'sort_order' => 70],
            ['label' => ['de' => 'Datenbankverbindungen', 'en' => 'Database connections', 'fr' => 'Connexions aux bases de données', 'it' => 'Connessioni ai database'], 'route' => 'filament.admin.resources.database-connections.index', 'icon' => 'heroicon-o-circle-stack', 'permission_name' => 'database_connections.view', 'sort_order' => 75],
            ['label' => ['de' => 'Tabellenverknüpfungen', 'en' => 'Table links', 'fr' => 'Liaisons de tables', 'it' => 'Collegamenti tra tabelle'], 'route' => 'filament.admin.resources.report-links.index', 'icon' => 'heroicon-o-arrows-right-left', 'permission_name' => 'report_links.view', 'sort_order' => 73],
            ['label' => ['de' => 'Geplante Aufgaben', 'en' => 'Scheduled tasks', 'fr' => 'Tâches planifiées', 'it' => 'Attività pianificate'], 'route' => 'filament.admin.resources.scheduled-tasks.index', 'icon' => 'heroicon-o-calendar-days', 'permission_name' => 'tasks.view', 'sort_order' => 78],
            ['label' => ['de' => 'Plugins', 'en' => 'Plugins', 'fr' => 'Plugins', 'it' => 'Plugin'], 'route' => 'filament.admin.pages.plugins', 'icon' => 'heroicon-o-puzzle-piece', 'permission_name' => 'plugins.view', 'sort_order' => 79],
            ['label' => ['de' => 'Systemprotokoll', 'en' => 'System log', 'fr' => 'Journal système', 'it' => 'Registro di sistema'], 'route' => 'filament.admin.pages.system-log', 'icon' => 'heroicon-o-document-text', 'permission_name' => 'system_log.view', 'sort_order' => 82],
            ['label' => ['de' => 'Konfiguration übertragen', 'en' => 'Transfer configuration', 'fr' => 'Transférer la configuration', 'it' => 'Trasferire la configurazione'], 'route' => 'filament.admin.pages.configuration-transfer', 'icon' => 'heroicon-o-arrows-up-down', 'permission_name' => 'configuration.view', 'sort_order' => 81],
            ['label' => ['de' => 'Table-Connector', 'en' => 'Table connector', 'fr' => 'Connecteur de tables', 'it' => 'Connettore di tabelle'], 'route' => 'filament.admin.resources.table-connector.index', 'icon' => 'heroicon-o-link', 'permission_name' => 'table_connector.view', 'sort_order' => 72],
        ];

        foreach ($children as $child) {
            Menu::updateOrCreate(
                ['parent_id' => $admin->id, 'route' => $child['route']],
                [...$child, 'is_active' => true],
            );
        }
    }
}
